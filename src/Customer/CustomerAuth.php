<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Customer;

use Kandoo\Cloud\Database;
use Kandoo\Cloud\Support\Clock;
use Kandoo\Cloud\Support\Uuid;

/** Database-backed OTP operations. All state transitions that affect limits lock rows. */
final class CustomerAuth
{
    public function __construct(private array $config, private Database $db, private Clock $clock, private ?OtpTransport $transport = null) {}

    /** @return array{status:string,challenge_id?:string,expires_at?:int} */
    public function issue(string $phone, string $key): array
    {
        if (!($this->config['enabled'] ?? false) || $this->transport === null) { return ['status' => 'UNAVAILABLE']; }
        if (preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone) !== 1 || $key === '' || strlen($key) > 128) { return ['status' => 'INVALID']; }
        $pdo = $this->db->pdo();
        $now = $this->clock->nowUnix();
        // Idempotency keys are global but keyed before persistence: no raw key or code stored.
        $keyHash = hash_hmac('sha256', $key, $this->config['otp_pepper']);
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('INSERT IGNORE INTO cloud_otp_idempotency_locks (idempotency_hash) VALUES (?)'); $q->execute([$keyHash]);
            $q = $pdo->prepare('SELECT idempotency_hash FROM cloud_otp_idempotency_locks WHERE idempotency_hash = ? FOR UPDATE'); $q->execute([$keyHash]); $q->fetch();
            $q = $pdo->prepare('INSERT IGNORE INTO cloud_customer_phones (phone) VALUES (?)'); $q->execute([$phone]);
            $q = $pdo->prepare('SELECT phone FROM cloud_customer_phones WHERE phone = ? FOR UPDATE'); $q->execute([$phone]); $q->fetch();
            $q = $pdo->prepare('SELECT phone, challenge_id, expires_at, delivery_state FROM cloud_otp_challenges WHERE idempotency_hash = ? FOR UPDATE');
            $q->execute([$keyHash]); $prior = $q->fetch();
            if ($prior !== false) {
                $pdo->commit();
                if ($prior['phone'] !== $phone) { return ['status' => 'CONFLICT']; }
                if ($prior['delivery_state'] !== 'sent') { return ['status' => 'INCOMPLETE']; }
                return ['status' => 'DUPLICATE', 'challenge_id' => $prior['challenge_id'], 'expires_at' => (int) $prior['expires_at']];
            }
            $q = $pdo->prepare('SELECT COUNT(*) FROM cloud_otp_challenges WHERE phone = ? AND created_at > ?');
            $q->execute([$phone, $now - $this->config['issue_window_seconds']]);
            if ((int) $q->fetchColumn() >= $this->config['max_issues_per_window']) { $pdo->commit(); return ['status' => 'RATE_LIMITED']; }
            // An invoice is not an announcement. Only an actual successful announcement
            // to this phone, still attached to the same invoice phone, grants a budget.
            $q = $pdo->prepare('SELECT a.announcement_id FROM cloud_sms_announcements a JOIN public_invoices i ON i.store_id=a.store_id AND i.invoice_id=a.invoice_id AND i.customer_phone=a.phone WHERE a.phone=? AND a.code_issues < ? ORDER BY a.delivered_at, a.announcement_id LIMIT 1 FOR UPDATE');
            $q->execute([$phone, $this->config['codes_per_announcement']]); $announcement = $q->fetchColumn();
            if ($announcement === false) { $pdo->commit(); return ['status' => 'TELEGRAM']; }
            $id = self::uuid(); $code = OtpSecrets::generateCode($this->config['code_length']);
            $expires = $now + $this->config['code_ttl_seconds'];
            $q = $pdo->prepare('UPDATE cloud_sms_announcements SET code_issues=code_issues+1 WHERE announcement_id=?'); $q->execute([$announcement]);
            $q = $pdo->prepare('INSERT INTO cloud_otp_challenges (challenge_id, phone, announcement_id, idempotency_hash, code_mac, created_at, expires_at, delivery_state) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $q->execute([$id, $phone, $announcement, $keyHash, OtpSecrets::codeMac($this->config['otp_pepper'], $id, $code), $now, $expires, 'pending']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        try { $sent = $this->transport->sendCode($phone, $code); } catch (\Throwable $e) { $sent = false; }
        // A failed/ambiguous delivery consumes budget; retrying the same key cannot send twice.
        $q = $pdo->prepare('UPDATE cloud_otp_challenges SET delivery_state = ? WHERE challenge_id = ?');
        $q->execute([$sent ? 'sent' : 'failed', $id]);
        return $sent ? ['status' => 'OTP_SENT', 'challenge_id' => $id, 'expires_at' => $expires] : ['status' => 'INCOMPLETE'];
    }

    /** @return array{status:string,token?:string} */
    public function verify(string $id, string $code): array
    {
        if (!($this->config['enabled'] ?? false)) { return ['status' => 'UNAVAILABLE']; }
        if (Uuid::tryNormalize($id) !== $id || preg_match('/^[0-9]+$/D', $code) !== 1 || strlen($code) !== $this->config['code_length']) { return ['status' => 'INVALID']; }
        $pdo = $this->db->pdo(); $now = $this->clock->nowUnix(); $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT * FROM cloud_otp_challenges WHERE challenge_id=? FOR UPDATE'); $q->execute([$id]); $row = $q->fetch();
            if ($row === false || $row['delivery_state'] !== 'sent' || (int) $row['consumed'] !== 0 || (int) $row['expires_at'] <= $now || (int) $row['attempts'] >= $this->config['max_attempts']) {
                $pdo->commit(); return ['status' => 'INVALID'];
            }
            $q = $pdo->prepare('UPDATE cloud_otp_challenges SET attempts=attempts+1 WHERE challenge_id=?'); $q->execute([$id]);
            if (!OtpSecrets::verifyCode($this->config['otp_pepper'], $id, $code, $row['code_mac'])) { $pdo->commit(); return ['status' => 'INVALID']; }
            $q = $pdo->prepare('UPDATE cloud_otp_challenges SET consumed=1 WHERE challenge_id=?'); $q->execute([$id]);
            $token = OtpSecrets::sessionToken();
            $q = $pdo->prepare('INSERT INTO cloud_customer_sessions (token_hash,phone,expires_at) VALUES (?,?,?)');
            $q->execute([OtpSecrets::sessionHash($token), $row['phone'], $now + $this->config['session_ttl_seconds']]);
            $pdo->commit(); return ['status' => 'OK', 'token' => $token];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e;
        }
    }

    public function phoneForToken(string $token): ?string
    {
        if (!($this->config['enabled'] ?? false) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $token) !== 1) { return null; }
        $q = $this->db->pdo()->prepare('SELECT phone FROM cloud_customer_sessions WHERE token_hash=? AND expires_at>?');
        $q->execute([OtpSecrets::sessionHash($token), $this->clock->nowUnix()]); $value = $q->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function invoices(string $phone): array
    {
        $q = $this->db->pdo()->prepare("SELECT store_id, invoice_id, store_name, display_number FROM public_invoices WHERE customer_phone=? AND lifecycle_status IN ('ISSUED','REVOKED','SUPERSEDED') ORDER BY issued_at DESC, invoice_id");
        $q->execute([$phone]); return $q->fetchAll();
    }

    public function invoice(string $phone, string $store, string $invoice): ?array
    {
        if (Uuid::tryNormalize($store) !== $store || Uuid::tryNormalize($invoice) !== $invoice) { return null; }
        $q = $this->db->pdo()->prepare("SELECT * FROM public_invoices WHERE customer_phone=? AND store_id=? AND invoice_id=? AND lifecycle_status IN ('ISSUED','REVOKED','SUPERSEDED')");
        $q->execute([$phone, $store, $invoice]); $row = $q->fetch(); return $row === false ? null : $row;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes); return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}

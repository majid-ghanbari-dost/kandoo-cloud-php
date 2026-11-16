<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Retention;

use Kandoo\Cloud\Database;

/** Prunes only explicitly eligible, expired transient data; never audit or replication data. */
final class Cleanup
{
    public function __construct(private Database $db, private array $settings, private int $issueWindowSeconds = 0) {}

    /** @return array<string,int> */
    public function run(int $now): array
    {
        $pdo = $this->db->pdo();
        $counts = [];
        // Pending sends are never deleted: their result may still be in flight or ambiguous.
        // Failed SMS challenges are held for both OTP and undeliverable-SMS periods.
        $sql = "DELETE FROM cloud_otp_challenges WHERE expires_at <= ? AND created_at <= ? AND delivery_state <> 'pending' AND (delivery_state <> 'failed' OR created_at <= ?)";
        $q = $pdo->prepare($sql);
        $q->execute([$now, $now - max($this->settings['otp_challenge_seconds'], $this->issueWindowSeconds), $now - $this->settings['undeliverable_sms_seconds']]);
        $counts['otp_challenges'] = $q->rowCount();
        $q = $pdo->prepare('DELETE FROM cloud_customer_sessions WHERE expires_at <= ?');
        $q->execute([$now - $this->settings['expired_session_seconds']]);
        $counts['expired_sessions'] = $q->rowCount();
        // Retain keys tied to any surviving challenge and in-flight sends even if old.
        $q = $pdo->prepare("DELETE l FROM cloud_otp_idempotency_locks l LEFT JOIN cloud_otp_challenges c ON c.idempotency_hash=l.idempotency_hash WHERE l.created_at <= ? AND c.challenge_id IS NULL AND (l.delivery_state IS NULL OR l.delivery_state <> 'pending')");
        $q->execute([$now - $this->settings['idempotency_seconds']]);
        $counts['otp_idempotency_keys'] = $q->rowCount();
        // No delivery-request or confirmed-announcement row is disposable under the ruling.
        $counts['sms_requests'] = 0;
        $counts['confirmed_announcements'] = 0;
        return $counts;
    }
}

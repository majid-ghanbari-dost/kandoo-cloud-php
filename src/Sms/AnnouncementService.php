<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Sms;

use Kandoo\Cloud\Database;
use Kandoo\Cloud\Support\Clock;
use Kandoo\Cloud\Support\Uuid;

/** Reserve sends before contacting the provider; uncertain sends are never retried automatically. */
final class AnnouncementService
{
    public function __construct(private Database $db, private FarazAdapter|\Kandoo\Cloud\Demo\DemoSms $adapter, private Clock $clock, private ?SmsCostRecorder $cost = null) {}

    public function send(string $requestId, string $storeId, string $invoiceId): string
    {
        foreach ([$requestId, $storeId, $invoiceId] as $id) { if (Uuid::tryNormalize($id) !== $id) { return 'INVALID'; } }
        $pdo = $this->db->pdo(); $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT store_id, invoice_id, state FROM cloud_sms_delivery_requests WHERE request_id = ? FOR UPDATE');
            $q->execute([$requestId]); $existing = $q->fetch();
            if ($existing !== false) {
                $pdo->commit();
                return $existing['store_id'] === $storeId && $existing['invoice_id'] === $invoiceId ? 'EXISTS' : 'CONFLICT';
            }
            $q = $pdo->prepare('SELECT customer_phone FROM public_invoices WHERE store_id=? AND invoice_id=? FOR UPDATE');
            $q->execute([$storeId, $invoiceId]); $phone = $q->fetchColumn();
            if (!is_string($phone) || preg_match('/^\+989[0-9]{9}$/D', $phone) !== 1) { $pdo->commit(); return 'INVALID'; }
            $q = $pdo->prepare('INSERT INTO cloud_sms_delivery_requests (request_id, store_id, invoice_id, phone, state, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $q->execute([$requestId, $storeId, $invoiceId, $phone, 'pending', $this->clock->nowUnix()]);
            $this->cost?->reserve($pdo, $requestId, $storeId, 'notification', $this->clock->nowUnix());
            $pdo->commit();
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
        try {
            if ($this->adapter instanceof \Kandoo\Cloud\Demo\DemoSms) {
                $q = $pdo->prepare("SELECT public_token FROM public_invoices WHERE store_id=? AND invoice_id=? AND lifecycle_status='ISSUED'");
                $q->execute([$storeId,$invoiceId]);
                $token = $q->fetchColumn();
                $accepted = is_string($token) && $this->adapter->sendAnnouncement($phone,$token);
            } else {
                $accepted = $this->adapter->sendAnnouncement($phone);
            }
        } catch (\Throwable $e) { $accepted = false; }
        $q = $pdo->prepare('UPDATE cloud_sms_delivery_requests SET state=?, provider_receipt=? WHERE request_id=? AND state=?');
        $q->execute([$accepted ? 'accepted' : 'uncertain', $this->adapter->lastReceipt(), $requestId, 'pending']);
        return $accepted ? 'ACCEPTED' : 'UNCERTAIN';
    }

    /** Only call after an operator verified provider delivery, not merely provider acceptance. */
    public function confirmDelivery(string $requestId): string
    {
        if (Uuid::tryNormalize($requestId) !== $requestId) { return 'INVALID'; }
        $pdo = $this->db->pdo(); $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT * FROM cloud_sms_delivery_requests WHERE request_id=? FOR UPDATE'); $q->execute([$requestId]); $row=$q->fetch();
            if ($row === false || !in_array($row['state'], ['accepted', 'confirmed'], true)) { $pdo->commit(); return 'INVALID'; }
            if ($row['state'] === 'confirmed') { $pdo->commit(); return 'EXISTS'; }
            $q = $pdo->prepare('SELECT customer_phone FROM public_invoices WHERE store_id=? AND invoice_id=? FOR UPDATE');
            $q->execute([$row['store_id'], $row['invoice_id']]);
            if ($q->fetchColumn() !== $row['phone']) { $pdo->commit(); return 'INVALID'; }
            $q = $pdo->prepare('INSERT INTO cloud_sms_announcements (announcement_id,store_id,invoice_id,phone,delivered_at) VALUES (?,?,?,?,?)');
            $q->execute([$requestId, $row['store_id'], $row['invoice_id'], $row['phone'], $this->clock->nowUnix()]);
            $q = $pdo->prepare("UPDATE cloud_sms_delivery_requests SET state='confirmed' WHERE request_id=?"); $q->execute([$requestId]);
            $pdo->commit(); return 'CONFIRMED';
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    }
}

<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class AdminV2DatabaseTest extends DbTestCase
{
    public function testDeviceRevocationBlocksAuthAndIsAudited(): void
    {
        $pdo = $this->db()->pdo();
        $gateway = new MariaDbGateway($this->db());
        $storeId = '55555555-5555-5555-5555-555555555555';
        $deviceId = '66666666-6666-6666-6666-666666666666';
        $secretHash = hash('sha256', 'TEST-db-device-secret');
        $gateway->seedStore(['store_id' => $storeId, 'active_writer_device_id' => $deviceId,
            'active_writer_epoch' => 1, 'accepted_through_seq' => 0, 'entry_count' => 0, 'projection_version' => 1, 'throttled' => false]);
        $gateway->insertDevice($deviceId, $storeId, $secretHash, '2026-01-01 00:00:00');
        try {
            $this->assertNotNull($gateway->findDeviceByStoreAndSecretHash($storeId, $secretHash));
            $this->assertTrue($gateway->revokeDevice($deviceId, 'TEST-owner revocation', '2026-01-02 00:00:00'));
            $this->assertFalse($gateway->revokeDevice($deviceId, 'TEST-second attempt', '2026-01-02 00:00:01'));
            $this->assertNull($gateway->findDeviceByStoreAndSecretHash($storeId, $secretHash));
            $device = $gateway->findDevice($deviceId);
            $this->assertNotNull($device['revoked_at']);
            $this->assertStringStartsWith('2026-01-02', (string) $device['revoked_at']);
            $this->assertSame('TEST-owner revocation', $device['revoked_reason']);
            $events = $pdo->query("SELECT detail FROM cloud_admin_events WHERE event_type = 'device_revoked'")->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertContains($deviceId, $events);
            $listed = array_values(array_filter($gateway->listDevices(), static fn (array $row): bool => $row['device_id'] === $deviceId));
            $this->assertCount(1, $listed);
            $this->assertNotNull($listed[0]['revoked_at']);
            $stores = array_values(array_filter($gateway->listStores(), static fn (array $row): bool => $row['store_id'] === $storeId));
            $this->assertCount(1, $stores);
            $this->assertSame(0, $stores[0]['entry_count']);
        } finally {
            $pdo->prepare('DELETE FROM cloud_replication_devices WHERE device_id = ?')->execute([$deviceId]);
            $pdo->prepare('DELETE FROM cloud_stores WHERE store_id = ?')->execute([$storeId]);
            $pdo->exec("DELETE FROM cloud_admin_events WHERE event_type = 'device_revoked'");
        }
    }

    public function testSmsStatisticsAndCostLog(): void
    {
        $pdo = $this->db()->pdo();
        $gateway = new MariaDbGateway($this->db());
        $storeId = '77777777-7777-7777-7777-777777777777';
        $invoiceId = '88888888-8888-8888-8888-888888888888';
        $today = gmdate('Y-m-d');
        $gateway->seedStore(['store_id' => $storeId, 'active_writer_device_id' => $storeId,
            'active_writer_epoch' => 1, 'accepted_through_seq' => 0, 'entry_count' => 0, 'projection_version' => 1, 'throttled' => false]);
        $gateway->seedProjection([
            'public_token' => 'TEST-admin-v2-public-token', 'public_token_hash' => hash('sha256', 'TEST-admin-v2-token'),
            'digital_invoice_id' => '99999999-9999-9999-9999-999999999999', 'invoice_id' => $invoiceId, 'store_id' => $storeId,
            'store_name' => 'TEST-store', 'display_number' => 1, 'lifecycle_status' => 'issued', 'issued_at' => '2026-01-01 00:00:00',
            'issued_payload' => '{}', 'issued_content_sha256' => hash('sha256', 'TEST-admin-v2-payload'),
            'payable_rials' => 1000, 'line_items' => '[]', 'customer_phone' => null,
        ]);
        try {
            $statsBefore = $gateway->smsDeliveryStatistics();
            $unitsBefore = 0;
            $costBefore = 0.0;
            foreach ($gateway->smsCostDailyTotals(366) as $row) {
                if ($row['cost_date'] === $today) { $unitsBefore = $row['units']; $costBefore = (float) $row['cost']; break; }
            }
            $insert = $pdo->prepare('INSERT INTO cloud_sms_delivery_requests (request_id, store_id, invoice_id, phone, state, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute(['aaaaaaaa-0000-0000-0000-000000000001', $storeId, $invoiceId, '+989120000001', 'sent', time()]);
            $insert->execute(['aaaaaaaa-0000-0000-0000-000000000002', $storeId, $invoiceId, '+989120000002', 'sent', time()]);
            $insert->execute(['aaaaaaaa-0000-0000-0000-000000000003', $storeId, $invoiceId, '+989120000003', 'failed', time()]);

            $statsAfter = $gateway->smsDeliveryStatistics();
            $this->assertSame($statsBefore['total'] + 3, $statsAfter['total']);
            $this->assertSame(($statsBefore['by_state']['sent'] ?? 0) + 2, $statsAfter['by_state']['sent']);
            $this->assertSame(($statsBefore['by_state']['failed'] ?? 0) + 1, $statsAfter['by_state']['failed']);

            $gateway->insertSmsCostEntry(['request_id' => 'aaaaaaaa-0000-0000-0000-000000000001', 'store_id' => $storeId, 'pattern' => 'notification', 'units' => 1, 'unit_cost' => '1500.000000', 'currency' => 'IRR', 'cost_date' => $today, 'created_at' => time()]);
            $gateway->insertSmsCostEntry(['request_id' => null, 'store_id' => $storeId, 'pattern' => 'otp', 'units' => 2, 'unit_cost' => '950.000000', 'currency' => 'IRR', 'cost_date' => $today, 'created_at' => time()]);

            $entries = array_values(array_filter($gateway->smsCostLog(500), static fn (array $row): bool => $row['store_id'] === $storeId));
            $this->assertCount(2, $entries);
            $this->assertSame('otp', $entries[0]['pattern']); // newest first
            $this->assertSame(2, $entries[0]['units']);
            $this->assertSame('IRR', $entries[0]['currency']);

            $unitsAfter = 0;
            $costAfter = 0.0;
            foreach ($gateway->smsCostDailyTotals(366) as $row) {
                if ($row['cost_date'] === $today) { $unitsAfter = $row['units']; $costAfter = (float) $row['cost']; break; }
            }
            $this->assertSame($unitsBefore + 3, $unitsAfter);
            $this->assertEqualsWithDelta($costBefore + 3400.0, $costAfter, 0.0001);
        } finally {
            $pdo->prepare('DELETE FROM cloud_sms_cost_log WHERE store_id = ?')->execute([$storeId]);
            $pdo->prepare('DELETE FROM cloud_sms_delivery_requests WHERE store_id = ?')->execute([$storeId]);
            $pdo->prepare('DELETE FROM public_invoices WHERE store_id = ?')->execute([$storeId]);
            $pdo->prepare('DELETE FROM cloud_stores WHERE store_id = ?')->execute([$storeId]);
        }
    }
}

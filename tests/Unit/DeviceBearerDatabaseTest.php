<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Tests\Unit;

use Kandoo\Cloud\Http\Request;
use Kandoo\Cloud\Kernel;
use Kandoo\Cloud\Store\MariaDbGateway;
use Kandoo\Cloud\Support\FixedClock;
use Kandoo\Cloud\Support\RandomToken;
use Kandoo\Cloud\Tests\Support\DbTestCase;

final class DeviceBearerDatabaseTest extends DbTestCase
{
    public function testBothCredentialsStoreScopeAndAmbiguity(): void
    {
        $db = $this->db(); $gateway = new MariaDbGateway($db);
        $store = RandomToken::uuidV4(); $other = RandomToken::uuidV4();
        $device = RandomToken::uuidV4(); $otherDevice = RandomToken::uuidV4();
        $secret = 'TEST-only-secret-123456789012345678901';
        $config = self::$config; $config['replication_auth_bypass'] = false; $config['replication_key'] = null;
        $kernel = new Kernel($config, $gateway, new FixedClock(1767323045));
        $path = '/v1/stores/'.$store.'/operation-batches';
        $request = static fn(array $headers) => $kernel->handle(new Request('POST', $path, $headers, [], '{'));
        try {
            foreach ([$store, $other] as $id) {
                $gateway->seedStore(['store_id'=>$id,'active_writer_device_id'=>$device,'active_writer_epoch'=>1,
                    'accepted_through_seq'=>0,'entry_count'=>0,'head_entry_hash'=>null,'projection_version'=>0]);
            }
            $gateway->insertDevice($device, $store, hash('sha256', $secret), '2026-01-01 00:00:00');
            $gateway->insertDevice($otherDevice, $other, hash('sha256', 'TEST-other-secret-123456789012345'), '2026-01-01 00:00:00');
            foreach ([[], ['authorization'=>'Bearer TEST-wrong'], ['authorization'=>'Basic '.$secret],
                ['authorization'=>'Bearer'], ['authorization'=>'Bearer TEST-other-secret-123456789012345'],
                ['x-kandoo-device-id'=>$device,'x-kandoo-device-secret'=>'TEST-wrong'],
                ['authorization'=>'Bearer TEST-wrong','x-kandoo-device-id'=>$device,'x-kandoo-device-secret'=>$secret]] as $headers) {
                $this->assertSame(401, $request($headers)->status());
            }
            $this->assertSame(400, $request(['authorization'=>'Bearer '.$secret])->status());
            $this->assertSame(400, $request(['x-kandoo-device-id'=>$device,'x-kandoo-device-secret'=>$secret])->status());
            $this->assertTrue($gateway->findDevice($device)['verified']);
            $gateway->insertDevice(RandomToken::uuidV4(), $store, hash('sha256', $secret), '2026-01-01 00:00:00');
            $this->assertSame(401, $request(['authorization'=>'Bearer '.$secret])->status(), 'ambiguous device secret must fail closed');
        } finally {
            $stmt = $db->pdo()->prepare('DELETE FROM cloud_stores WHERE store_id IN (?, ?)');
            $stmt->execute([$store, $other]);
        }
    }
}

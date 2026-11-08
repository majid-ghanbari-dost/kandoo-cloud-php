<?php

declare(strict_types=1);

/**
 * Store provisioning CLI (CLOUD-CONTRACT.md §5.7, UNCONFIRMED U2) — admin-only, NEVER
 * web-exposed (bin/ is denied). The frozen Cloud has no HTTP registration endpoint; the
 * pilot provisions store rows out of band. A production registration flow (paper-contract
 * OTP + lawyer-approved agreement) is a later window's decision.
 *
 * Usage:
 *   php bin/store.php provision <store_id> <writer_device_id> <writer_epoch>
 *   php bin/store.php show <store_id>
 *   php bin/store.php throttle <store_id> on|off
 *
 * UUIDs may be given in D/N/B/P form and are stored normalized lowercase D.
 * No credentials or customer data are printed beyond store bookkeeping columns.
 *
 * Product: فاکتور دیجیتال کندو (Kandoo Digital Invoice).
 */

use Kandoo\Cloud\Config;
use Kandoo\Cloud\ConfigException;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Support\Uuid;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

$usage = static function (): never {
    fwrite(STDERR, "Usage:\n"
        . "  php bin/store.php provision <store_id> <writer_device_id> <writer_epoch>\n"
        . "  php bin/store.php show <store_id>\n"
        . "  php bin/store.php throttle <store_id> on|off\n");
    exit(2);
};

$argvList = $argv;
array_shift($argvList);
$command = array_shift($argvList);
if ($command === null) {
    $usage();
}

try {
    $config = Config::load();
    $pdo = (new Database($config->all()))->pdo();
} catch (ConfigException $e) {
    fwrite(STDERR, 'CONFIG ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'DB ERROR (is MariaDB reachable and migrated?): ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$needUuid = static function (?string $value, string $label): string {
    $normalized = $value === null ? null : Uuid::tryNormalize($value);
    if ($normalized === null) {
        fwrite(STDERR, "Invalid UUID for $label." . PHP_EOL);
        exit(2);
    }
    return $normalized;
};

try {
    switch ($command) {
        case 'provision': {
            $storeId = $needUuid(array_shift($argvList) ?? null, 'store_id');
            $writerDeviceId = $needUuid(array_shift($argvList) ?? null, 'writer_device_id');
            $epochRaw = array_shift($argvList);
            if ($epochRaw === null || preg_match('/^\d+$/', $epochRaw) !== 1 || (int) $epochRaw < 1) {
                fwrite(STDERR, "writer_epoch must be an integer >= 1.\n");
                exit(2);
            }
            $check = $pdo->prepare('SELECT store_id FROM cloud_stores WHERE store_id = ?');
            $check->execute([$storeId]);
            if ($check->fetchColumn() !== false) {
                fwrite(STDERR, "Store already exists: $storeId\n");
                exit(1);
            }
            $insert = $pdo->prepare(
                'INSERT INTO cloud_stores
                    (store_id, active_writer_device_id, active_writer_epoch, accepted_through_seq,
                     entry_count, head_entry_hash, projection_version, throttled)
                 VALUES (?, ?, ?, 0, 0, NULL, 0, 0)'
            );
            $insert->execute([$storeId, $writerDeviceId, (int) $epochRaw]);
            echo "provisioned store $storeId (writer $writerDeviceId, epoch $epochRaw)\n";
            exit(0);
        }
        case 'show': {
            $storeId = $needUuid(array_shift($argvList) ?? null, 'store_id');
            $stmt = $pdo->prepare(
                'SELECT store_id, active_writer_device_id, active_writer_epoch, accepted_through_seq,
                        entry_count, head_entry_hash, projection_version, throttled
                 FROM cloud_stores WHERE store_id = ?'
            );
            $stmt->execute([$storeId]);
            $row = $stmt->fetch();
            if ($row === false) {
                echo "store not found: $storeId\n";
                exit(1);
            }
            foreach ($row as $key => $value) {
                echo str_pad((string) $key, 26), ': ', var_export($value, true), PHP_EOL;
            }
            exit(0);
        }
        case 'throttle': {
            $storeId = $needUuid(array_shift($argvList) ?? null, 'store_id');
            $mode = strtolower((string) (array_shift($argvList) ?? ''));
            if (!in_array($mode, ['on', 'off'], true)) {
                fwrite(STDERR, "throttle mode must be on|off.\n");
                exit(2);
            }
            $stmt = $pdo->prepare('UPDATE cloud_stores SET throttled = ? WHERE store_id = ?');
            $stmt->execute([$mode === 'on' ? 1 : 0, $storeId]);
            if ($stmt->rowCount() === 0 && $mode !== '') {
                // either unknown store or value unchanged — verify existence
                $check = $pdo->prepare('SELECT store_id FROM cloud_stores WHERE store_id = ?');
                $check->execute([$storeId]);
                if ($check->fetchColumn() === false) {
                    fwrite(STDERR, "store not found: $storeId\n");
                    exit(1);
                }
            }
            echo "throttled($storeId) = $mode\n";
            exit(0);
        }
        default:
            $usage();
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

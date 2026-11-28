<?php

declare(strict_types=1);

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Operations\LogicalBackup;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';

// `backup_hmac_key` (optional, private config) signs every archive and makes `restore-test`
// refuse a file this installation did not produce. Without it only the plain SHA-256 digest
// is checked, which proves integrity, not authenticity (P-30/P-34).
$key = static function (): ?string {
    $value = Config::load()->all()['backup_hmac_key'] ?? null;
    return is_string($value) && $value !== '' ? $value : null;
};

if (($argv[1] ?? '') === 'create' && count($argv) === 3) {
    try {
        $pdo=(new Database(Config::load()->all()))->pdo();
        $result=LogicalBackup::create($pdo,$argv[2],$key());
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL; exit(0);
    } catch (Throwable $e) { fwrite(STDERR,"BACKUP FAILED\n"); exit(1); }
}
if (($argv[1] ?? '') === 'restore-test' && (count($argv) === 4 || count($argv) === 5)) {
    try {
        $pdo=(new Database(Config::load()->all()))->pdo();
        LogicalBackup::restoreTest($pdo,$argv[2],$argv[3],$key(),$argv[4] ?? null);
        echo "ISOLATED RESTORE COMPLETE\n"; exit(0);
    } catch (Throwable $e) { fwrite(STDERR,"RESTORE FAILED; isolated target may be partially populated\n"); exit(1); }
}
fwrite(STDERR,"Usage: php bin/backup.php create <private-separate-directory> | restore-test <trusted-file> <sha256> [<hmac-sha256>]\n");
fwrite(STDERR,"When config.php sets backup_hmac_key, the HMAC printed by `create` is required by `restore-test`.\n");
exit(2);

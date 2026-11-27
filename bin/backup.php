<?php

declare(strict_types=1);

use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Operations\LogicalBackup;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
if (($argv[1] ?? '') === 'create' && count($argv) === 3) {
    try {
        $pdo=(new Database(Config::load()->all()))->pdo();
        $result=LogicalBackup::create($pdo,$argv[2]);
        echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL; exit(0);
    } catch (Throwable $e) { fwrite(STDERR,"BACKUP FAILED\n"); exit(1); }
}
if (($argv[1] ?? '') === 'restore-test' && count($argv) === 4) {
    try {
        $pdo=(new Database(Config::load()->all()))->pdo();
        LogicalBackup::restoreTest($pdo,$argv[2],$argv[3]);
        echo "ISOLATED RESTORE COMPLETE\n"; exit(0);
    } catch (Throwable $e) { fwrite(STDERR,"RESTORE FAILED; isolated target may be partially populated\n"); exit(1); }
}
fwrite(STDERR,"Usage: php bin/backup.php create <private-separate-directory> | restore-test <trusted-file> <sha256>\n"); exit(2);

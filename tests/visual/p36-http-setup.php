<?php

declare(strict_types=1);

// CI-only synthetic screenshots: use the isolated MariaDB and invoice seeded by HubReplay.
use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Customer\OtpSecrets;

require dirname(__DIR__, 2).'/src/bootstrap.php';
$config = Config::load()->all();
if (($config['env'] ?? '') !== 'testing' || !str_starts_with($config['db']['name'], 'TEST_')
    || !in_array($config['db']['host'], ['localhost', '127.0.0.1'], true)
    || getenv('KANDOO_BLOCK_REAL_SMS') !== '1' || count($argv) !== 2) {
    throw new RuntimeException('Visual fixture requires isolated test database and disabled real SMS');
}
$db = new Database($config); $pdo = $db->pdo();
$q = $pdo->prepare('SELECT public_token FROM public_invoices WHERE store_id=? AND invoice_id=?');
$q->execute(['11111111-1111-4111-8111-111111111111', '77777777-7777-4777-8777-777777777777']);
$public = $q->fetchColumn();
if (!is_string($public) || !\Kandoo\Cloud\Support\RandomToken::hasValidShape($public)) {
    throw new RuntimeException('Synthetic Hub invoice not found');
}
$session = OtpSecrets::sessionToken();
$q = $pdo->prepare('INSERT INTO cloud_customer_sessions (token_hash,phone,expires_at) VALUES (?,?,?)');
// This fictional phone is deliberately not attached to the seeded invoice.
$q->execute([OtpSecrets::sessionHash($session), '+989120000099', time()+900]);
file_put_contents($argv[1], json_encode(['public_token'=>$public, 'session'=>$session], JSON_THROW_ON_ERROR), LOCK_EX);
chmod($argv[1], 0600);

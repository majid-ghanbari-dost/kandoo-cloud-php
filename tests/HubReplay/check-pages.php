<?php

declare(strict_types=1);
use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
require dirname(__DIR__,2).'/src/bootstrap.php';
$config=Config::load()->all();
if ($config['env'] !== 'testing' || !str_starts_with($config['db']['name'],'TEST_') || !in_array($config['db']['host'],['127.0.0.1','localhost'],true)) { throw new RuntimeException('Not isolated local test DB'); }
$db=new Database($config);
$q=$db->pdo()->prepare('SELECT public_token, customer_name, currency_unit, remaining_balance FROM public_invoices WHERE store_id=? AND invoice_id=?');
$q->execute(['11111111-1111-4111-8111-111111111111','77777777-7777-4777-8777-777777777777']);
$row=$q->fetch();
if (!$row || $row['customer_name'] !== 'TEST customer' || $row['currency_unit'] !== 'toman' || (int)$row['remaining_balance'] !== 17) { throw new RuntimeException('Missing v1.1 projection'); }
$url='http://127.0.0.1:18083/i/'.rawurlencode($row['public_token']);
$html=file_get_contents($url); $json=file_get_contents($url.'?format=json'); $view=json_decode($json,true,512,JSON_THROW_ON_ERROR);
if (!str_contains($html,'TEST customer') || ($view['remaining_balance'] ?? null)!==17) { throw new RuntimeException('Public page mismatch'); }
$login=file_get_contents('http://127.0.0.1:18083/account/login');
if (!str_contains($login,'ورود مشتری')) { throw new RuntimeException('Customer login page mismatch'); }
echo "P-23 public HTML/JSON and customer login page PASS; recorded v1.1 fields preserved.\n";

<?php

declare(strict_types=1);
use Kandoo\Cloud\Config;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Json\Canonical;
use Kandoo\Cloud\Json\Parser;
use Kandoo\Cloud\Store\MariaDbGateway;

require dirname(__DIR__,2).'/src/bootstrap.php';
$root=dirname(__DIR__,2); $config=Config::load()->all();
if ($config['env'] !== 'testing' || !str_starts_with($config['db']['name'],'TEST_') || !in_array($config['db']['host'],['127.0.0.1','localhost'],true) || getenv('KANDOO_BLOCK_REAL_SMS') !== '1') { throw new RuntimeException('Not isolated local test DB'); }
$config['replication_auth_bypass']=false; $config['replication_key']=null;
$config['customer_auth']=['enabled'=>true,'code_length'=>6,'code_ttl_seconds'=>300,'max_attempts'=>3,'codes_per_announcement'=>2,'issue_window_seconds'=>900,'max_issues_per_window'=>3,'session_ttl_seconds'=>3600,'device_ttl_seconds'=>3600,'device_numbers_per_day'=>3,'issue_ip_limit_per_minute'=>10,'verify_ip_limit_per_minute'=>20,'otp_pepper'=>'TEST-only-pepper-01234567890123456789','telegram_url'=>null];
file_put_contents($argv[1], "<?php\nreturn ".var_export($config,true).";\n"); chmod($argv[1],0600);
$fixture=json_decode(file_get_contents($root.'/docs/golden/g-023-replication-issue-projection-e2e.json'),true,512,JSON_THROW_ON_ERROR);
$batch=json_decode($fixture['steps'][0]['request']['body_text'],true,512,JSON_THROW_ON_ERROR);
$invoice=&$batch['entries'][2]['response_snapshot']['response']['invoice'];
$invoice['invoice_schema_version']='1.1'; $invoice['customer_name']='TEST customer'; $invoice['currency_unit']='toman'; $invoice['remaining_balance']=17; unset($invoice);
$entry=$batch['entries'][2]; $entry['entry_hash']=Canonical::entryHash(Canonical::entryDto(Parser::parse(json_encode($entry,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)))); $batch['entries'][2]=$entry;
file_put_contents($argv[2],json_encode($batch,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
$db=new Database($config); $gateway=new MariaDbGateway($db);
$gateway->seedStore($fixture['given']['stores'][0]);
$gateway->insertDevice($batch['writer_device_id'],$batch['store_id'],hash('sha256','TEST-p23-synthetic-secret-0123456789abcdef'),gmdate('Y-m-d H:i:s'));

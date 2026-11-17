<?php

declare(strict_types=1);

// Build a temporary, local-only config for synthetic read-only load. Never modify the source.
if (PHP_SAPI !== 'cli' || count($argv)!==2) { exit(2); }
require dirname(__DIR__,2).'/src/bootstrap.php';
try {
    $config=\Kandoo\Cloud\Config::load()->all();
    $name=$config['db']['name'];
    if (!in_array($config['db']['host'],['127.0.0.1','localhost'],true)
        || !(($config['env']==='testing' && str_starts_with($name,'TEST_')) || ($config['env']==='demo' && preg_match('/^kandoo_demo_[0-9a-f]{12}$/D',$name)===1))) {
        throw new RuntimeException('Not an isolated local demo/test database');
    }
    $config['public_rate_limit_per_minute']=0; // This one temporary process only.
    if (file_put_contents($argv[1],"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX)===false) { throw new RuntimeException('Cannot write temporary config'); }
    @chmod($argv[1],0600);
} catch (Throwable $e) { fwrite(STDERR,"Load test refused: use an isolated local demo/TEST database.\n"); exit(1); }

<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Demo;

use Kandoo\Cloud\Customer\OtpTransport;
use Kandoo\Cloud\Database;
use Kandoo\Cloud\Support\Clock;
use Kandoo\Cloud\Support\RandomToken;

/** Local-only synthetic messaging; never connects to an SMS provider. */
final class DemoSms implements OtpTransport
{
    private ?string $receipt=null;
    public function __construct(private array $config, private Database $db, private Clock $clock, private string $directory)
    {
        if (($config['env'] ?? '') !== 'demo' || ($config['faraz_sms']['enabled'] ?? false) !== false || !is_dir($directory)) {
            throw new \RuntimeException('Demo SMS unavailable');
        }
    }
    public function sendCode(string $phone, string $code): bool
    {
        if (preg_match('/^[0-9]{6,9}$/D',$code)!==1) { return false; }
        return $this->write($phone,'otp','کد ورود نمایشی کندو: '.$code);
    }
    public function sendAnnouncement(string $phone): bool
    {
        return $this->write($phone,'announcement','فاکتور نمایشی در کندو ثبت شد. '.$this->config['base_url'].'/account/login');
    }
    public function lastReceipt(): ?string { return $this->receipt; }
    private function write(string $phone,string $kind,string $body): bool
    {
        if (preg_match('/^\+989[0-9]{9}$/D',$phone)!==1) { return false; }
        $id=RandomToken::uuidV4(); $at=$this->clock->nowUnix(); $pdo=$this->db->pdo();
        $line=json_encode(['id'=>$id,'at'=>$at,'phone'=>$phone,'kind'=>$kind,'body'=>$body],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
        $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('INSERT INTO cloud_demo_sms_outbox (message_id,phone,kind,body,created_at) VALUES (?,?,?,?,?)');
            $q->execute([$id,$phone,$kind,$body,$at]);
            if (file_put_contents($this->directory.'/sms.log',$line,FILE_APPEND|LOCK_EX)===false) { throw new \RuntimeException('Demo log unavailable'); }
            $pdo->commit(); $this->receipt='local-'.$id; return true;
        } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } $this->receipt=null; return false; }
    }
}

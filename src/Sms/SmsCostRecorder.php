<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Sms;

/** Estimated, configured unit prices for reserved attempts; not provider billing or delivery. */
final class SmsCostRecorder
{
    public function __construct(private array $settings) {}

    /** Called inside the same transaction as the message reservation. */
    public function reserve(\PDO $pdo, string $requestId, string $storeId, string $kind, int $now): void
    {
        if (!($this->settings['enabled'] ?? false)) { return; }
        if (!$pdo->inTransaction() || !in_array($kind, ['notification','otp'], true)) { throw new \LogicException('Invalid cost reservation'); }
        $amount=$this->settings[$kind.'_rials'] ?? null;
        if (!is_int($amount) || $amount < 0 || $amount > 999999) { throw new \InvalidArgumentException('Invalid SMS estimate'); }
        $q=$pdo->prepare('INSERT INTO cloud_sms_cost_log (request_id,store_id,pattern,units,unit_cost,currency,cost_date,created_at) VALUES (?,?,?,1,?,\'IRR\',?,?)');
        $q->execute([$requestId,$storeId,$kind,(string)$amount,gmdate('Y-m-d',$now),$now]);
    }
}

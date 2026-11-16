<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Service;

use Kandoo\Cloud\Store\StoreGateway;

/** Authenticated administrative state transitions. The gateway persists the audit atomically. */
final class AdminManager
{
    public function __construct(private array $config, private StoreGateway $gateway) {}

    public function transition(bool $enabled, string $password, string $at): bool
    {
        $hash = $this->config['admin_password_hash'] ?? null;
        if (!is_string($hash) || $password === '' || strlen($password) > 4096 || !password_verify($password, $hash)) {
            return false;
        }
        $this->gateway->setAdminEnabled($enabled, $at);
        return true;
    }
}

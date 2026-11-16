<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Customer;

interface OtpTransport
{
    /** Return true only when the provider accepts delivery. Never log the code. */
    public function sendCode(string $phone, string $code): bool;
}

<?php

declare(strict_types=1);

namespace Kandoo\Cloud\Sms;

use Kandoo\Cloud\Customer\OtpTransport;

/** Faraz pattern transport. Dry runs only capture request bodies; they never use the network. */
final class FarazAdapter implements OtpTransport
{
    private array $dryRequests = [];
    private ?string $lastReceipt = null;

    /** @param array{dry_run:bool,api_key:string,line_number:string,announcement_pattern:string,otp_pattern:string} $settings */
    public function __construct(private array $settings) {}

    public function sendCode(string $phone, string $code): bool
    {
        if (preg_match('/^[0-9]+$/D', $code) !== 1) { return false; }
        return $this->send($phone, $this->settings['otp_pattern'], ['code' => $code]);
    }

    public function sendAnnouncement(string $phone): bool
    {
        return $this->send($phone, $this->settings['announcement_pattern'], []);
    }

    /** @return list<array<string,mixed>> Only populated in dry mode. */
    public function dryRequests(): array { return $this->dryRequests; }

    public function lastReceipt(): ?string { return $this->lastReceipt; }

    private function send(string $phone, string $pattern, array $attributes): bool
    {
        $this->lastReceipt = null;
        if (preg_match('/^\+989[0-9]{9}$/D', $phone) !== 1 || $pattern === '' || $this->settings['line_number'] === '') { return false; }
        $payload = ['code' => $pattern, 'attributes' => (object) $attributes,
            'recipient' => '0'.substr($phone, 3), 'line_number' => $this->settings['line_number'], 'number_format' => 'english'];
        if ($this->settings['dry_run']) { $this->dryRequests[] = $payload; $this->lastReceipt = 'TEST-DRY-RECEIPT'; return true; }
        // CI explicitly prohibits real SMS, regardless of configuration.
        if (getenv('KANDOO_BLOCK_REAL_SMS') === '1') { return false; }
        if ($this->settings['api_key'] === '' || strpbrk($this->settings['api_key'], "\r\n") !== false) { return false; }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) { return false; }
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Accept: application/json\r\nContent-Type: application/json\r\nApi-Key: " . $this->settings['api_key'] . "\r\n",
            'content' => $body, 'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        // Suppress provider/network warnings: they may contain request details.
        $response = @file_get_contents('https://api.iranpayamak.com/ws/v1/sms/pattern', false, $context);
        if (!is_string($response) || !isset($http_response_header[0]) || preg_match('#^HTTP/\S+ 201(?:\s|$)#', $http_response_header[0]) !== 1) { return false; }
        $json = json_decode($response, true);
        if (!is_array($json) || ($json['status'] ?? null) !== 'success') { return false; }
        $receipt = is_array($json['data'] ?? null) ? ($json['data']['id'] ?? null) : ($json['data'] ?? null);
        if ((!is_string($receipt) && !is_int($receipt)) || (string) $receipt === '' || strlen((string) $receipt) > 128) { return false; }
        $this->lastReceipt = (string) $receipt;
        return true;
    }
}

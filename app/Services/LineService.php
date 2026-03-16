<?php
declare(strict_types=1);

namespace FujiraManager\Services;

final class LineService
{
    public function __construct(private array $config) {}

    public function validateSignature(string $body, string $signature): bool
    {
        $secret = $this->config['line']['channel_secret'] ?? '';
        if ($secret === '' || $signature === '') {
            return false;
        }

        $hash = base64_encode(hash_hmac('sha256', $body, $secret, true));
        return hash_equals($hash, $signature);
    }
}

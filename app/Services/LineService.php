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

    public function pushMessage(string $lineUserId, string $message, ?array $quickReply = null): void
    {
        $accessToken = trim((string)($this->config['line']['channel_access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('LINE access token is empty');
        }

        $msgObj = ['type' => 'text', 'text' => $message];
        if ($quickReply !== null) {
            $msgObj['quickReply'] = $quickReply;
        }

        $payload = [
            'to'       => $lineUserId,
            'messages' => [$msgObj],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type: application/json; charset=UTF-8',
            'Authorization: Bearer ' . $accessToken,
            'Content-Length: ' . strlen($json),
        ];

        $ch = curl_init('https://api.line.me/v2/bot/message/push');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '' || $httpCode >= 400) {
            throw new \RuntimeException(sprintf(
                'LINE push failed: http=%d curl=%s response=%s',
                $httpCode, $curlError, (string)$response
            ));
        }
    }
}

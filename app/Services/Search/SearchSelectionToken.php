<?php

namespace App\Services\Search;

use RuntimeException;

class SearchSelectionToken
{
    private const TTL_MINUTES = 15;

    public function issue(int $recordId): string
    {
        $claims = json_encode([
            'record_id' => $recordId,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->timestamp,
            'nonce' => bin2hex(random_bytes(16)),
        ], JSON_THROW_ON_ERROR);

        $payload = rtrim(strtr(base64_encode($claims), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, $this->signingKey());
    }

    /**
     * @return array{record_id: int, expires_at: int}|null
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$payload, $signature] = $parts;
        $expectedSignature = hash_hmac('sha256', $payload, $this->signingKey());

        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $json = base64_decode(strtr($payload, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        $claims = json_decode($json, true);

        if (
            ! is_array($claims)
            || ! is_int($claims['record_id'] ?? null)
            || ! is_int($claims['expires_at'] ?? null)
            || ! is_string($claims['nonce'] ?? null)
            || ! preg_match('/\A[a-f0-9]{32}\z/', $claims['nonce'])
            || $claims['expires_at'] < now()->timestamp
        ) {
            return null;
        }

        return [
            'record_id' => $claims['record_id'],
            'expires_at' => $claims['expires_at'],
        ];
    }

    private function signingKey(): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY must be configured to sign search selections.');
        }

        return $key;
    }
}

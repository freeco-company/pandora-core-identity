<?php

namespace App\Services\Webhook;

/**
 * HMAC-SHA256 webhook 簽章。
 *
 * 簽章基底：{timestamp}.{event_id}.{body}
 * 把 timestamp 與 event_id 也綁進簽章，避免：
 *   - replay：同樣 body 不同 timestamp 簽章不同（receiver 過 5min 拒絕）
 *   - 換 body：tamper body 即簽章不對
 *
 * Receiver 端用同樣 secret 重算比較（hash_equals 防 timing attack）。
 */
class WebhookSigner
{
    public function sign(string $secret, string $timestamp, string $eventId, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$eventId}.{$body}", $secret);
    }

    /**
     * @return array<string, string>
     */
    public function headers(string $secret, string $eventId, string $body): array
    {
        $timestamp = (string) time();

        return [
            'Content-Type' => 'application/json',
            'X-Pandora-Event-Id' => $eventId,
            'X-Pandora-Timestamp' => $timestamp,
            'X-Pandora-Signature' => $this->sign($secret, $timestamp, $eventId, $body),
        ];
    }
}

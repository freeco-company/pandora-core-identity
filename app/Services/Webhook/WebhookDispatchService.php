<?php

namespace App\Services\Webhook;

use App\Models\OutboxEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * 把 outbox 裡 pending 的 row POST 出去。由 identity:dispatch-pending command
 * 排程呼叫，每分鐘一次（schedule 在 bootstrap/app.php）。
 *
 * 失敗策略（ADR-007 §2.4）：
 *   - 5xx / connection error → 視為 transient，按 retry_backoff_seconds 排程重試
 *   - 4xx → 直接 dead_letter（contract 錯誤、簽章錯誤、receiver 拒絕）
 *   - 達 max_retries 也 dead_letter
 *   - 整體 publisher kill switch：identity.publisher_enabled=false 時整批 skip
 *   - Consumer config 缺失（url/secret null）→ skip 該 row、不增 retry（misconfigured）
 */
class WebhookDispatchService
{
    public function __construct(private WebhookSigner $signer) {}

    /**
     * 撈所有應該送的 row 並嘗試送出。
     *
     * 撈條件：status=pending && (next_retry_at IS NULL OR next_retry_at <= now)
     *
     * @return array{attempted: int, sent: int, failed: int, dead_letter: int, skipped: int}
     */
    public function dispatchPending(int $batchSize = 100): array
    {
        $stats = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'dead_letter' => 0, 'skipped' => 0];

        if (! Config::get('identity.publisher_enabled')) {
            return $stats;
        }

        OutboxEvent::query()
            ->where('status', OutboxEvent::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($batchSize)
            ->get()
            ->each(function (OutboxEvent $event) use (&$stats) {
                $stats['attempted']++;
                $result = $this->attemptSend($event);
                $stats[$result] = ($stats[$result] ?? 0) + 1;
            });

        return $stats;
    }

    /**
     * 送單一 row。回傳 'sent' / 'failed' / 'dead_letter' / 'skipped'。
     */
    public function attemptSend(OutboxEvent $event): string
    {
        $consumer = Config::get("identity.consumers.{$event->consumer}");
        if (! is_array($consumer) || empty($consumer['url']) || empty($consumer['secret'])) {
            // misconfigured：不增 retry，等人類修 config
            return 'skipped';
        }

        $body = $this->encodeBody($event);
        $headers = $this->signer->headers((string) $consumer['secret'], $event->event_id, $body);
        $timeout = (int) Config::get('identity.http_timeout', 10);

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->withBody($body, 'application/json')
                ->post((string) $consumer['url']);

            if ($response->successful()) {
                $event->status = OutboxEvent::STATUS_SENT;
                $event->sent_at = now();
                $event->last_error = null;
                $event->next_retry_at = null;
                $event->save();

                return 'sent';
            }

            // 4xx → dead_letter（contract / 簽章 / 拒絕）
            if ($response->clientError()) {
                return $this->markDeadLetter(
                    $event,
                    "HTTP {$response->status()}: ".substr($response->body(), 0, 500)
                );
            }

            // 5xx → retry
            return $this->scheduleRetry(
                $event,
                "HTTP {$response->status()}: ".substr($response->body(), 0, 500)
            );
        } catch (ConnectionException $e) {
            return $this->scheduleRetry($event, 'connection: '.$e->getMessage());
        } catch (RequestException $e) {
            // request-level error（DNS、TLS）也視為 transient
            return $this->scheduleRetry($event, 'request: '.$e->getMessage());
        } catch (Throwable $e) {
            Log::error('outbox dispatch unexpected error', [
                'event_id' => $event->event_id,
                'consumer' => $event->consumer,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->scheduleRetry($event, 'unexpected: '.$e->getMessage());
        }
    }

    private function scheduleRetry(OutboxEvent $event, string $error): string
    {
        $maxRetries = (int) Config::get('identity.max_retries', 5);
        /** @var array<int, int> $backoff */
        $backoff = Config::get('identity.retry_backoff_seconds', [60, 300, 900, 3600, 21600]);

        $event->retry_count++;
        $event->last_error = $error;

        if ($event->retry_count >= $maxRetries) {
            $event->status = OutboxEvent::STATUS_DEAD_LETTER;
            $event->next_retry_at = null;
            $event->save();

            return 'dead_letter';
        }

        $delay = $backoff[$event->retry_count - 1] ?? throw new RuntimeException(
            "retry_backoff_seconds 設定不夠長，retry_count={$event->retry_count}"
        );
        $event->status = OutboxEvent::STATUS_PENDING;
        $event->next_retry_at = now()->addSeconds($delay);
        $event->save();

        return 'failed';
    }

    private function markDeadLetter(OutboxEvent $event, string $error): string
    {
        $event->status = OutboxEvent::STATUS_DEAD_LETTER;
        $event->last_error = $error;
        $event->next_retry_at = null;
        $event->save();

        return 'dead_letter';
    }

    /**
     * 簽章基底 = JSON body（不帶白空格）。Receiver 端要用「原始 body bytes」
     * 重算 HMAC，所以這裡產出後就不能 re-encode。
     */
    private function encodeBody(OutboxEvent $event): string
    {
        $body = json_encode([
            'event_id' => $event->event_id,
            'type' => $event->type,
            'occurred_at' => $event->created_at?->toIso8601String(),
            'data' => $event->payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new RuntimeException("Failed to JSON-encode outbox payload (event_id={$event->event_id})");
        }

        return $body;
    }
}

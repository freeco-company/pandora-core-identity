<?php

namespace App\Services\Webhook;

use App\Models\GroupUser;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Config;

/**
 * 寫 outbox 行為的入口。
 *
 * 由 Observer 呼叫。每個「active consumer」（enabled=true + url + secret）
 * 各寫一筆 pending row。HTTP POST 由 DispatchOutboxJob / scheduled command
 * 之後撈 pending row 才執行 — 這是 transactional outbox pattern，
 * 確保 GroupUser DB 變動與 outbox 寫入在同一個 transaction 內 atomic。
 */
class OutboxEventDispatcher
{
    public function __construct(private PayloadBuilder $builder) {}

    /**
     * @return list<OutboxEvent> 為每個 consumer 寫入的 row（測試 / debug 用）
     */
    public function publishUserUpserted(GroupUser $user): array
    {
        return $this->publish($user, OutboxEvent::TYPE_USER_UPSERTED);
    }

    /**
     * @return list<OutboxEvent>
     */
    public function publish(GroupUser $user, string $type): array
    {
        $consumers = $this->activeConsumers();
        if ($consumers === []) {
            return [];
        }

        $payload = $this->builder->build($user);
        $rows = [];

        foreach ($consumers as $consumerKey) {
            $rows[] = OutboxEvent::create([
                'consumer' => $consumerKey,
                'type' => $type,
                'payload' => $payload,
                'status' => OutboxEvent::STATUS_PENDING,
                'next_retry_at' => now(),
            ]);
        }

        return $rows;
    }

    /**
     * 「active」= enabled=true 且 url + secret 都有值。
     * 任一缺失即 skip — 避免半成品 config 灌一堆 dead_letter。
     *
     * @return list<string>
     */
    private function activeConsumers(): array
    {
        /** @var array<string, array{enabled?: bool, url?: ?string, secret?: ?string}> $registry */
        $registry = Config::get('identity.consumers', []);
        $active = [];
        foreach ($registry as $key => $cfg) {
            if (! ($cfg['enabled'] ?? false)) {
                continue;
            }
            if (empty($cfg['url']) || empty($cfg['secret'])) {
                continue;
            }
            $active[] = $key;
        }

        return $active;
    }
}

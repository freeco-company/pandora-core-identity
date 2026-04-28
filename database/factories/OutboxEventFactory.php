<?php

namespace Database\Factories;

use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Symfony\Component\Uid\Uuid;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    protected $model = OutboxEvent::class;

    public function definition(): array
    {
        return [
            'event_id' => (string) Uuid::v7(),
            'consumer' => 'pandora_js_store',
            'type' => OutboxEvent::TYPE_USER_UPSERTED,
            'payload' => [
                'uuid' => (string) Uuid::v7(),
                'email_canonical' => fake()->unique()->safeEmail(),
                'display_name' => fake()->name(),
                'status' => 'active',
                'identities' => [],
            ],
            'status' => OutboxEvent::STATUS_PENDING,
            'retry_count' => 0,
            'next_retry_at' => now(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => OutboxEvent::STATUS_SENT,
            'sent_at' => now(),
            'next_retry_at' => null,
        ]);
    }

    public function deadLetter(): static
    {
        return $this->state(fn () => [
            'status' => OutboxEvent::STATUS_DEAD_LETTER,
            'retry_count' => 5,
            'last_error' => 'simulated dead letter',
            'next_retry_at' => null,
        ]);
    }

    public function consumer(string $consumer): static
    {
        return $this->state(fn () => ['consumer' => $consumer]);
    }
}

<?php

namespace App\Models;

use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Symfony\Component\Uid\Uuid;

/**
 * @property int $id
 * @property string $event_id UUID v7
 * @property string $consumer
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $status pending / sent / failed / dead_letter
 * @property int $retry_count
 * @property ?Carbon $next_retry_at
 * @property ?string $last_error
 * @property ?Carbon $sent_at
 *
 * @method static OutboxEventFactory factory($count = null, $state = [])
 */
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory;

    protected $table = 'platform_outbox_events';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const TYPE_USER_UPSERTED = 'user.upserted';

    public const TYPE_USER_SUSPENDED = 'user.suspended';

    public const TYPE_USER_MERGED = 'user.merged';

    protected $fillable = [
        'event_id',
        'consumer',
        'type',
        'payload',
        'status',
        'retry_count',
        'next_retry_at',
        'last_error',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'next_retry_at' => 'datetime',
        'sent_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (OutboxEvent $event) {
            if (empty($event->event_id)) {
                $event->event_id = (string) Uuid::v7();
            }
        });
    }
}

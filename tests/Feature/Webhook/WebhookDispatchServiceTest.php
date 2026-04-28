<?php

namespace Tests\Feature\Webhook;

use App\Models\OutboxEvent;
use App\Services\Webhook\WebhookDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('identity.publisher_enabled', true);
        config()->set('identity.consumers', [
            'pandora_js_store' => [
                'enabled' => true,
                'url' => 'http://js-store.test/webhook',
                'secret' => 'js-secret',
            ],
        ]);
    }

    public function test_kill_switch_skips_all_dispatch(): void
    {
        config()->set('identity.publisher_enabled', false);
        OutboxEvent::factory()->count(3)->create();
        Http::fake();

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(0, $stats['attempted']);
        Http::assertNothingSent();
        $this->assertSame(3, OutboxEvent::where('status', OutboxEvent::STATUS_PENDING)->count());
    }

    public function test_2xx_marks_sent_with_signed_headers(): void
    {
        $event = OutboxEvent::factory()->create();

        Http::fake(['js-store.test/*' => Http::response('', 200)]);

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(1, $stats['sent']);
        $event->refresh();
        $this->assertSame(OutboxEvent::STATUS_SENT, $event->status);
        $this->assertNotNull($event->sent_at);
        $this->assertNull($event->next_retry_at);

        Http::assertSent(function ($request) use ($event) {
            $this->assertSame($event->event_id, $request->header('X-Pandora-Event-Id')[0]);
            $this->assertNotEmpty($request->header('X-Pandora-Signature')[0]);
            $this->assertNotEmpty($request->header('X-Pandora-Timestamp')[0]);

            // 重算 HMAC 驗章
            $ts = $request->header('X-Pandora-Timestamp')[0];
            $expected = hash_hmac('sha256', "{$ts}.{$event->event_id}.{$request->body()}", 'js-secret');
            $this->assertSame($expected, $request->header('X-Pandora-Signature')[0]);

            return true;
        });
    }

    public function test_5xx_schedules_retry_with_backoff(): void
    {
        $event = OutboxEvent::factory()->create();

        Http::fake(['js-store.test/*' => Http::response('boom', 503)]);

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(1, $stats['failed']);
        $event->refresh();
        $this->assertSame(OutboxEvent::STATUS_PENDING, $event->status);
        $this->assertSame(1, $event->retry_count);
        $this->assertNotNull($event->next_retry_at);
        $this->assertTrue($event->next_retry_at->greaterThan(now()->addSeconds(30)));
        $this->assertStringContainsString('503', (string) $event->last_error);
    }

    public function test_4xx_marks_dead_letter_immediately(): void
    {
        $event = OutboxEvent::factory()->create();

        Http::fake(['js-store.test/*' => Http::response('bad', 400)]);

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(1, $stats['dead_letter']);
        $event->refresh();
        $this->assertSame(OutboxEvent::STATUS_DEAD_LETTER, $event->status);
        $this->assertSame(0, $event->retry_count);  // dead_letter 不算 retry
        $this->assertStringContainsString('400', (string) $event->last_error);
    }

    public function test_max_retries_marks_dead_letter(): void
    {
        $event = OutboxEvent::factory()->create(['retry_count' => 4]);  // 下一次失敗即達 5

        Http::fake(['js-store.test/*' => Http::response('boom', 503)]);

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(1, $stats['dead_letter']);
        $event->refresh();
        $this->assertSame(OutboxEvent::STATUS_DEAD_LETTER, $event->status);
        $this->assertSame(5, $event->retry_count);
    }

    public function test_connection_exception_is_treated_as_transient(): void
    {
        $event = OutboxEvent::factory()->create();

        Http::fake(function () {
            throw new ConnectionException('dns failed');
        });

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(1, $stats['failed']);
        $event->refresh();
        $this->assertSame(OutboxEvent::STATUS_PENDING, $event->status);
        $this->assertSame(1, $event->retry_count);
        $this->assertStringContainsString('connection', (string) $event->last_error);
    }

    public function test_misconfigured_consumer_skipped_without_retry_increment(): void
    {
        config()->set('identity.consumers.pandora_js_store.url', null);
        $event = OutboxEvent::factory()->create();
        Http::fake();

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(1, $stats['skipped']);
        Http::assertNothingSent();
        $event->refresh();
        $this->assertSame(0, $event->retry_count);
        $this->assertSame(OutboxEvent::STATUS_PENDING, $event->status);
    }

    public function test_sent_rows_are_not_redispatched(): void
    {
        OutboxEvent::factory()->sent()->count(2)->create();
        Http::fake();

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(0, $stats['attempted']);
        Http::assertNothingSent();
    }

    public function test_dead_letter_rows_are_not_redispatched(): void
    {
        OutboxEvent::factory()->deadLetter()->count(2)->create();
        Http::fake();

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(0, $stats['attempted']);
        Http::assertNothingSent();
    }

    public function test_future_next_retry_at_is_not_dispatched_yet(): void
    {
        OutboxEvent::factory()->create([
            'next_retry_at' => now()->addMinutes(10),
        ]);
        Http::fake();

        $stats = app(WebhookDispatchService::class)->dispatchPending();

        $this->assertSame(0, $stats['attempted']);
        Http::assertNothingSent();
    }

    public function test_command_runs_successfully(): void
    {
        Http::fake();

        $this->artisan('identity:dispatch-pending')
            ->expectsOutputToContain('attempted=0')
            ->assertSuccessful();
    }

    public function test_event_id_is_uuid_v7(): void
    {
        $event = OutboxEvent::factory()->create();
        // UUID v7：第三段第一個字應為 '7'
        $segments = explode('-', $event->event_id);
        $this->assertSame(5, count($segments));
        $this->assertSame('7', substr($segments[2], 0, 1));
    }
}

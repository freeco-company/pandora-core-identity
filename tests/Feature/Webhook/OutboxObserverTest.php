<?php

namespace Tests\Feature\Webhook;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboxObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 兩個 active consumer 用於驗證「每個 consumer 各寫一筆」
        config()->set('identity.consumers', [
            'pandora_js_store' => [
                'enabled' => true,
                'url' => 'http://js-store.test/webhook',
                'secret' => 'js-secret',
            ],
            'dodo' => [
                'enabled' => true,
                'url' => 'http://dodo.test/webhook',
                'secret' => 'dodo-secret',
            ],
        ]);
    }

    public function test_creating_group_user_writes_one_outbox_row_per_active_consumer(): void
    {
        GroupUser::factory()->create();

        $this->assertSame(2, OutboxEvent::count());
        $consumers = OutboxEvent::pluck('consumer')->sort()->values()->all();
        $this->assertSame(['dodo', 'pandora_js_store'], $consumers);

        $row = OutboxEvent::first();
        $this->assertSame(OutboxEvent::TYPE_USER_UPSERTED, $row->type);
        $this->assertSame(OutboxEvent::STATUS_PENDING, $row->status);
        $this->assertNotEmpty($row->event_id);
        $this->assertSame(0, $row->retry_count);
    }

    public function test_updating_group_user_emits_new_outbox_row(): void
    {
        $user = GroupUser::factory()->create();
        OutboxEvent::query()->delete();

        $user->display_name = 'changed';
        $user->save();

        $this->assertSame(2, OutboxEvent::count());
    }

    public function test_creating_identity_emits_outbox_for_owning_user(): void
    {
        $user = GroupUser::factory()->create();
        OutboxEvent::query()->delete();

        GroupUserIdentity::create([
            'group_user_id' => $user->id,
            'type' => GroupUserIdentity::TYPE_GOOGLE,
            'value' => 'google-sub-12345',
            'is_primary' => false,
        ]);

        $this->assertSame(2, OutboxEvent::count());
        $first = OutboxEvent::first();
        $this->assertSame($user->id, $first->payload['uuid']);
    }

    public function test_disabled_consumer_is_skipped(): void
    {
        config()->set('identity.consumers.dodo.enabled', false);

        GroupUser::factory()->create();

        $this->assertSame(1, OutboxEvent::count());
        $this->assertSame('pandora_js_store', OutboxEvent::first()->consumer);
    }

    public function test_consumer_missing_secret_is_skipped_to_avoid_dead_letters(): void
    {
        config()->set('identity.consumers.dodo.secret', null);

        GroupUser::factory()->create();

        $this->assertSame(1, OutboxEvent::count());
        $this->assertSame('pandora_js_store', OutboxEvent::first()->consumer);
    }

    public function test_no_active_consumers_no_rows(): void
    {
        config()->set('identity.consumers', []);

        GroupUser::factory()->create();

        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_payload_contains_identities(): void
    {
        $user = GroupUser::factory()->create();
        GroupUserIdentity::create([
            'group_user_id' => $user->id,
            'type' => GroupUserIdentity::TYPE_EMAIL,
            'value' => $user->email_canonical,
            'is_primary' => true,
        ]);

        $latest = OutboxEvent::latest('id')->first();
        $this->assertNotNull($latest);
        $this->assertCount(1, $latest->payload['identities']);
        $this->assertSame('email', $latest->payload['identities'][0]['type']);
    }
}

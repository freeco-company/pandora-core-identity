<?php

namespace Tests\Feature\Auth;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;
use App\Services\Auth\OAuthLoginResult;
use App\Services\Auth\OAuthLoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OAuthLoginServiceTest extends TestCase
{
    use RefreshDatabase;

    private OAuthLoginService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OAuthLoginService::class);
    }

    public function test_existing_identity_logs_in_directly(): void
    {
        $user = GroupUser::factory()->create();
        GroupUserIdentity::factory()->google('g-12345')->create([
            'group_user_id' => $user->id,
        ]);

        $result = $this->service->loginOrCreate(
            providerType: GroupUserIdentity::TYPE_GOOGLE,
            providerUserId: 'g-12345',
            email: 'foo@bar.com',
        );

        $this->assertSame(OAuthLoginResult::EXISTING, $result->status);
        $this->assertSame($user->id, $result->user->id);
        $this->assertNotNull($result->user->fresh()->last_login_at);
    }

    public function test_new_provider_with_unused_email_creates_user(): void
    {
        $result = $this->service->loginOrCreate(
            providerType: GroupUserIdentity::TYPE_LINE,
            providerUserId: 'U_xyz789',
            email: 'newuser@example.com',
            displayName: '新仙女',
        );

        $this->assertSame(OAuthLoginResult::CREATED, $result->status);
        $this->assertSame('newuser@example.com', $result->user->email_canonical);
        $this->assertSame('新仙女', $result->user->display_name);

        // Both LINE and email identity should be created
        $this->assertSame(2, $result->user->identities()->count());
        $line = $result->user->identities()->where('type', 'line')->first();
        $this->assertNotNull($line);
        $this->assertNotNull($line->verified_at);
    }

    public function test_email_collision_returns_merge_suggestion_not_auto_merge(): void
    {
        // 既有 email 用戶（婕樂纖過來的）
        $existing = GroupUser::factory()->create([
            'email_canonical' => 'collide@example.com',
        ]);
        GroupUserIdentity::factory()->create([
            'group_user_id' => $existing->id,
            'type' => GroupUserIdentity::TYPE_EMAIL,
            'value' => 'collide@example.com',
        ]);

        // 新的 google login，email 撞到
        $result = $this->service->loginOrCreate(
            providerType: GroupUserIdentity::TYPE_GOOGLE,
            providerUserId: 'g-conflict',
            email: 'collide@example.com',
        );

        $this->assertSame(OAuthLoginResult::MERGE_SUGGESTED, $result->status);
        $this->assertSame($existing->id, $result->user->id);
        $this->assertSame('google', $result->pendingIdentity['provider']);
        $this->assertSame('g-conflict', $result->pendingIdentity['provider_user_id']);

        // 沒有自動建 google identity
        $this->assertSame(0, GroupUserIdentity::where('type', 'google')->count());

        // shouldIssueTokens() = false
        $this->assertFalse($result->shouldIssueTokens());
    }

    public function test_provider_without_email_creates_anonymous_user(): void
    {
        // Apple Sign In 不一定有 email（user 第二次登入 Apple 不返 email）
        $result = $this->service->loginOrCreate(
            providerType: GroupUserIdentity::TYPE_APPLE,
            providerUserId: 'apple-001.xxx',
            email: null,
        );

        $this->assertSame(OAuthLoginResult::CREATED, $result->status);
        $this->assertNull($result->user->email_canonical);
        $this->assertSame(1, $result->user->identities()->count());
    }

    public function test_repeat_login_updates_raw_payload(): void
    {
        $user = GroupUser::factory()->create();
        GroupUserIdentity::factory()->google('g-repeat')->create([
            'group_user_id' => $user->id,
            'raw_payload' => ['old' => true],
        ]);

        $this->service->loginOrCreate(
            providerType: GroupUserIdentity::TYPE_GOOGLE,
            providerUserId: 'g-repeat',
            rawPayload: ['new' => true, 'token' => 'rotated'],
        );

        $iden = GroupUserIdentity::where('value', 'g-repeat')->first();
        $this->assertSame(['new' => true, 'token' => 'rotated'], $iden->raw_payload);
    }
}

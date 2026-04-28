<?php

namespace Tests\Feature\Auth;

use App\Models\GroupUser;
use App\Models\RefreshToken;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private RefreshTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RefreshTokenService::class);
    }

    public function test_issue_creates_active_token_with_family(): void
    {
        $user = GroupUser::factory()->create();
        $issued = $this->service->issue($user, 'fp');

        $this->assertNotEmpty($issued->plain);
        $this->assertSame(64, strlen($issued->plain));
        $this->assertNotEmpty($issued->record->family_id);
        $this->assertTrue($issued->record->isActive());
    }

    public function test_rotate_invalidates_old_and_returns_new(): void
    {
        $user = GroupUser::factory()->create();
        $first = $this->service->issue($user, 'fp');

        $second = $this->service->rotate($first->plain, 'fp');

        $this->assertNotSame($first->plain, $second->plain);
        $this->assertSame($first->record->family_id, $second->record->family_id);

        $first->record->refresh();
        $this->assertNotNull($first->record->used_at);
        $this->assertNotNull($first->record->revoked_at);
        $this->assertSame(RefreshToken::REASON_ROTATED, $first->record->revoked_reason);
        $this->assertSame($second->record->id, $first->record->replaced_by_id);
    }

    public function test_reuse_detection_revokes_entire_family(): void
    {
        $user = GroupUser::factory()->create();
        $first = $this->service->issue($user, 'fp');

        // 正常 rotate 一次：first → second
        $second = $this->service->rotate($first->plain, 'fp');

        // 攻擊者再用 first（已 used）→ family revoked
        try {
            $this->service->rotate($first->plain, 'fp');
            $this->fail('Reuse should have triggered exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('reuse detected', strtolower($e->getMessage()));
        }

        // 連 second（合法但 family 被毒了）也不能用
        $second->record->refresh();
        $this->assertNotNull($second->record->revoked_at);
        $this->assertSame(RefreshToken::REASON_REUSE_DETECTED, $second->record->revoked_reason);
    }

    public function test_cross_product_refresh_rejected(): void
    {
        $user = GroupUser::factory()->create();
        $token = $this->service->issue($user, 'fp');

        $this->expectException(\RuntimeException::class);
        $this->service->rotate($token->plain, 'dodo');
    }

    public function test_expired_refresh_rejected(): void
    {
        $user = GroupUser::factory()->create();
        $token = $this->service->issue($user, 'fp');

        // 直接強制過期
        $token->record->expires_at = now()->subDay();
        $token->record->save();

        $this->expectException(\RuntimeException::class);
        $this->service->rotate($token->plain, 'fp');
    }

    public function test_explicit_logout_revoke(): void
    {
        $user = GroupUser::factory()->create();
        $token = $this->service->issue($user, 'fp');

        $ok = $this->service->revoke($token->plain);
        $this->assertTrue($ok);

        $token->record->refresh();
        $this->assertNotNull($token->record->revoked_at);
        $this->assertSame(RefreshToken::REASON_LOGOUT, $token->record->revoked_reason);
    }

    public function test_revoke_family_kills_all_active_tokens(): void
    {
        $user = GroupUser::factory()->create();
        $a = $this->service->issue($user, 'fp');
        $b = $this->service->issue($user, 'fp', $a->record->family_id);

        $count = $this->service->revokeFamily($a->record->family_id);

        $this->assertSame(2, $count);
        $a->record->refresh();
        $b->record->refresh();
        $this->assertNotNull($a->record->revoked_at);
        $this->assertNotNull($b->record->revoked_at);
    }

    public function test_token_stored_only_as_hash(): void
    {
        $user = GroupUser::factory()->create();
        $issued = $this->service->issue($user, 'fp');

        // DB 裡找不到明文
        $this->assertSame(0, RefreshToken::where('token_hash', $issued->plain)->count());
        // 但 hash 對得上
        $hash = hash('sha256', $issued->plain);
        $this->assertSame(1, RefreshToken::where('token_hash', $hash)->count());
    }
}

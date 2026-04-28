<?php

namespace Tests\Feature\Auth;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;
use App\Services\Auth\EmailAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class AuthEndpointsExtraTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_register_endpoint(): void
    {
        $res = $this->postJson('/api/v1/auth/email/register', [
            'email' => 'register@example.com',
            'password' => 'password123',
            'display_name' => '小仙女',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('status', 'pending_verification');
        $this->assertNotNull($res->json('user_id'));
        $this->assertNotNull($res->json('verification_token_hint')); // dev/testing only
    }

    public function test_email_register_validates_password_length(): void
    {
        $res = $this->postJson('/api/v1/auth/email/register', [
            'email' => 'short@example.com',
            'password' => 'abc',
        ]);
        $res->assertStatus(422);
    }

    public function test_email_login_full_flow(): void
    {
        // arrange
        $svc = app(EmailAuthService::class);
        $user = $svc->register('flow@example.com', 'mypassword');
        $svc->verifyEmail($user->email_verification_token);

        // act
        $res = $this->postJson('/api/v1/auth/email/login', [
            'email' => 'flow@example.com',
            'password' => 'mypassword',
            'product_code' => 'fp',
        ]);

        $res->assertOk()->assertJsonStructure([
            'access_token', 'refresh_token', 'token_type', 'expires_in',
            'user' => ['id', 'email_canonical'],
        ]);
    }

    public function test_email_verify_endpoint(): void
    {
        $svc = app(EmailAuthService::class);
        $user = $svc->register('verify-ep@example.com', 'mypassword');

        $res = $this->postJson('/api/v1/auth/email/verify', [
            'token' => $user->email_verification_token,
        ]);
        $res->assertOk()->assertJsonPath('status', 'verified');
    }

    public function test_oauth_callback_creates_user_and_issues_tokens(): void
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn('g-12345');
        $socialUser->shouldReceive('getEmail')->andReturn('oauth@example.com');
        $socialUser->shouldReceive('getName')->andReturn('OAuth Test');
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->token = 'fake-token';
        $socialUser->refreshToken = null;
        $socialUser->expiresIn = 3600;

        Socialite::shouldReceive('driver->stateless->user')->andReturn($socialUser);

        $res = $this->getJson('/api/v1/auth/oauth/google/callback?product_code=fp');

        $res->assertOk()
            ->assertJsonPath('status', 'created')
            ->assertJsonStructure(['access_token', 'refresh_token', 'user' => ['id']]);

        $this->assertSame(1, GroupUser::where('email_canonical', 'oauth@example.com')->count());
        $this->assertSame(1, GroupUserIdentity::where('type', 'google')->where('value', 'g-12345')->count());
    }

    public function test_oauth_callback_email_collision_returns_409_merge_suggested(): void
    {
        $existing = GroupUser::factory()->create(['email_canonical' => 'collide@example.com']);
        GroupUserIdentity::factory()->create([
            'group_user_id' => $existing->id,
            'type' => 'email',
            'value' => 'collide@example.com',
        ]);

        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getId')->andReturn('g-conflict');
        $socialUser->shouldReceive('getEmail')->andReturn('collide@example.com');
        $socialUser->shouldReceive('getName')->andReturn('Conflict');
        $socialUser->shouldReceive('getAvatar')->andReturn(null);
        $socialUser->token = 'x';
        $socialUser->refreshToken = null;
        $socialUser->expiresIn = 3600;

        Socialite::shouldReceive('driver->stateless->user')->andReturn($socialUser);

        $res = $this->getJson('/api/v1/auth/oauth/google/callback?product_code=fp');
        $res->assertStatus(409)
            ->assertJsonPath('status', 'merge_suggested');
    }

    public function test_oauth_redirect_unknown_provider(): void
    {
        $res = $this->getJson('/api/v1/auth/oauth/twitter/redirect');
        $res->assertStatus(404);
    }

    public function test_internal_mirror_endpoint_requires_secret(): void
    {
        $res = $this->postJson('/api/internal/mirror/customer-upsert', [
            'fp_customer_id' => 1,
        ]);
        $res->assertStatus(401);
    }

    public function test_internal_mirror_creates_user_when_new(): void
    {
        config(['pandora_jwt.internal_secret' => 'test-secret']);

        $res = $this->postJson('/api/internal/mirror/customer-upsert', [
            'fp_customer_id' => 100,
            'email_canonical' => 'mirrored@example.com',
            'phone_canonical' => '+886900111222',
            'display_name' => '婕樂纖客戶',
            'identities' => [
                ['type' => 'email', 'value' => 'mirrored@example.com', 'is_primary' => true],
                ['type' => 'line', 'value' => 'U_line_user_id', 'is_primary' => false],
            ],
        ], [
            'X-Pandora-Internal-Secret' => 'test-secret',
        ]);

        $res->assertOk()->assertJsonStructure(['group_user_id', 'mirrored_at']);

        $user = GroupUser::find($res->json('group_user_id'));
        $this->assertNotNull($user);
        $this->assertSame('mirrored@example.com', $user->email_canonical);
        $this->assertSame(2, $user->identities()->count());
    }

    public function test_internal_mirror_accepts_null_fp_customer_id_for_cutover_oauth(): void
    {
        // ADR-007 Phase 3 cutover：母艦 OAuth login 走 platform 時還沒有 customer id
        // （PlatformOAuthBridge 送 null fp_customer_id），platform 必須能接受
        config(['pandora_jwt.internal_secret' => 'test-secret']);

        $res = $this->postJson('/api/internal/mirror/customer-upsert', [
            'fp_customer_id' => null,
            'email_canonical' => 'oauth-cutover@example.com',
            'display_name' => 'cutover user',
            'identities' => [
                ['type' => 'google', 'value' => 'g-cutover-1', 'is_primary' => true],
                ['type' => 'email', 'value' => 'oauth-cutover@example.com', 'is_primary' => true],
            ],
        ], [
            'X-Pandora-Internal-Secret' => 'test-secret',
        ]);

        $res->assertOk()->assertJsonStructure(['group_user_id', 'mirrored_at']);
        $this->assertSame(1, GroupUser::where('email_canonical', 'oauth-cutover@example.com')->count());
    }

    public function test_internal_mirror_is_idempotent_with_existing_identity(): void
    {
        config(['pandora_jwt.internal_secret' => 'test-secret']);

        $existing = GroupUser::factory()->create();
        GroupUserIdentity::factory()->create([
            'group_user_id' => $existing->id,
            'type' => 'line',
            'value' => 'U_already',
        ]);

        $res = $this->postJson('/api/internal/mirror/customer-upsert', [
            'fp_customer_id' => 200,
            'email_canonical' => 'merge-target@example.com',
            'identities' => [
                ['type' => 'line', 'value' => 'U_already'],
            ],
        ], [
            'X-Pandora-Internal-Secret' => 'test-secret',
        ]);

        $res->assertOk()->assertJsonPath('group_user_id', $existing->id);
        // 不該建新 user
        $this->assertSame(1, GroupUser::count());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

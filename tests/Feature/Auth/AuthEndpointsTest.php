<?php

namespace Tests\Feature\Auth;

use App\Models\GroupUser;
use App\Services\Auth\JwtIssuer;
use App\Services\Auth\RefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_endpoint_rotates_and_returns_new_pair(): void
    {
        $user = GroupUser::factory()->create();
        $issued = app(RefreshTokenService::class)->issue($user, 'fp');

        $res = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $issued->plain,
            'product_code' => 'fp',
        ]);

        $res->assertOk()->assertJsonStructure([
            'access_token', 'refresh_token', 'token_type', 'expires_in',
        ]);
        $this->assertSame('Bearer', $res->json('token_type'));
        $this->assertNotSame($issued->plain, $res->json('refresh_token'));
    }

    public function test_refresh_with_invalid_token_returns_401(): void
    {
        $res = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => 'totally-bogus',
            'product_code' => 'fp',
        ]);
        $res->assertStatus(401)->assertJsonPath('error', 'refresh_failed');
    }

    public function test_logout_revokes_refresh_token(): void
    {
        $user = GroupUser::factory()->create();
        $issued = app(RefreshTokenService::class)->issue($user, 'fp');

        $res = $this->postJson('/api/v1/auth/logout', [
            'refresh_token' => $issued->plain,
        ]);
        $res->assertOk()->assertJsonPath('revoked', true);
    }

    public function test_public_key_endpoint(): void
    {
        $res = $this->getJson('/api/v1/auth/public-key');
        $res->assertOk()
            ->assertJsonPath('algorithm', 'RS256')
            ->assertJsonPath('issuer', 'pandora-core');
        $this->assertStringContainsString('-----BEGIN PUBLIC KEY-----', (string) $res->json('public_key'));
    }

    public function test_protected_endpoint_requires_jwt(): void
    {
        $res = $this->getJson('/api/v1/users/me/scope-test');
        $res->assertStatus(401)->assertJsonPath('error', 'missing_token');
    }

    public function test_protected_endpoint_with_valid_jwt_succeeds(): void
    {
        $user = GroupUser::factory()->create();
        $jwt = app(JwtIssuer::class)->issueAccessToken($user, 'fp');

        $res = $this->getJson('/api/v1/users/me/scope-test', [
            'Authorization' => "Bearer {$jwt}",
        ]);
        $res->assertOk()->assertJsonPath('group_user_id', $user->id);
    }

    public function test_protected_endpoint_rejects_token_for_wrong_product(): void
    {
        $user = GroupUser::factory()->create();
        $jwt = app(JwtIssuer::class)->issueAccessToken($user, 'dodo');

        // route 要求 'fp'
        $res = $this->getJson('/api/v1/users/me/scope-test', [
            'Authorization' => "Bearer {$jwt}",
        ]);
        $res->assertStatus(401)->assertJsonPath('error', 'invalid_token');
    }

    public function test_suspended_user_cannot_use_token(): void
    {
        $user = GroupUser::factory()->suspended()->create();
        $jwt = app(JwtIssuer::class)->issueAccessToken($user, 'fp');

        $res = $this->getJson('/api/v1/users/me/scope-test', [
            'Authorization' => "Bearer {$jwt}",
        ]);
        $res->assertStatus(403)->assertJsonPath('error', 'user_not_active');
    }
}

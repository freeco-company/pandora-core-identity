<?php

namespace Tests\Feature\Auth;

use App\Models\GroupUser;
use App\Services\Auth\JwtIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JwtIssuerTest extends TestCase
{
    use RefreshDatabase;

    private JwtIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->issuer = app(JwtIssuer::class);
    }

    public function test_issue_and_verify_access_token(): void
    {
        $user = GroupUser::factory()->create();
        $jwt = $this->issuer->issueAccessToken($user, 'fp', ['profile:read']);

        $token = $this->issuer->verify($jwt, 'fp');

        $this->assertSame($user->id, $token->claims()->get('sub'));
        $this->assertSame('fp', $token->claims()->get('product_code'));
        $this->assertSame(['profile:read'], $token->claims()->get('scopes'));
        $this->assertSame('pandora-core', $token->claims()->get('iss'));
    }

    public function test_token_with_wrong_audience_is_rejected(): void
    {
        $user = GroupUser::factory()->create();
        $jwt = $this->issuer->issueAccessToken($user, 'fp');

        $this->expectException(\RuntimeException::class);
        $this->issuer->verify($jwt, 'dodo');
    }

    public function test_disallowed_product_code_throws(): void
    {
        $user = GroupUser::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->issuer->issueAccessToken($user, 'unknown-app');
    }

    public function test_expired_token_is_rejected(): void
    {
        config(['pandora_jwt.access_ttl' => 1]);
        $user = GroupUser::factory()->create();
        $jwt = $this->issuer->issueAccessToken($user, 'fp');

        sleep(2);

        $this->expectException(\RuntimeException::class);
        $this->issuer->verify($jwt, 'fp');
    }

    public function test_tampered_token_is_rejected(): void
    {
        $user = GroupUser::factory()->create();
        $jwt = $this->issuer->issueAccessToken($user, 'fp');

        // 改 payload 一個字元 → 簽章對不上
        $parts = explode('.', $jwt);
        $parts[1] = strrev($parts[1]);
        $tampered = implode('.', $parts);

        $this->expectException(\RuntimeException::class);
        $this->issuer->verify($tampered, 'fp');
    }

    public function test_jti_is_unique(): void
    {
        $user = GroupUser::factory()->create();
        $jwt1 = $this->issuer->issueAccessToken($user, 'fp');
        $jwt2 = $this->issuer->issueAccessToken($user, 'fp');

        $jti1 = $this->issuer->verify($jwt1, 'fp')->claims()->get('jti');
        $jti2 = $this->issuer->verify($jwt2, 'fp')->claims()->get('jti');

        $this->assertNotSame($jti1, $jti2);
    }
}

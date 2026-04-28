<?php

namespace Tests\Feature\Auth;

use App\Models\GroupUser;
use App\Services\Auth\EmailAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private EmailAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EmailAuthService::class);
    }

    public function test_register_creates_pending_user_with_verification_token(): void
    {
        $user = $this->service->register('signup@example.com', 'password123', '註冊測試');

        $this->assertSame('pending_verification', $user->status);
        $this->assertNotNull($user->password);
        $this->assertNotNull($user->email_verification_token);
        $this->assertSame(64, strlen($user->email_verification_token));

        // identity 也建好（unverified）
        $iden = $user->identities()->where('type', 'email')->first();
        $this->assertNotNull($iden);
        $this->assertNull($iden->verified_at);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        GroupUser::factory()->create(['email_canonical' => 'dup@example.com']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Email already registered.');
        $this->service->register('DUP@example.com', 'password123');
    }

    public function test_login_requires_verified_email(): void
    {
        $user = $this->service->register('verify@example.com', 'pw1234567');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Email not verified.');
        $this->service->login('verify@example.com', 'pw1234567');
    }

    public function test_login_rejects_wrong_password(): void
    {
        $user = $this->service->register('login@example.com', 'correct-pass');
        $this->service->verifyEmail($user->email_verification_token);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid credentials.');
        $this->service->login('login@example.com', 'wrong-pass');
    }

    public function test_full_flow_register_verify_login(): void
    {
        $user = $this->service->register('full@example.com', 'mypassword', '小仙女');

        $verified = $this->service->verifyEmail($user->email_verification_token);
        $this->assertNotNull($verified->email_verified_at);
        $this->assertSame('active', $verified->status);
        $this->assertNull($verified->fresh()->email_verification_token);

        $iden = $verified->identities()->where('type', 'email')->first();
        $this->assertNotNull($iden->verified_at);

        $logged = $this->service->login('full@example.com', 'mypassword');
        $this->assertSame($verified->id, $logged->id);
        $this->assertNotNull($logged->last_login_at);
    }

    public function test_login_blocked_when_suspended(): void
    {
        $user = $this->service->register('suspend@example.com', 'pw1234567');
        $this->service->verifyEmail($user->email_verification_token);
        $user->refresh();
        $user->status = 'suspended';
        $user->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Account suspended.');
        $this->service->login('suspend@example.com', 'pw1234567');
    }

    public function test_invalid_verification_token_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->verifyEmail('totally-bogus-token');
    }
}

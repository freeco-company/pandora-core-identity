<?php

namespace Tests\Feature\Models;

use App\Models\GroupUser;
use App\Models\GroupUserConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupUserConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_consent_belongs_to_group_user(): void
    {
        $consent = GroupUserConsent::factory()->create();
        $this->assertInstanceOf(GroupUser::class, $consent->user);
    }

    public function test_active_when_not_revoked(): void
    {
        $active = GroupUserConsent::factory()->create();
        $revoked = GroupUserConsent::factory()->revoked()->create();

        $this->assertTrue($active->isActive());
        $this->assertFalse($revoked->isActive());
    }

    public function test_versioned_history_preserved(): void
    {
        $user = GroupUser::factory()->create();

        // 同一 consent_type 兩個版本同時存在（v1 已撤銷 + v2 仍有效）
        GroupUserConsent::factory()->revoked()->create([
            'group_user_id' => $user->id,
            'consent_type' => GroupUserConsent::TYPE_PRIVACY,
            'version' => '2025-01-01',
        ]);
        GroupUserConsent::factory()->create([
            'group_user_id' => $user->id,
            'consent_type' => GroupUserConsent::TYPE_PRIVACY,
            'version' => '2026-04-01',
        ]);

        $privacyConsents = $user->consents()
            ->where('consent_type', GroupUserConsent::TYPE_PRIVACY)
            ->orderBy('version')
            ->get();

        $this->assertCount(2, $privacyConsents);
        $this->assertFalse($privacyConsents[0]->isActive());
        $this->assertTrue($privacyConsents[1]->isActive());
    }

    public function test_source_tracking(): void
    {
        $consent = GroupUserConsent::factory()->create(['source' => 'dodo']);
        $this->assertSame('dodo', $consent->source);
    }
}

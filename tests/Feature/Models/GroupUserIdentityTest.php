<?php

namespace Tests\Feature\Models;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupUserIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_belongs_to_group_user(): void
    {
        $identity = GroupUserIdentity::factory()->create();

        $this->assertInstanceOf(GroupUser::class, $identity->user);
        $this->assertSame($identity->group_user_id, $identity->user->id);
    }

    public function test_unique_type_value_constraint(): void
    {
        $user = GroupUser::factory()->create();
        GroupUserIdentity::factory()->google('1234567890')->create([
            'group_user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);
        // 同一個 google_id 不能屬於兩個 user
        GroupUserIdentity::factory()->google('1234567890')->create();
    }

    public function test_same_value_across_different_types_is_allowed(): void
    {
        $user = GroupUser::factory()->create();

        GroupUserIdentity::factory()->create([
            'group_user_id' => $user->id,
            'type' => GroupUserIdentity::TYPE_EMAIL,
            'value' => 'shared@example.com',
        ]);

        // phone type 用 'shared@example.com' 雖然奇怪但不該被擋（不同 type）
        $phone = GroupUserIdentity::factory()->create([
            'group_user_id' => $user->id,
            'type' => GroupUserIdentity::TYPE_PHONE,
            'value' => 'shared@example.com',
        ]);

        $this->assertSame(2, $user->identities()->count());
        $this->assertSame(GroupUserIdentity::TYPE_PHONE, $phone->type);
    }

    public function test_cascade_delete_when_user_force_deleted(): void
    {
        $user = GroupUser::factory()->create();
        GroupUserIdentity::factory()->count(3)->create([
            'group_user_id' => $user->id,
        ]);

        $user->forceDelete();

        $this->assertSame(0, GroupUserIdentity::where('group_user_id', $user->id)->count());
    }

    public function test_raw_payload_is_cast_to_array(): void
    {
        $payload = ['provider' => 'line', 'displayName' => '阿仙女'];
        $identity = GroupUserIdentity::factory()->line()->create([
            'raw_payload' => $payload,
        ]);

        $this->assertIsArray($identity->fresh()->raw_payload);
        $this->assertSame($payload, $identity->fresh()->raw_payload);
    }

    public function test_unverified_state(): void
    {
        $identity = GroupUserIdentity::factory()->unverified()->create();
        $this->assertNull($identity->verified_at);
    }
}

<?php

namespace Tests\Feature\Models;

use App\Models\GroupUser;
use App\Models\GroupUserMergeLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupUserMergeLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_record_merge_after_absorbed_user_force_deleted(): void
    {
        $survivor = GroupUser::factory()->create();
        $absorbed = GroupUser::factory()->create();

        $log = GroupUserMergeLog::create([
            'surviving_group_user_id' => $survivor->id,
            'absorbed_group_user_id' => $absorbed->id,
            'absorbed_email' => $absorbed->email_canonical,
            'absorbed_phone' => $absorbed->phone_canonical,
            'absorbed_identities' => [
                ['type' => 'line', 'value' => 'U_abc123'],
            ],
            'reason' => 'auto:phone+placeholder',
            'snapshot' => ['absorbed_orders_count' => 3],
            'actor' => 'system',
        ]);

        // 被合併方 force delete 後 log 還在
        $absorbed->forceDelete();

        $persisted = GroupUserMergeLog::find($log->id);
        $this->assertNotNull($persisted);
        $this->assertSame($survivor->id, $persisted->surviving_group_user_id);
        $this->assertIsArray($persisted->absorbed_identities);
        $this->assertIsArray($persisted->snapshot);
    }
}

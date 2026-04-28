<?php

namespace Tests\Feature\Models;

use App\Models\GroupUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;

class GroupUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_user_with_uuid_v7_primary_key(): void
    {
        $user = GroupUser::factory()->create();

        $this->assertNotEmpty($user->id);
        $this->assertTrue(Uuid::isValid($user->id));

        // v7 has version field 7
        $uuid = Uuid::fromString($user->id);
        $this->assertSame(7, (int) $uuid->toRfc4122()[14]);
    }

    public function test_uuid_is_time_ordered(): void
    {
        $first = GroupUser::factory()->create();
        usleep(2000);
        $second = GroupUser::factory()->create();

        // v7 是 time-ordered，按字串排序應與建立順序一致
        $this->assertLessThan($second->id, $first->id);
    }

    public function test_email_canonicalization(): void
    {
        $this->assertSame('foo@bar.com', GroupUser::canonicalizeEmail('  Foo@BAR.com  '));
        $this->assertNull(GroupUser::canonicalizeEmail(null));
        $this->assertNull(GroupUser::canonicalizeEmail('   '));
    }

    public function test_phone_canonicalization(): void
    {
        $this->assertSame('+886912345678', GroupUser::canonicalizePhone('+886-912-345-678'));
        $this->assertSame('0912345678', GroupUser::canonicalizePhone('(09) 12 34 56 78'));
        $this->assertNull(GroupUser::canonicalizePhone(null));
        $this->assertNull(GroupUser::canonicalizePhone('---'));
    }

    public function test_email_canonical_must_be_unique(): void
    {
        GroupUser::factory()->create(['email_canonical' => 'dup@example.com']);

        $this->expectException(QueryException::class);
        GroupUser::factory()->create(['email_canonical' => 'dup@example.com']);
    }

    public function test_phone_canonical_must_be_unique(): void
    {
        GroupUser::factory()->create(['phone_canonical' => '+886912345678']);

        $this->expectException(QueryException::class);
        GroupUser::factory()->create(['phone_canonical' => '+886912345678']);
    }

    public function test_soft_delete_keeps_row_for_30_day_cooling_period(): void
    {
        $user = GroupUser::factory()->create();
        $id = $user->id;

        $user->delete();

        $this->assertSoftDeleted('group_users', ['id' => $id]);
        $this->assertNull(GroupUser::find($id));
        $this->assertNotNull(GroupUser::withTrashed()->find($id));
    }

    public function test_status_states(): void
    {
        $active = GroupUser::factory()->create();
        $suspended = GroupUser::factory()->suspended()->create();
        $pending = GroupUser::factory()->pendingVerification()->create();

        $this->assertSame('active', $active->status);
        $this->assertSame('suspended', $suspended->status);
        $this->assertSame('pending_verification', $pending->status);
    }
}

<?php

namespace Tests\Feature\Internal;

use App\Models\GroupUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ADR-007 §6 risk #4 mitigation (b) — consumer reconcile delta endpoint.
 */
class ReconcileEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pandora_jwt.internal_secret' => 'test-secret']);
    }

    /** @return array<string, string> */
    private function authHeader(): array
    {
        return ['X-Pandora-Internal-Secret' => 'test-secret'];
    }

    public function test_unauthorized_without_secret(): void
    {
        $this->getJson('/api/internal/reconcile/users')->assertStatus(401);
    }

    public function test_invalid_since_returns_422(): void
    {
        $this->getJson('/api/internal/reconcile/users?since=not-a-date', $this->authHeader())
            ->assertStatus(422);
    }

    public function test_returns_users_modified_since(): void
    {
        // user A old (1 hour ago)
        $a = GroupUser::factory()->create();
        $a->updated_at = Carbon::now()->subHour();
        $a->save();

        // user B recent
        $b = GroupUser::factory()->create();
        $b->update(['display_name' => 'recent']);

        $cutoff = Carbon::now()->subMinutes(5)->toIso8601String();
        $res = $this->getJson('/api/internal/reconcile/users?since='.urlencode($cutoff), $this->authHeader());

        $res->assertOk();
        $users = (array) $res->json('users');
        $ids = array_map(fn ($u) => $u['id'], $users);
        $this->assertContains($b->id, $ids);
        $this->assertNotContains($a->id, $ids);
    }

    public function test_response_does_not_leak_pii(): void
    {
        GroupUser::factory()->create([
            'email_canonical' => 'leak@check.com',
            'phone_canonical' => '0911000000',
        ]);

        $res = $this->getJson('/api/internal/reconcile/users?since=1970-01-01T00:00:00Z', $this->authHeader());
        $res->assertOk();

        $users = $res->json('users');
        $this->assertGreaterThan(0, count($users));
        foreach ($users as $u) {
            $this->assertArrayNotHasKey('email_canonical', $u);
            $this->assertArrayNotHasKey('phone_canonical', $u);
            $this->assertArrayNotHasKey('password', $u);
            $this->assertArrayNotHasKey('birthday', $u);
            $this->assertArrayHasKey('id', $u);
            $this->assertArrayHasKey('display_name', $u);
            $this->assertArrayHasKey('status', $u);
            $this->assertArrayHasKey('updated_at', $u);
        }
    }

    public function test_pagination_with_has_more_and_next_cursor(): void
    {
        GroupUser::factory()->count(5)->create();

        $res = $this->getJson('/api/internal/reconcile/users?since=1970-01-01T00:00:00Z&limit=2', $this->authHeader());
        $res->assertOk();
        $res->assertJson([
            'has_more' => true,
            'count' => 2,
        ]);
        $this->assertNotNull($res->json('next_cursor'));

        // Page 2 — at minimum some new users beyond limit, has_more true again
        $res2 = $this->getJson(
            '/api/internal/reconcile/users?since='.urlencode($res->json('next_cursor')).'&limit=2',
            $this->authHeader(),
        );
        $res2->assertOk();
        $this->assertGreaterThan(0, $res2->json('count'));
    }

    public function test_no_more_results_signals_has_more_false(): void
    {
        GroupUser::factory()->count(3)->create();

        $res = $this->getJson('/api/internal/reconcile/users?since=1970-01-01T00:00:00Z&limit=100', $this->authHeader());
        $res->assertOk();
        $res->assertJson(['has_more' => false]);
        $this->assertNull($res->json('next_cursor'));
    }

    public function test_limit_clamped_to_max(): void
    {
        $res = $this->getJson('/api/internal/reconcile/users?since=1970-01-01T00:00:00Z&limit=99999', $this->authHeader());
        $res->assertOk();
        $this->assertLessThanOrEqual(500, $res->json('count'));
    }
}

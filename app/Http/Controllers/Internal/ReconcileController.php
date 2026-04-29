<?php

namespace App\Http\Controllers\Internal;

use App\Models\GroupUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ADR-007 §6 risk #4 mitigation (b) — consumer reconcile endpoint.
 *
 * Consumer apps (pandora-meal, 母艦, etc.) periodically poll this to pull
 * a delta of group_users that have changed since their last reconcile run.
 * Used as a safety net when the identity webhook chain drops events
 * (worker crash, network partition, payload validation reject).
 *
 * Response contract is intentionally PII-free per ADR-007 §2.3:
 *   - id (uuid)
 *   - display_name (mirrorable, ADR-007 §2.3 allows)
 *   - status
 *   - updated_at
 *
 * Email / phone / gender / birthday / password_hash are NOT returned.
 * Consumers needing PII for a specific user must hit
 * `GET /api/v1/internal/users/{uuid}` (TODO Phase 5).
 *
 * Cursor is `updated_at`-based with a stable id-tiebreaker:
 *   - `since`: ISO-8601 UTC; rows with `updated_at >= since` are included
 *   - On equal `updated_at`, sort by id ascending so cursor is deterministic
 *
 * Consumer pseudocode:
 *   cursor = state.last_reconcile_at  // initial = epoch
 *   loop:
 *     r = GET /reconcile/users?since={cursor}&limit=100
 *     for u in r.users:
 *       upsert local mirror
 *     cursor = r.next_cursor
 *     until r.has_more == false
 *   state.last_reconcile_at = cursor
 */
class ReconcileController
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;

    public function users(Request $request): JsonResponse
    {
        $sinceRaw = (string) $request->query('since', '1970-01-01T00:00:00Z');
        $limit = max(1, min(self::MAX_LIMIT, (int) $request->query('limit', self::DEFAULT_LIMIT)));

        try {
            $since = Carbon::parse($sinceRaw)->utc();
        } catch (\Exception) {
            return response()->json(['error' => 'invalid since'], 422);
        }

        // Pull `limit + 1` to detect has_more without a separate count.
        $rows = GroupUser::query()
            ->where('updated_at', '>=', $since)
            ->orderBy('updated_at', 'asc')
            ->orderBy('id', 'asc')
            ->limit($limit + 1)
            ->get(['id', 'display_name', 'status', 'updated_at']);

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        $nextCursor = null;
        if ($hasMore) {
            $last = $page->last();
            // Advance to the last returned row's updated_at; cursor is
            // inclusive on `since`, so consumers should dedup on (id) when
            // resuming from same timestamp.
            $nextCursor = $last->updated_at->toIso8601String();
        }

        return response()->json([
            'users' => $page->map(fn (GroupUser $u) => [
                'id' => $u->id,
                'display_name' => $u->display_name,
                'status' => $u->status,
                'updated_at' => $u->updated_at->toIso8601String(),
            ])->values(),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'count' => $page->count(),
        ]);
    }
}

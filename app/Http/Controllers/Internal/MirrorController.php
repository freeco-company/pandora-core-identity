<?php

namespace App\Http\Controllers\Internal;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Internal mirror endpoint - 母艦端 shadow-mode 鏡寫接收。
 *
 * 設計（ADR-001 §4.1 Step 1）：
 *   - 母艦每次 customer / customer_identity / consent 寫入 →
 *     IdentityMirrorService 序列化 → 打到本 endpoint
 *   - Endpoint upsert，idempotent（同 source_event_id 重送不會雙寫）
 *   - 認證走 mTLS + shared secret header（dev/staging 用 secret，prod ADR-006 走 mTLS）
 *
 * 留待 Step 2：
 *   - dual-write conflict resolution
 *   - source_event_id 表去重
 *   - webhook fan-out
 */
class MirrorController
{
    public function customerUpsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fp_customer_id' => ['required', 'integer'],
            'email_canonical' => ['nullable', 'string', 'max:255'],
            'phone_canonical' => ['nullable', 'string', 'max:32'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:16'],
            'birthday' => ['nullable', 'date'],
            'identities' => ['nullable', 'array'],
            'identities.*.type' => ['required_with:identities', 'string', 'max:24'],
            'identities.*.value' => ['required_with:identities', 'string', 'max:255'],
            'identities.*.is_primary' => ['nullable', 'boolean'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $emailCanon = GroupUser::canonicalizeEmail($data['email_canonical'] ?? null);
            $phoneCanon = GroupUser::canonicalizePhone($data['phone_canonical'] ?? null);

            // Lookup priority: existing identity → existing email → existing phone → create
            /** @var ?GroupUser $user */
            $user = null;

            foreach ($data['identities'] ?? [] as $iden) {
                $existing = GroupUserIdentity::where('type', $iden['type'])
                    ->where('value', $iden['value'])
                    ->first();
                if ($existing !== null) {
                    $user = GroupUser::find($existing->group_user_id);
                    break;
                }
            }

            if ($user === null && $emailCanon !== null) {
                $user = GroupUser::where('email_canonical', $emailCanon)->first();
            }
            if ($user === null && $phoneCanon !== null) {
                $user = GroupUser::where('phone_canonical', $phoneCanon)->first();
            }

            if ($user === null) {
                $user = GroupUser::create([
                    'email_canonical' => $emailCanon,
                    'phone_canonical' => $phoneCanon,
                    'display_name' => $data['display_name'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'birthday' => $data['birthday'] ?? null,
                    'status' => 'active',
                ]);
            } else {
                // 補欄位（不覆蓋既有非空值，避免 platform 端被母艦舊資料覆寫掉新值）
                $user->fill(array_filter([
                    'email_canonical' => $user->email_canonical ?? $emailCanon,
                    'phone_canonical' => $user->phone_canonical ?? $phoneCanon,
                    'display_name' => $user->display_name ?? ($data['display_name'] ?? null),
                    'gender' => $user->gender ?? ($data['gender'] ?? null),
                    'birthday' => $user->birthday ?? ($data['birthday'] ?? null),
                ], fn ($v) => $v !== null));
                $user->save();
            }

            // Sync identities (upsert by (type, value))
            foreach ($data['identities'] ?? [] as $iden) {
                GroupUserIdentity::updateOrCreate(
                    ['type' => $iden['type'], 'value' => $iden['value']],
                    [
                        'group_user_id' => $user->id,
                        'is_primary' => $iden['is_primary'] ?? false,
                        'verified_at' => now(),  // 從母艦來的視為已驗證
                    ]
                );
            }

            return $user;
        });

        return response()->json([
            'group_user_id' => $user->id,
            'mirrored_at' => now()->toIso8601String(),
        ]);
    }
}

<?php

namespace App\Services\Auth;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Email + password 登入備援。
 *
 * 跟 OAuth 不同：email 必須走 verification flow（OAuth provider 已替我們驗）。
 * 註冊時發 verification token，login 時若未 verified 拒絕。
 */
class EmailAuthService
{
    public function register(string $email, string $password, ?string $displayName = null): GroupUser
    {
        $emailCanon = GroupUser::canonicalizeEmail($email);
        if ($emailCanon === null) {
            throw new RuntimeException('Invalid email.');
        }

        if (GroupUser::where('email_canonical', $emailCanon)->exists()) {
            throw new RuntimeException('Email already registered.');
        }

        return DB::transaction(function () use ($emailCanon, $password, $displayName) {
            $user = GroupUser::create([
                'email_canonical' => $emailCanon,
                'display_name' => $displayName,
                'password' => $password,  // 'hashed' cast handles bcrypt
                'email_verification_token' => Str::random(64),
                'status' => 'pending_verification',
            ]);

            GroupUserIdentity::create([
                'group_user_id' => $user->id,
                'type' => GroupUserIdentity::TYPE_EMAIL,
                'value' => $emailCanon,
                'verified_at' => null,
                'is_primary' => true,
            ]);

            return $user;
        });
    }

    public function login(string $email, string $password): GroupUser
    {
        $emailCanon = GroupUser::canonicalizeEmail($email);
        if ($emailCanon === null) {
            throw new RuntimeException('Invalid credentials.');
        }

        /** @var ?GroupUser $user */
        $user = GroupUser::where('email_canonical', $emailCanon)->first();

        if ($user === null || $user->password === null) {
            throw new RuntimeException('Invalid credentials.');
        }

        if (! Hash::check($password, $user->password)) {
            throw new RuntimeException('Invalid credentials.');
        }

        if ($user->email_verified_at === null) {
            throw new RuntimeException('Email not verified.');
        }

        if ($user->status === 'suspended') {
            throw new RuntimeException('Account suspended.');
        }

        $user->last_login_at = now();
        $user->save();

        return $user;
    }

    public function verifyEmail(string $token): GroupUser
    {
        /** @var ?GroupUser $user */
        $user = GroupUser::where('email_verification_token', $token)->first();
        if ($user === null) {
            throw new RuntimeException('Invalid or expired verification token.');
        }

        $user->email_verified_at = now();
        $user->email_verification_token = null;
        if ($user->status === 'pending_verification') {
            $user->status = 'active';
        }
        $user->save();

        // 順便把 email identity verified
        GroupUserIdentity::where('group_user_id', $user->id)
            ->where('type', GroupUserIdentity::TYPE_EMAIL)
            ->update(['verified_at' => now()]);

        return $user;
    }
}

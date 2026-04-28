<?php

namespace App\Models;

use App\Models\Concerns\HasUuidV7Key;
use Database\Factories\GroupUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id UUID v7
 * @property ?string $email_canonical
 * @property ?string $phone_canonical
 * @property ?string $display_name
 * @property ?string $gender
 * @property ?Carbon $birthday
 * @property ?string $password bcrypt hash, null = OAuth-only user
 * @property ?Carbon $email_verified_at
 * @property ?string $email_verification_token
 * @property string $status
 * @property ?Carbon $last_login_at
 *
 * @method static GroupUserFactory factory($count = null, $state = [])
 */
class GroupUser extends Model
{
    /** @use HasFactory<GroupUserFactory> */
    use HasFactory;

    use HasUuidV7Key;
    use SoftDeletes;

    protected $table = 'group_users';

    protected $fillable = [
        'email_canonical',
        'phone_canonical',
        'display_name',
        'gender',
        'birthday',
        'password',
        'email_verified_at',
        'email_verification_token',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'email_verification_token',
    ];

    protected $casts = [
        'birthday' => 'date',
        'last_login_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * @return HasMany<GroupUserIdentity, $this>
     */
    public function identities(): HasMany
    {
        return $this->hasMany(GroupUserIdentity::class);
    }

    /**
     * @return HasMany<GroupUserConsent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(GroupUserConsent::class);
    }

    public static function canonicalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $trimmed = trim($email);

        return $trimmed === '' ? null : mb_strtolower($trimmed);
    }

    public static function canonicalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        // 留 + 與數字，其餘濾掉（連字號 / 空白 / 括號）
        $cleaned = preg_replace('/[^\d+]/', '', $phone) ?? '';

        return $cleaned === '' ? null : $cleaned;
    }
}

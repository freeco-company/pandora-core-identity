<?php

namespace Database\Factories;

use App\Models\GroupUser;
use App\Models\GroupUserConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupUserConsent>
 */
class GroupUserConsentFactory extends Factory
{
    protected $model = GroupUserConsent::class;

    public function definition(): array
    {
        return [
            'group_user_id' => GroupUser::factory(),
            'consent_type' => GroupUserConsent::TYPE_TOS,
            'version' => '2026-04-01',
            'granted_at' => now(),
            'revoked_at' => null,
            'source' => 'fp',
            'granted_ip' => fake()->ipv4(),
            'granted_user_agent' => fake()->userAgent(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}

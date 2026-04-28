<?php

namespace Database\Factories;

use App\Models\GroupUser;
use App\Models\GroupUserIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupUserIdentity>
 */
class GroupUserIdentityFactory extends Factory
{
    protected $model = GroupUserIdentity::class;

    public function definition(): array
    {
        return [
            'group_user_id' => GroupUser::factory(),
            'type' => GroupUserIdentity::TYPE_EMAIL,
            'value' => fake()->unique()->safeEmail(),
            'verified_at' => now(),
            'is_primary' => true,
            'raw_payload' => null,
        ];
    }

    public function google(?string $googleId = null): static
    {
        return $this->state(fn () => [
            'type' => GroupUserIdentity::TYPE_GOOGLE,
            'value' => $googleId ?? (string) fake()->numerify('1#############'),
        ]);
    }

    public function line(?string $lineId = null): static
    {
        return $this->state(fn () => [
            'type' => GroupUserIdentity::TYPE_LINE,
            'value' => $lineId ?? 'U'.fake()->regexify('[a-f0-9]{32}'),
        ]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['verified_at' => null]);
    }
}

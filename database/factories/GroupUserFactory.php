<?php

namespace Database\Factories;

use App\Models\GroupUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupUser>
 */
class GroupUserFactory extends Factory
{
    protected $model = GroupUser::class;

    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'email_canonical' => GroupUser::canonicalizeEmail($email),
            'phone_canonical' => GroupUser::canonicalizePhone(fake()->unique()->e164PhoneNumber()),
            'display_name' => fake()->name(),
            'gender' => fake()->randomElement(['female', 'male', 'other']),
            'birthday' => fake()->dateTimeBetween('-60 years', '-18 years'),
            'status' => 'active',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }

    public function pendingVerification(): static
    {
        return $this->state(fn () => ['status' => 'pending_verification']);
    }
}

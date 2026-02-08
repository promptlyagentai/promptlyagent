<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatSession>
 */
class ChatSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->sentence(3),
            'metadata' => [
                'initiated_by' => $this->faker->randomElement(['web', 'api', 'trigger']),
                'created_via' => $this->faker->randomElement(['chat_interface', 'research_interface', 'api']),
            ],
        ];
    }

    /**
     * Indicate that the session was created via API.
     */
    public function apiSession(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'API Chat Session',
            'metadata' => [
                'initiated_by' => 'api',
                'api_version' => 'v1',
                'can_continue_via_web' => true,
            ],
        ]);
    }

    /**
     * Indicate that the session was created via web interface.
     */
    public function webSession(): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => [
                'initiated_by' => 'web',
                'created_via' => 'chat_interface',
            ],
        ]);
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatInteraction>
 */
class ChatInteractionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_session_id' => \App\Models\ChatSession::factory(),
            'user_id' => \App\Models\User::factory(),
            'question' => $this->faker->sentence(),
            'answer' => $this->faker->paragraph(),
            'summary' => $this->faker->sentence(),
            'metadata' => null,
            'agent_execution_id' => null,
            'input_trigger_id' => null,
            'agent_id' => null,
        ];
    }
}

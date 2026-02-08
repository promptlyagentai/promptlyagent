<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'agent_type' => 'direct',
            'description' => $this->faker->sentence(),
            'system_prompt' => $this->faker->paragraph(),
            'ai_provider' => 'anthropic',
            'ai_model' => 'claude-3-5-sonnet-20241022',
            'status' => 'active',
            'is_public' => false,
            'show_in_chat' => true,
            'available_for_research' => true,
            'streaming_enabled' => true,
            'thinking_enabled' => false,
            'enforce_response_language' => true,
            'max_steps' => 10,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the agent is public.
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => true,
        ]);
    }

    /**
     * Indicate that the agent is private.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => false,
        ]);
    }

    /**
     * Indicate that the agent is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the agent is a promptly type.
     */
    public function promptly(): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_type' => 'promptly',
        ]);
    }

    /**
     * Indicate that the agent is a synthesizer type.
     */
    public function synthesizer(): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_type' => 'synthesizer',
        ]);
    }

    /**
     * Indicate that the agent has thinking enabled.
     */
    public function withThinking(): static
    {
        return $this->state(fn (array $attributes) => [
            'thinking_enabled' => true,
        ]);
    }
}

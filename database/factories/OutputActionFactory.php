<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OutputAction>
 */
class OutputActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'provider_id' => 'webhook',
            'status' => 'active',
            'config' => [
                'url' => $this->faker->url(),
                'method' => 'POST',
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ],
            'webhook_secret' => null,
            'trigger_on' => 'success',
            'total_executions' => 0,
            'successful_executions' => 0,
            'failed_executions' => 0,
            'last_executed_at' => null,
        ];
    }

    /**
     * Indicate that the action is for Slack.
     */
    public function slack(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_id' => 'slack',
            'config' => [
                'webhook_url' => 'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXX',
                'channel' => '#general',
            ],
        ]);
    }

    /**
     * Indicate that the action is for Discord.
     */
    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_id' => 'discord',
            'config' => [
                'webhook_url' => 'https://discord.com/api/webhooks/123456789012345678/XXXXXXXXXXXXXXXXXXXX',
            ],
        ]);
    }

    /**
     * Indicate that the action is for Email.
     */
    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_id' => 'email',
            'config' => [
                'to' => [$this->faker->email()],
                'subject' => 'Agent Execution Result',
            ],
        ]);
    }

    /**
     * Indicate that the action is paused.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
        ]);
    }

    /**
     * Indicate that the action is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disabled',
        ]);
    }

    /**
     * Indicate that the action triggers on failure.
     */
    public function triggerOnFailure(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_on' => 'failure',
        ]);
    }

    /**
     * Indicate that the action triggers always.
     */
    public function triggerAlways(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_on' => 'always',
        ]);
    }

    /**
     * Indicate that the action has execution statistics.
     */
    public function withExecutionStats(int $total = 100, int $successful = 95, int $failed = 5): static
    {
        return $this->state(fn (array $attributes) => [
            'total_executions' => $total,
            'successful_executions' => $successful,
            'failed_executions' => $failed,
            'last_executed_at' => now()->subMinutes(rand(1, 60)),
        ]);
    }
}

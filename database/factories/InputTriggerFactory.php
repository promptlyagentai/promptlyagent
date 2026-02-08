<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InputTrigger>
 */
class InputTriggerFactory extends Factory
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
            'agent_id' => Agent::factory(),
            'trigger_target_type' => 'agent',
            'command_class' => null,
            'command_parameters' => null,
            'provider_id' => 'api',
            'integration_id' => null,
            'status' => 'active',
            'config' => null,
            'rate_limits' => [
                'per_minute' => 10,
                'per_hour' => 100,
                'per_day' => 1000,
            ],
            'ip_whitelist' => null,
            'session_strategy' => 'new_each',
            'default_session_id' => null,
            'total_invocations' => 0,
            'successful_invocations' => 0,
            'failed_invocations' => 0,
            'last_invoked_at' => null,
            'secret_created_at' => now(),
            'secret_rotated_at' => null,
            'secret_rotation_count' => 0,
        ];
    }

    /**
     * Indicate that the trigger targets a command instead of an agent.
     */
    public function commandTrigger(): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => null,
            'trigger_target_type' => 'command',
            'command_class' => 'App\\Console\\Commands\\ExampleCommand',
            'command_parameters' => [
                'param1' => '$.data.value1',
                'param2' => '$.data.value2',
            ],
        ]);
    }

    /**
     * Indicate that the trigger is paused.
     */
    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
        ]);
    }

    /**
     * Indicate that the trigger is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'disabled',
        ]);
    }

    /**
     * Indicate that the trigger has IP whitelist configured.
     */
    public function withIpWhitelist(array $ips = ['127.0.0.1', '192.168.1.0/24']): static
    {
        return $this->state(fn (array $attributes) => [
            'ip_whitelist' => $ips,
        ]);
    }

    /**
     * Indicate that the trigger continues the last session.
     */
    public function continueLastSession(): static
    {
        return $this->state(fn (array $attributes) => [
            'session_strategy' => 'continue_last',
        ]);
    }

    /**
     * Indicate that the trigger uses a specified session.
     */
    public function specifiedSession(): static
    {
        return $this->state(fn (array $attributes) => [
            'session_strategy' => 'specified',
            'default_session_id' => ChatSession::factory()->create(['user_id' => $attributes['user_id']])->id,
        ]);
    }

    /**
     * Indicate that the trigger has custom rate limits.
     */
    public function withRateLimits(int $perMinute = 5, int $perHour = 50, int $perDay = 500): static
    {
        return $this->state(fn (array $attributes) => [
            'rate_limits' => [
                'per_minute' => $perMinute,
                'per_hour' => $perHour,
                'per_day' => $perDay,
            ],
        ]);
    }

    /**
     * Indicate that the trigger has been invoked multiple times.
     */
    public function withUsageStats(int $total = 100, int $successful = 95, int $failed = 5): static
    {
        return $this->state(fn (array $attributes) => [
            'total_invocations' => $total,
            'successful_invocations' => $successful,
            'failed_invocations' => $failed,
            'last_invoked_at' => now()->subMinutes(rand(1, 60)),
        ]);
    }
}

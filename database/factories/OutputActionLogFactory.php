<?php

namespace Database\Factories;

use App\Models\AgentExecution;
use App\Models\OutputAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\OutputActionLog>
 */
class OutputActionLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $statusCode = $this->faker->randomElement([200, 201, 204, 400, 401, 403, 404, 500]);
        $status = in_array($statusCode, [200, 201, 204]) ? 'success' : 'failed';

        return [
            'output_action_id' => OutputAction::factory(),
            'user_id' => User::factory(),
            'triggerable_type' => AgentExecution::class,
            'triggerable_id' => fn (array $attributes) => AgentExecution::factory()->create(['user_id' => $attributes['user_id']])->id,
            'url' => $this->faker->url(),
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'PromptlyAgent/1.0',
            ],
            'body' => json_encode([
                'event' => 'agent.execution.completed',
                'data' => ['result' => 'test data'],
            ]),
            'status' => $status,
            'response_code' => $statusCode,
            'response_body' => $status === 'success' ? '{"success": true}' : '{"error": "Something went wrong"}',
            'error_message' => $status === 'failed' ? 'HTTP '.$statusCode.' error' : null,
            'duration_ms' => $this->faker->numberBetween(50, 5000),
            'executed_at' => now()->subMinutes($this->faker->numberBetween(1, 1440)),
        ];
    }

    /**
     * Indicate that the log represents a successful execution.
     */
    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'success',
            'response_code' => 200,
            'response_body' => '{"success": true}',
            'error_message' => null,
        ]);
    }

    /**
     * Indicate that the log represents a failed execution.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'response_code' => 500,
            'response_body' => '{"error": "Internal server error"}',
            'error_message' => 'HTTP 500 - Internal server error',
        ]);
    }

    /**
     * Indicate that the log represents a timeout.
     */
    public function timeout(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'timeout',
            'response_code' => null,
            'response_body' => null,
            'error_message' => 'Request timeout after 30 seconds',
            'duration_ms' => 30000,
        ]);
    }
}

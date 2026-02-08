<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentTool>
 */
class AgentToolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tools = [
            'web_search',
            'knowledge_retrieval',
            'code_execution',
            'file_reader',
            'calculator',
            'weather_api',
            'database_query',
        ];

        return [
            'agent_id' => Agent::factory(),
            'tool_name' => $this->faker->randomElement($tools),
            'tool_config' => null,
            'enabled' => true,
            'execution_order' => $this->faker->numberBetween(0, 100),
        ];
    }

    /**
     * Indicate that the tool is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }
}

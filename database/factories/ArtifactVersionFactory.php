<?php

namespace Database\Factories;

use App\Models\Artifact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArtifactVersion>
 */
class ArtifactVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'artifact_id' => Artifact::factory(),
            'version' => '1.0.0',
            'content' => $this->faker->paragraphs(3, true),
            'asset_id' => null,
            'changes' => [
                'content_updated' => true,
                'lines_added' => $this->faker->numberBetween(5, 50),
                'lines_removed' => $this->faker->numberBetween(0, 20),
            ],
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate a specific version number.
     */
    public function version(string $version): static
    {
        return $this->state(fn (array $attributes) => [
            'version' => $version,
        ]);
    }

    /**
     * Indicate that this is a major version change.
     */
    public function majorChange(): static
    {
        return $this->state(fn (array $attributes) => [
            'changes' => [
                'content_updated' => true,
                'lines_added' => $this->faker->numberBetween(100, 500),
                'lines_removed' => $this->faker->numberBetween(50, 200),
                'description' => 'Major refactoring',
            ],
        ]);
    }

    /**
     * Indicate that this is a minor version change.
     */
    public function minorChange(): static
    {
        return $this->state(fn (array $attributes) => [
            'changes' => [
                'content_updated' => true,
                'lines_added' => $this->faker->numberBetween(10, 50),
                'lines_removed' => $this->faker->numberBetween(0, 10),
                'description' => 'Minor improvements',
            ],
        ]);
    }
}

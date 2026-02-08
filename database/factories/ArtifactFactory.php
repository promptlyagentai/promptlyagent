<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Artifact>
 */
class ArtifactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filetypes = ['md', 'html', 'txt', 'json', 'php', 'js', 'css'];
        $filetype = $this->faker->randomElement($filetypes);

        return [
            'asset_id' => null,
            'title' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'content' => $this->faker->paragraphs(3, true),
            'filetype' => $filetype,
            'version' => '1.0.0',
            'privacy_level' => 'private',
            'metadata' => null,
            'author_id' => User::factory(),
            'parent_artifact_id' => null,
        ];
    }

    /**
     * Indicate that the artifact is public.
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'privacy_level' => 'public',
        ]);
    }

    /**
     * Indicate that the artifact is shared.
     */
    public function shared(): static
    {
        return $this->state(fn (array $attributes) => [
            'privacy_level' => 'shared',
        ]);
    }

    /**
     * Indicate that the artifact is markdown.
     */
    public function markdown(): static
    {
        return $this->state(fn (array $attributes) => [
            'filetype' => 'md',
            'content' => "# {$this->faker->sentence()}\n\n{$this->faker->paragraphs(3, true)}",
        ]);
    }

    /**
     * Indicate that the artifact is HTML.
     */
    public function html(): static
    {
        return $this->state(fn (array $attributes) => [
            'filetype' => 'html',
            'content' => "<html><head><title>{$this->faker->words(3, true)}</title></head><body><h1>{$this->faker->sentence()}</h1><p>{$this->faker->paragraph()}</p></body></html>",
        ]);
    }

    /**
     * Indicate that the artifact is JSON.
     */
    public function json(): static
    {
        return $this->state(fn (array $attributes) => [
            'filetype' => 'json',
            'content' => json_encode([
                'name' => $this->faker->name(),
                'email' => $this->faker->email(),
                'data' => $this->faker->words(5),
            ], JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * Indicate that the artifact is PHP code.
     */
    public function php(): static
    {
        return $this->state(fn (array $attributes) => [
            'filetype' => 'php',
            'content' => "<?php\n\nfunction example() {\n    return 'Hello World';\n}\n",
        ]);
    }

    /**
     * Indicate that the artifact has a parent (fork).
     */
    public function forked(): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_artifact_id' => static::factory()->create(['author_id' => $attributes['author_id']])->id,
        ]);
    }

    /**
     * Indicate that the artifact has metadata.
     */
    public function withMetadata(array $metadata = []): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => array_merge([
                'tags' => ['example', 'test'],
                'created_via' => 'api',
            ], $metadata),
        ]);
    }
}

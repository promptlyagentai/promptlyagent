<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KnowledgeDocument>
 */
class KnowledgeDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'content' => $this->faker->paragraphs(5, true),
            'content_type' => 'text',
            'source_type' => 'manual',
            'privacy_level' => 'private',
            'processing_status' => 'completed',
            'created_by' => User::factory(),
            'metadata' => [],
        ];
    }

    /**
     * Indicate that the document is public.
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'privacy_level' => 'public',
        ]);
    }

    /**
     * Indicate that the document is still processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'processing_status' => 'processing',
        ]);
    }

    /**
     * Indicate that the document processing failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processing_status' => 'failed',
            'processing_error' => 'Test error message',
        ]);
    }

    /**
     * Indicate that the document is from an external source.
     */
    public function external(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'external',
            'source_type' => 'external',
            'external_source_identifier' => $this->faker->url(),
            'auto_refresh_enabled' => true,
            'refresh_interval_minutes' => 1440, // 24 hours
        ]);
    }

    /**
     * Indicate that the document is a file upload.
     */
    public function file(): static
    {
        return $this->state(fn (array $attributes) => [
            'content_type' => 'file',
            'source_type' => 'upload',
        ]);
    }
}

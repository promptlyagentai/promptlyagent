<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Asset>
 */
class AssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $filename = $this->faker->word().'.'.$this->faker->randomElement(['pdf', 'txt', 'md', 'jpg', 'png']);
        $content = $this->faker->paragraphs(3, true);

        return [
            'storage_key' => 'assets/'.Str::uuid().'_'.$filename,
            'original_filename' => $filename,
            'mime_type' => $this->getMimeTypeForFilename($filename),
            'size_bytes' => strlen($content),
            'checksum' => hash('sha256', $content),
        ];
    }

    /**
     * Indicate that the asset is a PDF document.
     */
    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'original_filename' => $this->faker->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(10000, 1000000),
        ]);
    }

    /**
     * Indicate that the asset is an image.
     */
    public function image(string $extension = 'jpg'): static
    {
        return $this->state(fn (array $attributes) => [
            'original_filename' => $this->faker->word().'.'.$extension,
            'mime_type' => 'image/'.$extension,
            'size_bytes' => $this->faker->numberBetween(50000, 5000000),
        ]);
    }

    /**
     * Indicate that the asset is a text file.
     */
    public function textFile(): static
    {
        return $this->state(fn (array $attributes) => [
            'original_filename' => $this->faker->word().'.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => $this->faker->numberBetween(1000, 10000),
        ]);
    }

    /**
     * Indicate that the asset is a markdown file.
     */
    public function markdown(): static
    {
        return $this->state(fn (array $attributes) => [
            'original_filename' => $this->faker->word().'.md',
            'mime_type' => 'text/markdown',
            'size_bytes' => $this->faker->numberBetween(1000, 50000),
        ]);
    }

    /**
     * Get MIME type based on filename extension.
     */
    protected function getMimeTypeForFilename(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
            default => 'application/octet-stream',
        };
    }
}

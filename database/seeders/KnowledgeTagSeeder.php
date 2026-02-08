<?php

namespace Database\Seeders;

use App\Models\KnowledgeTag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class KnowledgeTagSeeder extends Seeder
{
    /**
     * Color mapping from named colors to hex codes (Tailwind CSS default palette)
     */
    protected array $colorMap = [
        'slate' => '#64748b',
        'gray' => '#6b7280',
        'red' => '#ef4444',
        'orange' => '#f97316',
        'amber' => '#f59e0b',
        'yellow' => '#eab308',
        'lime' => '#84cc16',
        'green' => '#22c55e',
        'emerald' => '#10b981',
        'teal' => '#14b8a6',
        'cyan' => '#06b6d4',
        'sky' => '#0ea5e9',
        'blue' => '#3b82f6',
        'indigo' => '#6366f1',
        'violet' => '#8b5cf6',
        'purple' => '#a855f7',
        'fuchsia' => '#d946ef',
        'pink' => '#ec4899',
        'rose' => '#f43f5e',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tags = config('knowledge_tags.tags', []);

        if (empty($tags)) {
            Log::warning('KnowledgeTagSeeder: No tags found in config/knowledge_tags.php');
            $this->command->warn('No tags found in config/knowledge_tags.php');

            return;
        }

        $created = 0;
        $updated = 0;

        foreach ($tags as $tagName => $tagData) {
            // Convert color name to hex code
            $colorName = $tagData['color'] ?? 'blue';
            $hexColor = $this->colorMap[$colorName] ?? $this->colorMap['blue'];

            // Use updateOrCreate to be idempotent
            $tag = KnowledgeTag::updateOrCreate(
                ['name' => $tagName],
                [
                    'description' => $tagData['description'] ?? null,
                    'color' => $hexColor,
                    'is_system' => true, // All config-based tags are system tags
                    'created_by' => null, // System tags have no creator
                ]
            );

            if ($tag->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        $this->command->info('Knowledge tags seeded successfully!');
        $this->command->info("Created: {$created} tags");
        $this->command->info("Updated: {$updated} tags");
        $this->command->info('Total: '.count($tags).' tags');

        Log::info('KnowledgeTagSeeder: Completed', [
            'created' => $created,
            'updated' => $updated,
            'total' => count($tags),
        ]);
    }
}

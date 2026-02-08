<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        $defaultTags = [
            // Document type tags (required for AI analysis)
            ['name' => 'type:document', 'slug' => 'typedocument', 'color' => 'blue', 'description' => 'General documents and files', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:report', 'slug' => 'typereport', 'color' => 'green', 'description' => 'Reports and analytics', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:tutorial', 'slug' => 'typetutorial', 'color' => 'purple', 'description' => 'Tutorials and how-to guides', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:code', 'slug' => 'typecode', 'color' => 'gray', 'description' => 'Code snippets and technical documentation', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:data', 'slug' => 'typedata', 'color' => 'red', 'description' => 'Data files and datasets', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:note', 'slug' => 'typenote', 'color' => 'yellow', 'description' => 'Quick notes and memos', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:website', 'slug' => 'typewebsite', 'color' => 'cyan', 'description' => 'Website content and web pages', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:product', 'slug' => 'typeproduct', 'color' => 'pink', 'description' => 'Product information and specifications', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:manual', 'slug' => 'typemanual', 'color' => 'orange', 'description' => 'User manuals and instruction guides', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:presentation', 'slug' => 'typepresentation', 'color' => 'indigo', 'description' => 'Presentations and slide decks', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'type:service', 'slug' => 'typeservice', 'color' => 'teal', 'description' => 'Service-related documentation', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],

            // Common organizational tags
            ['name' => 'tag:news', 'slug' => 'tagnews', 'color' => 'blue', 'description' => 'News articles and updates', 'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'tag:work', 'slug' => 'tagwork', 'color' => 'gray', 'description' => 'Work-related content', 'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'tag:private', 'slug' => 'tagprivate', 'color' => 'red', 'description' => 'Private and confidential information', 'is_system' => false, 'created_at' => $now, 'updated_at' => $now],
        ];

        // Use upsert to avoid duplicates and update existing entries
        DB::table('knowledge_tags')->upsert(
            $defaultTags,
            ['name'], // Unique key
            ['slug', 'color', 'description', 'is_system', 'updated_at'] // Columns to update if exists
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove only the default tags seeded by this migration
        $defaultTagNames = [
            'type:document',
            'type:report',
            'type:tutorial',
            'type:code',
            'type:data',
            'type:note',
            'type:website',
            'type:product',
            'type:manual',
            'type:presentation',
            'type:service',
            'tag:news',
            'tag:work',
            'tag:private',
        ];

        DB::table('knowledge_tags')
            ->whereIn('name', $defaultTagNames)
            ->delete();
    }
};

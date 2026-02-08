<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // IMPORTANT: Admin user must be created BEFORE seeding
        // Run 'php artisan make:admin' first, then 'php artisan db:seed'
        // Seeders require at least one admin user to exist

        // Seed individual agents (uses new configuration system)
        // All agents including Promptly Manual are now auto-discovered from Config/Agents/
        $this->call(AgentSeeder::class);

        // Note: Multi-agent workflows are now created programmatically (see DailyDigestCommand)
        // The old WorkflowSeeder created deprecated agent_type='workflow' records

        // Clean up any stale Meilisearch index entries
        $this->call(MeilisearchCleanupSeeder::class);

        // Clear Redis cache to ensure a clean application state
        $this->call(RedisCleanupSeeder::class);
    }
}

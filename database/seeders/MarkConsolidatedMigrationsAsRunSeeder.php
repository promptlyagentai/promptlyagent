<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MarkConsolidatedMigrationsAsRunSeeder extends Seeder
{
    /**
     * Mark all consolidated migrations as run for production deployment.
     *
     * This seeder should be run ONCE in production after deploying consolidated migrations
     * to prevent Laravel from trying to run them (since the schema already exists from old migrations).
     *
     * Usage:
     *   php artisan db:seed --class=MarkConsolidatedMigrationsAsRunSeeder
     */
    public function run(): void
    {
        $this->command->info('🔧 Marking consolidated migrations as run in production...');

        $migrations = [
            '2025_07_01_000000_create_agent_executions_table',
            '2025_07_01_000000_create_agent_knowledge_assignments_table',
            '2025_07_01_000000_create_agent_output_action_table',
            '2025_07_01_000000_create_agent_sources_table',
            '2025_07_01_000000_create_agents_table',
            '2025_07_01_000000_create_agent_tools_table',
            '2025_07_01_000000_create_artifact_artifact_tag_table',
            '2025_07_01_000000_create_artifact_integrations_table',
            '2025_07_01_000000_create_artifacts_table',
            '2025_07_01_000000_create_artifact_tags_table',
            '2025_07_01_000000_create_artifact_versions_table',
            '2025_07_01_000000_create_assets_table',
            '2025_07_01_000000_create_cache_locks_table',
            '2025_07_01_000000_create_cache_table',
            '2025_07_01_000000_create_chat_interaction_artifacts_table',
            '2025_07_01_000000_create_chat_interaction_attachments_table',
            '2025_07_01_000000_create_chat_interaction_knowledge_sources_table',
            '2025_07_01_000000_create_chat_interaction_sources_table',
            '2025_07_01_000000_create_chat_interactions_table',
            '2025_07_01_000000_create_chat_sessions_table',
            '2025_07_01_000000_create_failed_jobs_table',
            '2025_07_01_000000_create_input_trigger_output_action_table',
            '2025_07_01_000000_create_input_triggers_table',
            '2025_07_01_000000_create_integrations_table',
            '2025_07_01_000000_create_integration_tokens_table',
            '2025_07_01_000000_create_job_batches_table',
            '2025_07_01_000000_create_jobs_table',
            '2025_07_01_000000_create_knowledge_documents_table',
            '2025_07_01_000000_create_knowledge_document_tags_table',
            '2025_07_01_000000_create_knowledge_groups_table',
            '2025_07_01_000000_create_knowledge_tags_table',
            '2025_07_01_000000_create_output_action_logs_table',
            '2025_07_01_000000_create_output_actions_table',
            '2025_07_01_000000_create_password_reset_tokens_table',
            '2025_07_01_000000_create_personal_access_tokens_table',
            '2025_07_01_000000_create_sessions_table',
            '2025_07_01_000000_create_sources_table',
            '2025_07_01_000000_create_status_streams_table',
            '2025_07_01_000000_create_users_table',
            '2025_07_01_000003_add_foreign_keys_to_agent_executions_table',
            '2025_07_01_000003_add_foreign_keys_to_agent_knowledge_assignments_table',
            '2025_07_01_000003_add_foreign_keys_to_agent_output_action_table',
            '2025_07_01_000003_add_foreign_keys_to_agent_sources_table',
            '2025_07_01_000003_add_foreign_keys_to_agents_table',
            '2025_07_01_000003_add_foreign_keys_to_agent_tools_table',
            '2025_07_01_000003_add_foreign_keys_to_artifact_artifact_tag_table',
            '2025_07_01_000003_add_foreign_keys_to_artifact_integrations_table',
            '2025_07_01_000003_add_foreign_keys_to_artifacts_table',
            '2025_07_01_000003_add_foreign_keys_to_artifact_tags_table',
            '2025_07_01_000003_add_foreign_keys_to_artifact_versions_table',
            '2025_07_01_000003_add_foreign_keys_to_chat_interaction_artifacts_table',
            '2025_07_01_000003_add_foreign_keys_to_chat_interaction_attachments_table',
            '2025_07_01_000003_add_foreign_keys_to_chat_interaction_knowledge_sources_table',
            '2025_07_01_000003_add_foreign_keys_to_chat_interaction_sources_table',
            '2025_07_01_000003_add_foreign_keys_to_chat_interactions_table',
            '2025_07_01_000003_add_foreign_keys_to_chat_sessions_table',
            '2025_07_01_000003_add_foreign_keys_to_input_trigger_output_action_table',
            '2025_07_01_000003_add_foreign_keys_to_input_triggers_table',
            '2025_07_01_000003_add_foreign_keys_to_integrations_table',
            '2025_07_01_000003_add_foreign_keys_to_integration_tokens_table',
            '2025_07_01_000003_add_foreign_keys_to_knowledge_documents_table',
            '2025_07_01_000003_add_foreign_keys_to_knowledge_document_tags_table',
            '2025_07_01_000003_add_foreign_keys_to_knowledge_groups_table',
            '2025_07_01_000003_add_foreign_keys_to_knowledge_tags_table',
            '2025_07_01_000003_add_foreign_keys_to_output_action_logs_table',
            '2025_07_01_000003_add_foreign_keys_to_output_actions_table',
            '2025_07_01_000003_add_foreign_keys_to_status_streams_table',
        ];

        $inserted = 0;
        $skipped = 0;

        foreach ($migrations as $migration) {
            // Check if migration already exists
            $exists = DB::table('migrations')
                ->where('migration', $migration)
                ->exists();

            if (! $exists) {
                DB::table('migrations')->insert([
                    'migration' => $migration,
                    'batch' => 0, // Batch 0 indicates baseline migrations
                ]);
                $inserted++;
                $this->command->line("  ✓ Inserted: {$migration}");
            } else {
                $skipped++;
            }
        }

        $this->command->newLine();
        $this->command->info("✅ Done! Inserted: {$inserted}, Skipped (already exists): {$skipped}");
        $this->command->newLine();
        $this->command->warn('⚠️  You can now safely run "php artisan migrate" in production.');
        $this->command->warn('   Laravel will skip these consolidated migrations since they are marked as run.');
        $this->command->newLine();
    }
}

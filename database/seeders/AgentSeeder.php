<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentTool;
use App\Models\User;
use App\Services\Agents\Config\AgentConfigRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unified Agent Seeder
 *
 * Idempotent seeder that uses AgentConfigRegistry to discover and seed all
 * agent configurations. Replaces both old AgentSeeder and AgentConfigurationSeeder.
 *
 * **Features:**
 * - Auto-discovers agent configs from Config/Agents/
 * - Idempotent: uses updateOrCreate pattern
 * - Selective seeding via --filter option
 * - Transaction-safe per agent
 * - Tool sync (delete old, create new)
 *
 * **Usage:**
 * ```bash
 * # Seed all agents
 * ./vendor/bin/sail artisan db:seed --class=AgentSeeder
 *
 * # Seed specific agent
 * ./vendor/bin/sail artisan db:seed --class=AgentSeeder --filter=research-assistant
 *
 * # Seed multiple specific agents
 * ./vendor/bin/sail artisan db:seed --class=AgentSeeder --filter=research-assistant,direct-chat-agent
 * ```
 */
class AgentSeeder extends Seeder
{
    protected AgentConfigRegistry $registry;

    protected User $systemUser;

    protected ?string $filterIdentifier = null;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Initialize registry
        $this->registry = app(AgentConfigRegistry::class);

        // Get filter from command option if provided (disabled for now)
        // $this->filterIdentifier = $this->command->option('filter');

        // Require admin user to exist before seeding
        $adminUser = User::where('is_admin', true)->first();

        if (! $adminUser) {
            $this->command->error('❌ No admin user found.');
            $this->command->newLine();
            $this->command->warn('Please create an admin user first:');
            $this->command->info('  php artisan make:admin');
            $this->command->newLine();

            return;
        }

        $this->command->info("✅ Assigning agents to admin: {$adminUser->email}");
        $this->systemUser = $adminUser; // Keep variable name for compatibility

        $this->command->info('🚀 Starting Agent Configuration Seeding...');
        $this->command->newLine();

        // Get all configurations from registry
        $configs = $this->getConfigsToSeed();

        if (empty($configs)) {
            if ($this->filterIdentifier) {
                $this->command->error("No agent configurations found matching filter: {$this->filterIdentifier}");
            } else {
                $this->command->error('No agent configurations found. Ensure configs exist in app/Services/Agents/Config/Agents/');
            }

            return;
        }

        $this->command->info(sprintf('Found %d agent configuration(s) to seed', count($configs)));
        $this->command->newLine();

        $created = 0;
        $updated = 0;
        $failed = 0;

        foreach ($configs as $identifier => $config) {
            try {
                $result = $this->seedAgent($config);
                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'updated') {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->command->error(sprintf(
                    '❌ Failed to seed %s: %s',
                    $config->getName(),
                    $e->getMessage()
                ));
                Log::error('Agent seeding failed', [
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->command->newLine();
        $this->command->info('🎉 Agent seeding completed!');
        $this->command->info(sprintf('   ✅ Created: %d', $created));
        $this->command->info(sprintf('   🔄 Updated: %d', $updated));
        if ($failed > 0) {
            $this->command->error(sprintf('   ❌ Failed: %d', $failed));
        }
    }

    /**
     * Get configurations to seed based on filter
     *
     * @return array<string, \App\Services\Agents\Config\AbstractAgentConfig>
     */
    protected function getConfigsToSeed(): array
    {
        $allConfigs = $this->registry->all();

        if (! $this->filterIdentifier) {
            return $allConfigs;
        }

        // Support multiple filters separated by comma
        $filters = array_map('trim', explode(',', $this->filterIdentifier));

        return array_filter(
            $allConfigs,
            fn ($config, $identifier) => in_array($identifier, $filters),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Seed individual agent configuration
     *
     * @param  \App\Services\Agents\Config\AbstractAgentConfig  $config
     * @return string 'created' or 'updated'
     */
    protected function seedAgent($config): string
    {
        $identifier = $config->getIdentifier();
        $name = $config->getName();

        return DB::transaction(function () use ($config, $identifier, $name) {
            // Convert config to array for updateOrCreate
            $agentData = $config->toArray();

            // Add created_by for new agents
            $agentData['created_by'] = $this->systemUser->id;

            // Check if agent exists
            $existingAgent = Agent::where('slug', $identifier)->first();
            $isNew = $existingAgent === null;

            // Update or create agent
            $agent = Agent::updateOrCreate(
                ['slug' => $identifier],
                $agentData
            );

            // Sync tools: delete old, create new
            $this->syncAgentTools($agent, $config->getToolConfiguration());

            // Sync knowledge assignments if configured (future enhancement)
            // $this->syncKnowledgeAssignments($agent, $config->getKnowledgeConfig());

            // Log result
            $action = $isNew ? 'Created' : 'Updated';
            $icon = $isNew ? '✅' : '🔄';

            $this->command->info(sprintf(
                '%s %s %s (ID: %d, Version: %s, Tools: %d)',
                $icon,
                $action,
                $name,
                $agent->id,
                $config->getVersion(),
                count($config->getToolConfiguration())
            ));

            Log::info("Agent {$action}", [
                'identifier' => $identifier,
                'name' => $name,
                'agent_id' => $agent->id,
                'version' => $config->getVersion(),
                'is_new' => $isNew,
            ]);

            return $isNew ? 'created' : 'updated';
        });
    }

    /**
     * Sync agent tools
     *
     * Deletes existing tools and creates new ones from configuration.
     * This is simpler than Laravel's sync() and ensures clean state.
     */
    protected function syncAgentTools(Agent $agent, array $toolConfigs): void
    {
        // Delete existing tools
        AgentTool::where('agent_id', $agent->id)->delete();

        // Create new tools from configuration
        foreach ($toolConfigs as $toolName => $toolConfig) {
            AgentTool::create([
                'agent_id' => $agent->id,
                'tool_name' => $toolName,
                'enabled' => $toolConfig['enabled'] ?? true,
                'execution_order' => $toolConfig['execution_order'] ?? 100,
                'priority_level' => $toolConfig['priority_level'] ?? 'standard',
                'execution_strategy' => $toolConfig['execution_strategy'] ?? 'always',
                'min_results_threshold' => $toolConfig['min_results_threshold'] ?? 1,
                'max_execution_time' => $toolConfig['max_execution_time'] ?? 15000,
                'tool_config' => $toolConfig['config'] ?? null, // Note: column is 'tool_config' not 'config'
            ]);
        }
    }

    /**
     * Sync knowledge assignments (future enhancement)
     */
    protected function syncKnowledgeAssignments(Agent $agent, ?array $knowledgeConfig): void
    {
        if (! $knowledgeConfig) {
            return;
        }

        // Future implementation:
        // - Handle 'all' type
        // - Handle 'documents' type with document_ids
        // - Handle 'tags' type with tag_ids
        // - Use AgentKnowledgeAssignment model
    }
}

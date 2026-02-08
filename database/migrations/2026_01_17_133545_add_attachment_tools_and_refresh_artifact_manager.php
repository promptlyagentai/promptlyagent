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
        // Step 1: Add new attachment tools to all existing agents
        $this->addAttachmentToolsToAgents();

        // Step 2: Delete old Artifact Manager agent
        $this->deleteOldArtifactManager();

        // Step 3: Create new Artifact Manager agent with enhanced system prompt
        $this->createNewArtifactManager();
    }

    /**
     * Add list_chat_attachments and create_chat_attachment tools to all agents
     */
    protected function addAttachmentToolsToAgents(): void
    {
        $agents = DB::table('agents')->get(['id']);

        $attachmentTools = [
            [
                'tool_name' => 'list_chat_attachments',
                'tool_config' => json_encode([]),
                'enabled' => true,
                'execution_order' => 121,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
            ],
            [
                'tool_name' => 'create_chat_attachment',
                'tool_config' => json_encode([]),
                'enabled' => true,
                'execution_order' => 122,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 30000,
            ],
        ];

        foreach ($agents as $agent) {
            foreach ($attachmentTools as $toolConfig) {
                // Check if tool already exists for this agent
                $exists = DB::table('agent_tools')
                    ->where('agent_id', $agent->id)
                    ->where('tool_name', $toolConfig['tool_name'])
                    ->exists();

                if (! $exists) {
                    DB::table('agent_tools')->insert([
                        'agent_id' => $agent->id,
                        'tool_name' => $toolConfig['tool_name'],
                        'tool_config' => $toolConfig['tool_config'],
                        'enabled' => $toolConfig['enabled'],
                        'execution_order' => $toolConfig['execution_order'],
                        'priority_level' => $toolConfig['priority_level'],
                        'execution_strategy' => $toolConfig['execution_strategy'],
                        'min_results_threshold' => $toolConfig['min_results_threshold'],
                        'max_execution_time' => $toolConfig['max_execution_time'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Delete the old Artifact Manager agent if it needs updating
     */
    protected function deleteOldArtifactManager(): void
    {
        $artifactManager = DB::table('agents')
            ->where('name', 'Artifact Manager Agent')
            ->first();

        if (! $artifactManager) {
            return; // No agent to update
        }

        // Check if agent already has the enhanced system prompt
        // Look for markers that indicate the new prompt version
        $hasEnhancedPrompt = str_contains($artifactManager->system_prompt, 'Multi-Media Document Creation')
            && str_contains($artifactManager->system_prompt, 'create_chat_attachment')
            && str_contains($artifactManager->system_prompt, 'Eisvogel PDF Template Features');

        if ($hasEnhancedPrompt) {
            // Agent is already up to date, skip recreation
            return;
        }

        // Agent needs updating - delete it so we can recreate with new prompt
        // Delete associated tools
        DB::table('agent_tools')->where('agent_id', $artifactManager->id)->delete();

        // Delete the agent
        DB::table('agents')->where('id', $artifactManager->id)->delete();
    }

    /**
     * Create new Artifact Manager agent with enhanced system prompt
     * Only creates if one doesn't already exist (was deleted by previous step)
     */
    protected function createNewArtifactManager(): void
    {
        $exists = DB::table('agents')
            ->where('name', 'Artifact Manager Agent')
            ->exists();

        if (! $exists) {
            // Get first admin user as creator
            $admin = DB::table('users')->where('is_admin', true)->first();

            if (! $admin) {
                // Fallback to first user if no admin exists
                $admin = DB::table('users')->first();
            }

            if ($admin) {
                $user = \App\Models\User::find($admin->id);
                $agentService = app(\App\Services\Agents\AgentService::class);
                $agentService->createArtifactAgent($user);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove attachment tools from all agents
        DB::table('agent_tools')
            ->whereIn('tool_name', ['list_chat_attachments', 'create_chat_attachment'])
            ->delete();

        // Note: We don't restore the old Artifact Manager agent as we can't recover
        // the old system prompt. Re-run the seeder if needed.
    }
};

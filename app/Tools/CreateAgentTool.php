<?php

namespace App\Tools;

use App\Models\Agent;
use App\Models\AgentTool;
use App\Services\Agents\Config\Presets\AIConfigPresets;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;

/**
 * CreateAgentTool - Create New AI Agents
 *
 * Prism tool for creating new agents in the database with full configuration.
 * Supports setting system prompts, AI provider/model, tools, and visibility options.
 *
 * Agent Configuration:
 * - name: Required, human-readable agent name
 * - description: Required, explains agent's purpose and capabilities
 * - system_prompt: Required, defines agent behavior and instructions
 * - agent_type: Type of agent (direct, promptly, synthesizer, integration)
 * - ai_provider: AI provider (openai, anthropic, bedrock, google, mistral)
 * - ai_model: Specific model to use
 * - max_steps: Maximum reasoning steps (default: 25)
 * - is_public: Whether agent is available to all users (default: true)
 * - show_in_chat: Whether agent appears in chat selector (default: true)
 * - available_for_research: Whether agent can be used in workflows (default: true)
 * - tools: Array of tool identifiers to assign (optional)
 *
 * Response Data:
 * - Created agent ID and slug
 * - Assigned tools count
 * - Full agent configuration
 *
 * @see \App\Models\Agent
 * @see \App\Services\Agents\AgentService
 */
class CreateAgentTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('create_agent')
            ->for('Create a new AI agent with specified configuration. Requires name, description, and system_prompt. Optionally assign tools, set AI provider/model, and configure visibility. Returns the created agent with ID and slug for further configuration.')
            ->withStringParameter('name', 'Agent name (required, will be used to generate slug)')
            ->withStringParameter('description', 'Agent description explaining purpose and capabilities (required)')
            ->withStringParameter('system_prompt', 'System prompt defining agent behavior and instructions (required)')
            ->withStringParameter('agent_type', 'Agent type: direct, promptly, synthesizer, integration, individual (default: direct)')
            ->withStringParameter('ai_provider', 'AI provider: openai, anthropic, bedrock, google, mistral (optional, uses default if not specified)')
            ->withStringParameter('ai_model', 'Specific AI model to use (optional, uses default for provider if not specified)')
            ->withNumberParameter('max_steps', 'Maximum reasoning steps (default: 25)')
            ->withBooleanParameter('is_public', 'Whether agent is available to all users (default: true)')
            ->withBooleanParameter('show_in_chat', 'Whether agent appears in chat selector (default: true)')
            ->withBooleanParameter('available_for_research', 'Whether agent can be used in research workflows (default: true)')
            ->withBooleanParameter('streaming_enabled', 'Whether agent supports streaming responses (default: true)')
            ->withArrayParameter('tools', 'Array of tool identifiers to assign to agent (optional)', new StringSchema('tool', 'Tool identifier'), false)
            ->using(function (
                string $name,
                string $description,
                string $system_prompt,
                string $agent_type = 'direct',
                ?string $ai_provider = null,
                ?string $ai_model = null,
                int $max_steps = 25,
                bool $is_public = true,
                bool $show_in_chat = true,
                bool $available_for_research = true,
                bool $streaming_enabled = true,
                array $tools = []
            ) {
                return static::executeCreateAgent([
                    'name' => $name,
                    'description' => $description,
                    'system_prompt' => $system_prompt,
                    'agent_type' => $agent_type,
                    'ai_provider' => $ai_provider,
                    'ai_model' => $ai_model,
                    'max_steps' => $max_steps,
                    'is_public' => $is_public,
                    'show_in_chat' => $show_in_chat,
                    'available_for_research' => $available_for_research,
                    'streaming_enabled' => $streaming_enabled,
                    'tools' => $tools,
                ]);
            });
    }

    protected static function executeCreateAgent(array $arguments): string
    {
        try {
            $interactionId = null;
            $statusReporter = null;

            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $interactionId = $statusReporter->getInteractionId();
            } elseif (app()->has('current_interaction_id')) {
                $interactionId = app('current_interaction_id');
            }

            if ($statusReporter) {
                $statusReporter->report('create_agent', 'Creating new agent: '.$arguments['name'], true, false);
            }

            // Validate input
            $validator = Validator::make($arguments, [
                'name' => 'required|string|max:255',
                'description' => 'required|string|max:1000',
                'system_prompt' => 'required|string',
                'agent_type' => 'required|in:direct,promptly,synthesizer,integration,individual',
                'ai_provider' => 'nullable|in:openai,anthropic,bedrock,google,mistral',
                'ai_model' => 'nullable|string|max:255',
                'max_steps' => 'integer|min:1|max:100',
                'is_public' => 'boolean',
                'show_in_chat' => 'boolean',
                'available_for_research' => 'boolean',
                'streaming_enabled' => 'boolean',
                'tools' => 'array',
                'tools.*' => 'string',
            ]);

            if ($validator->fails()) {
                Log::warning('CreateAgentTool: Validation failed', [
                    'interaction_id' => $interactionId,
                    'errors' => $validator->errors()->all(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid arguments: '.implode(', ', $validator->errors()->all()),
                ], 'CreateAgentTool');
            }

            $validated = $validator->validated();

            if ($statusReporter) {
                $statusReporter->report('create_agent', 'Validation passed - preparing agent configuration', true, false);
            }

            // Get current user from app context
            $userId = null;
            if (app()->has('current_user')) {
                $userId = app('current_user')->id;
            } elseif (app()->has('current_user_id')) {
                $userId = app('current_user_id');
            }

            if (! $userId) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'User context not available. Cannot create agent.',
                ], 'CreateAgentTool');
            }

            // Determine AI provider/model using AIConfigPresets
            $aiConfig = null;
            if (empty($validated['ai_provider']) || empty($validated['ai_model'])) {
                // Use balanced preset as default - replace BOTH fields for consistency
                $preset = AIConfigPresets::balanced();
                $validated['ai_provider'] = $preset['provider'];
                $validated['ai_model'] = $preset['model'];
                $aiConfig = $preset['parameters'] ?? null;

                if ($statusReporter) {
                    $statusReporter->report('create_agent', 'Using default AI configuration (balanced preset)', true, false);
                }
            }

            if ($statusReporter) {
                $toolCount = count($validated['tools']);
                $statusReporter->report('create_agent', "Using {$validated['ai_provider']}/{$validated['ai_model']}".($toolCount > 0 ? " with {$toolCount} tools" : ''), true, false);
            }

            // Create agent in transaction
            $agent = DB::transaction(function () use ($validated, $userId, $aiConfig, $statusReporter) {
                // Generate slug
                $slug = Str::slug($validated['name']);

                // Check if slug already exists and make unique
                $originalSlug = $slug;
                $counter = 1;
                while (Agent::where('slug', $slug)->exists()) {
                    $slug = $originalSlug.'-'.$counter;
                    $counter++;
                }

                if ($statusReporter) {
                    $statusReporter->report('create_agent', "Creating agent with slug: {$slug}", true, false);
                }

                // Create agent (created_by must be set separately as it's guarded)
                $agent = new Agent([
                    'name' => $validated['name'],
                    'slug' => $slug,
                    'description' => $validated['description'],
                    'system_prompt' => $validated['system_prompt'],
                    'agent_type' => $validated['agent_type'],
                    'ai_provider' => $validated['ai_provider'],
                    'ai_model' => $validated['ai_model'],
                    'ai_config' => $aiConfig, // Store temperature, top_p, max_tokens
                    'max_steps' => $validated['max_steps'],
                    'is_public' => $validated['is_public'],
                    'show_in_chat' => $validated['show_in_chat'],
                    'available_for_research' => $validated['available_for_research'],
                    'streaming_enabled' => $validated['streaming_enabled'],
                    'status' => 'active',
                ]);

                // Set guarded fields directly
                $agent->created_by = $userId;
                $agent->save();

                // Assign tools if provided
                if (! empty($validated['tools'])) {
                    if ($statusReporter) {
                        $statusReporter->report('create_agent', 'Assigning '.count($validated['tools']).' tools to agent', true, false);
                    }

                    foreach ($validated['tools'] as $index => $toolName) {
                        AgentTool::create([
                            'agent_id' => $agent->id,
                            'tool_name' => $toolName,
                            'enabled' => true,
                            'execution_order' => ($index + 1) * 10,
                            'priority_level' => 'standard',
                            'execution_strategy' => 'always',
                            'min_results_threshold' => 1,
                            'max_execution_time' => 30000,
                        ]);
                    }
                }

                return $agent;
            });

            if ($statusReporter) {
                $statusReporter->report('create_agent', 'Agent created: '.$agent->name.' (ID: '.$agent->id.')', true, false);
            }

            Log::info('CreateAgentTool: Agent created successfully', [
                'interaction_id' => $interactionId,
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'agent_slug' => $agent->slug,
                'tools_count' => count($validated['tools']),
                'created_by' => $userId,
            ]);

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'agent_id' => $agent->id,
                    'slug' => $agent->slug,
                    'name' => $agent->name,
                    'description' => $agent->description,
                    'agent_type' => $agent->agent_type,
                    'ai_provider' => $agent->ai_provider,
                    'ai_model' => $agent->ai_model,
                    'max_steps' => $agent->max_steps,
                    'is_public' => $agent->is_public,
                    'show_in_chat' => $agent->show_in_chat,
                    'available_for_research' => $agent->available_for_research,
                    'tools_assigned' => count($validated['tools']),
                    'created_at' => $agent->created_at->toISOString(),
                ],
                'message' => 'Agent created successfully. You can now use this agent or assign additional knowledge to it.',
            ], 'CreateAgentTool');

        } catch (\Exception $e) {
            Log::error('CreateAgentTool: Exception during execution', [
                'interaction_id' => $interactionId ?? null,
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to create agent: '.$e->getMessage(),
            ], 'CreateAgentTool');
        }
    }
}

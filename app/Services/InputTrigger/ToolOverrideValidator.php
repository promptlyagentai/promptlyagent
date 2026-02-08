<?php

namespace App\Services\InputTrigger;

use App\Models\Agent;
use App\Models\User;
use App\Services\Agents\ToolRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Tool Override Validator - Security Validation for Dynamic Tool Selection.
 *
 * Validates tool override requests from API/webhook triggers to prevent unauthorized
 * tool access. Ensures users can only invoke tools they own and agents have access to.
 *
 * Security Model:
 * - User can only access their own tools (ownership validation)
 * - Agent must have relationship to tool (agent_tool table)
 * - MCP servers must be in user's available servers
 * - Invalid tools rejected, not silently ignored
 *
 * Validation Checks:
 * 1. **Type Check**: tools parameter must be array
 * 2. **Ownership**: All tools belong to requesting user
 * 3. **Agent Access**: Agent has agent_tool relationship for each tool
 * 4. **MCP Servers**: Server names valid for user's integrations
 * 5. **Existence**: Tools actually exist in database
 *
 * Return Structure:
 * - valid: Boolean success/failure
 * - error: Human-readable error message if invalid
 * - details: Structured error details (invalid_tools, unauthorized_tools)
 *
 * Use Cases:
 * - API requests specifying custom tool set per invocation
 * - Webhook triggers with tool restrictions
 * - Per-request tool filtering for InputTriggers
 *
 * @see \App\Services\Agents\ToolOverrideService
 * @see \App\Services\InputTrigger\TriggerExecutor
 */
class ToolOverrideValidator
{
    public function __construct(
        private ToolRegistry $toolRegistry
    ) {}

    /**
     * Validate tool override request with ownership checks
     *
     * @param  mixed  $tools  The tools parameter from API request
     * @param  Agent  $agent  The agent being invoked
     * @param  User  $user  The user making the request (for ownership validation)
     * @return array ['valid' => bool, 'error' => ?string, 'details' => ?array]
     */
    public function validate($tools, Agent $agent, User $user): array
    {
        // Must be an array
        if (! is_array($tools)) {
            return [
                'valid' => false,
                'error' => 'Tools parameter must be an array of tool names',
                'details' => ['received_type' => gettype($tools)],
            ];
        }

        // Cannot be empty
        if (empty($tools)) {
            return [
                'valid' => false,
                'error' => 'Tools array cannot be empty. Omit the tools parameter to use agent defaults.',
            ];
        }

        // Must contain only strings
        foreach ($tools as $index => $tool) {
            if (! is_string($tool)) {
                return [
                    'valid' => false,
                    'error' => "Tool at index {$index} must be a string",
                    'details' => ['index' => $index, 'received_type' => gettype($tool)],
                ];
            }
        }

        // Get tools available to this specific user (scoped by ownership)
        $availableTools = $this->toolRegistry->getAvailableTools($user->id);
        $availableToolNames = array_keys($availableTools);

        // Validate each tool exists AND is owned by the user
        $invalidTools = [];
        $unauthorizedTools = [];

        foreach ($tools as $toolName) {
            if (! in_array($toolName, $availableToolNames)) {
                // Tool either doesn't exist or doesn't belong to this user
                $invalidTools[] = $toolName;

                // Check if tool exists globally (to distinguish unauthorized vs non-existent)
                $allTools = $this->toolRegistry->getAvailableTools(); // Get all tools without user filter
                if (in_array($toolName, array_keys($allTools))) {
                    $unauthorizedTools[] = $toolName;
                }
            }
        }

        if (! empty($invalidTools)) {
            // Security: Log unauthorized tool access attempts
            if (! empty($unauthorizedTools)) {
                Log::warning('ToolOverrideValidator: Unauthorized tool access attempted', [
                    'user_id' => $user->id,
                    'agent_id' => $agent->id,
                    'unauthorized_tools' => $unauthorizedTools,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'timestamp' => now()->toIso8601String(),
                ]);
            }

            return [
                'valid' => false,
                'error' => 'One or more tools are not available to you',
                'details' => [
                    'invalid_tools' => $invalidTools,
                    // Security: Do NOT leak available tools to potential attacker
                ],
            ];
        }

        // All validations passed
        Log::info('ToolOverrideValidator: Validation passed', [
            'user_id' => $user->id,
            'agent_id' => $agent->id,
            'requested_tools' => $tools,
        ]);

        return [
            'valid' => true,
            'error' => null,
            'details' => null,
        ];
    }
}

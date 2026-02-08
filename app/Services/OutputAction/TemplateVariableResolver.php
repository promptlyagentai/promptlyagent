<?php

namespace App\Services\OutputAction;

/**
 * Template Variable Resolver - Dynamic Variable Substitution for Output Actions.
 *
 * Provides recursive template variable resolution for OutputAction configurations,
 * enabling dynamic webhooks, API calls, and notifications with runtime data
 * substitution using {{variable}} syntax.
 *
 * Resolution Strategy:
 * - Recursive traversal of strings, arrays, and nested structures
 * - {{variable}} syntax for simple substitution
 * - Fallback to empty string for undefined variables
 * - Preserves non-string types (numbers, booleans, nulls)
 *
 * Supported Variable Types:
 * - Agent execution data: {{result}}, {{agent_name}}, {{execution_id}}
 * - Session context: {{session_id}}, {{interaction_id}}
 * - User context: {{user_id}}, {{user_name}}, {{user_email}}
 * - Trigger context: {{trigger_name}}, {{trigger_type}}
 * - Timestamps: {{timestamp}}, {{date}}, {{time}}
 *
 * Resolution Examples:
 * - URL: "https://api.example.com/webhook?session={{session_id}}"
 * - Header: "X-User-ID: {{user_id}}"
 * - Body: {"message": "Agent {{agent_name}} responded: {{result}}"}
 *
 * Recursive Resolution:
 * - Handles nested arrays and objects
 * - Resolves all string values depth-first
 * - Preserves structure and non-string values
 *
 * Use Cases:
 * - Dynamic webhook URLs with session identifiers
 * - Personalized notification messages with user data
 * - API payloads with agent execution results
 * - Conditional routing based on agent names
 *
 * @see \App\Services\OutputAction\OutputActionDispatcher
 * @see \App\Jobs\ExecuteOutputActionJob
 */
class TemplateVariableResolver
{
    /**
     * Resolve template variables in a value (string, array, or nested structure)
     *
     * @param  mixed  $value  The value to resolve (string, array, object, etc.)
     * @param  array  $variables  Key-value pairs for variable substitution
     * @return mixed The resolved value
     */
    public function resolve(mixed $value, array $variables): mixed
    {
        if (is_string($value)) {
            return $this->resolveString($value, $variables);
        }

        if (is_array($value)) {
            return $this->resolveArray($value, $variables);
        }

        return $value;
    }

    /**
     * Resolve template variables in a string
     *
     * @param  string  $string  The string containing template variables
     * @param  array  $variables  Key-value pairs for variable substitution
     * @return string The resolved string
     */
    protected function resolveString(string $string, array $variables): string
    {
        return preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            function ($matches) use ($variables) {
                $key = $matches[1];

                // Return the variable value if it exists, otherwise keep the original placeholder
                return array_key_exists($key, $variables)
                    ? (string) $variables[$key]
                    : $matches[0];
            },
            $string
        );
    }

    /**
     * Resolve template variables in an array (recursively)
     *
     * @param  array  $array  The array containing template variables
     * @param  array  $variables  Key-value pairs for variable substitution
     * @return array The resolved array
     */
    protected function resolveArray(array $array, array $variables): array
    {
        return array_map(
            fn ($item) => $this->resolve($item, $variables),
            $array
        );
    }

    /**
     * Build variable context from execution data
     *
     * @param  array  $executionData  Data from agent execution or input trigger
     * @return array Variables ready for template resolution
     */
    public function buildContext(array $executionData): array
    {
        return [
            'result' => $executionData['result'] ?? '',
            'session_id' => $executionData['session_id'] ?? null,
            'execution_id' => $executionData['execution_id'] ?? null,
            'user_id' => $executionData['user_id'] ?? null,
            'agent_id' => $executionData['agent_id'] ?? null,
            'agent_name' => $executionData['agent_name'] ?? null,
            'trigger_id' => $executionData['trigger_id'] ?? null,
            'trigger_name' => $executionData['trigger_name'] ?? null,
            'timestamp' => $executionData['timestamp'] ?? now()->toIso8601String(),
            'status' => $executionData['status'] ?? 'success',
        ];
    }

    /**
     * Get available template variables and their descriptions
     *
     * @return array List of available variables with descriptions
     */
    public function getAvailableVariables(): array
    {
        return [
            'result' => 'The agent execution result or input trigger response',
            'session_id' => 'The chat session ID',
            'execution_id' => 'The unique execution ID',
            'user_id' => 'The user who triggered the action',
            'agent_id' => 'The agent ID (if triggered by agent)',
            'agent_name' => 'The agent name (if triggered by agent)',
            'trigger_id' => 'The input trigger ID (if triggered by input)',
            'trigger_name' => 'The input trigger name (if triggered by input)',
            'timestamp' => 'ISO 8601 timestamp of execution',
            'status' => 'Execution status (success/failed)',
        ];
    }
}

<?php

namespace App\Services\InputTrigger;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Payload Template Processor
 *
 * Unified template processor for both input triggers (webhooks/API) and scheduled triggers.
 * Supports dynamic mapping of webhook payloads and scheduled trigger context to agent inputs.
 *
 * Template Syntax:
 * - {{payload}} - Entire payload/context as JSON
 * - {{payload.field}} - Nested field access (dot notation)
 * - {{key}} - Flat key access (for scheduled triggers: date, time, trigger_id, etc.)
 * - {{key|default:"fallback"}} - Default value if field missing/empty
 *
 * Use Cases:
 * 1. Input Triggers (Webhooks): Use {{payload.field}} for webhook data
 * 2. Scheduled Triggers: Use {{date}}, {{time}}, {{trigger_id}}, or custom placeholders
 *
 * Examples:
 * - "Research: {{payload.topic}}" → "Research: AI" (webhook trigger)
 * - "Daily report for {{date}}" → "Daily report for 2026-01-03" (scheduled trigger)
 * - "{{payload}}" → Entire context as formatted JSON
 *
 * @see \App\Services\InputTrigger\TriggerExecutor
 * @see \PromptlyAgentAI\ScheduleIntegration\Services\PlaceholderResolver
 */
class PayloadTemplateProcessor
{
    /**
     * Process template string with payload data
     *
     * @param  string  $template  Template string with {{payload}} placeholders
     * @param  array  $payload  Payload data from webhook/API request
     * @return string Processed string with placeholders replaced
     */
    public function process(string $template, array $payload): string
    {
        // No placeholders - return as-is
        if (! str_contains($template, '{{')) {
            return $template;
        }

        // Find all placeholders: {{...}}
        preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);

        if (empty($matches[0])) {
            return $template;
        }

        $result = $template;

        foreach ($matches[1] as $index => $placeholder) {
            $fullMatch = $matches[0][$index]; // {{payload.field}}
            $trimmed = trim($placeholder);    // payload.field

            try {
                $replacement = $this->resolvePlaceholder($trimmed, $payload);
                $result = str_replace($fullMatch, $replacement, $result);

                Log::debug('PayloadTemplateProcessor: Replaced placeholder', [
                    'placeholder' => $fullMatch,
                    'value' => $replacement,
                ]);
            } catch (\Exception $e) {
                Log::warning('PayloadTemplateProcessor: Failed to resolve placeholder', [
                    'placeholder' => $fullMatch,
                    'error' => $e->getMessage(),
                ]);

                // Keep original placeholder on error
            }
        }

        return $result;
    }

    /**
     * Process multiple template strings (for command parameters)
     *
     * @param  array  $templates  Array of template strings
     * @param  array  $payload  Payload data
     * @return array Processed array with placeholders replaced
     */
    public function processArray(array $templates, array $payload): array
    {
        $result = [];

        foreach ($templates as $key => $value) {
            if (is_string($value)) {
                $result[$key] = $this->process($value, $payload);
            } elseif (is_array($value)) {
                $result[$key] = $this->processArray($value, $payload);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Resolve placeholder to actual value
     *
     * Supports three resolution patterns:
     * 1. {{payload}} - Entire context as JSON
     * 2. {{payload.field}} - Nested field access (dot notation)
     * 3. {{key}} - Flat key access (for scheduled triggers)
     *
     * @param  string  $placeholder  Placeholder expression (without {{ }})
     * @param  array  $payload  Payload/context data
     * @return string Resolved value
     */
    protected function resolvePlaceholder(string $placeholder, array $payload): string
    {
        // Parse default value syntax: "key|default:fallback"
        $defaultValue = null;
        if (str_contains($placeholder, '|default:')) {
            [$placeholder, $defaultPart] = explode('|default:', $placeholder, 2);
            $placeholder = trim($placeholder);
            $defaultValue = trim($defaultPart, '\'"');
        }

        // Handle {{payload}} - entire payload
        if ($placeholder === 'payload') {
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        // Handle {{payload.field}} - dot notation access
        if (str_starts_with($placeholder, 'payload.')) {
            $path = substr($placeholder, 8); // Remove "payload."
            $value = Arr::get($payload, $path);

            // Return value or default
            if ($value === null || $value === '') {
                return $defaultValue ?? '';
            }

            // Convert arrays/objects to JSON
            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_SLASHES);
            }

            return (string) $value;
        }

        // Handle {{key}} - flat key access (for scheduled triggers)
        // Look up the key directly in the payload/context
        if (isset($payload[$placeholder])) {
            $value = $payload[$placeholder];

            // Convert arrays/objects to JSON
            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_SLASHES);
            }

            return (string) $value;
        }

        // Key not found - return default or empty string
        if ($defaultValue !== null) {
            return $defaultValue;
        }

        // Log warning for missing key
        Log::debug('PayloadTemplateProcessor: Key not found in context', [
            'placeholder' => $placeholder,
            'available_keys' => array_keys($payload),
        ]);

        return '';
    }

    /**
     * Validate template syntax
     *
     * Validates placeholder syntax for both webhook triggers and scheduled triggers.
     * Allows: {{payload}}, {{payload.field}}, and {{key}} formats.
     *
     * @param  string  $template  Template string to validate
     * @return array{valid: bool, errors: array<string>}
     */
    public function validate(string $template): array
    {
        $errors = [];

        // Check for basic syntax issues
        if (substr_count($template, '{{') !== substr_count($template, '}}')) {
            $errors[] = 'Unmatched placeholder braces - ensure all {{placeholders}} are properly closed';
        }

        // Check for nested placeholders (not supported)
        if (preg_match('/\{\{[^}]*\{\{/', $template)) {
            $errors[] = 'Nested placeholders are not supported';
        }

        // Check for empty placeholders
        if (preg_match('/\{\{\s*\}\}/', $template)) {
            $errors[] = 'Empty placeholders found - {{}} is not valid';
        }

        // Find all placeholders
        preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);

        foreach ($matches[1] as $placeholder) {
            $trimmed = trim($placeholder);

            // Parse out default value syntax
            if (str_contains($trimmed, '|default:')) {
                [$trimmed] = explode('|default:', $trimmed, 2);
                $trimmed = trim($trimmed);
            }

            // Check for double dots or trailing dots in dot notation
            if (str_contains($trimmed, '..') || (str_contains($trimmed, '.') && str_ends_with($trimmed, '.'))) {
                $errors[] = "Invalid placeholder syntax: {{{{{$placeholder}}}}} - check for double or trailing dots";
            }

            // Check for invalid characters (allow alphanumeric, underscore, dot)
            if (! preg_match('/^[a-zA-Z0-9_.]+$/', $trimmed)) {
                $errors[] = "Invalid placeholder characters: {{{{{$placeholder}}}}} - only alphanumeric, underscore, and dot allowed";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Check if template contains placeholders
     *
     * @param  string  $template  Template string to check
     * @return bool True if template contains any {{...}} placeholders
     */
    public function hasPlaceholders(string $template): bool
    {
        return str_contains($template, '{{');
    }

    /**
     * Extract placeholder field names from template
     *
     * @param  string  $template  Template string
     * @return array<string> List of payload field paths referenced
     */
    public function extractFields(string $template): array
    {
        preg_match_all('/\{\{payload\.([^}|]+)/', $template, $matches);

        return array_unique(array_map('trim', $matches[1] ?? []));
    }

    /**
     * Extract all placeholder keys from template
     *
     * Returns all placeholder keys found in the template, including both
     * flat keys (date, time) and nested keys (payload.field).
     *
     * @param  string  $template  Template string
     * @return array<string> List of all placeholder keys
     */
    public function extractAllKeys(string $template): array
    {
        preg_match_all('/\{\{([^}|]+)/', $template, $matches);

        return array_unique(array_map('trim', $matches[1] ?? []));
    }
}

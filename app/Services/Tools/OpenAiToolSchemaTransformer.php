<?php

namespace App\Services\Tools;

use Illuminate\Support\Facades\Log;

class OpenAiToolSchemaTransformer
{
    /**
     * Transform Prism tool definitions to OpenAI function calling format
     */
    public function transformTools(array $tools): array
    {
        $transformedTools = [];

        foreach ($tools as $tool) {
            try {
                $transformedTool = $this->transformSingleTool($tool);
                if ($transformedTool) {
                    $transformedTools[] = $transformedTool;
                }
            } catch (\Exception $e) {
                Log::error('OpenAiToolSchemaTransformer: Failed to transform tool', [
                    'tool_class' => get_class($tool),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $transformedTools;
    }

    /**
     * Transform a single Prism tool to OpenAI format
     */
    protected function transformSingleTool($tool): ?array
    {
        try {
            // Get tool metadata
            $name = method_exists($tool, 'name') ? $tool->name() : null;
            $description = method_exists($tool, 'description') ? $tool->description() : null;
            $parameters = method_exists($tool, 'parameters') ? $tool->parameters() : [];

            if (! $name || ! $description) {
                Log::warning('OpenAiToolSchemaTransformer: Tool missing required methods', [
                    'tool_class' => get_class($tool),
                    'has_name' => method_exists($tool, 'name'),
                    'has_description' => method_exists($tool, 'description'),
                ]);

                return null;
            }

            // Transform parameters to OpenAI schema format
            $transformedParameters = $this->transformParameters($parameters);

            $transformedTool = [
                'type' => 'function',
                'function' => [
                    'name' => $name,
                    'description' => $description,
                    'parameters' => $transformedParameters,
                ],
            ];

            Log::debug('OpenAiToolSchemaTransformer: Successfully transformed tool', [
                'tool_name' => $name,
                'original_params_count' => count($parameters),
                'transformed_params_count' => isset($transformedParameters['properties']) ? count($transformedParameters['properties']) : 0,
            ]);

            return $transformedTool;

        } catch (\Exception $e) {
            Log::error('OpenAiToolSchemaTransformer: Error transforming single tool', [
                'tool_class' => get_class($tool),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Transform Prism parameters to OpenAI JSON schema format
     */
    protected function transformParameters(array $parameters): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [],
            'required' => [],
        ];

        foreach ($parameters as $paramName => $paramDef) {
            try {
                $transformedParam = $this->transformParameter($paramDef);
                if ($transformedParam) {
                    $schema['properties'][$paramName] = $transformedParam;

                    // Add to required if not optional
                    if ($this->isParameterRequired($paramDef)) {
                        $schema['required'][] = $paramName;
                    }
                }
            } catch (\Exception $e) {
                Log::warning('OpenAiToolSchemaTransformer: Failed to transform parameter', [
                    'param_name' => $paramName,
                    'param_def' => json_encode($paramDef),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $schema;
    }

    /**
     * Transform a single parameter definition
     */
    protected function transformParameter($paramDef): ?array
    {
        // Handle Prism schema objects
        if (is_object($paramDef)) {
            return $this->transformSchemaObject($paramDef);
        }

        // Handle array format with Prism schema class names
        if (is_array($paramDef)) {
            return $this->transformArrayParameter($paramDef);
        }

        // Handle string definitions
        if (is_string($paramDef)) {
            return [
                'type' => 'string',
                'description' => $paramDef,
            ];
        }

        Log::warning('OpenAiToolSchemaTransformer: Unknown parameter definition format', [
            'param_def_type' => gettype($paramDef),
            'param_def' => json_encode($paramDef),
        ]);

        return null;
    }

    /**
     * Transform Prism schema objects to OpenAI format
     */
    protected function transformSchemaObject($schemaObject): ?array
    {
        $className = get_class($schemaObject);

        // Extract properties from the schema object
        $name = method_exists($schemaObject, 'name') ? $schemaObject->name() : null;
        $description = method_exists($schemaObject, 'description') ? $schemaObject->description() : null;
        $nullable = method_exists($schemaObject, 'nullable') ? $schemaObject->nullable() : false;

        switch ($className) {
            case 'Prism\\Prism\\Schema\\StringSchema':
                return [
                    'type' => 'string',
                    'description' => $description ?: 'String parameter',
                ];

            case 'Prism\\Prism\\Schema\\NumberSchema':
                return [
                    'type' => 'number',
                    'description' => $description ?: 'Number parameter',
                ];

            case 'Prism\\Prism\\Schema\\NumberSchema':
                return [
                    'type' => 'number',
                    'description' => $description ?: 'Integer parameter',
                ];

            case 'Prism\\Prism\\Schema\\BooleanSchema':
                return [
                    'type' => 'boolean',
                    'description' => $description ?: 'Boolean parameter',
                ];

            case 'Prism\\Prism\\Schema\\ArraySchema':
                return $this->transformArraySchema($schemaObject);

            default:
                Log::warning('OpenAiToolSchemaTransformer: Unknown schema class', [
                    'class_name' => $className,
                    'name' => $name,
                    'description' => $description,
                ]);

                // Generic fallback
                return [
                    'type' => 'string',
                    'description' => $description ?: 'Unknown parameter type',
                ];
        }
    }

    /**
     * Transform array parameter definitions
     */
    protected function transformArrayParameter(array $paramDef): ?array
    {
        // Check if this contains a Prism schema class reference
        foreach ($paramDef as $key => $value) {
            if (is_string($key) && str_contains($key, 'Prism\\Prism\\Schema\\')) {
                // This is a malformed parameter with Prism class name as key
                $schemaType = $this->extractSchemaTypeFromClassName($key);

                if (is_array($value)) {
                    return [
                        'type' => $schemaType,
                        'description' => $value['description'] ?? 'Parameter',
                    ];
                }
            }
        }

        // Handle normal array definitions
        if (isset($paramDef['type'])) {
            $result = [
                'type' => $paramDef['type'],
                'description' => $paramDef['description'] ?? 'Parameter',
            ];

            if (isset($paramDef['items'])) {
                $result['items'] = $this->transformParameter($paramDef['items']);
            }

            return $result;
        }

        return null;
    }

    /**
     * Transform Prism ArraySchema to OpenAI format
     */
    protected function transformArraySchema($arraySchema): array
    {
        $result = [
            'type' => 'array',
            'description' => method_exists($arraySchema, 'description') ? $arraySchema->description() : 'Array parameter',
        ];

        // Try to get item schema
        if (method_exists($arraySchema, 'items') || method_exists($arraySchema, 'getItems')) {
            try {
                $itemSchema = method_exists($arraySchema, 'items') ? $arraySchema->items() : $arraySchema->getItems();
                if ($itemSchema) {
                    $result['items'] = $this->transformSchemaObject($itemSchema);
                }
            } catch (\Exception $e) {
                Log::warning('OpenAiToolSchemaTransformer: Failed to get array item schema', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Extract schema type from Prism class name
     */
    protected function extractSchemaTypeFromClassName(string $className): string
    {
        if (str_contains($className, 'StringSchema')) {
            return 'string';
        } elseif (str_contains($className, 'NumberSchema')) {
            return 'number';
        } elseif (str_contains($className, 'BooleanSchema')) {
            return 'boolean';
        } elseif (str_contains($className, 'ArraySchema')) {
            return 'array';
        }

        return 'string'; // fallback
    }

    /**
     * Determine if a parameter is required
     */
    protected function isParameterRequired($paramDef): bool
    {
        // Handle Prism schema objects
        if (is_object($paramDef)) {
            if (method_exists($paramDef, 'nullable')) {
                return ! $paramDef->nullable();
            }
        }

        // Handle array definitions
        if (is_array($paramDef)) {
            return ! ($paramDef['nullable'] ?? false) && ! ($paramDef['optional'] ?? false);
        }

        // Default to optional for safety
        return false;
    }

    /**
     * Validate that transformed tools are valid OpenAI format
     */
    public function validateTransformedTools(array $transformedTools): bool
    {
        foreach ($transformedTools as $tool) {
            if (! $this->isValidOpenAiTool($tool)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a tool definition is valid OpenAI format
     */
    protected function isValidOpenAiTool(array $tool): bool
    {
        // Must have type and function
        if (! isset($tool['type']) || $tool['type'] !== 'function') {
            return false;
        }

        if (! isset($tool['function'])) {
            return false;
        }

        $function = $tool['function'];

        // Function must have name and description
        if (! isset($function['name']) || ! isset($function['description'])) {
            return false;
        }

        // Parameters must be valid JSON schema
        if (isset($function['parameters'])) {
            $params = $function['parameters'];
            if (! isset($params['type']) || $params['type'] !== 'object') {
                return false;
            }

            if (! isset($params['properties']) || ! is_array($params['properties'])) {
                return false;
            }
        }

        return true;
    }
}

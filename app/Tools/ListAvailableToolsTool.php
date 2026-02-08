<?php

namespace App\Tools;

use App\Services\Agents\ToolRegistry;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

/**
 * ListAvailableToolsTool - Discover Available Agent Tools
 *
 * Prism tool for listing all available tools from the ToolRegistry.
 * Provides detailed information about each tool including name, description,
 * category, and configuration options.
 *
 * Use Cases:
 * - Discovering available tools for agent configuration
 * - Understanding tool capabilities and categories
 * - Building agent tool assignments interactively
 * - Documentation and tool browsing
 *
 * Response Data:
 * - Tool identifier (key used in agent configuration)
 * - Tool name and description
 * - Category (search, knowledge, content, artifacts, etc.)
 * - Class name for reference
 *
 * @see \App\Services\Agents\ToolRegistry
 */
class ListAvailableToolsTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('list_available_tools')
            ->for('List all available tools that can be assigned to agents. Returns tool identifiers, names, descriptions, and categories. Use this to discover what tools are available when configuring a new agent.')
            ->withStringParameter('category', 'Filter by category: search, knowledge, content, artifacts, context, system, github, validation, diagram (optional)')
            ->using(function (?string $category = null) {
                return static::executeListTools($category);
            });
    }

    protected static function executeListTools(?string $category): string
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
                $statusReporter->report('list_tools', 'Listing available agent tools', true, false);
            }

            // Get ToolRegistry instance
            $registry = app(ToolRegistry::class);

            // Use reflection to access protected availableTools property
            $reflection = new \ReflectionClass($registry);
            $property = $reflection->getProperty('availableTools');
            $property->setAccessible(true);
            $availableTools = $property->getValue($registry);

            // Filter by category if provided
            if ($category) {
                $availableTools = array_filter($availableTools, function ($tool) use ($category) {
                    return isset($tool['category']) && $tool['category'] === $category;
                });
            }

            // Format tools for response
            $tools = [];
            foreach ($availableTools as $identifier => $tool) {
                $tools[] = [
                    'identifier' => $identifier,
                    'name' => $tool['name'] ?? $identifier,
                    'description' => $tool['description'] ?? '',
                    'category' => $tool['category'] ?? 'general',
                    'class' => $tool['class'] ?? 'Unknown',
                ];
            }

            // Sort by category, then name
            usort($tools, function ($a, $b) {
                $categoryCompare = strcmp($a['category'], $b['category']);

                return $categoryCompare !== 0 ? $categoryCompare : strcmp($a['name'], $b['name']);
            });

            // Group by category for better organization
            $groupedTools = [];
            foreach ($tools as $tool) {
                $cat = $tool['category'];
                if (! isset($groupedTools[$cat])) {
                    $groupedTools[$cat] = [];
                }
                $groupedTools[$cat][] = $tool;
            }

            if ($statusReporter) {
                $categoryCount = count($groupedTools);
                $toolCount = count($tools);
                $statusReporter->report('list_tools', "Found {$toolCount} tools across {$categoryCount} categories", true, false);
            }

            Log::info('ListAvailableToolsTool: Listed tools', [
                'interaction_id' => $interactionId,
                'category_filter' => $category,
                'total_tools' => count($tools),
                'categories' => array_keys($groupedTools),
            ]);

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'total_tools' => count($tools),
                    'categories' => array_keys($groupedTools),
                    'tools' => $tools,
                    'tools_by_category' => $groupedTools,
                ],
            ], 'ListAvailableToolsTool');

        } catch (\Exception $e) {
            Log::error('ListAvailableToolsTool: Exception during execution', [
                'interaction_id' => $interactionId ?? null,
                'category' => $category,
                'error_message' => $e->getMessage(),
                'error_type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'Failed to list tools: '.$e->getMessage(),
            ], 'ListAvailableToolsTool');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

/**
 * ListGitHubLabelsTool - GitHub Labels Retrieval with Caching.
 *
 * Prism tool for fetching available labels from the GitHub repository. Enables agents
 * to see what labels exist before creating or updating issues, ensuring proper categorization.
 *
 * Integration Requirements:
 * - Active GitHub integration with repo access
 * - Integration token with repo scope
 * - Repository read access
 *
 * Features:
 * - Lists all available labels with names, descriptions, and colors
 * - 1-hour cache to minimize API calls
 * - Formatted output for easy reference
 *
 * Response Data:
 * - Label names
 * - Label descriptions
 * - Label colors (hex codes)
 * - Total count
 *
 * Use Cases:
 * - Checking available labels before creating/updating issues
 * - Understanding repository label conventions
 * - Ensuring consistent issue categorization
 * - Discovering relevant labels for bug reports
 *
 * @see \App\Tools\CreateGitHubIssueTool
 * @see \App\Tools\UpdateGitHubIssueTool
 * @see \App\Tools\ListGitHubMilestonesTool
 */
class ListGitHubLabelsTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('list_github_labels')
            ->for('Get all available labels from the GitHub repository. Use this before creating or updating issues to see what labels are available. Results are cached for 1 hour.')
            ->using(function () {
                return static::executeListLabels();
            });
    }

    protected static function executeListLabels(): string
    {
        try {
            // Validate GitHub is configured
            if (! config('github.bug_report.enabled')) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub integration is not enabled on this instance.',
                ], 'ListGitHubLabelsTool');
            }

            if (! config('github.bug_report.token') || ! config('github.bug_report.repository')) {
                Log::error('GitHub configuration incomplete');

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub integration is not properly configured. Please contact an administrator.',
                ], 'ListGitHubLabelsTool');
            }

            // Parse repository owner and name
            [$owner, $repo] = explode('/', config('github.bug_report.repository'));

            // Cache key based on repository
            $cacheKey = "github_labels_{$owner}_{$repo}";

            // Try to get from cache (1 hour TTL)
            $labels = Cache::remember($cacheKey, 3600, function () use ($owner, $repo) {
                // Fetch labels from GitHub API
                $response = Http::withToken(config('github.bug_report.token'))
                    ->get("https://api.github.com/repos/{$owner}/{$repo}/labels", [
                        'per_page' => 100, // Get up to 100 labels
                    ]);

                if (! $response->successful()) {
                    Log::error('Failed to fetch GitHub labels', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                }

                return $response->json();
            });

            if ($labels === null) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Failed to fetch labels from GitHub. Please check your repository access and try again.',
                ], 'ListGitHubLabelsTool');
            }

            // Format labels for output
            $formattedLabels = array_map(function ($label) {
                return [
                    'name' => $label['name'],
                    'description' => $label['description'] ?? '',
                    'color' => $label['color'],
                ];
            }, $labels);

            // Build success message
            $message = "📋 **Available GitHub Labels** ({$owner}/{$repo})\n\n";
            $message .= '**Total Labels**: '.count($formattedLabels)."\n\n";

            // Group by common prefixes for better readability
            $grouped = [];
            foreach ($formattedLabels as $label) {
                $prefix = 'other';
                if (str_contains($label['name'], ':')) {
                    $prefix = explode(':', $label['name'])[0];
                } elseif (preg_match('/^(bug|feature|enhancement|documentation|question|help|good|wontfix|invalid|duplicate)/', $label['name'])) {
                    $prefix = 'standard';
                }
                $grouped[$prefix][] = $label;
            }

            foreach ($grouped as $prefix => $labels) {
                if ($prefix !== 'other' && $prefix !== 'standard') {
                    $message .= '**'.ucfirst($prefix).":**\n";
                }
                foreach ($labels as $label) {
                    $message .= "- `{$label['name']}`";
                    if (! empty($label['description'])) {
                        $message .= " - {$label['description']}";
                    }
                    $message .= "\n";
                }
                $message .= "\n";
            }

            $message .= '*Labels cached for 1 hour to minimize API calls*';

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'labels' => $formattedLabels,
                    'count' => count($formattedLabels),
                    'repository' => "{$owner}/{$repo}",
                ],
                'message' => $message,
            ], 'ListGitHubLabelsTool');

        } catch (\Exception $e) {
            Log::error('Error fetching GitHub labels', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'An unexpected error occurred while fetching labels. Please try again or contact support.',
            ], 'ListGitHubLabelsTool');
        }
    }
}

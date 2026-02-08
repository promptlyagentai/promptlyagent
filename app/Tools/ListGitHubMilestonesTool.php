<?php

declare(strict_types=1);

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

/**
 * ListGitHubMilestonesTool - GitHub Milestones Retrieval with Caching.
 *
 * Prism tool for fetching available milestones from the GitHub repository. Enables agents
 * to see what milestones exist before creating or updating issues, ensuring proper project tracking.
 *
 * Integration Requirements:
 * - Active GitHub integration with repo access
 * - Integration token with repo scope
 * - Repository read access
 *
 * Features:
 * - Lists all open and closed milestones
 * - Shows title, description, due date, and progress
 * - 1-hour cache to minimize API calls
 * - Formatted output for easy reference
 *
 * Response Data:
 * - Milestone titles and numbers
 * - Descriptions and due dates
 * - Progress (open/closed issues)
 * - State (open/closed)
 *
 * Use Cases:
 * - Checking available milestones before updating issues
 * - Understanding project timeline and structure
 * - Assigning issues to appropriate milestones
 * - Tracking project progress
 *
 * @see \App\Tools\UpdateGitHubIssueTool
 * @see \App\Tools\ListGitHubLabelsTool
 */
class ListGitHubMilestonesTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('list_github_milestones')
            ->for('Get all available milestones from the GitHub repository. Use this before updating issues to see what milestones are available. Shows open and closed milestones with progress. Results are cached for 1 hour.')
            ->withStringParameter('state', 'Filter by milestone state: "open" (default), "closed", or "all"', false)
            ->using(function (?string $state = 'open') {
                return static::executeListMilestones($state ?? 'open');
            });
    }

    protected static function executeListMilestones(string $state): string
    {
        try {
            // Validate state parameter
            if (! in_array($state, ['open', 'closed', 'all'])) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid state parameter. Must be "open", "closed", or "all".',
                ], 'ListGitHubMilestonesTool');
            }

            // Validate GitHub is configured
            if (! config('github.bug_report.enabled')) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub integration is not enabled on this instance.',
                ], 'ListGitHubMilestonesTool');
            }

            if (! config('github.bug_report.token') || ! config('github.bug_report.repository')) {
                Log::error('GitHub configuration incomplete');

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub integration is not properly configured. Please contact an administrator.',
                ], 'ListGitHubMilestonesTool');
            }

            // Parse repository owner and name
            [$owner, $repo] = explode('/', config('github.bug_report.repository'));

            // Cache key based on repository and state
            $cacheKey = "github_milestones_{$owner}_{$repo}_{$state}";

            // Try to get from cache (1 hour TTL)
            $milestones = Cache::remember($cacheKey, 3600, function () use ($owner, $repo, $state) {
                // Fetch milestones from GitHub API
                $response = Http::withToken(config('github.bug_report.token'))
                    ->get("https://api.github.com/repos/{$owner}/{$repo}/milestones", [
                        'state' => $state,
                        'per_page' => 100, // Get up to 100 milestones
                    ]);

                if (! $response->successful()) {
                    Log::error('Failed to fetch GitHub milestones', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return null;
                }

                return $response->json();
            });

            if ($milestones === null) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Failed to fetch milestones from GitHub. Please check your repository access and try again.',
                ], 'ListGitHubMilestonesTool');
            }

            // Format milestones for output
            $formattedMilestones = array_map(function ($milestone) {
                return [
                    'number' => $milestone['number'],
                    'title' => $milestone['title'],
                    'description' => $milestone['description'] ?? '',
                    'state' => $milestone['state'],
                    'due_on' => $milestone['due_on'] ?? null,
                    'open_issues' => $milestone['open_issues'],
                    'closed_issues' => $milestone['closed_issues'],
                    'progress_percent' => $milestone['open_issues'] + $milestone['closed_issues'] > 0
                        ? round(($milestone['closed_issues'] / ($milestone['open_issues'] + $milestone['closed_issues'])) * 100)
                        : 0,
                ];
            }, $milestones);

            // Build success message
            $stateLabel = ucfirst($state);
            $message = "🎯 **{$stateLabel} GitHub Milestones** ({$owner}/{$repo})\n\n";
            $message .= '**Total Milestones**: '.count($formattedMilestones)."\n\n";

            if (empty($formattedMilestones)) {
                $message .= "No {$state} milestones found.\n";
            } else {
                foreach ($formattedMilestones as $milestone) {
                    $stateIcon = $milestone['state'] === 'open' ? '🟢' : '⚫';
                    $message .= "{$stateIcon} **{$milestone['title']}** (#{$milestone['number']})\n";

                    if (! empty($milestone['description'])) {
                        $message .= "   {$milestone['description']}\n";
                    }

                    $totalIssues = $milestone['open_issues'] + $milestone['closed_issues'];
                    if ($totalIssues > 0) {
                        $message .= "   Progress: {$milestone['progress_percent']}% ({$milestone['closed_issues']}/{$totalIssues} issues closed)\n";
                    }

                    if ($milestone['due_on']) {
                        $dueDate = date('Y-m-d', strtotime($milestone['due_on']));
                        $message .= "   Due: {$dueDate}\n";
                    }

                    $message .= "\n";
                }
            }

            $message .= '*Milestones cached for 1 hour to minimize API calls*';

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'milestones' => $formattedMilestones,
                    'count' => count($formattedMilestones),
                    'repository' => "{$owner}/{$repo}",
                    'state' => $state,
                ],
                'message' => $message,
            ], 'ListGitHubMilestonesTool');

        } catch (\Exception $e) {
            Log::error('Error fetching GitHub milestones', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'An unexpected error occurred while fetching milestones. Please try again or contact support.',
            ], 'ListGitHubMilestonesTool');
        }
    }
}

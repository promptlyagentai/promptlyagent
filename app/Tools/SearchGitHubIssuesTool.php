<?php

declare(strict_types=1);

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

/**
 * SearchGitHubIssuesTool - GitHub Issue Search via Integration.
 *
 * Prism tool for searching GitHub issues through integration token. Find existing
 * issues, track status, and retrieve issue details from connected repositories.
 *
 * Integration Requirements:
 * - Active GitHub integration
 * - Integration token with repo read access
 * - Repository access permissions
 *
 * Search Capabilities:
 * - Keyword search across titles and bodies
 * - Filter by state (open, closed, all)
 * - Filter by labels
 * - Filter by assignee
 * - Sort by various criteria
 *
 * Response Data:
 * - Issue list with numbers and titles
 * - Current state and labels
 * - Author and assignee information
 * - Creation and update timestamps
 * - Issue URLs
 *
 * Use Cases:
 * - Finding existing bug reports
 * - Checking feature request status
 * - Tracking issue resolution
 * - Avoiding duplicate issues
 *
 * @see \App\Tools\Concerns\BaseIntegrationTool
 * @see \App\Tools\CreateGitHubIssueTool
 */
class SearchGitHubIssuesTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('search_github_issues')
            ->for('Search for existing GitHub issues to check for duplicates before creating a new bug report. Use this tool FIRST before creating a new issue to prevent duplicates. Returns a list of matching issues with their titles, URLs, and status.')
            ->withStringParameter('query', 'Search query to find similar issues. Use keywords from the bug title and description (e.g., "button not responding mobile")')
            ->withStringParameter('state', 'Filter by issue state: "open" (default), "closed", or "all"', false)
            ->using(function (string $query, ?string $state = 'open') {
                return static::executeSearch([
                    'query' => $query,
                    'state' => $state ?? 'open',
                ]);
            });
    }

    protected static function executeSearch(array $params): string
    {
        try {
            // Validate GitHub is configured
            if (! config('github.bug_report.enabled')) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub bug reporting is not enabled on this instance.',
                ], 'SearchGitHubIssuesTool');
            }

            if (! config('github.bug_report.token') || ! config('github.bug_report.repository')) {
                Log::error('GitHub bug report configuration incomplete');

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub bug reporting is not properly configured.',
                ], 'SearchGitHubIssuesTool');
            }

            // Parse repository owner and name
            [$owner, $repo] = explode('/', config('github.bug_report.repository'));

            // Build search query
            // Format: "query in:title,body repo:owner/repo is:issue state:open"
            $searchQuery = $params['query'];
            $state = $params['state'];

            // Use GitHub Search API
            $response = Http::withToken(config('github.bug_report.token'))
                ->get('https://api.github.com/search/issues', [
                    'q' => "{$searchQuery} in:title,body repo:{$owner}/{$repo} is:issue state:{$state}",
                    'sort' => 'relevance',
                    'order' => 'desc',
                    'per_page' => 10,
                ]);

            if (! $response->successful()) {
                Log::error('Failed to search GitHub issues', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Failed to search GitHub issues. Please try again.',
                ], 'SearchGitHubIssuesTool');
            }

            $data = $response->json();
            $totalCount = $data['total_count'] ?? 0;
            $items = $data['items'] ?? [];

            if ($totalCount === 0) {
                return static::safeJsonEncode([
                    'success' => true,
                    'data' => [
                        'total_count' => 0,
                        'issues' => [],
                    ],
                    'message' => '✅ No similar issues found. This appears to be a new bug report.',
                ], 'SearchGitHubIssuesTool');
            }

            // Format results
            $formattedIssues = collect($items)->map(function ($issue) {
                return [
                    'number' => $issue['number'],
                    'title' => $issue['title'],
                    'state' => $issue['state'],
                    'url' => $issue['html_url'],
                    'labels' => collect($issue['labels'] ?? [])->pluck('name')->toArray(),
                    'created_at' => $issue['created_at'],
                    'updated_at' => $issue['updated_at'],
                    'user' => $issue['user']['login'] ?? 'unknown',
                ];
            })->toArray();

            Log::info('GitHub issue search completed', [
                'query' => $params['query'],
                'total_count' => $totalCount,
                'results_returned' => count($formattedIssues),
            ]);

            // Build user-friendly message
            $message = "🔍 **Found {$totalCount} similar issue(s)**:\n\n";

            foreach ($formattedIssues as $idx => $issue) {
                $num = $idx + 1;
                $statusEmoji = $issue['state'] === 'open' ? '🟢' : '🔴';
                $labels = ! empty($issue['labels']) ? ' ['.implode(', ', $issue['labels']).']' : '';

                $message .= "{$num}. {$statusEmoji} **{$issue['title']}**{$labels}\n";
                $message .= "   - Issue #{$issue['number']} ({$issue['state']})\n";
                $message .= "   - URL: {$issue['url']}\n";
                $message .= "   - Reported by: @{$issue['user']}\n\n";
            }

            $message .= "---\n\n";
            $message .= "**What would you like to do?**\n";
            $message .= "- Review the similar issues above to see if your bug is already reported\n";
            $message .= "- If your bug is different, let me know and I'll create a new issue\n";
            $message .= '- If you found your bug in the list, you can add a comment to that issue instead';

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'total_count' => $totalCount,
                    'issues' => $formattedIssues,
                ],
                'message' => $message,
            ], 'SearchGitHubIssuesTool');

        } catch (\Exception $e) {
            Log::error('Error searching GitHub issues', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'An unexpected error occurred while searching for issues.',
            ], 'SearchGitHubIssuesTool');
        }
    }
}

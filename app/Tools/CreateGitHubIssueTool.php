<?php

declare(strict_types=1);

namespace App\Tools;

use App\Models\BugReport;
use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;

/**
 * CreateGitHubIssueTool - GitHub Issue Creation via Integration.
 *
 * Prism tool for creating GitHub issues through integration token. Enables agents
 * to report bugs, request features, or create tasks directly in GitHub repositories.
 *
 * Integration Requirements:
 * - Active GitHub integration with issue creation capability
 * - Integration token with repo scope
 * - Repository write access
 *
 * Issue Creation:
 * - Title and description (required)
 * - Labels for categorization
 * - Assignee assignment
 * - Milestone association
 *
 * Response Data:
 * - Created issue number
 * - Issue URL for viewing
 * - GitHub API response details
 *
 * Use Cases:
 * - Bug reporting workflow automation
 * - Feature request tracking
 * - Task creation from conversations
 * - Integration with project management
 *
 * @see \App\Tools\Concerns\BaseIntegrationTool
 * @see \App\Tools\SearchGitHubIssuesTool
 */
class CreateGitHubIssueTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('create_github_issue')
            ->for('Create a new GitHub issue for bug reports. Use this tool IMMEDIATELY when users explicitly ask you to report a bug, create an issue, or submit a bug report. The issue will be created with the provided title, description, and automatically formatted metadata.')
            ->withStringParameter('title', 'Short, descriptive title for the GitHub issue (e.g., "Submit button not responding on Knowledge page")')
            ->withStringParameter('description', 'Detailed description of the bug in markdown format. Include: problem description, steps to reproduce, expected vs actual behavior, and any relevant context.')
            ->withArrayParameter('labels', 'Optional additional labels to add beyond defaults (e.g., ["ui", "mobile", "urgent"]). Default labels "bug", "needs-triage", and "from-widget" are automatically added.', new StringSchema('label', 'Label name'), false)
            ->withStringParameter('screenshot_url', 'Optional URL to a screenshot of the bug (if the user attached one). This will be included in the issue body.', false)
            ->using(function (string $title, string $description, array $labels = [], ?string $screenshot_url = null) {
                return static::executeCreateIssue([
                    'title' => $title,
                    'description' => $description,
                    'labels' => $labels,
                    'screenshot_url' => $screenshot_url,
                ]);
            });
    }

    protected static function executeCreateIssue(array $params): string
    {
        try {
            // Validate GitHub is configured
            if (! config('github.bug_report.enabled')) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub bug reporting is not enabled on this instance.',
                ], 'CreateGitHubIssueTool');
            }

            if (! config('github.bug_report.token') || ! config('github.bug_report.repository')) {
                Log::error('GitHub bug report configuration incomplete');

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub bug reporting is not properly configured. Please contact an administrator.',
                ], 'CreateGitHubIssueTool');
            }

            // Get user from context (queued job context, not session auth)
            $userId = app()->has('current_user_id') ? app('current_user_id') : null;

            if (! $userId) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'User context not available. This tool must be called within an agent execution.',
                ], 'CreateGitHubIssueTool');
            }

            $user = \App\Models\User::find($userId);

            if (! $user) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'User not found for ID: '.$userId,
                ], 'CreateGitHubIssueTool');
            }

            // Create local bug report record
            $bugReport = BugReport::create([
                'user_id' => $user->id,
                'title' => $params['title'],
                'description' => $params['description'],
                'status' => 'pending',
                'metadata' => [
                    'created_via' => 'help_widget',
                    'labels' => $params['labels'],
                    'screenshot_url' => $params['screenshot_url'] ?? null,
                ],
            ]);

            // Parse repository owner and name
            [$owner, $repo] = explode('/', config('github.bug_report.repository'));

            // Build issue body with metadata
            $issueBody = static::formatIssueBody($params['description'], $user, $bugReport);

            // Merge labels
            $defaultLabels = config('github.bug_report.default_labels', ['bug', 'needs-triage', 'from-widget']);
            $labels = array_unique(array_merge($defaultLabels, $params['labels']));

            // Create GitHub issue
            $response = Http::withToken(config('github.bug_report.token'))
                ->post("https://api.github.com/repos/{$owner}/{$repo}/issues", [
                    'title' => $params['title'],
                    'body' => $issueBody,
                    'labels' => $labels,
                    'assignees' => static::getAssignees(),
                ]);

            if (! $response->successful()) {
                Log::error('Failed to create GitHub issue', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                $bugReport->update(['status' => 'failed']);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Failed to create GitHub issue. The bug report has been saved locally (ID: '.
                        $bugReport->id.') and an administrator has been notified.',
                ], 'CreateGitHubIssueTool');
            }

            $issue = $response->json();

            // Update bug report with GitHub info
            $bugReport->update([
                'status' => 'submitted',
                'github_issue_url' => $issue['html_url'],
                'github_issue_number' => $issue['number'],
            ]);

            Log::info('GitHub issue created successfully', [
                'issue_number' => $issue['number'],
                'bug_report_id' => $bugReport->id,
                'user_id' => $user->id,
            ]);

            $successMessage = "✅ **Bug Report Created Successfully!**\n\n";
            $successMessage .= "**GitHub Issue**: [#{$issue['number']}]({$issue['html_url']})\n";
            $successMessage .= "**Status**: Open\n";
            $successMessage .= '**Labels**: '.implode(', ', $labels)."\n\n";
            $successMessage .= 'The development team has been notified and will review your report soon. ';
            $successMessage .= 'You can track progress and add comments at the issue URL above.';

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'issue_number' => $issue['number'],
                    'issue_url' => $issue['html_url'],
                    'bug_report_id' => $bugReport->id,
                ],
                'message' => $successMessage,
            ], 'CreateGitHubIssueTool');

        } catch (\Exception $e) {
            Log::error('Error creating GitHub issue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (isset($bugReport)) {
                $bugReport->update(['status' => 'failed']);
            }

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'An unexpected error occurred while creating the bug report. Please try again or contact support.',
            ], 'CreateGitHubIssueTool');
        }
    }

    /**
     * Format issue body with metadata and user attribution
     */
    protected static function formatIssueBody(string $description, $user, BugReport $bugReport): string
    {
        $metadata = $bugReport->metadata ?? [];

        $body = "## Description\n\n{$description}\n\n";

        $body .= "---\n\n";
        $body .= "### Bug Report Metadata\n\n";

        // Add @mention if user has configured GitHub username
        $githubUsername = $user->preferences['help_widget']['github_username'] ?? null;
        if ($githubUsername) {
            $body .= "- **Reported by**: @{$githubUsername} ({$user->name})\n";
        } else {
            $body .= "- **Reported by**: {$user->name} ({$user->email})\n";
        }

        $body .= '- **Reported at**: '.$bugReport->created_at->format('Y-m-d H:i:s T')."\n";
        $body .= "- **Report ID**: {$bugReport->id}\n";

        if (isset($metadata['url'])) {
            $body .= "- **Page URL**: {$metadata['url']}\n";
        }

        if (isset($metadata['browser'])) {
            $body .= "- **Browser**: {$metadata['browser']}\n";
        }

        if (isset($metadata['app_version'])) {
            $body .= "- **App Version**: {$metadata['app_version']}\n";
        }

        if (isset($metadata['selected_element'])) {
            $body .= "\n### Selected Element\n\n";
            $body .= "```\n{$metadata['selected_element']}\n```\n";
        }

        if (isset($metadata['screenshot_url']) && ! empty($metadata['screenshot_url'])) {
            $body .= "\n### Screenshot\n\n";
            $body .= "![Bug Screenshot]({$metadata['screenshot_url']})\n";
        }

        $body .= "\n---\n";
        $body .= '*Reported via PromptlyAgent Help Widget*';

        return $body;
    }

    /**
     * Get assignees from config
     */
    protected static function getAssignees(): array
    {
        $assignees = config('github.bug_report.assignees', '');
        if (empty($assignees)) {
            return [];
        }

        return array_map('trim', explode(',', $assignees));
    }
}

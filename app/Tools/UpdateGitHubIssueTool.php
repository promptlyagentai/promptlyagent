<?php

declare(strict_types=1);

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\StringSchema;

/**
 * UpdateGitHubIssueTool - GitHub Issue Update via Integration.
 *
 * Prism tool for updating existing GitHub issues through integration token. Enables agents
 * to edit issue titles, descriptions, labels, and other metadata directly in GitHub repositories.
 *
 * Integration Requirements:
 * - Active GitHub integration with issue management capability
 * - Integration token with repo scope
 * - Repository write access
 *
 * Update Capabilities:
 * - Title and description editing
 * - Label addition/removal
 * - Issue state changes (open/closed)
 * - Assignee management
 *
 * Response Data:
 * - Updated issue number
 * - Issue URL for viewing
 * - GitHub API response details
 *
 * Use Cases:
 * - Updating issue details based on new information
 * - Changing issue status or priority
 * - Refining titles and descriptions
 * - Managing labels for organization
 *
 * @see \App\Tools\CreateGitHubIssueTool
 * @see \App\Tools\CommentOnGitHubIssueTool
 */
class UpdateGitHubIssueTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('update_github_issue')
            ->for('Update an existing GitHub issue. Use this tool to edit the title, description, labels, or state of a GitHub issue. Requires the issue number.')
            ->withStringParameter('issue_number', 'The GitHub issue number to update (e.g., "123" or "#123")')
            ->withStringParameter('title', 'New title for the issue (leave empty to keep current title)', false)
            ->withStringParameter('description', 'New description/body for the issue in markdown format (leave empty to keep current description)', false)
            ->withArrayParameter('labels', 'Complete list of labels to set on the issue (replaces existing labels). Leave empty to keep current labels.', new StringSchema('label', 'Label name'), false)
            ->withStringParameter('state', 'Issue state: "open" or "closed" (leave empty to keep current state)', false)
            ->using(function (string $issue_number, ?string $title = null, ?string $description = null, array $labels = [], ?string $state = null) {
                return static::executeUpdateIssue([
                    'issue_number' => $issue_number,
                    'title' => $title,
                    'description' => $description,
                    'labels' => $labels,
                    'state' => $state,
                ]);
            });
    }

    protected static function executeUpdateIssue(array $params): string
    {
        try {
            // Validate GitHub is configured
            if (! config('github.bug_report.enabled')) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub integration is not enabled on this instance.',
                ], 'UpdateGitHubIssueTool');
            }

            if (! config('github.bug_report.token') || ! config('github.bug_report.repository')) {
                Log::error('GitHub configuration incomplete');

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub integration is not properly configured. Please contact an administrator.',
                ], 'UpdateGitHubIssueTool');
            }

            // Parse issue number (remove # if present)
            $issueNumber = (int) str_replace('#', '', $params['issue_number']);

            if ($issueNumber <= 0) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid issue number. Please provide a valid issue number (e.g., "123" or "#123").',
                ], 'UpdateGitHubIssueTool');
            }

            // Parse repository owner and name
            [$owner, $repo] = explode('/', config('github.bug_report.repository'));

            // Build update payload (only include fields that are being updated)
            $updateData = [];

            if (! empty($params['title'])) {
                $updateData['title'] = $params['title'];
            }

            if (! empty($params['description'])) {
                $updateData['body'] = $params['description'];
            }

            if (! empty($params['labels'])) {
                $updateData['labels'] = $params['labels'];
            }

            if (! empty($params['state']) && in_array(strtolower($params['state']), ['open', 'closed'])) {
                $updateData['state'] = strtolower($params['state']);
            }

            // Check if there's anything to update
            if (empty($updateData)) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'No update parameters provided. Please specify at least one field to update (title, description, labels, or state).',
                ], 'UpdateGitHubIssueTool');
            }

            // Update GitHub issue
            $response = Http::withToken(config('github.bug_report.token'))
                ->patch("https://api.github.com/repos/{$owner}/{$repo}/issues/{$issueNumber}", $updateData);

            if (! $response->successful()) {
                Log::error('Failed to update GitHub issue', [
                    'issue_number' => $issueNumber,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Failed to update GitHub issue #'.$issueNumber.'. '.
                        'Status: '.$response->status().'. '.
                        'Please verify the issue exists and you have permission to edit it.',
                ], 'UpdateGitHubIssueTool');
            }

            $issue = $response->json();

            Log::info('GitHub issue updated successfully', [
                'issue_number' => $issue['number'],
                'updates' => array_keys($updateData),
            ]);

            // Build success message
            $successMessage = "✅ **GitHub Issue Updated Successfully!**\n\n";
            $successMessage .= "**Issue**: [#{$issue['number']}]({$issue['html_url']})\n";

            $updatedFields = [];
            if (isset($updateData['title'])) {
                $updatedFields[] = 'title';
            }
            if (isset($updateData['body'])) {
                $updatedFields[] = 'description';
            }
            if (isset($updateData['labels'])) {
                $updatedFields[] = 'labels';
            }
            if (isset($updateData['state'])) {
                $updatedFields[] = 'state';
            }

            $successMessage .= '**Updated**: '.implode(', ', $updatedFields)."\n";
            $successMessage .= '**Current State**: '.ucfirst($issue['state'])."\n";

            if (! empty($issue['labels'])) {
                $labelNames = array_column($issue['labels'], 'name');
                $successMessage .= '**Labels**: '.implode(', ', $labelNames)."\n";
            }

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'issue_number' => $issue['number'],
                    'issue_url' => $issue['html_url'],
                    'state' => $issue['state'],
                    'title' => $issue['title'],
                ],
                'message' => $successMessage,
            ], 'UpdateGitHubIssueTool');

        } catch (\Exception $e) {
            Log::error('Error updating GitHub issue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'An unexpected error occurred while updating the GitHub issue. Please try again or contact support.',
            ], 'UpdateGitHubIssueTool');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tools;

use App\Tools\Concerns\SafeJsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

/**
 * CommentOnGitHubIssueTool - GitHub Issue Comment via Integration.
 *
 * Prism tool for adding comments to existing GitHub issues through integration token. Enables agents
 * to provide follow-up information, status updates, clarifications, or additional details on issues.
 *
 * Integration Requirements:
 * - Active GitHub integration with issue comment capability
 * - Integration token with repo scope
 * - Repository write access
 *
 * Comment Features:
 * - Markdown-formatted comments
 * - User attribution metadata
 * - Automatic linking to conversation context
 *
 * Response Data:
 * - Comment ID
 * - Comment URL for direct access
 * - Issue number and URL
 *
 * Use Cases:
 * - Providing additional debugging information
 * - Status updates on issue progress
 * - Clarifying bug reports with follow-up questions
 * - Adding reproduction steps or workarounds
 * - Team collaboration and discussion
 *
 * @see \App\Tools\CreateGitHubIssueTool
 * @see \App\Tools\UpdateGitHubIssueTool
 */
class CommentOnGitHubIssueTool
{
    use SafeJsonResponse;

    public static function create()
    {
        return Tool::as('comment_on_github_issue')
            ->for('Add a comment to an existing GitHub issue. Use this tool to provide follow-up information, status updates, clarifications, or additional details. Requires the issue number and comment text.')
            ->withStringParameter('issue_number', 'The GitHub issue number to comment on (e.g., "123" or "#123")')
            ->withStringParameter('comment', 'The comment text in markdown format. Be clear, concise, and helpful.')
            ->using(function (string $issue_number, string $comment) {
                return static::executeAddComment([
                    'issue_number' => $issue_number,
                    'comment' => $comment,
                ]);
            });
    }

    protected static function executeAddComment(array $params): string
    {
        try {
            // Validate GitHub is configured
            if (! config('github.bug_report.enabled')) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub integration is not enabled on this instance.',
                ], 'CommentOnGitHubIssueTool');
            }

            if (! config('github.bug_report.token') || ! config('github.bug_report.repository')) {
                Log::error('GitHub configuration incomplete');

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'GitHub integration is not properly configured. Please contact an administrator.',
                ], 'CommentOnGitHubIssueTool');
            }

            // Parse issue number (remove # if present)
            $issueNumber = (int) str_replace('#', '', $params['issue_number']);

            if ($issueNumber <= 0) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Invalid issue number. Please provide a valid issue number (e.g., "123" or "#123").',
                ], 'CommentOnGitHubIssueTool');
            }

            // Validate comment is not empty
            if (empty(trim($params['comment']))) {
                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Comment cannot be empty. Please provide comment text.',
                ], 'CommentOnGitHubIssueTool');
            }

            // Get user from context (queued job context, not session auth)
            $userId = app()->has('current_user_id') ? app('current_user_id') : null;
            $user = $userId ? \App\Models\User::find($userId) : null;

            // Format comment with metadata
            $commentBody = static::formatComment($params['comment'], $user);

            // Parse repository owner and name
            [$owner, $repo] = explode('/', config('github.bug_report.repository'));

            // Create comment on GitHub issue
            $response = Http::withToken(config('github.bug_report.token'))
                ->post("https://api.github.com/repos/{$owner}/{$repo}/issues/{$issueNumber}/comments", [
                    'body' => $commentBody,
                ]);

            if (! $response->successful()) {
                Log::error('Failed to add comment to GitHub issue', [
                    'issue_number' => $issueNumber,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return static::safeJsonEncode([
                    'success' => false,
                    'error' => 'Failed to add comment to GitHub issue #'.$issueNumber.'. '.
                        'Status: '.$response->status().'. '.
                        'Please verify the issue exists and you have permission to comment.',
                ], 'CommentOnGitHubIssueTool');
            }

            $comment = $response->json();

            Log::info('GitHub issue comment added successfully', [
                'issue_number' => $issueNumber,
                'comment_id' => $comment['id'],
                'user_id' => $userId,
            ]);

            $successMessage = "✅ **Comment Added Successfully!**\n\n";
            $successMessage .= "**Issue**: [#{$issueNumber}](https://github.com/{$owner}/{$repo}/issues/{$issueNumber})\n";
            $successMessage .= "**Comment**: [View Comment]({$comment['html_url']})\n\n";
            $successMessage .= 'Your comment has been posted and the team will be notified.';

            return static::safeJsonEncode([
                'success' => true,
                'data' => [
                    'comment_id' => $comment['id'],
                    'comment_url' => $comment['html_url'],
                    'issue_number' => $issueNumber,
                    'issue_url' => "https://github.com/{$owner}/{$repo}/issues/{$issueNumber}",
                ],
                'message' => $successMessage,
            ], 'CommentOnGitHubIssueTool');

        } catch (\Exception $e) {
            Log::error('Error adding comment to GitHub issue', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return static::safeJsonEncode([
                'success' => false,
                'error' => 'An unexpected error occurred while adding the comment. Please try again or contact support.',
            ], 'CommentOnGitHubIssueTool');
        }
    }

    /**
     * Format comment with metadata and user attribution
     */
    protected static function formatComment(string $comment, $user = null): string
    {
        $body = $comment."\n\n";
        $body .= "---\n\n";

        if ($user) {
            // Add @mention if user has configured GitHub username
            $githubUsername = $user->preferences['help_widget']['github_username'] ?? null;
            if ($githubUsername) {
                $body .= "*Comment by @{$githubUsername} ({$user->name}) via PromptlyAgent*\n";
            } else {
                $body .= "*Comment by {$user->name} ({$user->email}) via PromptlyAgent*\n";
            }
        } else {
            $body .= "*Comment via PromptlyAgent*\n";
        }

        $body .= '*'.now()->format('Y-m-d H:i:s T').'*';

        return $body;
    }
}

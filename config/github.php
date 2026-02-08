<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | GitHub Bug Report Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the bug reporting feature that creates GitHub issues
    | via the interactive help widget. Uses a global bot token for issue
    | creation with optional per-user attribution via @mentions.
    |
    */

    'bug_report' => [
        /*
         * Enable or disable the bug reporting feature
         */
        'enabled' => env('GITHUB_BUG_REPORT_ENABLED', false),

        /*
         * GitHub Personal Access Token (PAT) with 'repo' scope
         * This should be a token from a dedicated bot account
         */
        'token' => env('GITHUB_BUG_REPORT_TOKEN'),

        /*
         * GitHub repository in 'owner/repo' format
         * Example: 'your-org/your-repo'
         */
        'repository' => env('GITHUB_BUG_REPORT_REPO'),

        /*
         * Default labels to add to all bug reports
         * These labels will be added to every issue created via the widget
         */
        'default_labels' => ['bug', 'needs-triage', 'from-widget'],

        /*
         * Optional comma-separated list of GitHub usernames to assign issues to
         * Example: 'user1,user2,user3'
         */
        'assignees' => env('GITHUB_BUG_REPORT_ASSIGNEES', ''),
    ],
];

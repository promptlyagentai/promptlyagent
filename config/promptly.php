<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Direct Answer Configuration
    |--------------------------------------------------------------------------
    |
    | When enabled, the Promptly Agent will attempt to answer high-confidence
    | queries directly instead of delegating to another agent.
    |
    */

    'direct_answer_enabled' => env('PROMPTLY_ENCOURAGE_DIRECT_ANSWERS', false),
];

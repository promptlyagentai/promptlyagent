<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Code Execution Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default code execution driver. Supported
    | drivers: "judge0", "eval" (unsafe, for development only)
    |
    */

    'driver' => env('CODE_EXECUTION_DRIVER', 'judge0'),

    /*
    |--------------------------------------------------------------------------
    | Judge0 Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for external Judge0 code execution service
    |
    | Options:
    | 1. Judge0 CE (Community Edition) - Self-hosted: https://github.com/judge0/judge0
    | 2. Judge0 RapidAPI - Hosted service: https://rapidapi.com/judge0-official/api/judge0-ce
    | 3. Public Instance - ce.judge0.com (for testing only, not for production)
    |
    | Set JUDGE0_URL to your Judge0 server URL
    | Set JUDGE0_API_KEY if using RapidAPI (leave empty for self-hosted)
    |
    */

    'judge0' => [
        'url' => env('JUDGE0_URL', null),
        'api_key' => env('JUDGE0_API_KEY', null),
        'timeout' => (int) env('JUDGE0_TIMEOUT', 30),
        'max_polling_attempts' => (int) env('JUDGE0_MAX_POLLING_ATTEMPTS', 10),
        'polling_interval' => (int) env('JUDGE0_POLLING_INTERVAL', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Limits
    |--------------------------------------------------------------------------
    |
    | Default resource limits for code execution
    |
    */

    'limits' => [
        'cpu_time' => (int) env('CODE_EXECUTION_CPU_TIME_LIMIT', 5),
        'memory' => (int) env('CODE_EXECUTION_MEMORY_LIMIT', 256000),
        'wall_time' => (int) env('CODE_EXECUTION_WALL_TIME_LIMIT', 10),
        'max_output_size' => (int) env('CODE_EXECUTION_MAX_OUTPUT_SIZE', 10240),
    ],

    /*
    |--------------------------------------------------------------------------
    | Language Mappings
    |--------------------------------------------------------------------------
    |
    | Map file extensions to Judge0 language IDs
    | See: https://ce.judge0.com/#statuses-and-languages-languages
    |
    */

    'language_ids' => [
        'php' => 68,
        'py' => 71,
        'python' => 71,
        'js' => 63,
        'javascript' => 63,
        'java' => 62,
        'c' => 50,
        'cpp' => 54,
        'cs' => 51,
        'rb' => 72,
        'ruby' => 72,
        'go' => 60,
        'rs' => 73,
        'rust' => 73,
        'swift' => 83,
        'kt' => 78,
        'kotlin' => 78,
        'sql' => 82,
        'bash' => 46,
        'sh' => 46,
    ],

];

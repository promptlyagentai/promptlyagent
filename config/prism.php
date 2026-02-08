<?php

return [
    'prism_server' => [
        'middleware' => [],
        'enabled' => env('PRISM_SERVER_ENABLED', false),
    ],
    'providers' => [
        'openai' => [
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY', ''),
            'organization' => env('OPENAI_ORGANIZATION', null),
            'project' => env('OPENAI_PROJECT', null),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY', ''),
            'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
            'default_thinking_budget' => env('ANTHROPIC_DEFAULT_THINKING_BUDGET', 1024),
            'anthropic_beta' => env('ANTHROPIC_BETA', null),
        ],
        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],
        'mistral' => [
            'api_key' => env('MISTRAL_API_KEY', ''),
            'url' => env('MISTRAL_URL', 'https://api.mistral.ai/v1'),
        ],
        'groq' => [
            'api_key' => env('GROQ_API_KEY', ''),
            'url' => env('GROQ_URL', 'https://api.groq.com/openai/v1'),
        ],
        'xai' => [
            'api_key' => env('XAI_API_KEY', ''),
            'url' => env('XAI_URL', 'https://api.x.ai/v1'),
        ],
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY', ''),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/models'),
        ],
        'deepseek' => [
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'url' => env('DEEPSEEK_URL', 'https://api.deepseek.com/v1'),
        ],
        'voyageai' => [
            'api_key' => env('VOYAGEAI_API_KEY', ''),
            'url' => env('VOYAGEAI_URL', 'https://api.voyageai.com/v1'),
        ],
        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY', ''),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        ],
        'bedrock' => [
            'region' => env('AWS_REGION', 'us-east-1'),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIAL_PROVIDER', false),
            'api_key' => env('AWS_ACCESS_KEY_ID'),
            'api_secret' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Model Profiles
    |--------------------------------------------------------------------------
    |
    | Define standardized model configurations for different complexity tiers.
    | This centralizes model selection and allows easy configuration via
    | environment variables while following the DRY principle.
    |
    */

    'model_profiles' => [
        'low_cost' => [
            'provider' => env('AI_LOW_COST_PROVIDER', 'openai'),
            'model' => env('AI_LOW_COST_MODEL', 'gpt-4o-mini'),
            'max_tokens' => (int) env('AI_LOW_COST_MAX_TOKENS', 512),
            'description' => 'Fast, cost-efficient model for simple tasks like summaries, titles, and basic analysis',
        ],
        'medium' => [
            'provider' => env('AI_MEDIUM_PROVIDER', 'openai'),
            'model' => env('AI_MEDIUM_MODEL', 'gpt-4o'),
            'max_tokens' => (int) env('AI_MEDIUM_MAX_TOKENS', 1024),
            'description' => 'Balanced model for standard complexity tasks requiring good reasoning',
        ],
        'complex' => [
            'provider' => env('AI_COMPLEX_PROVIDER', 'openai'),
            'model' => env('AI_COMPLEX_MODEL', 'gpt-4'),
            'max_tokens' => (int) env('AI_COMPLEX_MAX_TOKENS', 2048),
            'description' => 'Advanced model for complex reasoning, analysis, and problem-solving tasks',
        ],
    ],
];

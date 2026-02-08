<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Knowledge System Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for the knowledge management system including
    | file processing limits, allowed types, and system behavior.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | File Processing Limits
    |--------------------------------------------------------------------------
    |
    | SECURITY: Limits prevent resource exhaustion, memory issues, and excessive
    | AI API costs. Reduced from 1MB to 100KB for text content.
    */

    'max_file_size' => env('KNOWLEDGE_MAX_FILE_SIZE', 50 * 1024 * 1024),
    'max_text_length' => env('KNOWLEDGE_MAX_TEXT_LENGTH', 100000),
    'max_screenshot_size' => env('KNOWLEDGE_MAX_SCREENSHOT_SIZE', 512000),
    'large_document_warning_threshold' => 50000,

    /*
    |--------------------------------------------------------------------------
    | Allowed File Types
    |--------------------------------------------------------------------------
    |
    | MIME types that are allowed for file uploads to the knowledge system.
    |
    */

    'allowed_file_types' => [
        'text/plain',
        'text/markdown',
        'text/html',
        'text/csv',
        'application/json',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        // Archive formats
        'application/zip',
        'application/x-zip-compressed',
        'application/gzip',
        'application/x-gzip',
        'application/x-tar',
        'application/x-compressed-tar',
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    */

    'processing' => [
        'queue' => env('KNOWLEDGE_QUEUE_PROCESSING', true),
        'queue_name' => env('KNOWLEDGE_QUEUE_NAME', 'default'),
        'timeout' => env('KNOWLEDGE_PROCESSING_TIMEOUT', 300), // 5 minutes
        'retry_attempts' => env('KNOWLEDGE_RETRY_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Archive Processing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for handling archive files (ZIP, TGZ) uploads.
    |
    */

    'archives' => [
        'enabled' => env('KNOWLEDGE_ARCHIVES_ENABLED', true),
        'max_files_per_archive' => env('KNOWLEDGE_MAX_FILES_PER_ARCHIVE', 50),
        'max_extraction_size' => env('KNOWLEDGE_MAX_EXTRACTION_SIZE', 100 * 1024 * 1024),
        'supported_types' => [
            'application/zip',
            'application/x-zip-compressed',
            'application/gzip',
            'application/x-gzip',
            'application/x-tar',
            'application/x-compressed-tar',
        ],
        'file_extensions' => ['.zip', '.tar', '.tgz', '.tar.gz'],
        'extract_path' => 'tmp/knowledge_extraction',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vector Store Configuration
    |--------------------------------------------------------------------------
    */

    'vector_store' => [
        'driver' => env('KNOWLEDGE_VECTOR_STORE', 'meilisearch'),
        'chunk_size' => env('KNOWLEDGE_CHUNK_SIZE', 1000),
        'chunk_overlap' => env('KNOWLEDGE_CHUNK_OVERLAP', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for generating embeddings using Prism-supported providers.
    | Embeddings enable semantic search capabilities in the knowledge system.
    |
    */

    'embeddings' => [
        'enabled' => env('KNOWLEDGE_EMBEDDINGS_ENABLED', true),
        'provider' => env('KNOWLEDGE_EMBEDDING_PROVIDER', 'openai'),
        'model' => env('KNOWLEDGE_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'batch_size' => env('KNOWLEDGE_EMBEDDING_BATCH_SIZE', 50),
        'max_retries' => env('KNOWLEDGE_EMBEDDING_MAX_RETRIES', 3),
        'timeout' => env('KNOWLEDGE_EMBEDDING_TIMEOUT', 30),
        'cache_ttl' => env('KNOWLEDGE_EMBEDDING_CACHE_TTL', 3600),
        'models' => [
            'openai' => [
                'text-embedding-3-small' => ['dimensions' => 1536, 'max_tokens' => 8191],
                'text-embedding-3-large' => ['dimensions' => 3072, 'max_tokens' => 8191],
                'text-embedding-ada-002' => ['dimensions' => 1536, 'max_tokens' => 8191],
            ],
            'voyage' => [
                'voyage-large-2' => ['dimensions' => 1536, 'max_tokens' => 16000],
                'voyage-code-2' => ['dimensions' => 1536, 'max_tokens' => 16000],
            ],
            'ollama' => [
                'nomic-embed-text' => ['dimensions' => 768, 'max_tokens' => 2048],
                'all-minilm' => ['dimensions' => 384, 'max_tokens' => 512],
            ],
            'bedrock' => [
                'amazon.titan-embed-text-v1' => ['dimensions' => 1536, 'max_tokens' => 8000],
                'amazon.titan-embed-text-v2:0' => ['dimensions' => 1024, 'max_tokens' => 8000],
                'cohere.embed-english-v3' => ['dimensions' => 1024, 'max_tokens' => 512],
                'cohere.embed-multilingual-v3' => ['dimensions' => 1024, 'max_tokens' => 512],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Text Processing Configuration
    |--------------------------------------------------------------------------
    */

    'text_processing' => [
        'min_text_length' => 10,
        'max_title_length' => 200,
        'max_summary_words' => 200,
        'max_keywords' => 10,
        'remove_html' => true,
        'normalize_whitespace' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | TTL Configuration
    |--------------------------------------------------------------------------
    */

    'ttl' => [
        'default_hours' => env('KNOWLEDGE_DEFAULT_TTL_HOURS', null),
        'max_hours' => env('KNOWLEDGE_MAX_TTL_HOURS', 8760),
        'cleanup_schedule' => env('KNOWLEDGE_CLEANUP_SCHEDULE', '0 2 * * *'),
        'cleanup_batch_size' => env('KNOWLEDGE_CLEANUP_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Configuration
    |--------------------------------------------------------------------------
    */

    'search' => [
        'default_limit' => 10,
        'max_limit' => 100,
        'highlight_pre_tag' => '<mark>',
        'highlight_post_tag' => '</mark>',
        'similarity_threshold' => 0.7,
        'relevance_threshold' => 0.7,
        'agent_relevance_threshold' => 0.7,
        'internal_knowledge_threshold' => 0.7,
        'semantic_ratio' => [
            'knowledge_manager' => 0.3,
            'rag_pipeline' => 0.3,
            'agent_assignment' => 0.4,
        ],
        'meilisearch' => [
            'typo_tolerance' => [
                'enabled' => true,
                'min_word_size_for_typos' => [
                    'one_typo' => 5,
                    'two_typos' => 9,
                ],
                'disable_on_words' => [],
                'disable_on_attributes' => [],
            ],
            'ranking_rules' => [
                'words',
                'typo',
                'proximity',
                'attribute',
                'sort',
                'exactness',
            ],
            'searchable_attributes' => [
                'title',
                'content',
                'description',
                'tags',
            ],
            'attributes_to_highlight' => [
                'title',
                'content',
                'description',
            ],
            'highlight_max_chars' => 200,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy and Security
    |--------------------------------------------------------------------------
    */

    'privacy' => [
        'default_privacy_level' => 'private',
        'allowed_privacy_levels' => ['private', 'public'],
        'scan_for_sensitive_data' => env('KNOWLEDGE_SCAN_SENSITIVE_DATA', true),
        'sensitive_patterns' => [
            '/\b\d{3}-\d{2}-\d{4}\b/',
            '/\b\d{16}\b/',
            '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | System Tags
    |--------------------------------------------------------------------------
    |
    | Predefined system tags that are created during installation.
    |
    */

    'system_tags' => [
        ['name' => 'Important', 'color' => '#dc2626', 'description' => 'High priority documents'],
        ['name' => 'Documentation', 'color' => '#059669', 'description' => 'Documentation and guides'],
        ['name' => 'Reference', 'color' => '#7c3aed', 'description' => 'Reference materials'],
        ['name' => 'Tutorial', 'color' => '#ea580c', 'description' => 'Learning materials and tutorials'],
        ['name' => 'Archive', 'color' => '#6b7280', 'description' => 'Archived documents'],
        ['name' => 'Draft', 'color' => '#f59e0b', 'description' => 'Work in progress documents'],
        ['name' => 'Personal', 'color' => '#ec4899', 'description' => 'Personal documents'],
        ['name' => 'Shared', 'color' => '#06b6d4', 'description' => 'Documents shared with others'],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    */

    'api' => [
        'rate_limit' => env('KNOWLEDGE_API_RATE_LIMIT', '100:1'),
        'pagination' => [
            'default_per_page' => 20,
            'max_per_page' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for AI-powered file analysis to generate metadata
    | suggestions for uploaded documents.
    |
    */

    'file_analysis' => [
        'enabled' => env('KNOWLEDGE_FILE_ANALYSIS_ENABLED', true),
        'provider' => env('KNOWLEDGE_FILE_ANALYSIS_PROVIDER', 'openai'),
        'model' => env('KNOWLEDGE_FILE_ANALYSIS_MODEL', 'gpt-4.1-mini'),
        'max_content_length' => env('KNOWLEDGE_FILE_ANALYSIS_MAX_CONTENT', 4000),
        'timeout' => env('KNOWLEDGE_FILE_ANALYSIS_TIMEOUT', 30),
        'cache_ttl' => env('KNOWLEDGE_FILE_ANALYSIS_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Injection Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for injecting knowledge documents as Prism Document objects
    | instead of concatenating to system prompts.
    |
    */

    'injection' => [
        'max_documents' => env('KNOWLEDGE_INJECTION_MAX_DOCUMENTS', 10),
        'text_extensions' => ['txt', 'md', 'csv'],
        'text_mime_types' => [
            'text/plain',
            'text/markdown',
            'text/csv',
            'text/html',
            'application/json',
        ],
        'external_refresh_threshold_hours' => env('KNOWLEDGE_EXTERNAL_REFRESH_HOURS', 24),
        'cache_file_content' => env('KNOWLEDGE_CACHE_FILE_CONTENT', true),
        'provider_supported_types' => [
            'openai' => [
                'application/pdf',
                'text/plain',
                'text/markdown',
                'text/csv',
                'text/html',
                'application/json',
            ],
            'anthropic' => [
                'application/pdf',
                'text/plain',
                'text/markdown',
                'text/csv',
                'text/html',
                'application/json',
                'image/png',
                'image/jpeg',
                'image/gif',
                'image/webp',
            ],
            'gemini' => [
                'application/pdf',
                'text/plain',
                'text/markdown',
                'text/csv',
                'text/html',
                'application/json',
                'image/png',
                'image/jpeg',
                'image/gif',
                'image/webp',
                'audio/mpeg',
                'audio/wav',
                'video/mp4',
                'video/mpeg',
            ],
        ],
    ],

];

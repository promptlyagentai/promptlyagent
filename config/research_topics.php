<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Research Topics Feature Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration controls the dynamic research topic suggestion system.
    | Topics are generated daily using AI based on user personas, with these
    | topics serving as fallbacks when generation is disabled or fails.
    |
    */

    'enabled' => env('RESEARCH_SUGGESTIONS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Fallback Topics Pool
    |--------------------------------------------------------------------------
    |
    | When AI generation is disabled or fails, 4 random topics are selected
    | from this pool. Each topic includes:
    | - title: Short title (3-5 words)
    | - description: Brief description (max 100 chars)
    | - query: Detailed research query (10-30 words)
    | - icon_type: Visual identifier (ai, code, database, security, cloud, web, mobile, data)
    | - color_theme: Tailwind color scheme for UI styling
    |
    */

    'topics' => [
        // AI & Machine Learning
        [
            'title' => 'Latest AI Developments',
            'description' => 'Research current trends in artificial intelligence and their practical applications',
            'query' => 'Analyze the latest developments in artificial intelligence, focusing on practical applications, emerging trends, and their impact on software development and business operations',
            'icon_type' => 'ai',
            'color_theme' => 'accent',
        ],
        [
            'title' => 'LLM Integration Patterns',
            'description' => 'Explore best practices for integrating large language models into applications',
            'query' => 'What are the current best practices and architectural patterns for integrating large language models into production applications, including cost optimization and performance considerations?',
            'icon_type' => 'ai',
            'color_theme' => 'purple',
        ],
        [
            'title' => 'RAG System Architecture',
            'description' => 'Deep dive into Retrieval-Augmented Generation implementation strategies',
            'query' => 'Explain modern RAG system architectures, including vector database selection, chunking strategies, and hybrid search approaches for optimal retrieval accuracy',
            'icon_type' => 'ai',
            'color_theme' => 'indigo',
        ],

        // Web Development
        [
            'title' => 'Modern Web Frameworks',
            'description' => 'Compare latest features and performance of popular web frameworks',
            'query' => 'Compare the latest features, performance characteristics, and ecosystem maturity of modern web frameworks like Laravel, Next.js, and SvelteKit for building scalable applications',
            'icon_type' => 'web',
            'color_theme' => 'blue',
        ],
        [
            'title' => 'Progressive Web Apps',
            'description' => 'Explore PWA capabilities and implementation best practices',
            'query' => 'What are the current capabilities and best practices for building Progressive Web Apps, including offline functionality, push notifications, and app-like experiences?',
            'icon_type' => 'mobile',
            'color_theme' => 'teal',
        ],
        [
            'title' => 'Real-time Web Features',
            'description' => 'Implementation patterns for WebSockets and Server-Sent Events',
            'query' => 'Explore implementation patterns for real-time features using WebSockets, Server-Sent Events, and modern protocols like WebTransport for low-latency communication',
            'icon_type' => 'web',
            'color_theme' => 'emerald',
        ],

        // Database & Data
        [
            'title' => 'Database Optimization',
            'description' => 'Techniques for improving query performance and scalability',
            'query' => 'Research advanced database optimization techniques including indexing strategies, query analysis, connection pooling, and read replica patterns for high-traffic applications',
            'icon_type' => 'database',
            'color_theme' => 'emerald',
        ],
        [
            'title' => 'Vector Database Selection',
            'description' => 'Compare vector databases for AI/ML workloads',
            'query' => 'Compare modern vector databases like Meilisearch, Pinecone, and Qdrant for AI applications, focusing on performance, scalability, and integration complexity',
            'icon_type' => 'database',
            'color_theme' => 'purple',
        ],
        [
            'title' => 'Data Pipeline Architecture',
            'description' => 'Design patterns for ETL and real-time data processing',
            'query' => 'What are the modern architectural patterns for building scalable data pipelines, including stream processing, CDC, and event-driven ETL workflows?',
            'icon_type' => 'data',
            'color_theme' => 'orange',
        ],

        // Security
        [
            'title' => 'API Security Best Practices',
            'description' => 'Comprehensive guide to securing REST and GraphQL APIs',
            'query' => 'Research comprehensive API security best practices including authentication, authorization, rate limiting, input validation, and protection against common vulnerabilities',
            'icon_type' => 'security',
            'color_theme' => 'orange',
        ],
        [
            'title' => 'Zero Trust Architecture',
            'description' => 'Implement modern zero-trust security principles',
            'query' => 'Explain zero-trust architecture principles and their practical implementation in cloud-native applications, including identity verification, micro-segmentation, and continuous authentication',
            'icon_type' => 'security',
            'color_theme' => 'pink',
        ],

        // Cloud & DevOps
        [
            'title' => 'Kubernetes Best Practices',
            'description' => 'Production-ready patterns for container orchestration',
            'query' => 'What are the current best practices for deploying and managing production Kubernetes clusters, including resource management, monitoring, and disaster recovery strategies?',
            'icon_type' => 'cloud',
            'color_theme' => 'blue',
        ],
        [
            'title' => 'Serverless Architecture',
            'description' => 'When and how to use serverless computing effectively',
            'query' => 'Analyze when serverless architectures are beneficial, including cost optimization strategies, cold start mitigation, and integration patterns with traditional infrastructure',
            'icon_type' => 'cloud',
            'color_theme' => 'teal',
        ],
        [
            'title' => 'CI/CD Pipeline Optimization',
            'description' => 'Speed up and secure your deployment workflows',
            'query' => 'Research strategies for optimizing CI/CD pipelines including parallel execution, caching strategies, security scanning integration, and deployment strategies like blue-green and canary',
            'icon_type' => 'code',
            'color_theme' => 'indigo',
        ],

        // Infrastructure & Performance
        [
            'title' => 'Observability Strategies',
            'description' => 'Modern monitoring, logging, and tracing approaches',
            'query' => 'What are modern observability best practices including distributed tracing, structured logging, metrics collection, and alert management for microservices architectures?',
            'icon_type' => 'data',
            'color_theme' => 'accent',
        ],
        [
            'title' => 'Edge Computing Patterns',
            'description' => 'Leverage edge infrastructure for performance and resilience',
            'query' => 'Explore edge computing architectures and CDN strategies for reducing latency, improving user experience, and building resilient distributed applications',
            'icon_type' => 'cloud',
            'color_theme' => 'pink',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Controls caching behavior for generated research topics. Topics are
    | cached per-user with Redis cache tags for efficient invalidation.
    |
    */

    'cache' => [
        'ttl' => 60 * 60 * 48,
        'key_prefix' => 'research_topics',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Generation Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for AI-powered topic generation including model selection,
    | token limits, and creativity parameters.
    |
    */

    'generation' => [
        'pool_size' => 12,
        'display_count' => 4,
        'model_profile' => 'low_cost',
        'max_tokens' => 2000,
        'temperature' => 0.8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Icon Type Mapping
    |--------------------------------------------------------------------------
    |
    | Available icon types and their visual representations:
    | - ai: Brain/spark icon (AI, machine learning topics)
    | - code: Code brackets icon (programming, development)
    | - database: Database cylinders icon (data storage, queries)
    | - security: Lock/shield icon (security, authentication)
    | - cloud: Cloud icon (cloud infrastructure, deployment)
    | - web: Globe/window icon (web development, browsers)
    | - mobile: Mobile device icon (mobile apps, PWA)
    | - data: Chart/analytics icon (data processing, analytics)
    |
    */
];

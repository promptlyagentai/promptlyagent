<?php

namespace App\Services;

use App\Models\User;
use App\Services\Agents\Schemas\ResearchTopicSchema;
use App\Services\AI\ModelSelector;
use App\Services\AI\PrismWrapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ResearchTopicService
{
    public function __construct(
        protected PrismWrapper $prism,
        protected AiPersonaService $personaService
    ) {}

    /**
     * Get research topics for a user (cache-first with fallback chain).
     *
     * Returns 4 randomly selected topics from a larger cached pool for variety.
     *
     * Flow:
     * - Feature enabled → Random 4 from per-user personalized pool (12 topics)
     * - Feature disabled → Random 4 from global trending pool (12 topics)
     * - Pool cache empty → Random 4 from static fallback (16 topics)
     */
    public function getTopicsForUser(User $user): array
    {
        // Get the full topic pool (cached)
        $topicPool = $this->getTopicPool($user);

        // Randomly select display count from the pool
        $displayCount = config('research_topics.generation.display_count', 4);

        // Fixed colors for consistent visual positions (matches original hard-coded layout)
        $fixedColors = ['accent', 'emerald', 'purple', 'orange'];

        // Randomly select topics and assign fixed colors by position
        return collect($topicPool)
            ->shuffle()
            ->take($displayCount)
            ->values()
            ->map(function ($topic, $index) use ($fixedColors) {
                // Override color_theme with fixed position color
                $topic['color_theme'] = $fixedColors[$index] ?? 'accent';

                return $topic;
            })
            ->toArray();
    }

    /**
     * Get the full topic pool for a user (cached).
     *
     * Returns the complete pool of topics to cache.
     * The calling method will randomly select 4 for display.
     */
    protected function getTopicPool(User $user): array
    {
        // Check if feature is enabled for this user
        if (! $this->isFeatureEnabled($user)) {
            return $this->getGlobalTrendingTopics();
        }

        // Feature enabled: Use per-user personalized cache
        $cacheKey = config('research_topics.cache.key_prefix').":user_{$user->id}";
        $cacheTtl = config('research_topics.cache.ttl', 60 * 60 * 48);

        try {
            return Cache::tags(['research_topics', "user:{$user->id}"])
                ->remember($cacheKey, $cacheTtl, function () use ($user) {
                    return $this->generateTopics($user);
                });
        } catch (\RedisException $e) {
            // Cache unavailable - generate topics directly without caching
            return $this->generateTopics($user);
        }
    }

    /**
     * Get globally cached trending topics (shared across all non-personalized users).
     *
     * This provides AI-generated trending topics for users who don't have
     * personalization enabled, without per-user API calls.
     */
    public function getGlobalTrendingTopics(): array
    {
        $cacheKey = config('research_topics.cache.key_prefix').':global_trending';
        $cacheTtl = config('research_topics.cache.ttl', 60 * 60 * 48);

        return Cache::tags(['research_topics', 'global'])
            ->remember($cacheKey, $cacheTtl, function () {
                try {
                    return $this->generateGlobalTrendingTopics();
                } catch (\Exception $e) {
                    Log::warning('Failed to generate global trending topics, using fallback', [
                        'error' => $e->getMessage(),
                    ]);

                    return $this->getFallbackTopics();
                }
            });
    }

    /**
     * Generate global trending topics using AI (not personalized).
     *
     * Generates a pool of topics (default: 12) shared across all users without personalization.
     */
    protected function generateGlobalTrendingTopics(): array
    {
        $poolSize = config('research_topics.generation.pool_size', 12);

        $systemPrompt = $this->buildSystemPrompt($poolSize);
        $userPrompt = $this->buildGenericPrompt($poolSize);

        $response = $this->prism->structured()
            ->usingProfile(ModelSelector::LOW_COST)
            ->withSystemPrompt($systemPrompt)
            ->withMessages([new UserMessage($userPrompt)])
            ->withSchema(new ResearchTopicSchema)
            ->withContext([
                'mode' => 'research_topic_generation',
                'type' => 'global_trending',
                'pool_size' => $poolSize,
                'source' => static::class,
            ])
            ->asStructured();

        $topics = $response->structured['topics'] ?? [];

        // Validate we got the expected pool size
        if (count($topics) < $poolSize) {
            Log::warning('AI generated fewer global trending topics than requested', [
                'expected' => $poolSize,
                'received' => count($topics),
            ]);

            // If we got some topics but not enough, supplement with fallback
            if (count($topics) > 0) {
                $needed = $poolSize - count($topics);
                $fallback = $this->getFallbackTopics($needed);
                $topics = array_merge($topics, $fallback);
            } else {
                return $this->getFallbackTopics($poolSize);
            }
        }

        Log::info('Global trending topics generated successfully', [
            'count' => count($topics),
            'sample_titles' => collect($topics)->take(5)->pluck('title')->toArray(),
        ]);

        return $this->enrichTopicsWithIcons($topics);
    }

    /**
     * Check if the feature is enabled for this user.
     */
    protected function isFeatureEnabled(User $user): bool
    {
        // Global feature flag
        if (! config('research_topics.enabled', true)) {
            return false;
        }

        // User preference check
        $preferences = $user->preferences ?? [];

        return $preferences['research_suggestions']['enabled'] ?? false;
    }

    /**
     * Generate topics using AI with persona awareness.
     *
     * Generates a pool of topics (default: 12) for caching and variety.
     */
    protected function generateTopics(User $user): array
    {
        $poolSize = config('research_topics.generation.pool_size', 12);

        try {
            $systemPrompt = $this->buildSystemPrompt($poolSize);
            $userPrompt = $this->buildUserPrompt($user, $poolSize);

            $response = $this->prism->structured()
                ->usingProfile(ModelSelector::LOW_COST)
                ->withSystemPrompt($systemPrompt)
                ->withMessages([new UserMessage($userPrompt)])
                ->withSchema(new ResearchTopicSchema)
                ->withContext([
                    'mode' => 'research_topic_generation',
                    'type' => 'personalized',
                    'pool_size' => $poolSize,
                    'user_id' => $user->id,
                    'source' => static::class,
                ])
                ->asStructured();

            $topics = $response->structured['topics'] ?? [];

            // Validate we got the expected pool size
            if (count($topics) < $poolSize) {
                Log::warning('AI generated fewer topics than requested', [
                    'user_id' => $user->id,
                    'expected' => $poolSize,
                    'received' => count($topics),
                ]);

                // If we got some topics but not enough, supplement with fallback
                if (count($topics) > 0) {
                    $needed = $poolSize - count($topics);
                    $fallback = $this->getFallbackTopics($needed);
                    $topics = array_merge($topics, $fallback);
                } else {
                    return $this->getFallbackTopics($poolSize);
                }
            }

            // Add icon SVG paths
            return $this->enrichTopicsWithIcons($topics);

        } catch (\Exception $e) {
            Log::warning('Failed to generate research topics', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return $this->getFallbackTopics($poolSize);
        }
    }

    /**
     * Build the system prompt for topic generation.
     */
    protected function buildSystemPrompt(int $count = 12): string
    {
        return <<<PROMPT
You are a research topic recommendation system for a technical audience. Generate {$count} engaging, relevant research topics that are:

- Specific and actionable (not vague or generic)
- Current and timely (focus on recent developments and emerging trends)
- Professionally valuable (career growth, skill development, practical applications)
- Diverse across domains (cover multiple technical areas, not just one narrow focus)
- Written clearly and professionally

Each topic needs:
- title: Short, catchy title (3-5 words)
- description: Brief one-sentence description (max 100 characters)
- query: Detailed research query that will populate the search input (10-30 words, specific and actionable)
- icon_type: Choose the most appropriate icon (ai, code, database, security, cloud, web, mobile, data)
- color_theme: Choose a visually distinct color for each topic (accent, emerald, purple, orange, blue, indigo, pink, teal)

Ensure the {$count} topics cover different technical areas and use a variety of color themes for visual variety.
PROMPT;
    }

    /**
     * Build persona-aware or generic user prompt.
     */
    protected function buildUserPrompt(User $user, int $count = 12): string
    {
        $persona = $this->personaService->getPersonaContext($user);

        if ($this->hasPersona($persona)) {
            return $this->buildPersonalizedPrompt($persona, $count);
        }

        return $this->buildGenericPrompt($count);
    }

    /**
     * Check if user has a configured persona.
     */
    protected function hasPersona(array $persona): bool
    {
        // Check if any meaningful persona fields are filled
        return ! empty($persona['job_description'])
            || ! empty($persona['skills'])
            || ! empty($persona['location']);
    }

    /**
     * Build personalized prompt using user's AI persona.
     */
    protected function buildPersonalizedPrompt(array $persona, int $count = 12): string
    {
        $job = $persona['job_description'] ?? 'technical professional';
        $skills = ! empty($persona['skills']) ? implode(', ', $persona['skills']) : 'general technical skills';
        $location = $persona['location'] ?? 'global';
        $age = $persona['age'] ?? null;

        $prompt = "Generate {$count} personalized research topics for:\n\n";
        $prompt .= "- Job/Role: {$job}\n";
        $prompt .= "- Skills: {$skills}\n";
        $prompt .= "- Location: {$location}\n";

        if ($age) {
            $prompt .= "- Age: {$age}\n";
        }

        $prompt .= "\nFocus on:\n";
        $prompt .= "- Topics relevant to their professional interests and current role\n";
        $prompt .= "- Skill development and career advancement opportunities\n";
        $prompt .= "- Current trends and technologies in their field\n";
        $prompt .= "- Practical applications they can use in their work\n\n";
        $prompt .= "Make topics specific, actionable, and valuable for their career growth. Ensure diversity across {$count} topics.";

        return $prompt;
    }

    /**
     * Build generic prompt for users without persona.
     */
    protected function buildGenericPrompt(int $count = 12): string
    {
        return <<<PROMPT
Generate {$count} trending technology research topics for a general technical audience.

Focus on:
- Recent developments and breakthroughs in technology
- Emerging technologies with practical applications
- Current industry challenges and solutions
- Topics across diverse areas: AI, web development, security, infrastructure, data

Make topics accessible yet technically substantive, suitable for developers, engineers, and technical professionals.
Ensure diversity across all {$count} topics.
PROMPT;
    }

    /**
     * Get random fallback topics from config with current date context.
     *
     * Injects current date/time into queries to keep static fallbacks feeling fresh.
     */
    protected function getFallbackTopics(?int $count = null): array
    {
        $count = $count ?? config('research_topics.generation.pool_size', 12);
        $allTopics = config('research_topics.topics', []);

        if (empty($allTopics)) {
            Log::error('No fallback topics configured');

            return [];
        }

        // Shuffle and take requested count
        // If requested more than available, cycle through multiple times
        $shuffled = collect($allTopics)->shuffle();

        if ($count <= count($allTopics)) {
            $selectedTopics = $shuffled->take($count)->values()->toArray();
        } else {
            // Need more topics than available - cycle through
            $selectedTopics = [];
            while (count($selectedTopics) < $count) {
                $remaining = $count - count($selectedTopics);
                $batch = $shuffled->take($remaining)->values()->toArray();
                $selectedTopics = array_merge($selectedTopics, $batch);

                // Reshuffle for next batch if needed
                if (count($selectedTopics) < $count) {
                    $shuffled = collect($allTopics)->shuffle();
                }
            }
        }

        // Inject current date context to keep topics evergreen
        $selectedTopics = $this->injectDateContext($selectedTopics);

        return $this->enrichTopicsWithIcons($selectedTopics);
    }

    /**
     * Inject current date/time context into topics to keep them evergreen.
     *
     * Adds temporal context to static fallback topics so they always feel current.
     * Examples:
     * - "Analyze latest..." → adds "Focus on developments as of January 2026."
     * - "Research..." → adds "Include 2026 trends and recent developments."
     */
    protected function injectDateContext(array $topics): array
    {
        $now = now();
        $currentYear = $now->year;
        $currentMonthYear = $now->format('F Y');

        return array_map(function ($topic) use ($currentYear, $currentMonthYear) {
            $query = $topic['query'];

            // Skip if already has current year context
            if (str_contains($query, (string) $currentYear)) {
                return $topic;
            }

            // Ensure proper sentence ending before adding context
            $query = rtrim($query, '. ');

            // Add temporal context based on query type
            if (str_contains(strtolower($query), 'latest') || str_contains(strtolower($query), 'current')) {
                $query .= ". Focus on developments as of {$currentMonthYear}.";
            } else {
                $query .= ". Include {$currentYear} trends and recent developments.";
            }

            $topic['query'] = $query;

            return $topic;
        }, $topics);
    }

    /**
     * Map icon types to SVG paths and add to topics.
     */
    protected function enrichTopicsWithIcons(array $topics): array
    {
        $iconPaths = [
            'ai' => 'M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 14.5M14.25 3.104c.251.023.501.05.75.082M19.8 14.5l-5.207 5.207a2.25 2.25 0 01-1.591.659h-2.004a2.25 2.25 0 01-1.591-.659L4.8 14.5m15-.001l1.38 1.38a2.25 2.25 0 010 3.182l-1.38 1.38m-15-4.56l-1.38 1.38a2.25 2.25 0 000 3.182l1.38 1.38',
            'code' => 'M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5',
            'database' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4',
            'security' => 'M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z',
            'cloud' => 'M2.25 15a4.5 4.5 0 004.5 4.5H18a3.75 3.75 0 001.332-7.257 3 3 0 00-3.758-3.848 5.25 5.25 0 00-10.233 2.33A4.502 4.502 0 002.25 15z',
            'web' => 'M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418',
            'mobile' => 'M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3',
            'data' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
        ];

        return array_map(function ($topic) use ($iconPaths) {
            $topic['icon_path'] = $iconPaths[$topic['icon_type']] ?? $iconPaths['ai'];

            return $topic;
        }, $topics);
    }
}

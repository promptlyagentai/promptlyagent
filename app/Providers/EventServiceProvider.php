<?php

namespace App\Providers;

use App\Events\AgentExecutionCompleted;
use App\Events\ChatInteractionCompleted;
use App\Events\ResearchWorkflowCompleted;
use App\Listeners\ExtractChatSourceLinks;
use App\Listeners\GenerateSessionTitle;
use App\Listeners\QueueInteractionEmbeddings;
use App\Listeners\TrackAgentExecutionUrls;
use App\Listeners\TrackInteractionUrls;
use App\Listeners\TrackResearchUrls;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

/**
 * Event Service Provider
 *
 * Registers event listeners for chat, agent, and research workflow side effects.
 * Implements event-driven architecture for post-processing operations like
 * title generation, URL tracking, source extraction, and embedding generation.
 *
 * **Listener Execution Order:**
 * Listeners are executed in the order they appear in the array:
 * 1. ExtractChatSourceLinks - Creates structured source records
 * 2. TrackInteractionUrls/TrackAgentExecutionUrls/TrackResearchUrls - Tracks URLs for analytics
 * 3. GenerateSessionTitle - Generates AI title (affects embeddings)
 * 4. QueueInteractionEmbeddings - Queues embedding generation (MUST be last)
 *
 * **Error Handling:**
 * All listeners use non-blocking error handling - failures are logged but
 * don't prevent subsequent listeners from executing or user-facing operations
 * from completing.
 *
 * **Phase 2 Status:**
 * Currently dispatching events alongside direct calls for zero-risk deployment.
 * Once proven stable, direct calls can be removed in Phase 3.
 *
 * @see \App\Events\ChatInteractionCompleted
 * @see \App\Events\AgentExecutionCompleted
 * @see \App\Events\ResearchWorkflowCompleted
 */
class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // Chat interaction completion side effects
        ChatInteractionCompleted::class => [
            ExtractChatSourceLinks::class,      // Priority 1: Extract structured source data
            TrackInteractionUrls::class,        // Priority 2: Track URLs for analytics
            GenerateSessionTitle::class,        // Priority 3: Generate title (affects embeddings)
            QueueInteractionEmbeddings::class,  // Priority 4: Queue embeddings (MUST be last)
        ],

        // Agent execution completion side effects
        AgentExecutionCompleted::class => [
            TrackAgentExecutionUrls::class,     // Track URLs in synthesis results
        ],

        // Research workflow completion side effects
        ResearchWorkflowCompleted::class => [
            TrackResearchUrls::class,           // Track URLs in research answers
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}

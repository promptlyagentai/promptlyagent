<?php

namespace App\Enums;

/**
 * Agent Execution Phase Lifecycle Enum.
 *
 * Represents the progression stages of an agent execution from initialization
 * through completion. Used for real-time progress tracking via AgentPhaseChanged
 * event broadcasts and StatusReporter updates.
 *
 * Phase Lifecycle Flow:
 * 1. **INITIALIZING**: Setup execution environment, load agent configuration
 * 2. **PLANNING**: Analyze request, determine required tools and strategy
 * 3. **SEARCHING**: Execute search tools (web search, knowledge RAG, etc.)
 * 4. **READING**: Retrieve and extract content from discovered sources
 * 5. **PROCESSING**: Analyze and transform collected information
 * 6. **SYNTHESIZING**: Combine findings into coherent response
 * 7. **STREAMING**: Send response to user via WebSocket
 * 8. **COMPLETED**: Execution finished successfully
 *
 * Broadcasting:
 * - Each phase change triggers AgentPhaseChanged event
 * - Broadcasts to private channel: `chat-session.{sessionId}`
 * - UI components receive real-time updates for progress indicators
 *
 * Display Integration:
 * - getDisplayName(): User-friendly labels for UI display
 * - getDescription(): Detailed explanations for tooltips/status messages
 *
 * @see \App\Events\AgentPhaseChanged
 * @see \App\Services\StatusReporter
 * @see \App\Services\Agents\AgentExecutor
 */
enum AgentPhase: string
{
    case INITIALIZING = 'initializing';
    case PLANNING = 'planning';
    case SEARCHING = 'searching';
    case READING = 'reading';
    case PROCESSING = 'processing';
    case SYNTHESIZING = 'synthesizing';
    case STREAMING = 'streaming';
    case COMPLETED = 'completed';

    public function getDisplayName(): string
    {
        return match ($this) {
            self::INITIALIZING => 'Initializing',
            self::PLANNING => 'Planning',
            self::SEARCHING => 'Searching',
            self::READING => 'Reading Sources',
            self::PROCESSING => 'Processing Information',
            self::SYNTHESIZING => 'Synthesizing Response',
            self::STREAMING => 'Streaming Response',
            self::COMPLETED => 'Completed',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::INITIALIZING => 'Setting up agent execution environment',
            self::PLANNING => 'Analyzing request and creating execution plan',
            self::SEARCHING => 'Searching for relevant information and sources',
            self::READING => 'Reading and extracting content from sources',
            self::PROCESSING => 'Processing and analyzing collected information',
            self::SYNTHESIZING => 'Synthesizing findings into response',
            self::STREAMING => 'Streaming response to user',
            self::COMPLETED => 'Execution completed successfully',
        };
    }
}

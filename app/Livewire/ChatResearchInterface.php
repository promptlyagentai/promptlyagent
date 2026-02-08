<?php

namespace App\Livewire;

use App\Jobs\ResearchJob;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\ChatInteractionAttachment;
use App\Models\ChatSession;
use App\Models\StatusStream;
use App\Services\InputTrigger\SecureFileValidator;
use App\Services\Queue\JobStatusManager;
use App\Traits\UsesAIModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

/**
 * Research chat interface with multi-agent orchestration and real-time streaming.
 *
 * Handles three execution modes:
 * - Deeply: Holistic research workflow with multiple agents
 * - Directly: Real-time streaming chat without queue
 * - Single Agent: Individual agent execution via queue
 *
 * Features:
 * - File attachments with temporary storage
 * - Real-time WebSocket updates for status streams
 * - Queue-based background processing
 * - Tool override management
 * - Session restoration after page reload
 *
 * @property string $query Current search query
 * @property string $selectedAgent Selected agent mode (deeply|directly|promptly|agent_id)
 * @property int|null $currentInteractionId Active chat interaction
 * @property array<int, array{id: int, action: string|null, description: string, timestamp: string, tool: string, data: array}> $executionSteps
 * @property bool $isStreaming Whether streaming is active
 * @property bool $isThinking Whether thinking process is displayed
 */
class ChatResearchInterface extends BaseChatInterface
{
    use UsesAIModels, WithFileUploads;

    public string $query = '';

    public $attachments = [];

    public bool $isThinking = false;

    public bool $isOptimizingQuery = false;

    public function processAttachmentsForInteraction($interactionId): void
    {
        if (empty($this->attachments)) {
            return;
        }

        foreach ($this->attachments as $uploadedFile) {
            if ($uploadedFile) {
                try {
                    $this->createAttachmentRecord($interactionId, $uploadedFile);
                } catch (\Exception $e) {
                    Log::error('ChatResearchInterface: Failed to process attachment', [
                        'interaction_id' => $interactionId,
                        'filename' => $uploadedFile->getClientOriginalName(),
                        'error' => $e->getMessage(),
                        'user_id' => auth()->id(),
                    ]);

                    // Notify user of attachment failure but continue processing
                    $this->dispatch('notify', [
                        'message' => 'Failed to upload '.$uploadedFile->getClientOriginalName().': '.$e->getMessage(),
                        'type' => 'error',
                    ]);
                }
            }
        }

        // Clear attachments after processing
        $this->attachments = [];
    }

    public function removeAttachment($index)
    {
        try {
            // Validate index
            if (! isset($this->attachments[$index])) {
                $this->dispatch('notify', [
                    'message' => 'Attachment not found',
                    'type' => 'error',
                ]);

                return;
            }

            // Get attachment info for logging
            $attachment = $this->attachments[$index];
            $filename = method_exists($attachment, 'getClientOriginalName')
                ? $attachment->getClientOriginalName()
                : 'unknown';

            // Remove attachment from array
            array_splice($this->attachments, $index, 1);

            Log::info('Attachment removed', [
                'filename' => $filename,
                'index' => $index,
                'remaining_count' => count($this->attachments),
            ]);

            $this->dispatch('notify', [
                'message' => 'Attachment removed',
                'type' => 'success',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to remove attachment', [
                'error' => $e->getMessage(),
                'index' => $index,
            ]);

            $this->dispatch('notify', [
                'message' => 'Failed to remove attachment',
                'type' => 'error',
            ]);
        }
    }

    // Copy functionality methods
    protected function copyToClipboard($content, $successMessage = 'Content copied to clipboard')
    {
        if (empty($content)) {
            $this->dispatch('notify', [
                'message' => 'No content available to copy',
                'type' => 'error',
            ]);

            return;
        }

        $this->dispatch('copy-content-to-clipboard', [
            'content' => json_encode($content),
            'successMessage' => $successMessage,
        ]);
    }

    public function copyInteractionAnswer($interactionId)
    {
        $interaction = ChatInteraction::find($interactionId);
        $this->copyToClipboard($interaction?->answer, 'Answer copied to clipboard');
    }

    public function copyInteractionQuestion($interactionId)
    {
        $interaction = ChatInteraction::find($interactionId);
        $this->copyToClipboard($interaction?->question, 'Question copied to clipboard');
    }

    public function copyFullInteraction($interactionId)
    {
        $interaction = ChatInteraction::find($interactionId);
        if (! $interaction) {
            $this->copyToClipboard(null, 'Interaction not found');

            return;
        }

        $content = "**Question:**\n".$interaction->question;
        if ($interaction->answer) {
            $content .= "\n\n**Answer:**\n".$interaction->answer;
        }

        $this->copyToClipboard($content, 'Interaction copied to clipboard');
    }

    public function retryQuestion($interactionId)
    {
        $interaction = ChatInteraction::find($interactionId);
        if (! $interaction || ! $interaction->question) {
            $this->dispatch('notify', [
                'message' => 'Question not found',
                'type' => 'error',
            ]);

            return;
        }

        // Set the query to the interaction's question
        $this->query = $interaction->question;

        // Focus the input field via JavaScript
        $this->dispatch('focus-search-input');
    }

    /**
     * Optimize the current query using AI based on the selected agent mode
     */
    public function optimizeQuery()
    {
        // Validate query is not empty
        if (empty(trim($this->query))) {
            $this->dispatch('notify', [
                'message' => 'Please enter a query to optimize',
                'type' => 'warning',
            ]);

            return;
        }

        try {
            // Set loading state
            $this->isOptimizingQuery = true;

            // Determine optimization strategy based on selected agent
            $systemPrompt = $this->getOptimizationPrompt();

            // Get conversation context from the last interaction
            $conversationContext = $this->getLastInteractionContext();

            Log::info('ChatResearchInterface: Optimizing query', [
                'original_query' => $this->query,
                'selected_agent' => $this->selectedAgent,
                'has_context' => ! empty($conversationContext),
            ]);

            // Build messages array with context if available
            // Use withSystemPrompt() for provider interoperability (per Prism best practices)
            $userMessage = ! empty($conversationContext)
                ? "Previous conversation context:\n\n{$conversationContext}\n\n---\n\nCurrent query to optimize:\n{$this->query}"
                : $this->query;

            // Use Prism with low-cost model for fast optimization
            $response = $this->useLowCostModel()
                ->withSystemPrompt($systemPrompt)
                ->withMessages([new \Prism\Prism\ValueObjects\Messages\UserMessage($userMessage)])
                ->asText();

            $optimizedQuery = trim($response->text);

            // Validate the optimized query
            if (empty($optimizedQuery)) {
                throw new \Exception('Optimization returned an empty query');
            }

            // SECURITY: Sanitize AI response to prevent XSS
            // AI could return malicious HTML/JS via prompt injection or echoing user input
            // Strip all HTML tags to prevent stored XSS
            $optimizedQuery = strip_tags($optimizedQuery);

            // Validate length (prevent excessive content)
            if (strlen($optimizedQuery) > 5000) {
                throw new \Exception('Optimized query exceeds maximum length (5000 characters)');
            }

            // Final validation after sanitization
            if (empty($optimizedQuery)) {
                throw new \Exception('Optimization returned invalid content after sanitization');
            }

            // Update the query with sanitized optimized version
            $this->query = $optimizedQuery;

            Log::info('ChatResearchInterface: Query optimized successfully', [
                'optimized_query' => $optimizedQuery,
                'selected_agent' => $this->selectedAgent,
            ]);

            // Show success notification
            $this->dispatch('notify', [
                'message' => 'Query optimized successfully!',
                'type' => 'success',
            ]);

        } catch (\Exception $e) {
            Log::error('ChatResearchInterface: Error optimizing query', [
                'error' => $e->getMessage(),
                'query' => $this->query,
                'selected_agent' => $this->selectedAgent,
            ]);

            // Show error notification
            $this->dispatch('notify', [
                'message' => 'Failed to optimize query: '.$e->getMessage(),
                'type' => 'error',
            ]);
        } finally {
            // Always unset loading state
            $this->isOptimizingQuery = false;
        }
    }

    /**
     * Get the optimization prompt based on the selected agent
     */
    protected function getOptimizationPrompt(): string
    {
        $baseInstruction = 'Optimize the following query. Return ONLY the optimized query text, without any explanations, quotes, or additional commentary. ';

        if ($this->selectedAgent === 'deeply') {
            return $baseInstruction.'Transform this into a workflow-optimized research plan by breaking it down into clear, actionable steps. '.
                'Use workflow orchestration language to help the planner understand task relationships:'."\n\n".
                '**For INDEPENDENT tasks (can run in parallel):**'."\n".
                '- Use: "compare", "analyze both", "simultaneously investigate", "in parallel", "multiple perspectives"'."\n".
                '- Example: "Compare economic impacts AND analyze social factors in parallel"'."\n\n".
                '**For DEPENDENT tasks (must run sequentially):**'."\n".
                '- Use: "then", "after that", "next", "based on previous results", "following the analysis"'."\n".
                '- Example: "First gather climate data, THEN analyze trends based on that data"'."\n\n".
                'Identify which sub-tasks are independent (can be researched simultaneously) vs dependent (require previous results). '.
                'Structure your query to make these relationships explicit using the keywords above.';
        } elseif ($this->selectedAgent === 'directly') {
            return $baseInstruction.'Make it conversational, clear, and well-structured for getting a comprehensive response in a direct chat interaction. Ensure it\'s friendly and easy to understand.';
        } else {
            // For 'promptly' and individual agents
            return $baseInstruction.'Make it clear, specific, and action-oriented for intelligent agent selection and execution. Ensure the query is unambiguous and contains all necessary context.';
        }
    }

    /**
     * Get the last interaction's question and answer as context for query optimization
     */
    protected function getLastInteractionContext(): ?string
    {
        if (! $this->currentSessionId) {
            return null;
        }

        // Get the last interaction with an answer from the current session
        $lastInteraction = ChatInteraction::where('chat_session_id', $this->currentSessionId)
            ->whereNotNull('answer')
            ->where('answer', '!=', '')
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $lastInteraction) {
            return null;
        }

        // Build context string with question and answer
        $question = $lastInteraction->question;
        $answer = $lastInteraction->answer;

        // Estimate token usage (rough approximation: 1 token ≈ 4 characters)
        // Low-cost models typically have 128K context window, reserve space for:
        // - System prompt: ~200 tokens
        // - Current query: ~500 tokens
        // - Response: ~1000 tokens
        // - Context overhead: ~300 tokens
        // Available for context: ~126K tokens ≈ 500K characters
        $maxContextChars = 500000;

        // Calculate current context size
        $contextSize = strlen($question) + strlen($answer) + 50; // +50 for formatting

        // Only truncate if context exceeds available space
        if ($contextSize > $maxContextChars) {
            // Calculate how much space we have for the answer
            $availableForAnswer = $maxContextChars - strlen($question) - 50;

            // Keep the beginning of the answer, truncate the end
            if ($availableForAnswer > 0 && strlen($answer) > $availableForAnswer) {
                $answer = substr($answer, 0, $availableForAnswer).'... [truncated due to length]';
            }
        }

        $context = "Question: {$question}\n\n";
        $context .= "Answer: {$answer}";

        return $context;
    }

    protected function createAttachmentRecord($interactionId, $uploadedFile): void
    {
        try {
            // SECURITY: Validate file using SecureFileValidator before processing
            // Prevents RCE, XSS, path traversal, ZIP bombs, and malicious uploads
            $validator = app(SecureFileValidator::class);
            $validationResult = $validator->validate($uploadedFile);

            if (! $validationResult->valid) {
                Log::warning('ChatResearchInterface: File validation failed', [
                    'user_id' => auth()->id(),
                    'interaction_id' => $interactionId,
                    'filename' => $uploadedFile->getClientOriginalName(),
                    'error' => $validationResult->error,
                ]);

                throw new \Exception('File validation failed: '.$validationResult->error);
            }

            // Use validated data from SecureFileValidator
            $safeFilename = $validationResult->data['original_filename'];
            $validatedMimeType = $validationResult->data['mime_type'];
            $extension = $validationResult->data['extension'];

            $fileSize = $uploadedFile->getSize();
            $type = ChatInteractionAttachment::determineTypeFromMimeType($validatedMimeType);

            // Store file to permanent location (S3 for cross-pod access)
            // Use store() to let Laravel generate unique filename (matches master behavior)
            try {
                $permanentPath = $uploadedFile->store('chat-attachments', 's3');

                if (! $permanentPath) {
                    throw new \RuntimeException('S3 storage returned false - upload may have failed');
                }
            } catch (\Exception $e) {
                Log::error('ChatResearchInterface: S3 storage failed', [
                    'interaction_id' => $interactionId,
                    'filename' => $safeFilename,
                    'error' => $e->getMessage(),
                    'user_id' => auth()->id(),
                ]);

                throw new \Exception('Failed to store file to S3: '.$e->getMessage());
            }

            // Extract metadata from temporary file before it's deleted
            $tempPath = $uploadedFile->getRealPath();
            $metadata = $this->extractFileMetadata($tempPath, $type);

            // Create attachment record with validated data
            ChatInteractionAttachment::create([
                'chat_interaction_id' => $interactionId,
                'filename' => $safeFilename, // Sanitized filename
                'storage_path' => $permanentPath,
                'mime_type' => $validatedMimeType, // Content-based MIME type
                'file_size' => $fileSize,
                'type' => $type,
                'metadata' => $metadata,
                'is_temporary' => true,
                'expires_at' => now()->addDays(7), // Expire after 7 days
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to process attachment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'interaction_id' => $interactionId,
                'filename' => $uploadedFile->getClientOriginalName(),
            ]);

            // Notify user of failure
            $this->dispatch('notify', [
                'message' => 'Failed to upload attachment: '.$uploadedFile->getClientOriginalName(),
                'type' => 'error',
            ]);
        }
    }

    protected function extractFileMetadata($filePath, $type): array
    {
        $metadata = [];

        try {
            switch ($type) {
                case 'image':
                    if (function_exists('getimagesize')) {
                        $imageSize = getimagesize($filePath);
                        if ($imageSize) {
                            $metadata['width'] = $imageSize[0];
                            $metadata['height'] = $imageSize[1];
                        }
                    }
                    break;

                case 'video':
                case 'audio':
                    // Could add ffmpeg integration here for duration
                    $metadata['duration'] = null;
                    break;

                case 'document':
                    // Could add page count for PDFs, word count for text
                    break;
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract file metadata', [
                'file_path' => $filePath,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }

        return $metadata;
    }

    public function getAttachmentsForPrism($interactionId): array
    {
        $attachments = ChatInteractionAttachment::where('chat_interaction_id', $interactionId)->get();
        $prismObjects = [];

        foreach ($attachments as $attachment) {
            $prismObject = $attachment->toPrismValueObject();
            if ($prismObject) {
                $prismObjects[] = $prismObject;
            }
        }

        return $prismObjects;
    }

    public string $selectedAgent = 'promptly';

    public string $selectedTab = 'answer';

    public ?int $currentInteractionId = null;

    public array $executionSteps = [];

    public $pendingQuestion = '';

    public $pendingAnswer = '';

    public $isStreaming = false;

    public $currentStatus = 'Starting research...';

    public $stepCounter = 0;

    public $lastRefreshTime = '';

    public $inlineArtifacts = [];

    public $queueJobCounts = [
        'running' => 0,
        'queued' => 0,
        'failed' => 0,
        'completed' => 0,
        'total' => 0,
    ];

    public $queueJobDisplay = [];

    public $blockingExecutionId = null;

    public bool $isCreatingArtifact = false;

    protected $listeners = [
        'chat-interaction-updated' => 'handleChatInteractionUpdated',
        'source-created' => 'handleSourceCreated',
        'chat-interaction-source-created' => 'handleChatInteractionSourceCreated',
        'chat-interaction-artifact-created' => 'handleChatInteractionArtifactCreated',
        'artifact-deleted' => 'handleArtifactDeleted',
        'title-updated' => '$refresh',
        'holistic-workflow-completed' => 'handleHolisticWorkflowCompleted',
        'holistic-workflow-failed' => 'handleHolisticWorkflowFailed',
        'holistic-workflow-updated' => 'handleHolisticWorkflowUpdated',
        'session-restored' => 'handleSessionRestored',
        'research-complete' => 'handleResearchComplete',
        'queue-status-updated' => 'handleQueueStatusUpdated',
        'sessions-updated' => 'loadSessions',
    ];

    /**
     * Handle form submission to start streaming search results.
     */
    public function startSearch()
    {
        Log::info('ChatResearchInterface: startSearch() called', [
            'query' => $this->query,
            'current_session_id' => $this->currentSessionId,
            'current_interaction_id' => $this->currentInteractionId,
            'execution_steps_count' => count($this->executionSteps),
            'timestamp' => now()->toISOString(),
        ]);

        // Validate query is not empty
        if (empty(trim($this->query))) {
            Log::info('ChatResearchInterface: startSearch() aborted - empty query');

            return;
        }

        // Prevent double-submission: Check if there's already a pending execution
        if ($this->hasActivePendingExecution()) {
            Log::info('ChatResearchInterface: Preventing double-submission - active execution already exists', [
                'session_id' => $this->currentSessionId,
                'user_id' => auth()->id(),
                'query' => $this->query,
            ]);

            return;
        }

        // Check if we have an active interaction with the same query to prevent duplicates
        if ($this->currentSessionId) {
            $activeInteraction = \App\Models\ChatInteraction::where('chat_session_id', $this->currentSessionId)
                ->where('question', $this->query)
                ->whereHas('agentExecution', function ($query) {
                    // Query state column: pending='pending', running=['planning','planned','executing','synthesizing']
                    $query->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing']);
                })
                ->first();

            if ($activeInteraction) {
                Log::info('ChatResearchInterface: Preventing duplicate interaction with same query', [
                    'session_id' => $this->currentSessionId,
                    'interaction_id' => $activeInteraction->id,
                    'query' => $this->query,
                ]);
                $this->currentInteractionId = $activeInteraction->id;

                return;
            }
        }

        // Set up UI for new interaction
        $this->selectedTab = 'answer';
        $this->isStreaming = true;
        $this->isThinking = true;
        $this->blockingExecutionId = null; // Clear any previous blocking state
        $this->agentExecutionId = null; // Clear any previous execution state

        // Clear previous thinking process content for new session
        $this->js("
            const container = document.getElementById('thinking-process-container');
            if (container) {
                const initialState = container.querySelector('.relative.mb-6');
                if (initialState) {
                    container.innerHTML = initialState.outerHTML;
                } else {
                    container.innerHTML = '';
                }
            }
        ");

        // Store the pending question for immediate UI display
        $this->pendingQuestion = $this->query;
        $this->pendingAnswer = '';

        // Handle execution steps preservation
        $shouldPreserve = $this->shouldPreserveExecutionSteps();
        if (! $shouldPreserve) {
            $this->executionSteps = [];
        }

        // Create or get session
        $sessionCreated = $this->ensureSession();

        // SECURITY: Validate input length to prevent DoS via oversized input
        // Note: We do NOT strip tags - users may legitimately ask about code/HTML
        // XSS protection happens at output via Blade escaping {{ }}
        $validatedQuestion = \Illuminate\Support\Str::limit($this->query, 10000, ''); // Cap at 10K chars

        if (empty(trim($validatedQuestion))) {
            throw new \Exception('Question cannot be empty');
        }

        // Create interaction record with validated input
        $interaction = ChatInteraction::create([
            'chat_session_id' => $this->currentSessionId,
            'user_id' => auth()->id(), // SECURITY: Explicit auth user (never trust input)
            'question' => $validatedQuestion,
            'answer' => '',
        ]);

        $this->currentInteractionId = $interaction->id;

        // Process attachments
        $this->processAttachmentsForInteraction($interaction->id);
        $this->loadExistingStatusSteps();
        $this->loadInteractions();
        $this->pendingQuestion = '';

        // Clear any existing queue status to prevent old data from appearing
        $jobStatusManager = app(JobStatusManager::class);
        $jobStatusManager->clearJobs((string) $interaction->id);

        // Initialize queue status for new interaction
        $this->refreshQueueStatus();

        // Dispatch UI events
        $this->dispatch('streaming-started');
        $this->dispatch('interaction-added');
        $this->dispatch('interaction-created', [
            'query' => $this->query,
            'agent' => $this->selectedAgent,
            'interactionId' => $interaction->id,
            'preserveWorkflowContinuity' => $shouldPreserve,
        ]);

        // Update URL if new session
        if ($sessionCreated) {
            $newUrl = route('dashboard.research-chat.session', ['sessionId' => $this->currentSessionId]);
            $this->js("window.history.replaceState({}, '', '{$newUrl}')");
        }

        // Store query and agent before clearing
        $queryForExecution = $this->query;
        $selectedAgentForExecution = $this->selectedAgent;

        // Clear query but keep agent selection persistent (Issue #180)
        // Users can manually change agents if needed for follow-up questions
        $this->query = '';

        // ======================================
        // CLEAN THREE-PATH EXECUTION ARCHITECTURE
        // ======================================

        if ($selectedAgentForExecution === 'deeply') {
            // PATH 1: HOLISTIC RESEARCH WORKFLOW
            $this->executeResearchChat($interaction);
        } elseif ($selectedAgentForExecution === 'directly') {
            // PATH 2: DIRECT CHAT STREAMING MODE
            // Bypasses queue for real-time streaming responses
            $this->executeDirectChatMode($interaction);
        } else {
            // PATH 3: SINGLE AGENT EXECUTION
            // This handles: specific agent selection, promptly mode, and legacy chat
            $this->executeSingleAgentMode($interaction, $queryForExecution, $selectedAgentForExecution);
        }
    }

    /**
     * Execute research chat with holistic research system.
     *
     * Creates or reuses an AgentExecution and dispatches ResearchJob to the
     * research-coordinator queue. Handles reconnection after page reload.
     *
     * @param  ChatInteraction  $interaction  The chat interaction to process
     *
     * @throws \Exception If no agents available or job dispatch fails
     */
    protected function executeResearchChat(ChatInteraction $interaction): void
    {
        // Set up StatusReporter using helper method
        $statusReporter = $this->setupStatusReporter($interaction->id);

        try {
            Log::info('ChatResearchInterface: Starting holistic research workflow', [
                'interaction_id' => $interaction->id,
                'query' => $interaction->question,
                'user_id' => auth()->id(),
            ]);

            // Find a suitable workflow agent for holistic research, fallback to any available agent
            $workflowAgent = \App\Models\Agent::where('agent_type', 'workflow')->first();
            $agentId = $workflowAgent ? $workflowAgent->id : \App\Models\Agent::first()->id;

            if (! $agentId) {
                throw new \Exception('No agents available for holistic research execution');
            }

            // Create or reuse execution using helper method
            $execution = $this->createOrReuseExecution(
                $agentId,
                $interaction->question,
                50, // max_steps for holistic research
                $interaction
            );

            // Note: Tool override mode remains active for subsequent requests until manually disabled

            // Link the interaction with the execution
            $interaction->update([
                'agent_execution_id' => $execution->id,
            ]);

            // Dispatch events for UI updates
            $this->dispatch('holistic-research-started', [
                'interactionId' => $interaction->id,
                'executionId' => $execution->id,
                'query' => $interaction->question,
            ]);

            // Set initial status message
            $this->currentStatus = '🤖 **Holistic Research** is analyzing your query...';

            // CRITICAL: Execute holistic research asynchronously via queue to prevent UI blocking
            // while keeping UI-related operations in the Livewire component
            Log::info('ChatResearchInterface: Dispatching HolisticWorkflowJob', [
                'execution_id' => $execution->id,
                'interaction_id' => $interaction->id,
            ]);

            // Initialize UI components before job runs
            $this->pendingAnswer = 'Research is being processed. Results will appear here shortly...';

            // Set default metadata to ensure UI has initial values
            $interaction->update([
                'metadata' => array_merge($interaction->metadata ?? [], [
                    'execution_strategy' => 'pending',
                    'research_threads' => 0,
                    'total_sources' => 0,
                    'duration_seconds' => 0,
                    'holistic_research' => true,
                ]),
            ]);

            // Status reporter will continue to work via the job
            $statusReporter->report('workflow_job_dispatched', 'Your research is being processed in the background. Results will appear here when ready.');

            // Register event listeners for job updates
            $this->registerJobEventListeners($interaction->id, $execution->id);

            // Commit transaction if needed using helper method
            $this->commitTransactionIfNeeded($execution->id, $interaction->id);

            \Log::info('ChatResearchInterface: Transaction state before job dispatch', [
                'execution_id' => $execution->id,
                'interaction_id' => $interaction->id,
                'in_transaction' => \DB::transactionLevel() > 0,
            ]);

            // Dispatch job to research-coordinator queue to avoid UI blocking
            ResearchJob::dispatch(
                ResearchJob::MODE_PLAN,
                $execution->id,
                $interaction->id
            )->onQueue('research-coordinator');

            Log::info('ChatResearchInterface: Launched research plan command', [
                'execution_id' => $execution->id,
                'interaction_id' => $interaction->id,
            ]);

            // Research is now asynchronous - results will be updated via StatusReporter
            // through the job and events will be broadcast through Reverb/Echo

            // We keep thinking process active while job runs
            // Events from the job will update the UI in real-time

            // Let the user know research is happening in background
            $this->dispatch('background-research-started', [
                'interaction_id' => $interaction->id,
                'execution_id' => $execution->id,
            ]);

            // Force reload interactions to show the pending answer
            // This ensures the container is created for results to be loaded into
            $this->loadInteractions();

            // Mark streaming as active but not blocking UI
            $this->isStreaming = true;

            // Job will handle errors, but we should report initial status
            $statusReporter->report('workflow_dispatched', 'Research dispatched to background processing. You can continue using the application while results are being prepared.');

        } catch (\Exception $e) {
            // Handle execution failure using helper method
            $this->handleExecutionFailure(
                $e,
                $interaction,
                $execution ?? null,
                $statusReporter,
                'holistic research'
            );
        }
    }

    /**
     * Execute direct chat mode with real-time streaming without queue.
     *
     * Uses StreamingController for server-sent events. No queue involved,
     * provides immediate AI responses. Tool overrides stored in interaction metadata.
     *
     * @param  ChatInteraction  $interaction  The chat interaction to process
     *
     * @throws \Exception If Direct Chat Agent not found or streaming fails
     */
    protected function executeDirectChatMode(ChatInteraction $interaction): void
    {
        // Set up StatusReporter using helper method
        $statusReporter = $this->setupStatusReporter($interaction->id);

        Log::info('ChatResearchInterface: Starting direct chat mode execution', [
            'interaction_id' => $interaction->id,
            'query' => $interaction->question,
            'user_id' => auth()->id(),
        ]);

        try {
            // Find the Direct Chat Agent
            $directAgent = \App\Models\Agent::directType()->first();

            if (! $directAgent) {
                throw new \Exception('Direct Chat Agent not found. Please run database seeders.');
            }

            Log::info('ChatResearchInterface: Found Direct Chat Agent', [
                'agent_id' => $directAgent->id,
                'agent_name' => $directAgent->name,
            ]);

            // Store tool overrides in interaction metadata if enabled
            if ($this->toolOverrideEnabled) {
                $interaction->update([
                    'metadata' => array_merge($interaction->metadata ?? [], [
                        'tool_overrides' => [
                            'override_enabled' => true,
                            'enabled_tools' => $this->toolOverrides,
                            'enabled_servers' => $this->serverOverrides,
                        ],
                    ]),
                ]);

                Log::info('ChatResearchInterface: Stored tool overrides in Direct Chat interaction metadata', [
                    'interaction_id' => $interaction->id,
                    'tool_overrides' => $this->toolOverrides,
                    'server_overrides' => $this->serverOverrides,
                ]);
            }

            // Set initial UI state
            $this->isStreaming = true;
            $this->isThinking = false; // Direct chat doesn't show thinking process
            $this->pendingAnswer = ''; // Will be populated by streaming

            // Set initial status message
            $this->currentStatus = '⚡ **Direct Chat** is streaming your response...';

            // Dispatch events for UI updates
            $this->dispatch('direct-chat-started', [
                'interactionId' => $interaction->id,
                'agentId' => $directAgent->id,
                'query' => $interaction->question,
            ]);

            // Report initial status
            $statusReporter->report('direct_chat_started', 'Direct chat streaming initiated. Connecting to AI...');

            // Build the full stream URL with query parameters BEFORE loadInteractions
            $streamUrl = route('chat.stream.direct', [
                'query' => $interaction->question,
                'interactionId' => $interaction->id,
            ]);

            Log::info('ChatResearchInterface: Direct chat stream initiated', [
                'interaction_id' => $interaction->id,
                'stream_url' => $streamUrl,
                'query' => $interaction->question,
                'has_query_params' => str_contains($streamUrl, '?'),
            ]);

            // Use JavaScript dispatch instead of Livewire event to avoid timing issues
            // This ensures the event reaches the frontend even during DOM updates
            $this->js("
                console.log('Dispatching direct chat stream event via JavaScript');
                window.dispatchEvent(new CustomEvent('initiate-direct-chat-stream', {
                    detail: {
                        query: ".json_encode($interaction->question).",
                        interactionId: {$interaction->id},
                        streamUrl: ".json_encode($streamUrl).'
                    }
                }));
            ');

            // Force reload interactions to ensure UI is ready
            // This now happens AFTER the event dispatch to avoid race conditions
            $this->loadInteractions();

            // Note: The actual streaming will be handled by StreamingController
            // The frontend will receive chunks via EventSource and update the UI in real-time
            // When streaming completes, the controller will update the interaction with the final answer

        } catch (\Exception $e) {
            // Handle execution failure using helper method
            $this->handleExecutionFailure(
                $e,
                $interaction,
                null, // Direct chat doesn't create execution itself
                $statusReporter,
                'direct chat'
            );

            // Dispatch error event (specific to direct chat)
            $this->dispatch('direct-chat-failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Execute single agent mode - unified execution for all individual agents
     */
    protected function executeSingleAgentMode(ChatInteraction $interaction, string $queryForExecution, string $selectedAgent): void
    {
        // Set up StatusReporter using helper method
        $statusReporter = $this->setupStatusReporter($interaction->id);

        Log::info('ChatResearchInterface: Starting single agent mode execution', [
            'interaction_id' => $interaction->id,
            'query' => $interaction->question,
            'selected_agent' => $selectedAgent,
            'user_id' => auth()->id(),
        ]);

        try {
            // Determine which agent to use
            $agent = $this->getSelectedAgent($selectedAgent);

            if (! $agent) {
                throw new \Exception('No suitable agents available for single agent execution');
            }

            // Create or reuse execution using helper method
            $execution = $this->createOrReuseExecution(
                $agent->id,
                $interaction->question,
                $agent->max_steps,
                $interaction
            );

            // Note: Tool override mode remains active for subsequent requests until manually disabled

            // Link the interaction with the execution
            $interaction->update(['agent_execution_id' => $execution->id]);

            // Dispatch events for UI updates
            $this->dispatch('single-agent-execution-started', [
                'interactionId' => $interaction->id,
                'executionId' => $execution->id,
                'agentId' => $agent->id,
                'agentName' => $agent->name,
                'query' => $interaction->question,
            ]);

            // Set initial status message
            $this->currentStatus = "🤖 **{$agent->name}** is processing your request...";

            // Execute single agent mode via queue to prevent UI blocking
            Log::info('ChatResearchInterface: Dispatching SingleAgentJob', [
                'execution_id' => $execution->id,
                'interaction_id' => $interaction->id,
                'agent_id' => $agent->id,
            ]);

            // Initialize UI components
            $this->pendingAnswer = 'Your request is being processed. Results will appear here shortly...';

            // Set default metadata for single agent execution
            $interaction->update([
                'metadata' => array_merge($interaction->metadata ?? [], [
                    'execution_strategy' => 'single_agent',
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'research_threads' => 1,
                    'total_sources' => 0,
                    'duration_seconds' => 0,
                    'single_agent_execution' => true,
                ]),
            ]);

            // Report status
            $statusReporter->report('single_agent_job_dispatched', "Your request is being processed by {$agent->name}. Results will appear here when ready.");

            // Register event listeners for job updates
            $this->registerJobEventListeners($interaction->id, $execution->id);

            // Commit transaction if needed using helper method
            $this->commitTransactionIfNeeded($execution->id, $interaction->id);

            // Dispatch job to single-agent queue for processing
            ResearchJob::dispatch(
                ResearchJob::MODE_SINGLE_AGENT, // Use single agent mode
                $execution->id,
                $interaction->id
            )->onQueue('single-agent');

            Log::info('ChatResearchInterface: Launched single agent execution job', [
                'execution_id' => $execution->id,
                'interaction_id' => $interaction->id,
                'agent_id' => $agent->id,
            ]);

            // Let the user know execution is happening
            $this->dispatch('single-agent-execution-background-started', [
                'interaction_id' => $interaction->id,
                'execution_id' => $execution->id,
                'agent_id' => $agent->id,
            ]);

            // Force reload interactions to show the pending answer
            $this->loadInteractions();

            // Mark streaming as active
            $this->isStreaming = true;

            // Report initial status
            $statusReporter->report('single_agent_dispatched', 'Single agent execution dispatched for processing. You can continue using the application while results are being prepared.');

        } catch (\Exception $e) {
            // Handle execution failure using helper method
            $this->handleExecutionFailure(
                $e,
                $interaction,
                $execution ?? null,
                $statusReporter,
                'single agent execution'
            );
        }
    }

    /**
     * Set up status reporter for execution tracking.
     *
     * Creates a StatusReporter instance and binds it to the service container
     * for use throughout the execution lifecycle.
     *
     * @param  int  $interactionId  Chat interaction ID for status tracking
     * @return \App\Services\StatusReporter Configured status reporter instance
     */
    private function setupStatusReporter(int $interactionId): \App\Services\StatusReporter
    {
        $statusReporter = new \App\Services\StatusReporter($interactionId);
        app()->instance('status_reporter', $statusReporter);

        Log::info('ChatResearchInterface: StatusReporter configured', [
            'interaction_id' => $interactionId,
            'interaction_id_type' => gettype($interactionId),
        ]);

        return $statusReporter;
    }

    /**
     * Create or reuse agent execution with common configuration.
     *
     * Handles execution reuse for reconnected sessions and creates new executions
     * with tool override support when needed.
     *
     * @param  int  $agentId  Agent ID to use for execution
     * @param  string  $question  User's query/question
     * @param  int  $maxSteps  Maximum steps for agent execution
     * @param  ChatInteraction  $interaction  Current chat interaction
     * @return AgentExecution Created or reused execution instance
     */
    private function createOrReuseExecution(
        int $agentId,
        string $question,
        int $maxSteps,
        ChatInteraction $interaction
    ): AgentExecution {
        // Check for reconnected flag from session restoration
        $isReconnected = session('reconnected_execution_'.$interaction->id, false);

        // Try to find existing execution linked to interaction
        if ($interaction->agent_execution_id) {
            $existing = AgentExecution::find($interaction->agent_execution_id);
            if ($existing) {
                Log::info('ChatResearchInterface: Reusing existing execution', [
                    'execution_id' => $existing->id,
                    'interaction_id' => $interaction->id,
                    'is_reconnected' => $isReconnected,
                    'execution_status' => $existing->status,
                ]);

                // Clear reconnected flag
                if ($isReconnected) {
                    session()->forget('reconnected_execution_'.$interaction->id);
                }

                return $existing;
            }
        }

        // Check for session-level active executions
        // Query state column: pending='pending', running=['planning','planned','executing','synthesizing']
        $sessionExecution = AgentExecution::where('chat_session_id', $this->currentSessionId)
            ->where('user_id', auth()->id())
            ->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing'])
            ->first();

        if ($sessionExecution) {
            Log::info('ChatResearchInterface: Reusing session-level execution', [
                'execution_id' => $sessionExecution->id,
                'chat_session_id' => $this->currentSessionId,
                'interaction_id' => $interaction->id,
                'is_reconnected' => $isReconnected,
            ]);

            // Link execution to interaction
            if ($interaction->agent_execution_id !== $sessionExecution->id) {
                $interaction->update(['agent_execution_id' => $sessionExecution->id]);
            }

            // Clear reconnected flag
            if ($isReconnected) {
                session()->forget('reconnected_execution_'.$interaction->id);
            }

            return $sessionExecution;
        }

        // Create new execution
        Log::info('ChatResearchInterface: Creating new execution', [
            'interaction_id' => $interaction->id,
            'agent_id' => $agentId,
            'is_reconnected' => $isReconnected,
            'tool_override_enabled' => $this->toolOverrideEnabled,
        ]);

        $execution = AgentExecution::create([
            'agent_id' => $agentId,
            'user_id' => auth()->id(),
            'input' => $question,
            'status' => 'running',
            'max_steps' => $maxSteps,
            'chat_session_id' => $this->currentSessionId,
        ]);

        // Apply tool overrides if enabled
        if ($this->toolOverrideEnabled) {
            $execution->setToolOverrides($this->toolOverrides, $this->serverOverrides);

            Log::info('ChatResearchInterface: Applied tool overrides to execution', [
                'execution_id' => $execution->id,
                'tool_overrides' => $this->toolOverrides,
                'server_overrides' => $this->serverOverrides,
            ]);
        }

        // Clear reconnected flag
        if ($isReconnected) {
            session()->forget('reconnected_execution_'.$interaction->id);
        }

        return $execution;
    }

    /**
     * Handle execution failure with consistent error reporting.
     *
     * Updates interaction, marks execution as failed, reports via StatusReporter,
     * and resets UI state.
     *
     * @param  \Exception  $e  Exception that caused the failure
     * @param  ChatInteraction  $interaction  Chat interaction to update
     * @param  AgentExecution|null  $execution  Optional execution to mark as failed
     * @param  \App\Services\StatusReporter|null  $statusReporter  Optional status reporter
     * @param  string  $context  Context description for error messages
     */
    private function handleExecutionFailure(
        \Exception $e,
        ChatInteraction $interaction,
        ?AgentExecution $execution = null,
        ?\App\Services\StatusReporter $statusReporter = null,
        string $context = 'execution'
    ): void {
        Log::error("ChatResearchInterface: {$context} failed", [
            'interaction_id' => $interaction->id,
            'execution_id' => $execution?->id,
            'error' => $e->getMessage(),
            'error_class' => get_class($e),
        ]);

        // Update interaction with error
        $interaction->update([
            'answer' => "❌ Failed to start {$context}: ".$e->getMessage(),
        ]);

        // Update execution if exists
        if ($execution) {
            $execution->update([
                'status' => 'failed',
                'error_message' => "{$context} error: ".$e->getMessage(),
                'completed_at' => now(),
            ]);
        }

        // Report via StatusReporter
        if ($statusReporter) {
            $statusReporter->report('error', "Failed to start {$context}: ".$e->getMessage());
        }

        // Reset UI state
        $this->isStreaming = false;
        $this->isThinking = false;

        // Reload interactions to show error
        $this->loadInteractions();
    }

    /**
     * Commit active database transaction if needed.
     *
     * Ensures transaction is committed before dispatching queue jobs to prevent
     * jobs from accessing uncommitted data.
     *
     * @param  int  $executionId  Execution ID for logging context
     * @param  int  $interactionId  Interaction ID for logging context
     */
    private function commitTransactionIfNeeded(int $executionId, int $interactionId): void
    {
        try {
            if (\DB::transactionLevel() > 0) {
                \DB::commit();
                Log::info('ChatResearchInterface: Committed transaction before job dispatch', [
                    'execution_id' => $executionId,
                    'interaction_id' => $interactionId,
                    'transaction_level' => \DB::transactionLevel(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('ChatResearchInterface: Error during transaction handling', [
                'error' => $e->getMessage(),
                'execution_id' => $executionId,
                'interaction_id' => $interactionId,
            ]);
        }
    }

    /**
     * Legacy method - now redirects to unified single agent execution
     *
     * @deprecated Use executeSingleAgentMode instead
     */
    public function executeAgent(ChatInteraction $interaction, string $queryForExecution): void
    {
        Log::info('ChatResearchInterface: executeAgent called - redirecting to single agent mode', [
            'interaction_id' => $interaction->id,
            'query' => $queryForExecution,
            'selected_agent' => $this->selectedAgent,
        ]);

        // Redirect to the unified single agent execution method
        $this->executeSingleAgentMode($interaction, $queryForExecution, $this->selectedAgent);
    }

    /**
     * Get the selected agent model for single agent execution
     */
    protected function getSelectedAgent(?string $selectedAgent = null): ?Agent
    {
        // Use provided agent or fall back to component property
        $agentValue = $selectedAgent ?? $this->selectedAgent;

        // Handle special cases first
        if ($agentValue === 'deeply') {
            // Research mode uses holistic workflow, not single agent
            return null;
        }

        if ($agentValue === 'promptly') {
            // Find the Promptly Agent by name
            return Agent::where('name', 'Promptly Agent')->first();
        }

        // Specific agent ID selected
        return Agent::find($agentValue);
    }

    /**
     * Ensure we have a chat session (only create if none exists)
     */
    protected function ensureSession(): bool
    {
        if (! $this->currentSessionId) {
            // Generate default title with current date and time
            $defaultTitle = 'Chat '.now()->format('m-d-Y H:i');

            // Only create a session when we actually submit a query
            $session = ChatSession::create([
                'user_id' => auth()->id(),
                'title' => $defaultTitle,
            ]);

            $this->currentSessionId = $session->id;

            // Reload sessions to include the new one
            $this->loadSessions();

            return true; // Session was created
        }

        return false; // Session already existed
    }

    /**
     * Poll agent execution for results
     */
    public function pollAgentExecution($interactionId, $executionId): void
    {
        $execution = \App\Models\AgentExecution::find($executionId);

        if (! $execution) {
            return;
        }

        if ($execution->isCompleted()) {
            // Update interaction with final results
            $interaction = ChatInteraction::find($interactionId);
            if ($interaction) {
                $interaction->update(['answer' => $execution->output]);

                // Dispatch event for side effect listeners (Phase 3: side effects via events only)
                // Listener: TrackResearchUrls
                \App\Events\ResearchWorkflowCompleted::dispatch(
                    $interaction,
                    $execution->output,
                    ['execution_id' => $execution->id],
                    'research_interface_agent'
                );

                // Extract and store source links (UI-specific, not global side effect)
                $this->extractAgentSourceLinks($execution, $interactionId);

                // Extract execution steps (UI-specific, not global side effect)
                $this->extractExecutionSteps($execution);

                // Mark streaming as complete
                $this->isStreaming = false;

                // Force reload interactions to show the updated result
                $this->loadInteractions();

                // Update the UI with final results
                $this->dispatch('agent-completed', [
                    'result' => $execution->output,
                    'sources' => $this->sourceLinks[$interactionId] ?? [],
                    'steps' => $this->executionSteps,
                ]);
            }
        } elseif ($execution->isFailed()) {
            // Mark streaming as complete on failure
            $this->isStreaming = false;

            // Update interaction with error message
            $interaction = ChatInteraction::find($interactionId);
            if ($interaction) {
                $errorMessage = '❌ **Agent execution failed**: '.($execution->error_message ?? 'Unknown error');
                $interaction->update(['answer' => $errorMessage]);

                // Force reload interactions to show the error
                $this->loadInteractions();
            }

            $this->dispatch('agent-failed', [
                'error' => $execution->error_message ?? 'Agent execution failed',
            ]);
        } else {
            // Continue polling
            $this->dispatch('continue-agent-polling', [
                'interactionId' => $interactionId,
                'executionId' => $executionId,
            ]);
        }
    }

    /**
     * Extract source links from agent execution
     */
    protected function extractAgentSourceLinks($execution, int $interactionId): void
    {
        $metadata = $execution->metadata ?? [];

        if (isset($metadata['source_links'])) {
            $this->sourceLinks[$interactionId] = $metadata['source_links'];
        }
    }

    /**
     * Extract execution steps from agent execution
     */
    protected function extractExecutionSteps($execution): void
    {
        $metadata = $execution->metadata ?? [];

        if (isset($metadata['execution_steps'])) {
            $this->executionSteps = $metadata['execution_steps'];
        }
    }

    /**
     * Change active tab
     */
    public function selectTab(string $tab): void
    {
        $this->selectedTab = $tab;
    }

    /**
     * Force refresh of current tab data (can be called from frontend during streaming)
     */
    public function forceRefreshCurrentTab(): void
    {
        if ($this->isStreaming && $this->currentInteractionId) {
            // Process any pending EventStream events first
            $this->processQueuedEventStreamEvents();

            // Then refresh the current tab
            $this->refreshTabData($this->selectedTab);

            // Force Livewire to detect changes by updating a timestamp property
            $this->forceRerender();
        }
    }

    /**
     * Force component re-render by updating a reactive property
     */
    protected function forceRerender(): void
    {
        // Increment step counter to force re-render of Steps tab
        $this->stepCounter = count($this->executionSteps);

        // Dispatch a generic refresh event
        $this->dispatch('component-refreshed', [
            'tab' => $this->selectedTab,
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Refresh current tab data from database (called when switching tabs during research)
     */
    public function refreshTabData(string $tab): void
    {
        if (! $this->currentInteractionId) {
            return;
        }

        switch ($tab) {
            case 'steps':
                $this->refreshStepsData();
                break;
            case 'sources':
                $this->refreshSourcesData();
                break;
            case 'answer':
                $this->refreshAnswerData();
                break;
        }

        // Note: selectedTab is now managed by Alpine.js, not Livewire
    }

    /**
     * Refresh steps data from StatusStream and AgentExecution
     */
    protected function refreshStepsData(): void
    {
        if (! $this->currentInteractionId) {
            return;
        }

        // During streaming, preserve existing steps and merge with database steps
        $existingSteps = $this->isStreaming ? $this->executionSteps : [];

        // Load steps from StatusStream using workflow-aware method
        $statusSteps = $this->loadWorkflowAwareStatusSteps();

        $databaseSteps = [];
        foreach ($statusSteps as $status) {
            $stepData = [
                'id' => $status->id, // Add StatusStream ID for modal opening
                'action' => null, // Remove technical source labels
                'description' => $status->message,
                'timestamp' => $status->timestamp->format('H:i:s'),
                'tool' => $status->source,
                'data' => [],
                'source' => 'database',
            ];

            // Extract duration from metadata if available
            if ($status->metadata && isset($status->metadata['step_duration_ms'])) {
                $stepData['duration_ms'] = $status->metadata['step_duration_ms'];
                $stepData['duration_formatted'] = $this->formatDuration($status->metadata['step_duration_ms']);
            }

            $databaseSteps[] = $stepData;
        }

        // If streaming, merge existing steps with database steps, avoiding duplicates
        if ($this->isStreaming && ! empty($existingSteps)) {
            // Create a combined array, preferring database steps for accuracy
            $mergedSteps = $databaseSteps;

            // Add any existing steps that aren't in database yet
            foreach ($existingSteps as $existingStep) {
                $isDuplicate = false;
                foreach ($databaseSteps as $dbStep) {
                    if ($dbStep['tool'] === $existingStep['tool'] &&
                        $dbStep['description'] === $existingStep['description']) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if (! $isDuplicate) {
                    $existingStep['source'] = 'live';
                    $existingStep['action'] = null; // Remove technical source labels
                    $mergedSteps[] = $existingStep;
                }
            }

            $this->executionSteps = $mergedSteps;
        } else {
            // Not streaming or no existing steps, use database steps only
            $this->executionSteps = $databaseSteps;
        }

        // Update step counter to force UI re-render
        $this->stepCounter = count($this->executionSteps);

        // DO NOT update lastRefreshTime - preserve original research start time
        // DO NOT dispatch $refresh - WebSocket StatusStreamManager handles UI updates

        // Explicitly mark properties as dirty to ensure Livewire detects changes
        $this->updatedExecutionSteps();
    }

    /**
     * Format duration from milliseconds to human-readable format
     */
    protected function formatDuration(float $durationMs): string
    {
        if ($durationMs < 1000) {
            return round($durationMs).'ms';
        } elseif ($durationMs < 60000) {
            return round($durationMs / 1000, 1).'s';
        } else {
            $seconds = $durationMs / 1000;
            $minutes = floor($seconds / 60);
            $remainingSeconds = round($seconds % 60, 1);

            return $minutes.'m '.$remainingSeconds.'s';
        }
    }

    /**
     * Get average execution time for a specific agent type
     */
    private function getAverageExecutionTime(?string $agentType = null): int
    {
        if (! $agentType) {
            $agentType = $this->selectedAgent;
        }

        // Get completed executions for this agent type from the last 30 days
        // Join with agents table to filter by agent_type
        $averageSeconds = \App\Models\AgentExecution::join('agents', 'agent_executions.agent_id', '=', 'agents.id')
            ->where('agent_executions.status', 'completed')
            ->where('agents.agent_type', $agentType)
            ->where('agent_executions.created_at', '>=', now()->subDays(30))
            ->whereNotNull('agent_executions.completed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, agent_executions.created_at, agent_executions.completed_at)) as avg_duration')
            ->value('avg_duration');

        if (! $averageSeconds) {
            // Fallback estimates based on agent type
            $defaultEstimates = [
                'chat' => 120, // 2 minutes
                'workflow' => 300, // 5 minutes
                'individual' => 240, // 4 minutes
                'deeply-workflow' => 480, // 8 minutes
            ];

            return $defaultEstimates[$agentType] ?? 180; // Default 3 minutes
        }

        return (int) round($averageSeconds);
    }

    /**
     * Format execution time estimate for display
     */
    private function formatExecutionTimeEstimate(?string $agentType = null): string
    {
        $seconds = $this->getAverageExecutionTime($agentType);
        $minutes = round($seconds / 60);

        if ($minutes < 1) {
            return 'less than 1 minute';
        } elseif ($minutes === 1) {
            return '1 minute';
        } else {
            return "{$minutes} minutes";
        }
    }

    /**
     * Called when executionSteps property is updated to ensure UI refresh
     */
    public function updatedExecutionSteps(): void
    {
        $this->stepCounter = count($this->executionSteps);
    }

    /**
     * Refresh sources data by dispatching to child components
     */
    protected function refreshSourcesData(): void
    {
        if (! $this->currentInteractionId) {
            return;
        }

        // Check how many sources exist in database
        $sourcesCount = \App\Models\ChatInteractionSource::where('chat_interaction_id', $this->currentInteractionId)->count();

        // Dispatch to SourcesTabContent component to refresh sources
        $this->dispatch('refreshSources');

        // Also dispatch a more specific event with interaction data
        $this->dispatch('refreshSourcesForInteraction', [
            'interactionId' => $this->currentInteractionId,
            'sourcesCount' => $sourcesCount,
        ]);
    }

    /**
     * Refresh answer data from database
     */
    protected function refreshAnswerData(): void
    {
        if (! $this->currentInteractionId) {
            return;
        }

        // Reload interactions to get the latest answer
        $this->loadInteractions();

        // Sync with agent executions if needed
        $this->syncInteractionAnswersWithExecutions();
    }

    /**
     * Refresh all tab data from database (useful for periodic updates)
     */
    public function refreshAllTabsData(): void
    {
        if (! $this->currentInteractionId) {
            return;
        }

        $this->refreshStepsData();
        $this->refreshSourcesData();
        $this->refreshAnswerData();

        // Also check for any queued EventStream events and process them
        if ($this->isStreaming) {
            $this->processQueuedEventStreamEvents();
        }

    }

    /**
     * Process any queued EventStream events (useful for manual refresh during streaming)
     */
    protected function processQueuedEventStreamEvents(): void
    {
        if (! $this->currentInteractionId) {
            return;
        }

        // Get queued events from EventStreamNotifier cache
        $events = \App\Services\EventStreamNotifier::getAndClearEvents($this->currentInteractionId);

        foreach ($events as $event) {
            switch ($event['type']) {
                case 'step_added':
                    $this->handleEventStreamStepAdded($event);
                    break;
                case 'source_added':
                    $this->handleEventStreamSourceAdded($event);
                    break;
                case 'interaction_updated':
                    $this->handleEventStreamInteractionUpdated($event);
                    break;
            }
        }

    }

    /**
     * Update research steps from JavaScript when streaming completes
     */
    public function updateResearchSteps(array $steps): void
    {
        // Merge with existing steps instead of overwriting
        $this->executionSteps = array_merge($this->executionSteps, $steps);
    }

    /**
     * Handle real-time tool status updates for live feedback
     */
    public function handleToolStatusUpdate($statusData = []): void
    {
        // Process tool status updates and convert to execution steps
        $step = [
            'action' => $statusData['status'] ?? 'Tool Update',
            'description' => $statusData['message'] ?? 'Tool is running...',
            'timestamp' => now()->format('H:i:s'),
            'tool' => $statusData['tool'] ?? 'unknown',
            'data' => $statusData,
        ];

        // Add special handling for counter progress
        if (isset($statusData['current_count']) && isset($statusData['counter_max'])) {
            $step['action'] = 'Counter Progress';
            $step['description'] = "Count: {$statusData['current_count']}/{$statusData['counter_max']} ({$statusData['percentage']}%)";
        }

        // Add to execution steps
        $this->executionSteps[] = $step;

    }

    /**
     * Load execution steps from the current interaction's metadata
     */
    protected function loadExecutionStepsFromMetadata(): void
    {
        if (! $this->currentInteractionId) {
            $this->executionSteps = [];

            return;
        }

        // Need to explicitly select metadata since it's excluded by default
        $interaction = \App\Models\ChatInteraction::where('id', $this->currentInteractionId)
            ->addSelect('metadata')
            ->first();
        if ($interaction && isset($interaction->metadata['execution_steps'])) {
            $this->executionSteps = $interaction->metadata['execution_steps'];
        } else {
            $this->executionSteps = [];
        }
    }

    /**
     * Get status stream updates for the current interaction
     */
    public function getStatusUpdates(): array
    {
        if (! $this->currentInteractionId) {
            return [];
        }

        $statusUpdates = StatusStream::forInteraction($this->currentInteractionId);

        return $statusUpdates->map(function ($update) {
            return [
                'action' => $update->source,
                'description' => $update->message,
                'timestamp' => $update->timestamp->format('H:i:s'),
                'tool' => $update->source,
                'data' => [],
            ];
        })->toArray();
    }

    /**
     * Get combined timeline for an interaction (StatusStream + AgentExecution phases)
     */
    public function getCombinedTimelineForInteraction($interactionId): array
    {
        $combinedTimeline = [];

        // Load StatusStream entries for this interaction
        $statusEntries = StatusStream::where('interaction_id', $interactionId)
            ->orderBy('timestamp')
            ->get();

        foreach ($statusEntries as $status) {
            $combinedTimeline[] = [
                'id' => $status->id, // Add ID at top level for modal opening
                'type' => 'status',
                'action' => null, // Remove technical source labels
                'description' => $status->message,
                'timestamp' => $status->timestamp->format('H:i:s'),
                'full_timestamp' => $status->timestamp,
                'tool' => $status->source,
                'data' => [
                    'source' => $status->source,
                    'message' => $status->message,
                    'id' => $status->id,
                ],
            ];
        }

        // Find AgentExecution entries for this session around the interaction time
        $interaction = \App\Models\ChatInteraction::find($interactionId);
        if ($interaction && $interaction->chat_session_id) {
            $executions = \App\Models\AgentExecution::where('chat_session_id', $interaction->chat_session_id)
                ->where('created_at', '>=', $interaction->created_at->subMinutes(5))
                ->where('created_at', '<=', $interaction->created_at->addMinutes(30))
                ->get();

            foreach ($executions as $execution) {
                $phaseTimeline = $execution->phase_timeline ?? [];

                foreach ($phaseTimeline as $phase) {
                    $combinedTimeline[] = [
                        'id' => null, // Phase entries don't have StatusStream IDs
                        'type' => 'phase',
                        'action' => $phase['phase'] ?? 'Phase',
                        'description' => $phase['message'] ?? $phase['phase'] ?? 'Phase update',
                        'timestamp' => \Carbon\Carbon::parse($phase['timestamp'])->format('H:i:s'),
                        'full_timestamp' => \Carbon\Carbon::parse($phase['timestamp']),
                        'tool' => 'agent_execution',
                        'data' => $phase,
                    ];
                }
            }
        }

        // Sort combined timeline chronologically
        usort($combinedTimeline, function ($a, $b) {
            return $a['full_timestamp']->timestamp <=> $b['full_timestamp']->timestamp;
        });

        return $combinedTimeline;
    }

    /**
     * Replace thinking process with final report
     */
    public function showFinalReport(string $answer, array $sources = []): void
    {
        Log::info('ChatResearchInterface: showFinalReport called', [
            'interaction_id' => $this->currentInteractionId,
            'answer_length' => strlen($answer),
            'sources_count' => count($sources),
        ]);

        try {
            // Render the final report
            $finalReportHtml = view('livewire.partials.final-report', [
                'answer' => $answer,
                'sources' => $sources,
                'interaction_id' => $this->currentInteractionId,
            ])->render();

            Log::info('ChatResearchInterface: Final report HTML rendered', [
                'html_length' => strlen($finalReportHtml),
            ]);

            // Replace thinking process with final report
            $this->stream(to: 'thinking-process', content: $finalReportHtml, replace: true);

            Log::info('ChatResearchInterface: Final report streamed to thinking-process container');

            // Note: Keep $isThinking = true to preserve wire:stream container
            // The container is needed for potential WebSocket fallback updates
            // $this->isThinking will be set to false by the completion handler

        } catch (\Exception $e) {
            Log::error('ChatResearchInterface: Failed to show final report', [
                'error' => $e->getMessage(),
                'interaction_id' => $this->currentInteractionId,
            ]);
        }
    }

    /**
     * Store the final answer to prevent Livewire from overriding JavaScript updates
     */
    public function setFinalAnswer(string $answer): void
    {
        // Update the interaction with the final answer if we have one
        if ($this->currentInteractionId) {
            // Need to explicitly select metadata since it's excluded by default
            $interaction = \App\Models\ChatInteraction::where('id', $this->currentInteractionId)
                ->addSelect('metadata')
                ->first();
            if ($interaction) {
                // Store both answer and execution steps as metadata
                $metadata = $interaction->metadata ?? [];
                if (! empty($this->executionSteps)) {
                    $metadata['execution_steps'] = $this->executionSteps;
                }

                $interaction->update([
                    'answer' => $answer,
                    'metadata' => $metadata,
                ]);

                // Dispatch event for side effect listeners (Phase 3: side effects via events only)
                // Listener: TrackResearchUrls
                \App\Events\ResearchWorkflowCompleted::dispatch(
                    $interaction,
                    $answer,
                    $metadata,
                    'research_interface_workflow'
                );

                // Show the final report in the thinking process area (UI-specific, not global side effect)
                $this->showFinalReport($answer);

                // Mark streaming as complete
                $this->isStreaming = false;
                $this->isThinking = false;

                // Immediately reload interactions to ensure UI reflects the update
                $this->loadInteractions();

                // Dispatch event to scroll to bottom for updated answer
                $this->dispatch('answer-updated');

            }
        }
    }

    /**
     * Generate title for session if needed (first interaction with answer)
     */
    protected function generateTitleIfNeeded(\App\Models\ChatInteraction $interaction): void
    {
        \App\Services\SessionTitleService::generateTitleIfNeeded($interaction);
        // Reload sessions to show the new title
        $this->loadSessions();
    }

    /**
     * Mount component with research-specific setup
     */
    public function mount($sessionId = null)
    {
        try {
            // Call parent mount first to set up sessions, tools, and agents
            parent::mount();

            // After parent mount, ensure tool configuration matches override state
            if (! $this->toolOverrideEnabled) {
                // If override is disabled, load agent configuration instead of user preferences
                $this->loadCurrentAgentToolConfiguration();
            }

            // Keep both individual agents and workflows for research interface
            // This allows testing individual agents like Search Strategy Agent
            // Filter is removed to enable individual agent testing

            // Handle session ID from URL or create one if none exists
            if ($sessionId) {
                $this->loadSessionFromUrl($sessionId);
            } else {
                // No session ID in URL - automatically create a session and redirect to it
                $lastSession = \App\Models\ChatSession::where('user_id', auth()->id())
                    ->latest('updated_at')
                    ->first();

                if ($lastSession) {
                    // Redirect to the last session
                    return redirect()->route('dashboard.research-chat.session', ['sessionId' => $lastSession->id]);
                } else {
                    // No sessions exist, create a new one and redirect
                    $defaultTitle = 'Chat '.now()->format('m-d-Y H:i');
                    $session = \App\Models\ChatSession::create([
                        'user_id' => auth()->id(),
                        'title' => $defaultTitle,
                    ]);

                    return redirect()->route('dashboard.research-chat.session', ['sessionId' => $session->id]);
                }
            }
        } catch (\Exception $e) {
            \Log::error('ChatResearchInterface mount failed', [
                'error' => $e->getMessage(),
                'sessionId' => $sessionId,
            ]);

            // Set safe defaults to prevent redirect loops
            $this->currentSessionId = null;
            $this->interactions = [];

            $this->availableAgents = [];
            $this->sessions = collect();
            $this->query = '';
            $this->currentInteractionId = null;
            $this->executionSteps = [];
            $this->pendingQuestion = '';
            $this->pendingAnswer = '';
            $this->isStreaming = false;
        }
    }

    /**
     * Load a specific session from URL parameter
     */
    protected function loadSessionFromUrl($sessionId)
    {
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', auth()->id())
            ->first();

        if ($session) {
            $this->currentSessionId = $sessionId;
            $this->loadInteractions();

            // Leave query empty when loading existing session
            $firstInteraction = $session->interactions()
                ->orderBy('created_at')
                ->first();
            if ($firstInteraction) {
                $this->query = '';  // Keep text input empty for new queries
                $latestInteraction = $session->interactions()
                    ->orderBy('created_at', 'desc')
                    ->first();
                $this->currentInteractionId = $latestInteraction?->id;

                // Check if this is an ongoing research interaction that needs the thinking UI
                if ($latestInteraction && $latestInteraction->agent_execution_id) {
                    $execution = \App\Models\AgentExecution::find($latestInteraction->agent_execution_id);
                    if ($execution) {
                        if (in_array($execution->status, ['pending', 'running'])) {
                            // For active executions, enable thinking view to show timeline
                            $this->isThinking = true;
                            \Log::info('ChatResearchInterface: Detected active execution during mount, enabling thinking view', [
                                'interaction_id' => $latestInteraction->id,
                                'execution_id' => $latestInteraction->agent_execution_id,
                                'execution_status' => $execution->status,
                            ]);
                        } else {
                            // For completed executions, check if we have status steps to display
                            $statusCount = \App\Models\StatusStream::where('interaction_id', $latestInteraction->id)->count();
                            if ($statusCount > 0) {
                                $this->isThinking = true;
                                \Log::info('ChatResearchInterface: Detected completed execution with status steps, enabling thinking view', [
                                    'interaction_id' => $latestInteraction->id,
                                    'execution_id' => $latestInteraction->agent_execution_id,
                                    'status_count' => $statusCount,
                                ]);
                            }
                        }
                    }
                }

                $this->loadExecutionStepsFromMetadata();
            } else {
                $this->query = '';
            }

            $this->selectedTab = 'answer';

            // Dispatch event to scroll to bottom when session is loaded
            $this->dispatch('session-loaded');

        } else {
            // Session not found or doesn't belong to user

            // Only redirect if we're not already on the base route to prevent loops
            if (request()->route()->getName() !== 'dashboard.research-chat') {
                return redirect()->route('dashboard.research-chat');
            } else {
                // Already on base route, just reset to clean state
                $this->currentSessionId = null;
                $this->interactions = [];

                $this->query = '';
                $this->currentInteractionId = null;
                $this->executionSteps = [];
            }
        }
    }

    /**
     * Handle agent selection change to reset tool overrides and load agent tools
     */
    public function updatedSelectedAgent($value)
    {
        Log::info('ChatResearchInterface: Agent selection changed', [
            'old_agent' => $this->selectedAgent,
            'new_agent' => $value,
            'tool_override_enabled' => $this->toolOverrideEnabled,
        ]);

        // Always disable tool override mode when switching agents
        if ($this->toolOverrideEnabled) {
            $this->disableToolOverride();
            Log::info('ChatResearchInterface: Disabled tool override mode due to agent switch');
        }

        // Load the configured tools of the selected agent into tool settings
        $this->loadAgentToolConfiguration($value);

        Log::info('ChatResearchInterface: Agent switch completed', [
            'selected_agent' => $value,
            'tool_override_enabled' => $this->toolOverrideEnabled,
            'enabled_tools_count' => count($this->enabledTools),
            'enabled_servers_count' => count($this->enabledServers),
        ]);
    }

    /**
     * Load tool configuration for the current selected agent (used when disabling override)
     */
    protected function loadCurrentAgentToolConfiguration(): void
    {
        if (! $this->selectedAgent) {
            return;
        }

        $this->loadAgentToolConfiguration($this->selectedAgent);
    }

    /**
     * Load tool configuration for the selected agent
     */
    protected function loadAgentToolConfiguration($agentValue): void
    {
        // Handle special agent values
        if (in_array($agentValue, ['deeply', 'promptly', 'directly'])) {
            // For special modes, load default tool configuration
            Log::info('ChatResearchInterface: Special agent mode selected, loading default configuration', [
                'agent_mode' => $agentValue,
            ]);

            // Load default tools for special modes (when override is disabled)
            $defaultTools = ['hello-world', 'searxng_search', 'link_validator', 'bulk_link_validator', 'markitdown', 'knowledge_search', 'research_sources', 'source_content'];
            $defaultServers = []; // No MCP servers by default for special modes

            $this->enabledTools = $defaultTools;
            $this->enabledServers = $defaultServers;

            Log::info('ChatResearchInterface: Loaded default tools for special agent mode', [
                'agent_mode' => $agentValue,
                'enabled_tools' => $this->enabledTools,
                'enabled_servers' => $this->enabledServers,
            ]);

            return;
        }

        // Load specific agent configuration
        $agent = Agent::with(['enabledTools'])->find($agentValue);
        if (! $agent) {
            Log::warning('ChatResearchInterface: Agent not found when loading tool configuration', [
                'agent_id' => $agentValue,
            ]);

            return;
        }

        // Load agent's enabled tools into the tool settings
        $agentToolNames = $agent->enabledTools->pluck('tool_name')->toArray();
        $this->enabledTools = $agentToolNames;

        Log::info('ChatResearchInterface: Loaded agent tool configuration', [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'loaded_tools' => $agentToolNames,
            'tools_count' => count($agentToolNames),
        ]);

        // Save the new tool preferences
        $this->saveUserToolPreferences();
    }

    /**
     * Get available agents formatted for the research dropdown - simplified architecture
     */
    public function getResearchAgentsProperty(): array
    {
        $agents = [
            'promptly' => 'Promptly Agent', // Fast, versatile default
            'deeply' => 'Deeply Agent', // Holistic research workflow
            'directly' => 'Directly Agent', // Real-time streaming AI responses
        ];

        // Add individual agents (including Promptly Agent itself)
        foreach ($this->availableAgents as $agent) {
            if ($agent['agent_type'] === 'individual') {
                $agents[$agent['id']] = $agent['name'];
            }
        }

        // Add workflow agents last (for advanced users)
        foreach ($this->availableAgents as $agent) {
            if ($agent['agent_type'] === 'workflow') {
                $agents[$agent['id']] = $agent['name'];
            }
        }

        return $agents;
    }

    /**
     * Override parent openSession to redirect to session URL
     *
     * @param  int  $sessionId  The session ID to open
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function openSession($sessionId)
    {
        // SECURITY: Verify session ownership BEFORE redirect
        // Without this check, any authenticated user could access any session via URL manipulation
        $session = \App\Models\ChatSession::where('id', $sessionId)
            ->where('user_id', auth()->id())
            ->firstOrFail(); // Throws 404 if not found or not owned by current user

        Log::info('Opening chat session', [
            'session_id' => $sessionId,
            'user_id' => auth()->id(),
            'session_title' => $session->title ?? 'Untitled',
        ]);

        // Redirect to session-specific URL
        return redirect()->route('dashboard.research-chat.session', ['sessionId' => $sessionId]);
    }

    /**
     * Override parent deleteSession to handle deletion and redirect appropriately
     */
    public function deleteSession($sessionId)
    {
        $session = \App\Models\ChatSession::where('id', $sessionId)
            ->where('user_id', auth()->id())
            ->first();

        if ($session) {
            // Delete associated interactions and sources
            \App\Models\ChatInteraction::where('chat_session_id', $sessionId)->delete();

            // Delete the session
            $session->delete();

            // Reload sessions
            $this->loadSessions();

            // If we deleted the current session, redirect appropriately
            if ($this->currentSessionId == $sessionId) {
                if ($this->sessions->isEmpty()) {
                    // No sessions left, redirect to base research chat
                    return redirect()->route('dashboard.research-chat');
                } else {
                    // Switch to the first available session
                    $firstSession = $this->sessions->first();

                    return redirect()->route('dashboard.research-chat.session', ['sessionId' => $firstSession->id]);
                }
            }

            // If we're not in the deleted session, just refresh the component
            $this->dispatch('$refresh');
        }
    }

    /**
     * Override parent createSession to create and redirect to new session URL
     */
    public function createSession()
    {
        // Create a new session using the parent method
        $defaultTitle = 'Chat '.now()->format('m-d-Y H:i');
        $session = \App\Models\ChatSession::create([
            'user_id' => auth()->id(),
            'title' => $defaultTitle,
        ]);

        $this->loadSessions();
        $this->currentSessionId = $session->id;
        $this->loadInteractions();

        // Set this as the last active session
        $this->setLastActiveSession($session->id);

        // Redirect to the new session URL
        return redirect()->route('dashboard.research-chat.session', ['sessionId' => $session->id]);
    }

    /**
     * Register event listeners for job updates
     */
    protected function registerJobEventListeners(int $interactionId, int $executionId): void
    {
        Log::info('ChatResearchInterface: Registering job event listeners', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
        ]);

        // The events will be broadcast using Reverb and picked up by Echo
        // in the frontend JavaScript and routed to the appropriate handler
    }

    public function handleHolisticWorkflowCompleted($data = []): void
    {
        Log::info('ChatResearchInterface: Received holistic workflow completed event', [
            'data' => $data,
        ]);

        // Get interaction ID from event data
        $interactionId = $data['interaction_id'] ?? null;
        if (! $interactionId || $interactionId !== $this->currentInteractionId) {
            return; // Not for current interaction
        }

        $result = $data['result'] ?? '';
        $metadata = $data['metadata'] ?? [];
        $sources = $data['sources'] ?? [];

        // Update executionSteps if provided
        if (isset($data['steps'])) {
            $this->executionSteps = array_merge($this->executionSteps, $data['steps']);
        }

        // Store source links if provided
        if (! empty($sources)) {
            $this->sourceLinks[$interactionId] = $sources;
        }

        // Update final answer using setFinalAnswer method
        // Skip if broadcast was truncated - full answer is already in database
        $isTruncated = $metadata['broadcast_truncated'] ?? false;
        if (! empty($result) && ! $isTruncated) {
            $this->setFinalAnswer($result);
        }

        // Mark streaming as complete
        $this->isStreaming = false;
        $this->isThinking = false;

        // Keep the user's agent selection persistent (Issue #180)
        // Users can manually change agents if needed for follow-up questions

        // Force reload interactions to show the updated result
        // This will load the full answer from database if broadcast was truncated
        $this->loadInteractions();

        // Dispatch completion events for UI
        $this->dispatch('agent-completed', [
            'result' => $result,
            'sources' => $sources,
            'steps' => $this->executionSteps,
        ]);
    }

    /**
     * Handle holistic workflow failure event from background job
     */
    public function handleHolisticWorkflowFailed($data = []): void
    {
        Log::info('ChatResearchInterface: Received holistic workflow failed event', [
            'data' => $data,
        ]);

        // Get interaction ID from event data
        $interactionId = $data['interaction_id'] ?? null;
        if (! $interactionId || $interactionId !== $this->currentInteractionId) {
            return; // Not for current interaction
        }

        $error = $data['error'] ?? 'Unknown error in holistic research';

        // Mark streaming as complete
        $this->isStreaming = false;
        $this->isThinking = false;

        // Force reload interactions to show the error
        $this->loadInteractions();

        // Dispatch error event for UI
        $this->dispatch('agent-failed', [
            'error' => $error,
        ]);
    }

    /**
     * Handle holistic workflow update events from background job
     *
     * @deprecated Moving to WebSocket-only updates - this method disabled
     */
    public function handleHolisticWorkflowUpdated($data = []): void
    {
        // Log for monitoring during transition to WebSocket-only updates
        Log::info('DEPRECATED: Holistic workflow update handler called - now using WebSocket-only', [
            'interaction_id' => $data['interaction_id'] ?? 'unknown',
            'has_status' => isset($data['status']),
            'has_step' => isset($data['step']),
            'transition_note' => 'WebSocket StatusStreamManager handles all updates now',
        ]);

        // COMMENTED OUT: Status updates now handled by WebSocket only
        /*
        // Get interaction ID from event data
        $interactionId = $data['interaction_id'] ?? null;
        if (!$interactionId || $interactionId !== $this->currentInteractionId) {
            return; // Not for current interaction
        }

        // Update status if provided
        if (isset($data['status'])) {
            $this->currentStatus = $data['status'];
        }

        // Update executionSteps if provided
        if (isset($data['step'])) {
            $this->executionSteps[] = $data['step'];
            $this->stepCounter = count($this->executionSteps);
        }

        // Force component refresh
        $this->dispatch('$refresh');
        */
    }

    /**
     * Regenerate session title using AI based on full conversation context
     */
    public function regenerateTitle($sessionId = null): void
    {
        $sessionId = $sessionId ?: $this->currentSessionId;
        if (! $sessionId) {
            return;
        }

        $session = \App\Models\ChatSession::where('id', $sessionId)
            ->where('user_id', auth()->id())
            ->first();

        if (! $session) {
            return;
        }

        $interactions = $session->interactions()
            ->orderBy('created_at')
            ->get();
        if ($interactions->isEmpty()) {
            return;
        }

        // Build conversation context from all interactions
        $conversationContext = $interactions->map(function ($interaction) {
            return "Q: {$interaction->question}\nA: ".(($interaction->answer) ?: '[No response]');
        })->join("\n\n");

        try {
            // Use the centralized TitleGenerator service
            $titleGenerator = new \App\Services\TitleGenerator;

            // For multi-interaction conversations, use the first question and latest answer
            $firstQuestion = $interactions->first()->question;
            $latestAnswer = $interactions->filter(fn ($i) => ! empty($i->answer))->last()?->answer ?? '';

            $title = $titleGenerator->generateFromContent($firstQuestion, $latestAnswer);

            if ($title) {
                $session->update(['title' => $title]);
                $this->loadSessions();
                $this->dispatch('title-updated', ['title' => $title]);
                $this->dispatch('$refresh');

                \Log::info('ChatResearchInterface: Regenerated title using TitleGenerator', [
                    'session_id' => $sessionId,
                    'title' => $title,
                ]);
            }
        } catch (\Throwable $e) {
            \Log::error('ChatResearchInterface: Failed to regenerate title using TitleGenerator', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            // Fallback: use first question
            $firstQuestion = $interactions->first()->question;
            $title = \Illuminate\Support\Str::words($firstQuestion, 5, '');
            if ($title) {
                $session->update(['title' => trim($title)]);
                $this->loadSessions();
                $this->dispatch('$refresh');
            }
        }
    }

    /**
     * Update session title (for inline editing)
     */
    public function updateSessionTitle(string $newTitle, $sessionId = null): void
    {
        $sessionId = $sessionId ?: $this->currentSessionId;
        if (! $sessionId) {
            return;
        }

        $session = \App\Models\ChatSession::where('id', $sessionId)
            ->where('user_id', auth()->id())
            ->first();

        if ($session) {
            $trimmedTitle = trim($newTitle);
            $session->update(['title' => $trimmedTitle]);
            $this->loadSessions();
            $this->dispatch('title-updated', ['title' => $trimmedTitle]);
            $this->dispatch('$refresh');
        }
    }

    /**
     * Implementation of required abstract method
     */
    public function sendMessage()
    {
        // Research interface uses startSearch instead
        $this->startSearch();
    }

    /**
     * Retry a specific chat interaction by prefilling the search box
     */
    public function retryInteraction($interactionId)
    {
        try {
            $interaction = ChatInteraction::find($interactionId);

            if (! $interaction) {
                $this->addError('retry', 'Interaction not found.');

                return;
            }

            // Check if interaction belongs to current session
            if ($interaction->chat_session_id !== $this->currentSessionId) {
                $this->addError('retry', 'Cannot retry interaction from different session.');

                return;
            }

            // Check if there's already a request in progress
            if ($this->isStreaming) {
                $this->addError('retry', 'Please wait for current request to complete.');

                return;
            }

            Log::info('ChatResearchInterface: Retrying interaction', [
                'interaction_id' => $interactionId,
                'original_question' => $interaction->question,
                'session_id' => $this->currentSessionId,
            ]);

            // Simple approach: just set the query and clear errors
            $this->query = $interaction->question;
            $this->resetErrorBag();

            // Show a notification that the question has been loaded
            $this->dispatch('notify', [
                'message' => 'Question loaded in search box - click "Ask" to retry',
                'type' => 'info',
            ]);

        } catch (\Exception $e) {
            Log::error('ChatResearchInterface: Error retrying interaction', [
                'interaction_id' => $interactionId,
                'error' => $e->getMessage(),
            ]);

            $this->addError('retry', 'Failed to retry interaction. Please try again.');
        }
    }

    // Echo listeners removed - using JavaScript Echo directly for dynamic channel subscription

    /**
     * Load existing StatusStream steps for current interaction
     */
    protected function loadExistingStatusSteps(): void
    {
        if (! $this->currentInteractionId) {
            return;
        }

        // Use workflow-aware status loading to support multi-agent workflows
        $statusSteps = $this->loadWorkflowAwareStatusSteps();

        foreach ($statusSteps as $status) {
            $stepData = [
                'id' => $status->id, // Add StatusStream ID for modal opening
                'action' => $status->source,
                'description' => $status->message,
                'timestamp' => $status->timestamp->format('H:i:s'),
                'tool' => $status->source,
                'data' => [],
            ];

            // Extract duration from metadata if available
            if ($status->metadata && isset($status->metadata['step_duration_ms'])) {
                $stepData['duration_ms'] = $status->metadata['step_duration_ms'];
                $stepData['duration_formatted'] = $this->formatDuration($status->metadata['step_duration_ms']);
            }

            $this->executionSteps[] = $stepData;
        }

        $this->stepCounter = count($this->executionSteps);
    }

    /**
     * Load status streams from all executions related to current interaction (workflow-aware)
     */
    protected function loadWorkflowAwareStatusSteps(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->currentInteractionId) {
            return collect();
        }

        // Get the ChatInteraction and its linked execution
        $interaction = \App\Models\ChatInteraction::find($this->currentInteractionId);
        if (! $interaction || ! $interaction->agent_execution_id) {
            // Fallback to original behavior if no execution linked
            return \App\Models\StatusStream::where('interaction_id', $this->currentInteractionId)
                ->orderBy('timestamp')
                ->get();
        }

        // Get the linked execution
        $linkedExecution = \App\Models\AgentExecution::find($interaction->agent_execution_id);
        if (! $linkedExecution) {
            // Fallback to original behavior if execution not found
            return \App\Models\StatusStream::where('interaction_id', $this->currentInteractionId)
                ->orderBy('timestamp')
                ->get();
        }

        // Get all workflow executions (parent + children OR just this one if not a workflow)
        $workflowExecutions = $linkedExecution->getWorkflowExecutions();
        $executionIds = $workflowExecutions->pluck('id');

        Log::info('ChatResearchInterface: Loading workflow-aware status steps', [
            'interaction_id' => $this->currentInteractionId,
            'linked_execution_id' => $interaction->agent_execution_id,
            'workflow_execution_ids' => $executionIds->toArray(),
            'is_workflow' => $linkedExecution->isWorkflowExecution(),
        ]);

        // Load status streams from ALL executions in the workflow
        return \App\Models\StatusStream::where('interaction_id', $this->currentInteractionId)
            ->orderBy('timestamp')
            ->get();
    }

    /**
     * Check if we should preserve execution steps to maintain workflow continuity
     */
    protected function shouldPreserveExecutionSteps(): bool
    {
        if (! $this->currentSessionId) {
            Log::info('ChatResearchInterface: shouldPreserveExecutionSteps = false (no session ID)', [
                'current_session_id' => $this->currentSessionId,
            ]);

            return false;
        }

        // Check if there are any pending/running executions (includes parent workflows)
        // This would indicate an ongoing workflow that should preserve thinking process
        // Query state column: pending='pending', running=['planning','planned','executing','synthesizing']
        $executions = \App\Models\AgentExecution::where('chat_session_id', $this->currentSessionId)
            ->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing'])
            ->get();

        $activeExecutions = $executions->count() > 0;

        Log::info('ChatResearchInterface: shouldPreserveExecutionSteps evaluation', [
            'session_id' => $this->currentSessionId,
            'current_execution_steps_count' => count($this->executionSteps),
            'active_executions_found' => $activeExecutions,
            'executions_count' => $executions->count(),
            'executions_details' => $executions->map(function ($exec) {
                return [
                    'id' => $exec->id,
                    'status' => $exec->status,
                    'agent_name' => $exec->agent->name ?? 'unknown',
                    'parent_id' => $exec->parent_agent_execution_id,
                ];
            })->toArray(),
        ]);

        if ($activeExecutions) {
            Log::info('ChatResearchInterface: Preserving execution steps due to active workflow execution');

            return true;
        }

        // ENHANCED: Also preserve if we're currently in thinking/streaming state
        // This prevents clearing thinking process history during ongoing WebSocket status updates
        if ($this->isThinking || $this->isStreaming) {
            Log::info('ChatResearchInterface: Preserving execution steps due to active thinking/streaming state', [
                'is_thinking' => $this->isThinking,
                'is_streaming' => $this->isStreaming,
            ]);

            return true;
        }

        // Also preserve if there are recent status streams (within last 30 seconds)
        // This handles cases where WebSocket updates are still coming after execution completes
        if ($this->currentInteractionId) {
            $recentStatusStreams = \App\Models\StatusStream::where('interaction_id', $this->currentInteractionId)
                ->where('created_at', '>', now()->subSeconds(30))
                ->count();

            if ($recentStatusStreams > 0) {
                Log::info('ChatResearchInterface: Preserving execution steps due to recent status streams', [
                    'recent_status_streams_count' => $recentStatusStreams,
                    'interaction_id' => $this->currentInteractionId,
                ]);

                return true;
            }
        }

        Log::info('ChatResearchInterface: NOT preserving execution steps - no active executions, thinking, or recent status');

        return false;
    }

    /**
     * Check if there's already an active (pending/running) execution to prevent double-submission
     */
    protected function hasActivePendingExecution(): bool
    {
        if (! $this->currentSessionId) {
            return false;
        }

        // Check for any pending or running executions in this session for the current user
        // This prevents rapid double-clicks from creating duplicate executions
        // Only consider executions updated within the last 20 minutes as active (older ones are stale/abandoned)
        // NOTE: Query 'state' column directly since 'status' is a computed attribute
        // State mapping: pending='pending', running=['planning','planned','executing','synthesizing']
        $activeExecution = \App\Models\AgentExecution::where('chat_session_id', $this->currentSessionId)
            ->where('user_id', auth()->id())
            ->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing'])
            ->where('updated_at', '>', now()->subMinutes(20))
            ->first();

        if ($activeExecution) {
            // Store blocking execution info for UI display
            $this->blockingExecutionId = $activeExecution->id;

            return true;
        }

        // Also check if we have a running execution with an agentExecutionId saved already
        if ($this->agentExecutionId) {
            $activeExecution = \App\Models\AgentExecution::find($this->agentExecutionId);
            if ($activeExecution && in_array($activeExecution->status, ['pending', 'running'])) {
                // Also apply timeout check here
                if ($activeExecution->updated_at > now()->subMinutes(20)) {
                    Log::info('ChatResearchInterface: Found active execution via agentExecutionId', [
                        'execution_id' => $this->agentExecutionId,
                        'status' => $activeExecution->status,
                    ]);

                    $this->blockingExecutionId = $activeExecution->id;

                    return true;
                } else {
                    Log::info('ChatResearchInterface: Ignoring stale execution via agentExecutionId', [
                        'execution_id' => $this->agentExecutionId,
                        'status' => $activeExecution->status,
                        'updated_at' => $activeExecution->updated_at,
                    ]);
                }
            }
        }

        // Clear blocking execution ID if no active execution found
        $this->blockingExecutionId = null;

        return false;
    }

    /**
     * Handle status stream updates from broadcasts
     *
     * @deprecated Moving to WebSocket-only updates to prevent race conditions
     * This method is disabled to prevent competing update mechanisms between
     * Livewire and WebSocket status streams in multi-process php-fpm environment.
     */
    public function handleStatusStreamUpdate($data): void
    {
        // Log for monitoring during transition to WebSocket-only updates
        Log::info('DEPRECATED: Livewire status update handler called - now using WebSocket-only', [
            'interaction_id' => $this->currentInteractionId,
            'source' => $data['source'] ?? 'unknown',
            'message' => isset($data['message']) ? substr($data['message'], 0, 100) : 'no message',
            'transition_note' => 'WebSocket StatusStreamManager handles all updates now',
        ]);

        // DO NOT update executionSteps - WebSocket StatusStreamManager handles all updates
        // DO NOT dispatch $refresh - prevents race conditions with WebSocket updates
        // DO NOT update currentStatus - JavaScript StatusStreamManager handles real-time display

        // All status updates are now handled exclusively by:
        // 1. WebSocket broadcasting via StatusStreamCreated events
        // 2. JavaScript StatusStreamManager for real-time DOM updates
        // 3. No server-side Livewire state management for status streams

        // COMMENTED OUT: Previously handled workflow completion and DOM updates
        // Now handled entirely by WebSocket StatusStreamManager
        /*
        if (isset($data['source'], $data['message'])) {
            // Handle workflow completion
            if ($data['source'] === 'agent_execution_completed') {
                $this->isStreaming = false;
                $this->isThinking = false;
                // Force reload interactions to show the final answer
                $this->loadInteractions();
            }
            // Force immediate DOM update using JavaScript
            $this->js('console.log("Step added: ' . addslashes($data['message']) . '");');
        }
        */
    }

    /**
     * Handle echo chat status updates
     */
    public function handleEchoStatusUpdate($data): void
    {

        // Update streaming status
        if (isset($data['status'])) {
            $this->isStreaming = $data['status'] === 'streaming';
        }

        // Update pending answer if provided
        if (isset($data['answer'])) {
            $this->pendingAnswer = $data['answer'];
        }

        // Add execution step if provided
        if (isset($data['step'])) {
            $this->executionSteps[] = $data['step'];
        }
    }

    /**
     * Handle echo agent execution updates
     */
    public function handleEchoAgentUpdate($data): void
    {

        // Update agent execution status
        if (isset($data['status'])) {
            switch ($data['status']) {
                case 'completed':
                    $this->isStreaming = false;
                    $this->isThinking = false;
                    if (isset($data['result'])) {
                        $this->setFinalAnswer($data['result']);
                    }
                    break;
                case 'failed':
                    $this->isStreaming = false;
                    $this->isThinking = false;
                    if (isset($data['error'])) {
                        $this->dispatch('agent-failed', ['error' => $data['error']]);
                    }
                    break;
                case 'running':
                    $this->isStreaming = true;
                    break;
            }
        }

        // Add execution step if provided
        if (isset($data['step'])) {
            $this->executionSteps[] = $data['step'];
        }
    }

    /**
     * Handle echo tool status updates
     */
    public function handleEchoToolUpdate($data): void
    {

        // Process tool status updates similar to existing handler
        $this->handleToolStatusUpdate($data);
    }

    /**
     * Handle echo research stream updates
     */
    public function handleEchoStreamUpdate($data): void
    {

        // Update research stream content
        if (isset($data['content'])) {
            $this->pendingAnswer .= $data['content'];
        }

        // Update execution steps if provided
        if (isset($data['steps'])) {
            $this->executionSteps = array_merge($this->executionSteps, $data['steps']);
        }

        // Handle stream completion
        if (isset($data['status']) && $data['status'] === 'completed') {
            $this->isStreaming = false;
            $this->isThinking = false;
            if (isset($data['final_answer'])) {
                $this->setFinalAnswer($data['final_answer']);
            }
        }
    }

    /**
     * Override parent loadInteractions to sync with AgentExecutions
     */
    public function loadInteractions()
    {
        // Call parent method first
        parent::loadInteractions();

        // PERFORMANCE: Eager load all relationships in single query to prevent N+1
        // Before fix: N interactions × 2 queries each = 2N+1 total queries
        // After fix: 3 queries total (interactions, attachments, artifacts+tags)
        if (! empty($this->interactions)) {
            // Check if we have model instances to eager load
            $interactionIds = collect($this->interactions)->pluck('id')->filter()->toArray();

            if (! empty($interactionIds)) {
                // Re-query with eager loading to get an Eloquent Collection
                // This allows us to use ->load() which only works on Eloquent Collections
                $loadedInteractions = ChatInteraction::whereIn('id', $interactionIds)
                    ->with([
                        'attachments',          // Load interaction attachments
                        'artifacts.artifact.tags',   // Load artifacts with nested relationships
                    ])
                    ->orderBy('created_at', 'asc')
                    ->get();

                // Replace interactions with eagerly loaded versions
                $this->interactions = $loadedInteractions;
            }

            // Process inline artifacts without additional queries
            // Data already loaded via eager loading above
            foreach ($this->interactions as $interaction) {
                $artifacts = $interaction->artifacts
                    ->pluck('artifact')
                    ->filter()
                    ->unique('id'); // Ensure each artifact appears only once per interaction

                $this->inlineArtifacts[$interaction->id] = $artifacts;
            }
        }

        // Sync with agent executions
        $this->syncInteractionAnswersWithExecutions();
    }

    /**
     * Sync interaction answers with their linked AgentExecutions
     */
    protected function syncInteractionAnswersWithExecutions(): void
    {
        $updated = false;

        foreach ($this->interactions as $interaction) {
            // If interaction has no answer but has a linked agent execution, get the output
            if (empty($interaction->answer) && $interaction->agent_execution_id) {
                $execution = AgentExecution::find($interaction->agent_execution_id);
                if ($execution && $execution->isCompleted() && $execution->output) {
                    $interaction->update(['answer' => $execution->output]);

                    // Dispatch event for side effect listeners (Phase 3: side effects via events only)
                    // Listener: TrackResearchUrls
                    \App\Events\ResearchWorkflowCompleted::dispatch(
                        $interaction,
                        $execution->output,
                        ['execution_id' => $execution->id],
                        'research_interface_sync'
                    );

                    $updated = true;
                }
            }
        }

        // Reload interactions if any were updated
        if ($updated) {
            parent::loadInteractions();
        }
    }

    /**
     * Handle EventStream step added events (for Steps tab)
     *
     * @deprecated Moving to WebSocket-only updates - this method disabled
     */
    public function handleEventStreamStepAdded($eventData)
    {
        // Log for monitoring during transition to WebSocket-only updates
        Log::info('DEPRECATED: EventStream step handler called - now using WebSocket-only', [
            'interaction_id' => $this->currentInteractionId,
            'event_source' => $eventData['data']['source'] ?? 'unknown',
            'has_message' => isset($eventData['data']['message']),
            'transition_note' => 'WebSocket StatusStreamManager handles all step updates now',
        ]);

        // COMMENTED OUT: Step updates now handled by WebSocket only
        /*
        // Add the step to executionSteps for real-time display in Steps tab
        $this->executionSteps[] = [
            'action' => null, // Remove technical source labels
            'description' => $eventData['data']['message'] ?? '',
            'timestamp' => $eventData['data']['timestamp'] ?? now()->format('H:i:s'),
            'tool' => $eventData['data']['source'] ?? 'system',
            'data' => [],
            'source' => 'eventstream'
        ];

        // Update step counter for UI reactivity
        $this->stepCounter = count($this->executionSteps);

        // Force Livewire to detect the change by touching the executionSteps array
        $this->dispatch('$refresh');
        */
    }

    /**
     * Handle EventStream source added events (for Sources tab)
     *
     * @deprecated Moving to WebSocket-only updates - this method disabled
     */
    public function handleEventStreamSourceAdded($eventData)
    {
        // Log for monitoring during transition to WebSocket-only updates
        Log::info('DEPRECATED: EventStream source handler called - now using WebSocket-only', [
            'interaction_id' => $this->currentInteractionId,
            'has_event_data' => ! empty($eventData),
            'transition_note' => 'WebSocket StatusStreamManager handles all source updates now',
        ]);

        // COMMENTED OUT: Source updates now handled by WebSocket only
        /*
        // Emit to child components (SourcesTabContent) to refresh sources
        $this->dispatch('refreshSources');

        // Also force parent component refresh
        $this->dispatch('$refresh');
        */
    }

    /**
     * Handle EventStream interaction updated events (for Answers tab)
     */
    public function handleEventStreamInteractionUpdated($eventData)
    {

        // Reload interactions to show the updated answer
        $this->loadInteractions();

        // If we received a complete answer, stop streaming
        if ($eventData['data']['has_answer'] ?? false) {
            $this->isStreaming = false;
            $this->isThinking = false;
        }
    }

    /**
     * Handle session restoration after page reload for ongoing research
     */
    public function handleSessionRestored()
    {
        \Log::info('ChatResearchInterface: Handling session-restored event', [
            'interaction_id' => $this->currentInteractionId,
        ]);

        if (! $this->currentInteractionId) {
            return;
        }

        // Make sure isThinking is true to show the timeline instead of fallback view
        $this->isThinking = true;

        // Retrieve the current interaction to get associated execution
        $interaction = \App\Models\ChatInteraction::find($this->currentInteractionId);
        if ($interaction && $interaction->agent_execution_id) {
            // Find the associated execution
            $execution = \App\Models\AgentExecution::find($interaction->agent_execution_id);
            if ($execution) {
                // Store execution ID for tracking and potential cancellation
                $this->agentExecutionId = $execution->id;

                // Set important component properties to match existing execution
                if ($execution->agent_id) {
                    try {
                        $agent = \App\Models\Agent::find($execution->agent_id);
                        if ($agent) {
                            $this->selectedAgent = $agent->id;
                        }
                    } catch (\Exception $e) {
                        // Ignore error if agent doesn't exist
                    }
                }

                \Log::info('ChatResearchInterface: Reconnected to existing execution on page reload', [
                    'interaction_id' => $this->currentInteractionId,
                    'execution_id' => $execution->id,
                    'execution_status' => $execution->status,
                    'selected_agent' => $this->selectedAgent,
                ]);

                // Special flag to mark this session as reconnected to avoid duplicate executions
                session(['reconnected_execution_'.$this->currentInteractionId => true]);
            }
        }

        // Reload existing status steps
        $this->loadExistingStatusSteps();

        // Refresh all tabs to ensure proper state after reload
        $this->refreshAllTabsData();

        \Log::info('ChatResearchInterface: Session restored successfully', [
            'is_thinking' => $this->isThinking,
            'execution_steps' => count($this->executionSteps),
            'agent_execution_id' => $this->agentExecutionId ?? null,
        ]);
    }

    /**
     * Handle research complete event from ResearchComplete broadcast
     */
    public function handleResearchComplete($data = []): void
    {
        Log::info('ChatResearchInterface: Received research-complete event', [
            'data' => $data,
            'current_interaction_id' => $this->currentInteractionId,
        ]);

        // Get interaction ID from event data
        $interactionId = $data['interactionId'] ?? null;
        if (! $interactionId || $interactionId !== $this->currentInteractionId) {
            Log::info('ChatResearchInterface: Ignoring research-complete event for different interaction', [
                'event_interaction_id' => $interactionId,
                'current_interaction_id' => $this->currentInteractionId,
            ]);

            return; // Not for current interaction
        }

        Log::info('ChatResearchInterface: Processing research-complete event for current interaction', [
            'interaction_id' => $interactionId,
        ]);

        // Research is complete, stop streaming and thinking
        $this->isStreaming = false;
        $this->isThinking = false;

        // Clear agent execution ID since execution is complete
        $this->agentExecutionId = null;

        // Keep the user's agent selection persistent (Issue #180)
        // Users can manually change agents if needed for follow-up questions

        // Force reload interactions to show the updated answer
        $this->loadInteractions();

        // Refresh all tab data to ensure UI is up to date
        $this->refreshAllTabsData();

        // Dispatch UI event for any frontend JavaScript handlers
        $this->dispatch('research-completed', [
            'interaction_id' => $interactionId,
            'timestamp' => $data['timestamp'] ?? now()->toISOString(),
        ]);

        Log::info('ChatResearchInterface: Research complete event processed successfully', [
            'interaction_id' => $interactionId,
            'is_streaming' => $this->isStreaming,
            'is_thinking' => $this->isThinking,
        ]);
    }

    /**
     * Handle chat interaction updated event from ChatInteraction model
     */
    public function handleChatInteractionUpdated($data = []): void
    {
        Log::info('ChatResearchInterface: Received chat-interaction-updated event', [
            'data' => $data,
            'current_interaction_id' => $this->currentInteractionId,
        ]);

        // Get interaction ID from event data - could be in different formats
        $interactionId = $data['interaction_id'] ?? $data['interactionId'] ?? null;
        if (! $interactionId || $interactionId !== $this->currentInteractionId) {
            Log::info('ChatResearchInterface: Ignoring chat-interaction-updated event for different interaction', [
                'event_interaction_id' => $interactionId,
                'current_interaction_id' => $this->currentInteractionId,
            ]);

            return; // Not for current interaction
        }

        Log::info('ChatResearchInterface: Processing chat-interaction-updated event for current interaction', [
            'interaction_id' => $interactionId,
        ]);

        // Reload interactions to show the updated answer
        $this->loadInteractions();

        // Check if we have an answer now to stop streaming
        $hasAnswer = $data['has_answer'] ?? $data['answer'] ?? false;
        if ($hasAnswer) {
            $this->isStreaming = false;
            $this->isThinking = false;

            // Clear agent execution ID since execution is complete
            $this->agentExecutionId = null;

            Log::info('ChatResearchInterface: Stopping streaming due to completed answer', [
                'interaction_id' => $interactionId,
            ]);
        }

        // Refresh all tab data to ensure UI is up to date
        $this->refreshAllTabsData();

        // Dispatch UI event for any frontend JavaScript handlers
        $this->dispatch('interaction-updated', [
            'interaction_id' => $interactionId,
            'has_answer' => $hasAnswer,
        ]);

        Log::info('ChatResearchInterface: Chat interaction updated event processed successfully', [
            'interaction_id' => $interactionId,
            'has_answer' => $hasAnswer,
            'is_streaming' => $this->isStreaming,
            'is_thinking' => $this->isThinking,
        ]);
    }

    /**
     * Handle queue status updated event from WebSocket broadcasting
     */
    public function handleQueueStatusUpdated($data = []): void
    {
        Log::info('Queue status update received', [
            'data' => $data,
            'current_interaction_id' => $this->currentInteractionId,
        ]);

        // Get interaction ID from event data
        $interactionId = $data['interaction_id'] ?? null;
        if (! $interactionId || $interactionId !== $this->currentInteractionId) {
            Log::info('Ignoring queue status update for different interaction', [
                'event_interaction_id' => $interactionId,
                'current_interaction_id' => $this->currentInteractionId,
            ]);

            return; // Not for current interaction
        }

        // Fetch fresh data from Redis and store in component properties
        $this->refreshQueueStatus();

        Log::info('Queue status refreshed', [
            'counts' => $this->queueJobCounts,
            'display_count' => count($this->queueJobDisplay),
        ]);
    }

    /**
     * Refresh queue status from Redis and update component properties
     */
    public function refreshQueueStatus(): void
    {
        if (! $this->currentInteractionId) {
            $this->queueJobCounts = [
                'running' => 0,
                'queued' => 0,
                'failed' => 0,
                'completed' => 0,
                'total' => 0,
            ];
            $this->queueJobDisplay = [];

            return;
        }

        try {
            $jobStatusManager = app(JobStatusManager::class);

            // Update counts property
            $counts = $jobStatusManager->getJobCounts((string) $this->currentInteractionId);
            $this->queueJobCounts = array_merge($counts, ['batches' => []]);

            // Update display property
            $this->queueJobDisplay = $jobStatusManager->getJobStatusDisplay((string) $this->currentInteractionId);
        } catch (\Exception $e) {
            Log::error('Error refreshing queue status', [
                'interaction_id' => $this->currentInteractionId,
                'error' => $e->getMessage(),
            ]);

            $this->queueJobCounts = [
                'running' => 0,
                'queued' => 0,
                'failed' => 0,
                'completed' => 0,
                'total' => 0,
            ];
            $this->queueJobDisplay = [];
        }
    }

    /**
     * Get queue job counts for the current chat session using JobStatusManager
     */
    public function getQueueJobCounts(): array
    {
        if (! $this->currentInteractionId) {
            return [
                'running' => 0,
                'queued' => 0,
                'failed' => 0,
                'completed' => 0,
                'total' => 0,
                'batches' => [],
            ];
        }

        try {
            $jobStatusManager = app(JobStatusManager::class);
            $counts = $jobStatusManager->getJobCounts((string) $this->currentInteractionId);

            // Add empty batches array to maintain compatibility with existing UI
            $counts['batches'] = [];

            return $counts;

        } catch (\Exception $e) {
            \Log::error('ChatResearchInterface: Error getting queue job counts from JobStatusManager', [
                'interaction_id' => $this->currentInteractionId,
                'error' => $e->getMessage(),
            ]);

            return [
                'running' => 0,
                'queued' => 0,
                'failed' => 0,
                'completed' => 0,
                'total' => 0,
                'batches' => [],
            ];
        }
    }

    /**
     * Cancel all pending jobs for the current chat session
     */
    public function cancelPendingJobs(): void
    {
        if (! $this->currentSessionId) {
            return;
        }

        try {
            \Log::info('ChatResearchInterface: Cancelling pending jobs', [
                'session_id' => $this->currentSessionId,
                'user_id' => auth()->id(),
            ]);

            // Get all running executions for current session (remove user filter for session-based jobs)
            // Query state column: pending='pending', running=['planning','planned','executing','synthesizing']
            $runningExecutions = \App\Models\AgentExecution::where('chat_session_id', $this->currentSessionId)
                ->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing'])
                ->get();

            $cancelledCount = 0;
            $cancelledBatches = 0;

            foreach ($runningExecutions as $execution) {
                // Cancel the execution
                $execution->cancel();
                $cancelledCount++;

                // Cancel associated batch if it exists
                $metadata = $execution->metadata ?? [];
                if (isset($metadata['batch_id'])) {
                    $batch = \Illuminate\Support\Facades\Bus::findBatch($metadata['batch_id']);
                    if ($batch && ! $batch->finished() && ! $batch->cancelled()) {
                        $batch->cancel();
                        $cancelledBatches++;

                        \Log::info('ChatResearchInterface: Cancelled batch', [
                            'batch_id' => $batch->id,
                            'execution_id' => $execution->id,
                        ]);
                    }
                }
            }

            // Update any linked interactions
            if ($this->currentInteractionId) {
                $interaction = \App\Models\ChatInteraction::find($this->currentInteractionId);
                if ($interaction && empty($interaction->answer)) {
                    $interaction->update([
                        'answer' => '🛑 **Research cancelled** by user request.',
                    ]);
                }
            }

            // Stop streaming state
            $this->isStreaming = false;
            $this->isThinking = false;

            // Dispatch notification for UI
            $this->dispatch('jobs-cancelled', [
                'cancelled_executions' => $cancelledCount,
                'cancelled_batches' => $cancelledBatches,
            ]);

            // Reload interactions to show cancellation
            $this->loadInteractions();

            \Log::info('ChatResearchInterface: Successfully cancelled pending jobs', [
                'session_id' => $this->currentSessionId,
                'cancelled_executions' => $cancelledCount,
                'cancelled_batches' => $cancelledBatches,
            ]);

        } catch (\Exception $e) {
            \Log::error('ChatResearchInterface: Error cancelling pending jobs', [
                'session_id' => $this->currentSessionId,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('jobs-cancel-error', [
                'error' => 'Failed to cancel jobs: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel a specific execution that is blocking submission
     */
    public function cancelBlockingExecution(int $executionId): void
    {
        try {
            // Query state column: pending='pending', running=['planning','planned','executing','synthesizing']
            $execution = \App\Models\AgentExecution::where('id', $executionId)
                ->where('user_id', auth()->id())
                ->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing'])
                ->first();

            if (! $execution) {
                \Log::warning('ChatResearchInterface: Attempted to cancel non-existent or unauthorized execution', [
                    'execution_id' => $executionId,
                    'user_id' => auth()->id(),
                ]);

                return;
            }

            \Log::info('ChatResearchInterface: Cancelling blocking execution', [
                'execution_id' => $executionId,
                'status' => $execution->status,
                'session_id' => $execution->chat_session_id,
            ]);

            // Cancel the execution
            $execution->cancel();

            // Cancel associated batch if it exists
            $metadata = $execution->metadata ?? [];
            if (isset($metadata['batch_id'])) {
                $batch = \Illuminate\Support\Facades\Bus::findBatch($metadata['batch_id']);
                if ($batch && ! $batch->finished() && ! $batch->cancelled()) {
                    $batch->cancel();

                    \Log::info('ChatResearchInterface: Cancelled batch for blocking execution', [
                        'batch_id' => $batch->id,
                        'execution_id' => $executionId,
                    ]);
                }
            }

            // Update any linked interaction
            if ($execution->chat_interaction_id) {
                $interaction = \App\Models\ChatInteraction::find($execution->chat_interaction_id);
                if ($interaction && empty($interaction->answer)) {
                    $interaction->update([
                        'answer' => '🛑 **Execution cancelled** - was blocking new submissions.',
                    ]);
                }
            }

            // Clear the blocking execution ID
            if ($this->blockingExecutionId === $executionId) {
                $this->blockingExecutionId = null;
            }

            // Dispatch notification for UI
            $this->dispatch('execution-cancelled', executionId: $executionId);

            \Log::info('ChatResearchInterface: Successfully cancelled blocking execution', [
                'execution_id' => $executionId,
            ]);

        } catch (\Exception $e) {
            \Log::error('ChatResearchInterface: Error cancelling blocking execution', [
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('execution-cancel-error', [
                'error' => 'Failed to cancel execution: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Check if there are any active jobs for the current session
     */
    public function hasActiveJobs(): bool
    {
        if (! $this->currentInteractionId) {
            return false;
        }

        try {
            // Check if the interaction has an answer - if it does, hide queue status
            $interaction = \App\Models\ChatInteraction::find($this->currentInteractionId);
            if ($interaction && ! empty(trim($interaction->answer))) {
                return false; // Hide queue status if interaction is completed
            }

            $jobStatusManager = app(JobStatusManager::class);

            return $jobStatusManager->hasActiveJobs((string) $this->currentInteractionId);
        } catch (\Exception $e) {
            \Log::error('ChatResearchInterface: Error checking active jobs via JobStatusManager', [
                'interaction_id' => $this->currentInteractionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get formatted job status for display
     */
    public function getJobStatusDisplay(): array
    {
        if (! $this->currentInteractionId) {
            return [];
        }

        try {
            $jobStatusManager = app(JobStatusManager::class);

            return $jobStatusManager->getJobStatusDisplay((string) $this->currentInteractionId);
        } catch (\Exception $e) {
            \Log::error('ChatResearchInterface: Error getting job status display via JobStatusManager', [
                'interaction_id' => $this->currentInteractionId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Format step description to make URLs clickable
     */
    protected function formatStepDescription(string $description): string
    {
        // First escape HTML to prevent XSS
        $escaped = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

        // Make full URLs clickable (http:// and https://)
        $formatted = preg_replace(
            '/(https?:\/\/[^\s<>"\']+)/i',
            '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-tropical-teal-600 dark:text-tropical-teal-400 underline hover:text-tropical-teal-800 dark:hover:text-tropical-teal-300">$1</a>',
            $escaped
        );

        // Make bare domain names clickable (e.g., example.com, subdomain.example.com)
        // Match domains but avoid matching things like file extensions in sentences
        $formatted = preg_replace(
            '/(?<![\w\/@])\b([a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}\b(?![\w\/@])/i',
            '<a href="https://$0" target="_blank" rel="noopener noreferrer" class="text-tropical-teal-600 dark:text-tropical-teal-400 underline hover:text-tropical-teal-800 dark:hover:text-tropical-teal-300">$0</a>',
            $formatted
        );

        return $formatted;
    }

    /**
     * Open interaction modal for displaying detailed execution information
     * This replaces the old step modal functionality
     */
    public function openStepModal($stepId, $stepData = null)
    {
        try {
            // For steps from combined timeline, we need to extract the StatusStream ID
            if (is_array($stepData) && isset($stepData['data']['id'])) {
                $actualStepId = $stepData['data']['id'];
            } else {
                $actualStepId = $stepId;
            }

            Log::info('ChatResearchInterface: Opening interaction modal for step', [
                'original_step_id' => $stepId,
                'actual_step_id' => $actualStepId,
                'step_data' => $stepData,
            ]);

            // Try to find the StatusStream and get its interaction
            $statusStream = \App\Models\StatusStream::find($actualStepId);

            if ($statusStream && $statusStream->interaction_id) {
                // Open the interaction modal with the found interaction
                $this->dispatch('openInteractionModal', interactionId: $statusStream->interaction_id);

                return;
            }

            // If no StatusStream found, try to find an AgentExecution and get its interaction
            $agentExecution = \App\Models\AgentExecution::find($actualStepId);

            if ($agentExecution && $agentExecution->chatInteraction) {
                $this->dispatch('openInteractionModal', interactionId: $agentExecution->chatInteraction->id);

                return;
            }

            // If we have current interaction loaded, use that as fallback
            if ($this->currentInteraction) {
                $this->dispatch('openInteractionModal', interactionId: $this->currentInteraction->id);

                return;
            }

            Log::warning('ChatResearchInterface: Could not find interaction for step', [
                'step_id' => $actualStepId,
                'step_data' => $stepData,
            ]);

        } catch (\Exception $e) {
            Log::error('ChatResearchInterface: Error opening step modal', [
                'step_id' => $stepId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Open interaction modal for displaying detailed chat interaction information
     */
    public function openInteractionModal($interactionId)
    {
        Log::info('ChatResearchInterface: Opening interaction modal', [
            'interaction_id' => $interactionId,
        ]);

        $this->dispatch('openInteractionModal', interactionId: $interactionId);
    }

    /**
     * Get step details for modal display (helper method)
     */
    public function getStepDetails($stepId): ?array
    {
        try {
            $statusStream = StatusStream::with('chatInteraction')->find($stepId);

            if (! $statusStream) {
                return null;
            }

            return [
                'id' => $statusStream->id,
                'interaction_id' => $statusStream->interaction_id,
                'source' => $statusStream->source,
                'message' => $statusStream->message,
                'timestamp' => $statusStream->timestamp,
                'metadata' => $statusStream->metadata ?? [],
                'is_significant' => $statusStream->is_significant,
                'create_event' => $statusStream->create_event,
                'interaction' => $statusStream->chatInteraction ? [
                    'id' => $statusStream->chatInteraction->id,
                    'question' => $statusStream->chatInteraction->question,
                    'created_at' => $statusStream->chatInteraction->created_at,
                ] : null,
            ];
        } catch (\Exception $e) {
            Log::error('ChatResearchInterface: Error getting step details', [
                'step_id' => $stepId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get interaction details for modal display (helper method)
     */
    public function getInteractionDetails($interactionId): ?array
    {
        try {
            // Need to explicitly select metadata since it's excluded by default
            $interaction = ChatInteraction::with([
                'session',
                'user',
                'agentExecution',
                'agent',
                'sources.source',
                'knowledgeSources.knowledgeDocument.tags',
                'attachments',
            ])->addSelect('metadata')
                ->find($interactionId);

            if (! $interaction) {
                return null;
            }

            $details = [
                'id' => $interaction->id,
                'question' => $interaction->question,
                'answer' => $interaction->answer,
                'summary' => $interaction->summary,
                'metadata' => $interaction->metadata ?? [],
                'created_at' => $interaction->created_at,
                'updated_at' => $interaction->updated_at,
                'session' => $interaction->session ? [
                    'id' => $interaction->session->id,
                    'title' => $interaction->session->title,
                ] : null,
                'user' => $interaction->user ? [
                    'id' => $interaction->user->id,
                    'name' => $interaction->user->name,
                ] : null,
                'agent' => $interaction->agent ? [
                    'id' => $interaction->agent->id,
                    'name' => $interaction->agent->name,
                ] : null,
                'attachments' => $interaction->attachments->map(function ($attachment) {
                    return [
                        'id' => $attachment->id,
                        'filename' => $attachment->filename,
                        'type' => $attachment->type,
                        'file_size' => $attachment->file_size,
                    ];
                })->toArray(),
                'sources' => $interaction->getAllSources()->toArray(),
            ];

            // Add agent execution details if available
            if ($interaction->agentExecution) {
                $execution = $interaction->agentExecution;
                $details['agent_execution'] = [
                    'id' => $execution->id,
                    'status' => $execution->status,
                    'state' => $execution->state,
                    'input' => $execution->input,
                    'output' => $execution->output,
                    'error_message' => $execution->error_message,
                    'metadata' => $execution->metadata ?? [],
                    'max_steps' => $execution->max_steps,
                    'started_at' => $execution->started_at,
                    'completed_at' => $execution->completed_at,
                    'duration' => $execution->getDuration(),
                ];
            }

            return $details;
        } catch (\Exception $e) {
            Log::error('ChatResearchInterface: Error getting interaction details', [
                'interaction_id' => $interactionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Export a single interaction as markdown
     */
    public function exportInteractionAsMarkdown($interactionId)
    {
        $interaction = ChatInteraction::with(['session', 'attachments'])->find($interactionId);

        if (! $interaction) {
            $this->dispatch('notify', [
                'message' => 'Interaction not found.',
                'type' => 'error',
            ]);

            return;
        }

        $markdown = $this->generateInteractionMarkdown($interaction);
        $filename = 'interaction-'.$interaction->id.'-'.date('Y-m-d-H-i-s').'.md';

        return response()->streamDownload(function () use ($markdown) {
            echo $markdown;
        }, $filename, [
            'Content-Type' => 'text/markdown',
        ]);
    }

    /**
     * Export entire session as markdown
     */
    public function exportSessionAsMarkdown($sessionId = null)
    {
        $sessionId = $sessionId ?: $this->currentSessionId;

        if (! $sessionId) {
            $this->dispatch('notify', [
                'message' => 'No session to export.',
                'type' => 'error',
            ]);

            return;
        }

        $session = ChatSession::with(['interactions.attachments', 'interactions.sources'])
            ->find($sessionId);

        if (! $session) {
            $this->dispatch('notify', [
                'message' => 'Session not found.',
                'type' => 'error',
            ]);

            return;
        }

        $markdown = $this->generateSessionMarkdown($session);
        $filename = 'session-'.$session->id.'-'.date('Y-m-d-H-i-s').'.md';

        return response()->streamDownload(function () use ($markdown) {
            echo $markdown;
        }, $filename, [
            'Content-Type' => 'text/markdown',
        ]);
    }

    /**
     * Show share modal for current session
     */
    public function showShareModal()
    {
        if (! $this->currentSessionId) {
            $this->dispatch('notify', [
                'message' => 'No session to share.',
                'type' => 'error',
            ]);

            return;
        }

        $session = ChatSession::find($this->currentSessionId);

        if (! $session) {
            $this->dispatch('notify', [
                'message' => 'Session not found.',
                'type' => 'error',
            ]);

            return;
        }

        $this->dispatch('show-share-modal', [
            'sessionId' => $session->id,
            'publicUrl' => $session->getPublicUrl(),
            'isPublic' => $session->is_public,
        ]);
    }

    /**
     * Generate markdown content for a single interaction
     */
    private function generateInteractionMarkdown(ChatInteraction $interaction): string
    {
        $markdown = [];

        // Header
        $markdown[] = '# Chat Interaction';
        $markdown[] = '';
        $markdown[] = '**Date:** '.$interaction->created_at->format('F j, Y \a\t g:i A');
        $markdown[] = '**Session:** '.($interaction->session->title ?: 'Untitled Session');
        $markdown[] = '';

        // Question section
        $markdown[] = '## Question';
        $markdown[] = '';
        $markdown[] = $interaction->question;
        $markdown[] = '';

        // Attachments if any
        if ($interaction->attachments && $interaction->attachments->count() > 0) {
            $markdown[] = '### Attachments';
            $markdown[] = '';
            foreach ($interaction->attachments as $attachment) {
                $markdown[] = "- **{$attachment->filename}** ({$attachment->type})";
            }
            $markdown[] = '';
        }

        // Answer section
        if ($interaction->answer && trim($interaction->answer) !== '') {
            $markdown[] = '## Answer';
            $markdown[] = '';
            $markdown[] = $this->adjustMarkdownHeadingLevels($interaction->answer, 3);
            $markdown[] = '';
        }

        // Sources section
        if ($interaction->sources && $interaction->sources->count() > 0) {
            $markdown[] = '## Sources';
            $markdown[] = '';
            foreach ($interaction->sources as $source) {
                $markdown[] = "- [{$source->title}]({$source->url})";
                if ($source->description) {
                    $markdown[] = "  {$source->description}";
                }
            }
            $markdown[] = '';
        }

        // Comprehensive sources list at the bottom
        if ($interaction->sources && $interaction->sources->count() > 0) {
            $markdown[] = '---';
            $markdown[] = '';
            $markdown[] = '## All Sources Referenced';
            $markdown[] = '';
            foreach ($interaction->sources as $index => $source) {
                $num = $index + 1;
                $markdown[] = "{$num}. **{$source->title}**";
                $markdown[] = "   - URL: {$source->url}";
                if ($source->description) {
                    $markdown[] = "   - Description: {$source->description}";
                }
                $markdown[] = '';
            }
        }

        return implode("\n", $markdown);
    }

    /**
     * Generate markdown content for an entire session
     */
    private function generateSessionMarkdown(ChatSession $session): string
    {
        $markdown = [];

        // Header
        $markdown[] = '# '.($session->title ?: 'Research Session');
        $markdown[] = '';
        $markdown[] = '**Created:** '.$session->created_at->format('F j, Y \a\t g:i A');
        $markdown[] = '**Last Updated:** '.$session->updated_at->format('F j, Y \a\t g:i A');
        $markdown[] = '**Total Interactions:** '.$session->interactions->count();
        $markdown[] = '';

        // Table of Contents
        if ($session->interactions->count() > 1) {
            $markdown[] = '## Table of Contents';
            $markdown[] = '';
            foreach ($session->interactions as $index => $interaction) {
                $num = $index + 1;
                $question = Str::limit($interaction->question, 60);
                $markdown[] = "{$num}. [{$question}](#interaction-{$num})";
            }
            $markdown[] = '';
        }

        // Interactions
        foreach ($session->interactions as $index => $interaction) {
            $num = $index + 1;
            $datetime = $interaction->created_at->format('m/d/y g:i A');

            $markdown[] = "## Interaction {$num} - {$datetime}";
            $markdown[] = '';

            // Question
            $markdown[] = '### Question';
            $markdown[] = '';
            $markdown[] = $interaction->question;
            $markdown[] = '';

            // Attachments if any
            if ($interaction->attachments && $interaction->attachments->count() > 0) {
                $markdown[] = '#### Attachments';
                $markdown[] = '';
                foreach ($interaction->attachments as $attachment) {
                    $markdown[] = "- **{$attachment->filename}** ({$attachment->type})";
                }
                $markdown[] = '';
            }

            // Answer
            if ($interaction->answer && trim($interaction->answer) !== '') {
                $markdown[] = '### Answer';
                $markdown[] = '';
                $markdown[] = $this->adjustMarkdownHeadingLevels($interaction->answer, 4);
                $markdown[] = '';
            }

            // Sources
            if ($interaction->sources && $interaction->sources->count() > 0) {
                $markdown[] = '#### Sources';
                $markdown[] = '';
                foreach ($interaction->sources as $source) {
                    $markdown[] = "- [{$source->title}]({$source->url})";
                    if ($source->description) {
                        $markdown[] = "  {$source->description}";
                    }
                }
                $markdown[] = '';
            }

            if ($index < $session->interactions->count() - 1) {
                $markdown[] = '---';
                $markdown[] = '';
            }
        }

        // Comprehensive sources list from all interactions
        $allSources = collect();
        foreach ($session->interactions as $interaction) {
            if ($interaction->sources && $interaction->sources->count() > 0) {
                foreach ($interaction->sources as $source) {
                    // Avoid duplicates by URL
                    if (! $allSources->contains('url', $source->url)) {
                        $allSources->push($source);
                    }
                }
            }
        }

        if ($allSources->count() > 0) {
            $markdown[] = '';
            $markdown[] = '---';
            $markdown[] = '';
            $markdown[] = '## All Sources Referenced in This Session';
            $markdown[] = '';
            foreach ($allSources as $index => $source) {
                $num = $index + 1;
                $markdown[] = "{$num}. **{$source->title}**";
                $markdown[] = "   - URL: {$source->url}";
                if ($source->description) {
                    $markdown[] = "   - Description: {$source->description}";
                }
                $markdown[] = '';
            }
        }

        return implode("\n", $markdown);
    }

    /**
     * Adjust markdown heading levels to prevent conflicts with document structure
     */
    private function adjustMarkdownHeadingLevels(string $content, int $minLevel = 1): string
    {
        if (empty($content)) {
            return $content;
        }

        // Split content into lines for processing
        $lines = explode("\n", $content);
        $adjustedLines = [];

        foreach ($lines as $line) {
            // Check if line starts with markdown heading syntax
            if (preg_match('/^(#{1,6})\s+(.*)/', $line, $matches)) {
                $currentLevel = strlen($matches[1]); // Count the # symbols
                $headingText = $matches[2];

                // Adjust heading level by adding # symbols to reach minimum level
                $newLevel = max($currentLevel + $minLevel - 1, $minLevel);
                $newLevel = min($newLevel, 6); // Cap at H6

                $adjustedLines[] = str_repeat('#', $newLevel).' '.$headingText;
            } else {
                // Not a heading, keep as-is
                $adjustedLines[] = $line;
            }
        }

        return implode("\n", $adjustedLines);
    }

    /**
     * Create a artifact from an interaction's answer
     */
    public function createArtifactFromAnswer($interactionId)
    {
        // Prevent double-clicks and concurrent requests (debounce protection)
        if ($this->isCreatingArtifact) {
            Log::warning('ChatResearchInterface: Artifact creation already in progress', [
                'interaction_id' => $interactionId,
                'user_id' => auth()->id(),
            ]);

            $this->dispatch('notify', [
                'message' => 'Artifact creation already in progress. Please wait...',
                'type' => 'warning',
            ]);

            return;
        }

        $this->isCreatingArtifact = true;

        try {
            // Find the interaction
            $interaction = ChatInteraction::find($interactionId);

            if (! $interaction) {
                $this->dispatch('notify', [
                    'message' => 'Interaction not found.',
                    'type' => 'error',
                ]);

                return;
            }

            // Verify ownership
            if ($interaction->user_id !== auth()->id()) {
                $this->dispatch('notify', [
                    'message' => 'You do not have permission to create artifacts from this interaction.',
                    'type' => 'error',
                ]);

                return;
            }

            // Verify the interaction has an answer
            if (empty($interaction->answer)) {
                $this->dispatch('notify', [
                    'message' => 'This interaction does not have an answer to save.',
                    'type' => 'error',
                ]);

                return;
            }

            // Generate artifact title from question (first 50 characters or first sentence)
            $title = $this->generateArtifactTitle($interaction->question);

            // Create the artifact
            $artifact = \App\Models\Artifact::create([
                'author_id' => auth()->id(),
                'title' => $title,
                'description' => 'Answer from research chat interaction',
                'content' => $interaction->answer,
                'filetype' => 'md',
                'privacy_level' => 'private',
                'source' => 'chat_interaction',
                'metadata' => [
                    'interaction_id' => $interaction->id,
                    'question' => $interaction->question,
                    'created_from' => 'research_chat',
                    'session_id' => $interaction->chat_session_id,
                ],
            ]);

            // Add tag for easy filtering
            $chatAnswerTag = \App\Models\ArtifactTag::firstOrCreate([
                'name' => 'chat-answer',
                'created_by' => auth()->id(),
            ]);
            $artifact->tags()->attach($chatAnswerTag->id);

            // Create ChatInteractionArtifact pivot record
            \App\Models\ChatInteractionArtifact::createOrUpdate(
                $interaction->id,
                $artifact->id,
                'referenced',
                'create_artifact_from_answer',
                "Created artifact: {$artifact->title}",
                [
                    'title' => $artifact->title,
                    'filetype' => $artifact->filetype,
                ]
            );

            Log::info('ChatResearchInterface: Created artifact from answer', [
                'interaction_id' => $interaction->id,
                'artifact_id' => $artifact->id,
                'user_id' => auth()->id(),
            ]);

            $this->dispatch('notify', [
                'message' => "Artifact '{$artifact->title}' created successfully! Click the artifact card to view it.",
                'type' => 'success',
            ]);

            $this->dispatch('refreshArtifacts');

            // Clear cache and reload inline artifacts for this interaction
            unset($this->inlineArtifacts[$interactionId]);
            $this->loadInlineArtifacts($interactionId);

            // Don't auto-open drawer - let user click artifact card to avoid browser freeze
            // Removed: dispatch('open-artifact-drawer') - this triggered heavy queries immediately

        } catch (\Exception $e) {
            Log::error('ChatResearchInterface: Error creating artifact from answer', [
                'interaction_id' => $interactionId,
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notify', [
                'message' => 'Failed to create artifact: '.$e->getMessage(),
                'type' => 'error',
            ]);
        } finally {
            // Always reset the flag to allow future artifact creations
            $this->isCreatingArtifact = false;
        }
    }

    /**
     * Generate a meaningful title for a artifact from the question
     */
    protected function generateArtifactTitle(string $question): string
    {
        // Remove markdown syntax and clean up
        $cleaned = strip_tags($question);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);

        // Try to get first sentence (up to first period, question mark, or exclamation mark)
        if (preg_match('/^([^.!?]+[.!?])/', $cleaned, $matches)) {
            $title = trim($matches[1]);

            // If first sentence is too long, truncate
            if (strlen($title) > 80) {
                $title = Str::limit($cleaned, 80);
            }
        } else {
            // No sentence ending found, just truncate
            $title = Str::limit($cleaned, 80);
        }

        return $title;
    }

    /**
     * Load inline artifacts for a specific interaction
     */
    public function loadInlineArtifacts($interactionId)
    {
        // Check if already loaded to avoid redundant queries (prevents N+1 and browser freeze)
        if (isset($this->inlineArtifacts[$interactionId]) && ! empty($this->inlineArtifacts[$interactionId])) {
            Log::debug('ChatResearchInterface: Skipping loadInlineArtifacts - already cached', [
                'interaction_id' => $interactionId,
                'cached_count' => $this->inlineArtifacts[$interactionId]->count(),
            ]);

            return; // Already loaded, skip query
        }

        $artifacts = \App\Models\ChatInteractionArtifact::with(['artifact.tags'])
            ->where('chat_interaction_id', $interactionId)
            ->get()
            ->pluck('artifact')
            ->filter()
            ->unique('id'); // Ensure each artifact appears only once per interaction

        $this->inlineArtifacts[$interactionId] = $artifacts;

        Log::debug('ChatResearchInterface: Loaded inline artifacts', [
            'interaction_id' => $interactionId,
            'artifacts_count' => $artifacts->count(),
        ]);
    }

    /**
     * Handle chat interaction artifact created event
     */
    public function handleChatInteractionArtifactCreated($cifData = [])
    {
        Log::info('ChatResearchInterface: handleChatInteractionArtifactCreated', [
            'cifData' => $cifData,
        ]);

        // Clear cache and reload inline artifacts for this interaction
        if (isset($cifData['chat_interaction_id'])) {
            // Clear cached artifacts to force reload with new artifact
            unset($this->inlineArtifacts[$cifData['chat_interaction_id']]);

            $this->loadInlineArtifacts($cifData['chat_interaction_id']);
        }
    }

    /**
     * Handle artifact deleted event
     */
    public function handleArtifactDeleted($artifactId)
    {
        Log::info('ChatResearchInterface: handleArtifactDeleted', [
            'artifactId' => $artifactId,
        ]);

        // Remove artifact from inline artifacts
        foreach ($this->inlineArtifacts as $interactionId => $artifacts) {
            $this->inlineArtifacts[$interactionId] = $artifacts->filter(function ($artifact) use ($artifactId) {
                return $artifact->id !== $artifactId;
            });
        }

        // Notify artifacts tab to refresh
        $this->dispatch('refreshArtifacts');

        // Livewire will automatically detect the property change and update the UI
    }

    public function render()
    {
        return view('livewire.chat-research-interface', [
            'interactions' => $this->interactions,
        ])->layout('components.layouts.app', ['title' => 'Research Chat']);
    }
}

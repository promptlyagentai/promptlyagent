{{--
    Chat Research Interface

    Main AI research chat with real-time streaming, multi-tab UI, and WebSocket updates.
    Handles answer streaming, execution steps, sources, and artifacts via dedicated channels.
--}}
<div x-data="{ sidebarOpen: false }"
     @open-sessions-sidebar.window="$nextTick(() => sidebarOpen = true)"
     class="p-2"
     data-current-interaction-id="{{ $currentInteractionId }}"
     data-current-session-id="{{ $currentSessionId }}">
    <meta name="interaction-id" content="{{ $currentInteractionId }}" data-livewire-update>
    <meta name="session-id" content="{{ $currentSessionId }}" data-livewire-update>

    <template id="timeline-step-template" data-version="1">
        @include('livewire.components.timeline-step', ['template' => true, 'context' => 'thinking', 'step' => []])
    </template>

    @include('livewire.components.chat.session-sidebar', [
        'sessions' => $sessions,
        'currentSessionId' => $currentSessionId
    ])
    <div class="rounded-xl border border-default bg-surface flex flex-col gap-6 h-[calc(100vh-8rem)]">
                <div class="h-full flex flex-col p-6">
                    <div class="flex-shrink-0 pb-4">
                        @include('livewire.components.chat.session-header', [
                            'currentSessionId' => $currentSessionId,
                            'sessions' => $sessions,
                            'query' => $query
                        ])
                    </div>

                    <div class="flex-1 min-h-0 flex flex-col" 
                         x-data="{ 
                             currentTab: '{{ $selectedTab }}',
                             stepsData: [],
                             sourcesData: [],
                             lastStepsRefresh: '',
                             lastSourcesRefresh: '',
                             lastArtifactsRefresh: '',
                             queueStatusInterval: null,
                             
                             init() {
                                 // WebSocket-based queue status updates - no polling needed
                                 console.log('Queue status updates handled via WebSocket broadcasting');
                             }
                         }" 
                         x-on:jobs-cancelled.window="console.log('Jobs cancelled - UI updates handled via WebSocket');"
                         x-on:streaming-started.window="console.log('Streaming started - UI updates handled via WebSocket');"
                        {{-- Tab Navigation Component --}}
                        @include('livewire.components.chat.tab-navigation', [
                            'selectedTab' => $selectedTab,
                            'queueJobDisplay' => $queueJobDisplay,
                            'queueJobCounts' => $queueJobCounts,
                            'blockingExecutionId' => $blockingExecutionId
                        ])

                        <!-- Scrollable Tab Content Area -->
                        <div class="flex-1 min-h-0 pt-4">
                        {{-- Answer Tab Component --}}
                        @include('livewire.components.chat.answer-tab', [
                            'interactions' => $interactions,
                            'pendingQuestion' => $pendingQuestion,
                            'currentInteractionId' => $currentInteractionId,
                            'isStreaming' => $isStreaming,
                            'isThinking' => $isThinking,
                            'currentStatus' => $currentStatus,
                            'inlineArtifacts' => $inlineArtifacts,
                            'formatExecutionTimeEstimate' => $this->formatExecutionTimeEstimate()
                        ])
                        
                        {{-- Steps Tab Component --}}
                        @include('livewire.components.chat.steps-tab', [
                            'interactions' => $interactions,
                            'currentInteractionId' => $currentInteractionId,
                            'isStreaming' => $isStreaming,
                            'executionSteps' => $executionSteps,
                            'stepCounter' => $stepCounter,
                            'pendingQuestion' => $pendingQuestion,
                            'formatStepDescription' => $this->formatStepDescription(...),
                            'getCombinedTimelineForInteraction' => $this->getCombinedTimelineForInteraction(...)
                        ])

                        <div x-show="currentTab === 'sources'" x-transition class="h-full overflow-hidden">
                            <div class="h-full flex flex-col border border-default rounded">
                                <div class="flex-1 overflow-y-auto">
                                <div class="relative p-4">
                                    <!-- Timeline line -->
                                    <div class="absolute left-10 top-4 bottom-4 w-px bg-accent"></div>

                                @if(count($interactions) > 0)
                                    @foreach($interactions as $loop_interaction)
                                        @php
                                            // For Sources/Steps tabs: show if has answer OR is current interaction (even without streaming flag)
                                            $shouldShowSources = $loop_interaction->answer || 
                                                                ($loop_interaction->id === $currentInteractionId && ($isStreaming || empty(trim($loop_interaction->answer))));
                                        @endphp
                                        @if($shouldShowSources) <!-- Show sources for completed interactions OR current interaction (streaming or no answer yet) -->
                                            @php
                                                $executionId = $loop_interaction->execution?->id;
                                            @endphp
                                            @livewire('components.tabs.sources-tab-content', [
                                                'executionId' => $executionId,
                                                'interactionId' => $loop_interaction->id,
                                                'showAsTimelineItem' => true,
                                                'interactionQuestion' => $loop_interaction->question,
                                                'interactionTimestamp' => $loop_interaction->created_at->format('M j, H:i')
                                            ], key('sources-' . $loop_interaction->id))
                                        @endif
                                    @endforeach
                                @else
                                    {{-- Show sources component for pending interaction during streaming --}}
                                    @if($isStreaming && $currentInteractionId)
                                        @livewire('components.tabs.sources-tab-content', [
                                            'executionId' => null,
                                            'interactionId' => $currentInteractionId,
                                            'showAsTimelineItem' => true,
                                            'interactionQuestion' => $pendingQuestion ?: 'Current Research',
                                            'interactionTimestamp' => 'In Progress...'
                                        ], key('sources-pending'))
                                    @else
                                        <div class="ml-12 text-center text-accent p-8">
                                            Sources will appear here after starting a research session.
                                        </div>
                                    @endif
                                @endif
                                </div>
                                </div>
                            </div>
                        </div>

                        <div x-show="currentTab === 'artifacts'" x-transition class="h-full overflow-hidden">
                            <div class="h-full flex flex-col border border-default rounded">
                                <div class="flex-1 overflow-y-auto">
                                <div class="relative p-4">
                                    <!-- Timeline line -->
                                    <div class="absolute left-10 top-4 bottom-4 w-px bg-accent"></div>

                                @if(count($interactions) > 0)
                                    @foreach($interactions as $loop_interaction)
                                        @php
                                            // For Artifacts tab: show if has answer OR is current interaction (even without streaming flag)
                                            $shouldShowArtifacts = $loop_interaction->answer ||
                                                                ($loop_interaction->id === $currentInteractionId && ($isStreaming || empty(trim($loop_interaction->answer))));
                                        @endphp
                                        @if($shouldShowArtifacts) <!-- Show artifacts for completed interactions OR current interaction (streaming or no answer yet) -->
                                            @php
                                                $executionId = $loop_interaction->execution?->id;
                                            @endphp
                                            @livewire('components.tabs.artifacts-tab-content', [
                                                'executionId' => $executionId,
                                                'interactionId' => $loop_interaction->id,
                                                'showAsTimelineItem' => true,
                                                'interactionQuestion' => $loop_interaction->question,
                                                'interactionTimestamp' => $loop_interaction->created_at->format('M j, H:i')
                                            ], key('artifacts-' . $loop_interaction->id))
                                        @endif
                                    @endforeach
                                @else
                                    {{-- Show artifacts component for pending interaction during streaming --}}
                                    @if($isStreaming && $currentInteractionId)
                                        @livewire('components.tabs.artifacts-tab-content', [
                                            'executionId' => null,
                                            'interactionId' => $currentInteractionId,
                                            'showAsTimelineItem' => true,
                                            'interactionQuestion' => $pendingQuestion ?: 'Current Research',
                                            'interactionTimestamp' => 'In Progress...'
                                        ], key('artifacts-pending'))
                                    @else
                                        <div class="ml-12 text-center text-accent p-8">
                                            Artifacts will appear here when the AI creates or modifies documents during the conversation.
                                        </div>
                                    @endif
                                @endif
                                </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <!-- Fixed Bottom Form -->
                    {{-- Search Form Component --}}
                    @include('livewire.components.chat.search-form', [
                        'selectedAgent' => $selectedAgent,
                        'availableAgents' => $this->availableAgents,
                        'toolOverrideEnabled' => $toolOverrideEnabled,
                        'availableTools' => $availableTools,
                        'availableServers' => $availableServers,
                        'enabledTools' => $enabledTools,
                        'enabledServers' => $enabledServers,
                        'toolOverrides' => $toolOverrides,
                        'serverOverrides' => $serverOverrides,
                        'researchAgents' => $this->researchAgents,
                        'attachments' => $attachments,
                        'query' => $query
                    ])
                </div>
            </div>

    <!-- Modal Components -->
    @livewire('components.modals.chat-interaction-detail-modal')
    @livewire('components.modals.execution-step-detail-modal')
    @livewire('components.modals.share-session-modal')

    <!-- Artifact Drawer Component -->
    @livewire('components.artifact-drawer')
</div>

    @push('styles')
    <style>
        [x-cloak] { display: none !important; }
    </style>
    @endpush

    @push('scripts')
    <script>
        // Debug helper function for WebSockets
        window.debugStatusStream = function() {
            console.log('DEBUGGING STATUS STREAM:');
            console.log('Echo available:', !!window.Echo);
            if (window.Echo) {
                console.log('Echo connector:', window.Echo.connector);
                console.log('Echo socket ID:', window.Echo.socketId());
                console.log('Echo connection state:', window.Echo.connector.pusher.connection.state);
                console.log('Subscribed channels:', window.Echo.connector.channels);
            }
            
            // Get status elements
            const statusElements = {
                'thinking-process': document.getElementById('thinking-process-container'),
                'real-time-status': document.getElementById('real-time-status-container'),
                'status-fallback': document.getElementById('livewire-status-fallback'),
                'status-fallback-pending': document.getElementById('livewire-status-fallback-pending')
            };
            console.log('Status UI elements:', statusElements);
            
            // Get channel name from meta tag
            const interactionMeta = document.querySelector('meta[name="interaction-id"]');
            console.log('Interaction meta tag:', interactionMeta);
            if (interactionMeta) {
                const interactionId = interactionMeta.getAttribute('content');
                console.log('Channel should be: status-stream.' + interactionId);
                
                // REMOVED: Test Echo listeners - now using WebSocket-only StatusStreamManager
                // StatusStreamManager handles all WebSocket subscriptions automatically
                console.log('WebSocket subscriptions handled by StatusStreamManager only');
            }
            
            // Check for any global status stream manager
            console.log('Global statusStreamManager:', window.statusStreamManager);
            
            return 'Debug check complete - see console output';
        };
        
        // StatusStream function removed - now using Laravel broadcasting with Livewire echo listeners

        // Update status display directly in DOM for real-time feedback
        function updateStatusDisplayDirectly(statusData, interactionId = null) {
            // Get status message
            const statusMessage = `🔍 ${statusData.source}: ${statusData.message}`;
            const statusHTML = `<div class="flex items-center gap-2">
                <div class="animate-spin w-4 h-4 border-2 border-accent border-t-transparent rounded-full"></div>
                ${statusMessage}
            </div>`;

            // Priority 1: Interaction-specific container (Alpine markdown renderer)
            if (interactionId) {
                const searchResults = document.getElementById(`search-results-${interactionId}`);
                if (searchResults) {
                    const sourceElement = searchResults.querySelector('[x-ref="source"]');
                    if (sourceElement) {
                        sourceElement.textContent = `_${statusMessage}_`;
                        sourceElement.dispatchEvent(new Event('input'));
                        console.log(`Status updated using interaction-specific container: search-results-${interactionId}`);
                        return;
                    }
                }
            }

            // Priority 2: Legacy search-results element (for backward compatibility)
            const searchResults = document.getElementById('search-results');

            let updated = false;

            // Update search-results if available
            if (searchResults) {
                try {
                    searchResults.innerHTML = statusHTML;

                    // Delay making search-results visible to prevent premature display
                    // of dispatch messages before actual execution starts
                    setTimeout(() => {
                        searchResults.classList.remove('hidden');
                    }, 1500);

                    updated = true;
                    console.log('Status updated using search-results');

                    // Auto-scroll to newest message in search-results container
                    // Auto-scroll to newest search result
                    window.scrollToNewestMessage && window.scrollToNewestMessage(searchResults);
                } catch (e) {
                    console.warn('Failed to update search-results:', e);
                }
            }
            
            // Try to update livewire status fallbacks if search-results unavailable
            if (!updated) {
                const fallbackTargets = [
                    'livewire-status-fallback',
                    'livewire-status-fallback-pending'
                ];
                
                for (const targetId of fallbackTargets) {
                    const statusElement = document.getElementById(targetId);
                    if (statusElement) {
                        try {
                            statusElement.innerHTML = statusHTML;
                            statusElement.classList.remove('hidden');
                            updated = true;
                            console.log(`Status updated using fallback target: ${targetId}`);
                            
                            // Auto-scroll for fallback status updates
                            // Auto-scroll to newest status update
                            window.scrollToNewestMessage && window.scrollToNewestMessage(statusElement);
                            break;
                        } catch (e) {
                            console.warn(`Failed to update fallback target ${targetId}:`, e);
                        }
                    }
                }
            }
            
            // If we still couldn't update, try to find any other suitable container
            if (!updated) {
                // Attempt to update any available status containers
                const currentResultsDiv = document.querySelector('.text-secondary.text-sm');
                if (currentResultsDiv) {
                    try {
                        currentResultsDiv.innerHTML = statusHTML;
                        updated = true;
                        console.log('Status updated using generic status container');
                        
                        // Auto-scroll for generic status updates
                        // Auto-scroll to newest result
                        window.scrollToNewestMessage && window.scrollToNewestMessage(currentResultsDiv);
                    } catch (e) {
                        console.warn('Failed to update generic status container:', e);
                    }
                }
            }

            // Only log error if debug mode
            if (!updated) {
                console.info('Status update received but no suitable display container found:', statusMessage);
            } else {
                // Log for debugging
                console.log('Status update applied:', {
                    message: statusMessage,
                    searchResultsFound: !!searchResults,
                    updated: updated,
                    source: statusData.source
                });
            }
        }

        // REMOVED: Legacy EventSource function - all real-time updates now handled via WebSocket
        // The startEventStream function has been removed as part of WebSocket-only migration
        // All status updates are now handled by StatusStreamManager via WebSocket broadcasting

        // Research step tracking for grouping related events
        // Use conditional assignment to prevent redeclaration errors during Livewire DOM updates
        if (typeof window.activeResearchSteps === 'undefined') {
            window.activeResearchSteps = new Map(); // keyed by tool name
        }
        if (typeof window.stepCounter === 'undefined') {
            window.stepCounter = 0;
        }

        // Enhanced function to manually update thinking-process container
        function updateThinkingProcess(statusData) {
            const thinkingContainer = document.getElementById('thinking-process-container');
            if (!thinkingContainer) {
                console.log('No thinking-process container found');
                return;
            }

            // Create unique step ID for tracking
            const stepId = `step-${statusData.source}-${window.stepCounter++}`;
            
            // Process the step data to make it more conversational
            const processedStep = {
                id: stepId,
                type: determineStepType(statusData.source, statusData.message),
                source: statusData.source,
                message: makeMessageConversational(statusData.message),
                timestamp: new Date().toLocaleTimeString('en-US', { 
                    hour12: false, 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                }),
                metadata: statusData.metadata || {},
                is_significant: statusData.is_significant || false  // Pass through significance from backend
            };

            // ALWAYS APPEND - never overwrite existing steps for history preservation
            const stepHtml = createThinkingStepHTML(processedStep);
            thinkingContainer.insertAdjacentHTML('beforeend', stepHtml);

            console.log('Appended new step to thinking process:', {
                stepId: stepId,
                source: statusData.source,
                message: processedStep.message,
                isSignificant: processedStep.is_significant,
                totalSteps: thinkingContainer.querySelectorAll('.thinking-step').length
            });

            // Auto-scroll to show latest updates
            window.scrollToNewestMessage && window.scrollToNewestMessage(thinkingContainer);
        }

        // Helper function to make messages more conversational
        function makeMessageConversational(message) {
            // Convert technical messages to more natural language
            const conversions = [
                { from: /^Running <([^>]+)>:/, to: 'Running $1:' },
                { from: /^Searching Web for:/, to: 'Searching for:' },
                { from: /^Tool Use <([^>]+)>:/, to: 'Using $1:' },
                { from: /^Reading:/, to: 'Reading:' },
                { from: /^Downloading:/, to: 'Downloading:' },
                { from: /\((\d+) results\)/, to: '($1 results found)' },
                { from: /\(ok\)/, to: '✓' },
                { from: /\((\d+) bytes\)/, to: '($1 bytes)' }
            ];

            let conversational = message;
            conversions.forEach(conversion => {
                conversational = conversational.replace(conversion.from, conversion.to);
            });

            return conversational;
        }

        // Helper function to create HTML for thinking steps - timeline format matching Steps tab
        function createThinkingStepHTML(step) {
            // Read significance from backend data instead of client-side detection
            const isSignificant = step.is_significant || false;
            
            // Format the message with URLs, search terms, and highlighting
            const formattedMessage = formatThinkingMessage(step.message);
            
            // Check if user is admin (we'll pass this from PHP)
            const isAdmin = window.userIsAdmin || false;
            
            if (isSignificant) {
                // Significant steps get a full timeline section like main interactions in Steps tab
                return `
                    <div id="${step.id}" class="relative mt-6 mb-3">
                        <!-- Timeline dot for major events -->
                        <div class="absolute left-4 w-4 h-4 bg-white dark:bg-surface border-2 border-accent rounded-full flex items-center justify-center">
                            <div class="w-2 h-2 bg-accent rounded-full"></div>
                        </div>

                        <!-- Content -->
                        <div class="ml-12">
                            <div class="flex items-center justify-between mb-3 px-2">
                                <div class="font-medium text-sm text-primary flex-1 pr-4">
                                    ${formattedMessage}
                                </div>
                                <div class="text-xs text-secondary flex-shrink-0">
                                    ${step.timestamp}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                // Regular steps get small dots within the current section (like individual steps in Steps tab)
                return `
                    <div id="${step.id}" class="ml-12 mb-1">
                        <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface rounded">
                            <div class="w-1.5 h-1.5 bg-accent rounded-full flex-shrink-0"></div>

                            <div class="text-secondary truncate flex-1 min-w-0">${formattedMessage}</div>
                            <span class="text-xs text-secondary flex-shrink-0">${step.timestamp}</span>
                        </div>
                    </div>
                `;
            }
        }

        
        // Helper function to format thinking messages with URLs and highlights
        function formatThinkingMessage(message) {
            let formatted = message;
            
            // First: Make full URLs clickable (before domain highlighting to avoid conflicts)
            formatted = formatted.replace(
                /(https?:\/\/[^\s<>"']+)/g,
                '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-accent underline hover:text-accent-hover">$1</a>'
            );
            
            // Bold search terms in quotes
            formatted = formatted.replace(
                /"([^"]+)"/g,
                '<strong class="font-semibold text-primary ">"$1"</strong>'
            );
            
            // Bold key results with more patterns
            formatted = formatted.replace(
                /(found \d+ results?|completed in \d+[.\d]*\w*|validated \d+ URLs?|converting \d+ URLs?|downloading from|processing \d+ links?)/gi,
                '<strong class="font-semibold text-accent">$1</strong>'
            );
            
            // Bold domain names (but skip if already inside a link)
            formatted = formatted.replace(
                /(?<!href="|>)([a-zA-Z0-9.-]+\.(com|org|net|edu|gov|io|co|ai|dev|uk|de|fr|jp|ca|au)(?:[^\s<"']*)?)/gi,
                function(match, domain) {
                    // Don't wrap if it's already inside an HTML tag
                    return '<strong class="font-semibold text-accent">' + domain + '</strong>';
                }
            );
            
            // Specific markitdown patterns
            formatted = formatted.replace(
                /(Converting|Downloaded|Processing)(\s+\d+\s+characters?|\s+content)/gi,
                '<strong class="font-semibold text-accent">$1$2</strong>'
            );
            
            // Specific link validator patterns  
            formatted = formatted.replace(
                /(Validating|Validated|Checking)(\s+\d*\s*URLs?|\s+links?)/gi,
                '<strong class="font-semibold text-orange-600 dark:text-orange-400">$1$2</strong>'
            );
            
            return formatted;
        }

        // Helper function to determine step type from source and message
        function determineStepType(source, message) {
            const messageLower = message.toLowerCase();
            
            if (messageLower.includes('search') || messageLower.includes('searching')) {
                return 'search';
            } else if (messageLower.includes('validat') || messageLower.includes('checking')) {
                return 'validation';
            } else if (messageLower.includes('download') || messageLower.includes('fetch')) {
                return 'download';
            } else if (messageLower.includes('analyz') || messageLower.includes('process')) {
                return 'analysis';
            } else if (messageLower.includes('complet') || messageLower.includes('finish')) {
                return 'complete';
            } else if (messageLower.includes('error') || messageLower.includes('fail')) {
                return 'error';
            }
            
            return 'info';
        }

        // Legacy research step processing function (keeping for compatibility)
        function updateThinkingProcessLegacy(statusData) {
            const thinkingContainer = document.getElementById('thinking-process-container');
            if (!thinkingContainer) {
                console.log('No thinking-process container found');
                return;
            }

            // Filter and process only relevant research events
            const processedStep = processResearchEvent(statusData);
            if (!processedStep) {
                return; // Skip irrelevant events
            }
            
            // Check if this is updating an existing step or creating a new one
            const existingStepId = findExistingStep(processedStep);
            
            if (existingStepId) {
                updateExistingStep(existingStepId, processedStep);
            } else {
                createNewResearchStep(thinkingContainer, processedStep);
            }
            
            // Auto-scroll to show latest updates
            window.scrollToNewestMessage && window.scrollToNewestMessage(thinkingContainer);
            
            console.log('Successfully updated thinking-process container', {
                stepType: processedStep.type,
                action: existingStepId ? 'updated' : 'created',
                message: processedStep.message
            });
        }

        // Process and filter research events into meaningful steps
        function processResearchEvent(statusData) {
            const source = statusData.source;
            const message = statusData.message;
            
            // Filter out noise and focus on key research activities
            if (source === 'searxng_search') {
                if (message.includes('Searching for:')) {
                    const query = message.replace('Searching for: ', '');
                    return {
                        type: 'search',
                        tool: 'searxng_search', 
                        action: 'start',
                        title: 'Web Search',
                        message: `Searching for: "${query}"`,
                        query: query,
                        timestamp: new Date().toLocaleTimeString(),
                        status: 'running'
                    };
                } else if (message.includes('Error:') || message.includes('failed')) {
                    return {
                        type: 'search',
                        tool: 'searxng_search',
                        action: 'error',
                        title: 'Web Search',
                        message: message,
                        timestamp: new Date().toLocaleTimeString(),
                        status: 'error'
                    };
                } else if (message.includes('Found') && message.includes('results')) {
                    const resultsMatch = message.match(/Found (\d+) results/);
                    const count = resultsMatch ? resultsMatch[1] : 'some';
                    return {
                        type: 'search',
                        tool: 'searxng_search',
                        action: 'results',
                        title: 'Web Search',
                        message: `Found ${count} search results`,
                        timestamp: new Date().toLocaleTimeString(),
                        status: 'success',
                        resultsCount: count
                    };
                }
            } else if (source === 'tool_result') {
                if (message.includes('Tool') && message.includes('completed')) {
                    const toolMatch = message.match(/Tool (\w+) completed/);
                    const durationMatch = message.match(/\(([0-9.]+ms)\)/);
                    const toolName = toolMatch ? toolMatch[1] : 'unknown';
                    const duration = durationMatch ? durationMatch[1] : '';
                    
                    return {
                        type: 'completion',
                        tool: toolName,
                        action: 'complete',
                        title: `${toolName} Complete`,
                        message: `Completed in ${duration}`,
                        timestamp: new Date().toLocaleTimeString(),
                        status: 'complete',
                        duration: duration
                    };
                }
            } else if (source === 'link_validator' || source === 'bulk_link_validator') {
                if (message.includes('Validating')) {
                    return {
                        type: 'validation',
                        tool: source,
                        action: 'start', 
                        title: 'Link Validation',
                        message: message,
                        timestamp: new Date().toLocaleTimeString(),
                        status: 'running'
                    };
                } else if (message.includes('validated') || message.includes('accessible')) {
                    return {
                        type: 'validation',
                        tool: source,
                        action: 'results',
                        title: 'Link Validation',
                        message: message,
                        timestamp: new Date().toLocaleTimeString(),
                        status: 'success'
                    };
                }
            } else if (source === 'markitdown') {
                if (message.includes('Converting') || message.includes('Downloading')) {
                    return {
                        type: 'download',
                        tool: 'markitdown',
                        action: 'start',
                        title: 'Content Download',
                        message: message,
                        timestamp: new Date().toLocaleTimeString(),
                        status: 'running'
                    };
                } else if (message.includes('converted') || message.includes('characters')) {
                    return {
                        type: 'download',
                        tool: 'markitdown',
                        action: 'results',
                        title: 'Content Download', 
                        message: message,
                        timestamp: new Date().toLocaleTimeString(),
                        status: 'success'
                    };
                }
            } else if (source === 'single_agent_completed') {
                // Handle single agent execution completion
                console.log('Single agent execution completed, triggering UI refresh');
                
                // Trigger Livewire to refresh the interaction and show results
                const component = Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id'));
                if (component) {
                    console.log('Calling loadInteractions to refresh with completed results');
                    component.call('loadInteractions');
                    
                    // Stop streaming state
                    component.set('isStreaming', false);
                    component.set('isThinking', false);
                }
                
                return {
                    type: 'completion',
                    tool: 'single_agent',
                    action: 'completed',
                    title: 'Agent Execution Complete',
                    message: 'Agent execution completed successfully. Results are now available.',
                    timestamp: new Date().toLocaleTimeString(),
                    status: 'success'
                };
            }
            
            // Skip unrecognized events
            return null;
        }

        // Find existing step that this event should update
        function findExistingStep(processedStep) {
            const stepKey = `${processedStep.type}-${processedStep.tool}`;
            return window.activeResearchSteps.get(stepKey);
        }

        // Update an existing research step
        function updateExistingStep(stepId, processedStep) {
            const existingElement = document.getElementById(stepId);
            if (!existingElement) return;
            
            // Update the step status and content
            const statusElement = existingElement.querySelector('.step-status');
            const messageElement = existingElement.querySelector('.step-message');
            const iconElement = existingElement.querySelector('.step-icon');
            
            if (statusElement) statusElement.textContent = processedStep.status;
            if (messageElement) messageElement.textContent = processedStep.message;
            if (iconElement) iconElement.innerHTML = getStepIcon(processedStep.type, processedStep.status);
            
            // Update border and background colors based on new status
            existingElement.className = existingElement.className.replace(
                /border-l-\w+-\d+|bg-\w+-\d+\/\d+/g, 
                ''
            );
            existingElement.classList.add(
                ...getBorderColor(processedStep.type, processedStep.status).split(' '),
                ...getBackgroundColor(processedStep.type, processedStep.status).split(' ')
            );
        }

        // Create a new persistent research step
        function createNewResearchStep(container, processedStep) {
            window.stepCounter++;
            const stepId = `research-step-${window.stepCounter}`;
            const stepKey = `${processedStep.type}-${processedStep.tool}`;
            
            // Store reference to this step for future updates
            window.activeResearchSteps.set(stepKey, stepId);
            
            const stepHtml = `
                <div id="${stepId}" class="research-step flex items-start space-x-3 p-3 border-l-2 ${getBorderColor(processedStep.type, processedStep.status)} ${getBackgroundColor(processedStep.type, processedStep.status)} mb-2 rounded-r-lg">
                    <div class="flex-shrink-0 mt-0.5 step-icon">
                        ${getStepIcon(processedStep.type, processedStep.status)}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-primary ">${processedStep.title}</p>
                                <p class="text-sm text-secondary mt-1 step-message">${processedStep.message}</p>
                            </div>
                            <div class="flex items-center space-x-2 text-xs text-tertiary">
                                <span class="step-status px-2 py-1 rounded text-xs font-medium ${getStatusBadgeClass(processedStep.status)}">${processedStep.status}</span>
                                <span>${processedStep.timestamp}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', stepHtml);
        }

        // Helper function to get appropriate icon for step type and status
        function getStepIcon(stepType, status = 'running') {
            const iconClasses = "h-4 w-4";
            
            // Show spinning loader for running status
            if (status === 'running') {
                return `<svg class="${iconClasses} text-accent animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.934v-5.643z"></path>
                </svg>`;
            }
            
            // Show error icon for error status
            if (status === 'error') {
                return `<svg class="${iconClasses}" style="color: var(--palette-error-700)" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>`;
            }
            
            // Show success icons for completed/success status
            if (status === 'success' || status === 'complete') {
                switch (stepType) {
                    case 'search':
                        return `<svg class="${iconClasses} text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>`;
                    case 'validation':
                        return `<svg class="${iconClasses} text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>`;
                    case 'download':
                        return `<svg class="${iconClasses} text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>`;
                    case 'completion':
                        return `<svg class="${iconClasses} text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>`;
                    default:
                        return `<svg class="${iconClasses} text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>`;
                }
            }
            
            // Default icons for running status by type
            switch (stepType) {
                case 'search':
                    return `<svg class="${iconClasses} text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>`;
                case 'validation':
                    return `<svg class="${iconClasses} text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>`;
                case 'download':
                    return `<svg class="${iconClasses} text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>`;
                case 'completion':
                    return `<svg class="${iconClasses} text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>`;
                default: // info
                    return `<svg class="${iconClasses} text-tertiary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>`;
            }
        }

        // Helper function to get border color based on step type and status
        function getBorderColor(stepType, status = 'running') {
            if (status === 'error') {
                return 'border-l-error';
            }
            if (status === 'success' || status === 'complete') {
                return 'border-l-success';
            }

            switch (stepType) {
                case 'search': return 'border-l-accent';
                case 'validation': return 'border-l-warning';
                case 'download': return 'border-l-accent';
                case 'completion': return 'border-l-accent';
                default: return 'border-l-default';
            }
        }

        // Helper function to get background color based on step type and status
        function getBackgroundColor(stepType, status = 'running') {
            if (status === 'error') {
                return 'bg-error';
            }
            if (status === 'success' || status === 'complete') {
                return 'bg-success';
            }

            switch (stepType) {
                case 'search': return 'bg-surface';
                case 'validation': return 'bg-warning';
                case 'download': return 'bg-accent/10';
                case 'completion': return 'bg-accent/10';
                default: return 'bg-surface';
            }
        }

        // Helper function to get status badge styling
        function getStatusBadgeClass(status) {
            switch (status) {
                case 'running': return 'bg-accent text-accent-foreground';
                case 'success': return 'bg-success text-success-contrast';
                case 'complete': return 'bg-success text-success-contrast';
                case 'error': return 'bg-error text-error-contrast';
                default: return 'bg-surface text-primary';
            }
        }

        // Helper function to set up Echo subscriptions for an interaction
        function setupEchoSubscriptions(interactionId) {
            if (!window.Echo || !interactionId) {
                console.error('Echo not available or no interaction ID provided');
                return;
            }

            // Subscribe to queue status updates for real-time job tracking
            const queueChannelName = `chat-interaction.${interactionId}`;
            const queueChannel = window.Echo.channel(queueChannelName);

            queueChannel.listen('.QueueStatusUpdated', (e) => {
                    console.log('Queue status update received:', e);

                    // Log via EventLogger for tracking
                    if (window.eventLogger) {
                        window.eventLogger.logEvent('QueueStatusUpdate', queueChannelName, {
                            interaction_id: e.interaction_id,
                            job_data: e.job_data,
                            receivedAt: new Date().toISOString()
                        });
                    }

                    // Call component method directly
                    const componentElement = document.querySelector('[wire\\:id]');

                    if (componentElement) {
                        const component = Livewire.find(componentElement.getAttribute('wire:id'));

                        if (component) {
                            console.log('Calling refreshQueueStatus for interaction:', e.interaction_id);
                            try {
                                component.call('refreshQueueStatus');
                            } catch (error) {
                                console.error('Error calling refreshQueueStatus:', error);
                                if (window.eventLogger) {
                                    window.eventLogger.logError(error, {
                                        context: 'livewire_dispatch_failed',
                                        event: 'queue-status-updated',
                                        channel: queueChannelName
                                    });
                                }
                            }
                        } else {
                            console.warn('Livewire component not found for queue status update');
                        }
                    } else {
                        console.warn('Component element not found for queue status update');
                    }
                });

            // Subscribe to chat interaction updates for Answers tab
            const answerChannelName = `chat-interaction.${interactionId}`;
            console.log('Subscribing to chat interaction channel:', answerChannelName);
            
            window.Echo.channel(answerChannelName)
                .listen('ChatInteractionUpdated', (e) => {
                    console.log('ChatInteractionUpdated event received:', e);
                    
                    // Log via EventLogger for comprehensive tracking
                    if (window.eventLogger) {
                        window.eventLogger.logEvent('ChatInteractionUpdated', answerChannelName, {
                            ...e,
                            source: 'blade_template_listener',
                            receivedAt: new Date().toISOString()
                        });
                    }
                    
                    const startTime = performance.now();
                    const component = Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id'));
                    if (component) {
                        console.log('Calling handleChatInteractionUpdated with:', e);
                        try {
                            component.call('handleChatInteractionUpdated', e);
                            
                            const processingTime = performance.now() - startTime;
                            if (window.eventLogger && processingTime > 5) {
                                window.eventLogger.logEvent('livewire_call_completed', answerChannelName, {
                                    method: 'handleChatInteractionUpdated',
                                    processingTimeMs: processingTime.toFixed(2)
                                });
                            }
                        } catch (error) {
                            if (window.eventLogger) {
                                window.eventLogger.logError(error, {
                                    context: 'livewire_call_failed',
                                    method: 'handleChatInteractionUpdated',
                                    channel: answerChannelName
                                });
                            }
                            throw error;
                        }
                    } else {
                        console.warn('Livewire component not found for ChatInteractionUpdated event');
                        if (window.eventLogger) {
                            window.eventLogger.logEvent('livewire_component_not_found', answerChannelName, {
                                eventType: 'ChatInteractionUpdated',
                                timestamp: new Date().toISOString()
                            });
                        }
                    }
                })
                .listen('ResearchComplete', (e) => {
                    console.log('ResearchComplete event received:', e);

                    // Log via EventLogger
                    if (window.eventLogger) {
                        window.eventLogger.logEvent('ResearchComplete', answerChannelName, {
                            ...e,
                            source: 'backend_broadcast',
                            receivedAt: new Date().toISOString()
                        });
                    }

                    // Directly call the Livewire method
                    @this.call('handleResearchComplete', {
                        interactionId: e.interaction_id,
                        executionId: e.execution_id,
                        timestamp: e.timestamp
                    });
                });

            // Subscribe to source updates for Sources tab
            const sourcesChannelName = `sources-updated.${interactionId}`;
            console.log('Subscribing to sources channel:', sourcesChannelName);
            
            window.Echo.channel(sourcesChannelName)
                .listen('ChatInteractionSourceCreated', (e) => {
                    console.log('ChatInteractionSourceCreated event received:', e);

                    // Log via EventLogger for comprehensive tracking
                    if (window.eventLogger) {
                        window.eventLogger.logEvent('ChatInteractionSourceCreated', sourcesChannelName, {
                            ...e,
                            source: 'blade_template_listener',
                            receivedAt: new Date().toISOString()
                        });
                    }

                    // Dispatch Livewire event that SourcesTabContent is listening for
                    try {
                        console.log('Dispatching chat-interaction-source-created Livewire event with:', e);
                        Livewire.dispatch('chat-interaction-source-created', { cisData: e });

                        if (window.eventLogger) {
                            window.eventLogger.logEvent('livewire_event_dispatched', sourcesChannelName, {
                                event: 'chat-interaction-source-created',
                                data: e
                            });
                        }
                    } catch (error) {
                        console.error('Error dispatching Livewire event:', error);
                        if (window.eventLogger) {
                            window.eventLogger.logError(error, {
                                context: 'livewire_dispatch_failed',
                                event: 'chat-interaction-source-created',
                                channel: sourcesChannelName
                            });
                        }
                    }
                });

            // Subscribe to general source creation events
            console.log('Subscribing to general sources-updated channel');

            window.Echo.channel('sources-updated')
                .listen('SourceCreated', (e) => {
                    console.log('SourceCreated event received:', e);

                    // Log via EventLogger for comprehensive tracking
                    if (window.eventLogger) {
                        window.eventLogger.logEvent('SourceCreated', 'sources-updated', {
                            ...e,
                            source: 'blade_template_listener',
                            receivedAt: new Date().toISOString()
                        });
                    }

                    // Dispatch Livewire event that SourcesTabContent is listening for
                    try {
                        console.log('Dispatching source-created Livewire event with:', e);
                        Livewire.dispatch('source-created', { sourceData: e });

                        if (window.eventLogger) {
                            window.eventLogger.logEvent('livewire_event_dispatched', 'sources-updated', {
                                event: 'source-created',
                                data: e
                            });
                        }
                    } catch (error) {
                        console.error('Error dispatching Livewire event:', error);
                        if (window.eventLogger) {
                            window.eventLogger.logError(error, {
                                context: 'livewire_dispatch_failed',
                                event: 'source-created',
                                channel: 'sources-updated'
                            });
                        }
                    }
                });

            // Subscribe to artifact updates for Artifacts tab (session-level channel)
            const sessionId = document.querySelector('[data-current-session-id]')?.dataset.currentSessionId;
            const artifactsChannelName = sessionId ? `artifacts-updated.${sessionId}` : `artifacts-updated.${interactionId}`;
            console.log('Subscribing to artifacts channel:', artifactsChannelName, 'for session:', sessionId);

            window.Echo.channel(artifactsChannelName)
                .listen('ChatInteractionArtifactCreated', (e) => {
                    console.log('ChatInteractionArtifactCreated event received:', e);

                    // Log via EventLogger for comprehensive tracking
                    if (window.eventLogger) {
                        window.eventLogger.logEvent('ChatInteractionArtifactCreated', artifactsChannelName, {
                            ...e,
                            source: 'blade_template_listener',
                            receivedAt: new Date().toISOString()
                        });
                    }

                    // Dispatch Livewire event that ArtifactsTabContent is listening for
                    try {
                        console.log('Dispatching chat-interaction-artifact-created Livewire event with:', e);
                        Livewire.dispatch('chat-interaction-artifact-created', { cifData: e });

                        if (window.eventLogger) {
                            window.eventLogger.logEvent('livewire_event_dispatched', artifactsChannelName, {
                                event: 'chat-interaction-artifact-created',
                                data: e
                            });
                        }
                    } catch (error) {
                        console.error('Error dispatching Livewire event:', error);
                        if (window.eventLogger) {
                            window.eventLogger.logError(error, {
                                context: 'livewire_dispatch_failed',
                                event: 'chat-interaction-artifact-created',
                                channel: artifactsChannelName
                            });
                        }
                    }
                });

            // Subscribe to status stream for real-time step updates
            const statusStreamChannelName = `status-stream.${interactionId}`;
            console.log('Subscribing to status stream channel:', statusStreamChannelName);

            window.Echo.channel(statusStreamChannelName)
                .listen('StatusStreamCreated', (e) => {
                    console.log('StatusStreamCreated event received:', e);

                    // Log via EventLogger for comprehensive tracking
                    if (window.eventLogger) {
                        window.eventLogger.logEvent('StatusStreamCreated', statusStreamChannelName, {
                            ...e,
                            source: 'blade_template_listener',
                            receivedAt: new Date().toISOString()
                        });
                    }

                    // Forward event to StatusStreamManager for timeline rendering
                    if (window.statusStreamManager) {
                        window.statusStreamManager.handleStatusStreamEvent(e);
                    }

                    // Handle completion events as fallback (in case ResearchComplete broadcast is delayed)
                    const isCompletion =
                        e.source === 'single_agent_completed' ||
                        e.source === 'agent_execution_completed' ||
                        (e.source === 'system' && e.message && e.message.toLowerCase().includes('execution completed'));

                    if (isCompletion) {
                        console.log('Agent completion detected, calling handleResearchComplete');
                        @this.call('handleResearchComplete', {
                            interactionId: interactionId,
                            executionId: e.execution_id || null,
                            timestamp: e.timestamp || new Date().toISOString()
                        });
                    }

                    // Update the Steps tab with real-time status updates (legacy)
                    updateStatusDisplayDirectly(e, interactionId);

                    // Dispatch Livewire event for component-level handling
                    try {
                        console.log('Dispatching status-stream-update Livewire event with:', e);
                        Livewire.dispatch('status-stream-update', { statusData: e });

                        if (window.eventLogger) {
                            window.eventLogger.logEvent('livewire_event_dispatched', statusStreamChannelName, {
                                event: 'status-stream-update',
                                data: e
                            });
                        }
                    } catch (error) {
                        console.error('Error dispatching Livewire event:', error);
                        if (window.eventLogger) {
                            window.eventLogger.logError(error, {
                                context: 'livewire_dispatch_failed',
                                event: 'status-stream-update',
                                channel: statusStreamChannelName
                            });
                        }
                    }
                });

        }

        // Helper function to set up session channel subscription for discovering new interactions
        function setupSessionChannelSubscription(sessionId) {
            if (!window.Echo || !sessionId) {
                console.error('Echo not available or no session ID provided');
                return;
            }

            const sessionChannelName = `chat-session.${sessionId}`;
            console.log(`Setting up session channel subscription: ${sessionChannelName}`);

            // Subscribe to private session channel
            window.Echo.private(sessionChannelName)
                .listen('.interaction.created', (e) => {
                    console.log('New interaction created via API/webhook:', e);

                    // Log via EventLogger for tracking
                    if (window.eventLogger) {
                        window.eventLogger.logEvent('InteractionCreated', sessionChannelName, {
                            interaction_id: e.interaction_id,
                            session_id: e.chat_session_id,
                            has_answer: e.has_answer,
                            input_trigger_id: e.input_trigger_id,
                            receivedAt: new Date().toISOString()
                        });
                    }

                    // Set this as the current interaction so it receives live updates
                    try {
                        const componentElement = document.querySelector('[wire\\:id]');
                        if (componentElement) {
                            const component = Livewire.find(componentElement.getAttribute('wire:id'));
                            if (component) {
                                console.log('Setting current interaction to new API-triggered interaction:', e.interaction_id);
                                component.set('currentInteractionId', e.interaction_id);
                                component.set('isStreaming', true);
                                // Set isThinking=true to show "Researching" state with live steps
                                component.set('isThinking', true);
                            } else {
                                console.warn('Livewire component not found for setting current interaction');
                            }
                        }
                    } catch (error) {
                        console.error('Error setting current interaction:', error);
                        if (window.eventLogger) {
                            window.eventLogger.logError(error, {
                                context: 'set_current_interaction_failed',
                                interaction_id: e.interaction_id
                            });
                        }
                    }

                    // Set up Echo subscriptions for the new interaction
                    if (e.interaction_id) {
                        try {
                            console.log('Setting up subscriptions for new interaction:', e.interaction_id);
                            setupEchoSubscriptions(e.interaction_id);
                        } catch (error) {
                            console.error('Error calling setupEchoSubscriptions for new interaction:', error);
                            if (window.eventLogger) {
                                window.eventLogger.logError(error, {
                                    context: 'setup_subscriptions_failed',
                                    interaction_id: e.interaction_id,
                                    channel: sessionChannelName
                                });
                            }
                        }
                    }

                    // Refresh interactions list to show the new interaction
                    try {
                        const componentElement = document.querySelector('[wire\\:id]');
                        if (componentElement) {
                            const component = Livewire.find(componentElement.getAttribute('wire:id'));
                            if (component) {
                                console.log('Refreshing interactions list for new API-triggered interaction');
                                component.call('loadInteractions');
                            } else {
                                console.warn('Livewire component not found for interaction refresh');
                            }
                        }
                    } catch (error) {
                        console.error('Error refreshing interactions list:', error);
                        if (window.eventLogger) {
                            window.eventLogger.logError(error, {
                                context: 'interaction_refresh_failed',
                                interaction_id: e.interaction_id
                            });
                        }
                    }
                });
        }

        // Modal event listeners for Livewire dispatched events
        document.addEventListener('livewire:init', () => {
            Livewire.on('openInteractionModal', (event) => {
                const interactionId = event.interactionId;
                const interactionModalComponent = Livewire.find(document.querySelector('[wire\\:id*="chat-interaction-detail-modal"]')?.getAttribute('wire:id'));
                if (interactionModalComponent) {
                    interactionModalComponent.call('openModal', interactionId);
                } else {
                    console.error('Interaction modal component not found');
                }
            });

            // Set up Echo subscriptions when a new interaction is created
            Livewire.on('interaction-created', (event) => {
                const interactionId = event.interactionId || event[0]?.interactionId;

                if (interactionId) {
                    try {
                        setupEchoSubscriptions(interactionId);
                    } catch (error) {
                        console.error('Error calling setupEchoSubscriptions:', error);
                    }
                }
            });

            // Set up session channel subscription to discover new API/webhook interactions
            // Read session ID from DOM data attribute
            const sessionId = document.querySelector('[data-current-session-id]')?.dataset.currentSessionId;
            if (sessionId) {
                console.log('Initializing session channel subscription for session:', sessionId);
                setupSessionChannelSubscription(sessionId);
            } else {
                console.warn('Unable to get session ID from DOM - session channel subscription not initialized');
            }
        });

        // Handle copy content to clipboard event
        document.addEventListener('livewire:init', () => {
            Livewire.on('copy-content-to-clipboard', (event) => {
                const data = event[0] || event;
                const content = data.content;
                const successMessage = data.successMessage || 'Content copied to clipboard';
                
                if (!content) {
                    console.warn('No content provided to copy');
                    return;
                }
                
                // Parse JSON if it's a JSON string
                let textToCopy = content;
                try {
                    textToCopy = JSON.parse(content);
                    // If it parsed successfully and is a string, use it as is
                    // If it's an object/array, stringify it nicely
                    if (typeof textToCopy !== 'string') {
                        textToCopy = JSON.stringify(textToCopy, null, 2);
                    }
                } catch (e) {
                    // If parsing fails, use the original content
                    textToCopy = content;
                }
                
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(textToCopy).then(() => {
                        console.log('Copied to clipboard:', textToCopy);
                        // Dispatch notify event for success
                        Livewire.dispatch('notify', { message: successMessage, type: 'success' });
                    }).catch((error) => {
                        console.error('Failed to copy to clipboard:', error);
                        Livewire.dispatch('notify', { message: 'Failed to copy to clipboard', type: 'error' });
                    });
                } else {
                    // Fallback for insecure contexts
                    const textArea = document.createElement('textarea');
                    textArea.value = textToCopy;
                    textArea.style.position = 'fixed';
                    textArea.style.left = '-999999px';
                    textArea.style.top = '-999999px';
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    try {
                        document.execCommand('copy');
                        Livewire.dispatch('notify', { message: successMessage, type: 'success' });
                    } catch (error) {
                        console.error('Fallback copy failed:', error);
                        Livewire.dispatch('notify', { message: 'Failed to copy to clipboard', type: 'error' });
                    }
                    document.body.removeChild(textArea);
                }
            });
            
            Livewire.on('focus-search-input', () => {
                setTimeout(() => {
                    const inputEl = document.querySelector('input[wire\\:model\\.live\\.debounce\\.300ms="query"]');
                    if (inputEl) {
                        inputEl.focus();
                        inputEl.select();
                    }
                }, 100);
            });
        });

        // CRITICAL: Register Direct Chat event listener BEFORE livewire:init
        // This ensures the listener is ready when $this->js() dispatches the event
        // Handle direct chat streaming mode with EventSource for real-time AI responses
        window.addEventListener('initiate-direct-chat-stream', (event) => {
                const data = event.detail;
                const streamUrl = data.streamUrl;
                const interactionId = data.interactionId;
                const query = data.query;

                console.log('Direct chat stream initiated (browser event):', {
                    streamUrl,
                    interactionId,
                    query,
                    fullEventData: data,
                    streamUrlType: typeof streamUrl,
                    streamUrlLength: streamUrl?.length,
                    eventType: 'CustomEvent'
                });

                // Wait for Livewire to finish DOM updates before looking for container
                setTimeout(() => {
                    // Find the interaction-specific answer container
                    const searchResults = document.getElementById(`search-results-${interactionId}`);
                    if (!searchResults) {
                        console.error(`Search results container not found for interaction ${interactionId}`);
                        console.log('Available search-results elements:',
                            Array.from(document.querySelectorAll('[id^="search-results"]')).map(el => el.id));
                        return;
                    }

                    console.log(`Found search results container: search-results-${interactionId}`);

                // Initialize answer content - DON'T destroy the Alpine component structure!
                let accumulatedAnswer = '';
                searchResults.classList.remove('hidden');

                // Find the existing Alpine component elements
                let sourceElement = searchResults.querySelector('[x-ref="source"]');
                let targetElement = searchResults.querySelector('[x-ref="target"]');

                // Update the source element to show loading state
                if (sourceElement && targetElement) {
                    sourceElement.textContent = '_Connecting to AI..._';
                    sourceElement.dispatchEvent(new Event('input'));
                } else {
                    // Fallback if Alpine structure doesn't exist yet
                    searchResults.innerHTML = '<div class="flex items-center gap-2"><div class="animate-spin w-4 h-4 border-2 border-accent border-t-transparent rounded-full"></div>Connecting to AI...</div>';
                }

                // Dispatch streaming-started event to trigger auto-scroll
                window.dispatchEvent(new Event('streaming-started'));

                // Create EventSource connection
                console.log('Creating EventSource with URL:', streamUrl);
                console.log('EventSource URL analysis:', {
                    hasQueryParams: streamUrl.includes('?'),
                    hasInteractionId: streamUrl.includes('interactionId'),
                    hasQuery: streamUrl.includes('query=')
                });
                const eventSource = new EventSource(streamUrl);

                eventSource.addEventListener('update', (e) => {
                    try {
                        // Check for stream terminator before parsing
                        if (e.data === '</stream>') {
                            console.log('Direct chat stream completed - closing connection');
                            eventSource.close();

                            // Update streaming state without reloading interactions
                            // The markdown renderer already has the final content, no need to reload
                            const component = Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id'));
                            if (component) {
                                component.set('isStreaming', false);
                                component.set('isThinking', false);
                            }
                            return;
                        }

                        const data = JSON.parse(e.data);
                        console.log('Direct chat stream data received:', data);

                        if (data.type === 'error') {
                            let sourceElement = searchResults.querySelector('[x-ref="source"]');
                            if (sourceElement) {
                                sourceElement.textContent = `**Error:** ${data.content || 'Streaming error occurred'}`;
                                sourceElement.dispatchEvent(new Event('input'));
                            }
                            eventSource.close();
                            return;
                        }

                        if (data.type === 'research_step') {
                            // Skip research_step status updates in directly mode
                            // Status updates should only appear in Steps tab (via WebSocket StatusStreamManager)
                            // This prevents status text from appearing in the answer area during streaming
                            console.log('Skipping research_step status in directly mode:', data.status);
                            // Don't update answer container with status messages
                        }

                        if (data.type === 'answer_stream') {
                            // Update accumulated answer
                            accumulatedAnswer = data.content;

                            // Get Alpine markdown renderer elements
                            let sourceElement = searchResults.querySelector('[x-ref="source"]');
                            let targetElement = searchResults.querySelector('[x-ref="target"]');

                            if (sourceElement && targetElement) {
                                // Update the source (for consistency)
                                sourceElement.textContent = accumulatedAnswer;

                                // Directly render markdown to target element (bypass Alpine for real-time updates)
                                if (window.marked) {
                                    try {
                                        const html = window.marked.parse(accumulatedAnswer);
                                        targetElement.innerHTML = html;

                                        // Apply syntax highlighting
                                        if (window.hljs) {
                                            targetElement.querySelectorAll('pre code').forEach(codeElement => {
                                                try {
                                                    window.hljs.highlightElement(codeElement);
                                                } catch (e) {
                                                    console.warn('Highlighting failed:', e);
                                                }
                                            });
                                        }

                                        console.log('Updated markdown renderer with new content length:', accumulatedAnswer.length);

                                        // Dispatch answer-updated event to trigger auto-scroll (reuse existing infrastructure)
                                        window.dispatchEvent(new Event('answer-updated'));
                                    } catch (error) {
                                        console.error('Error rendering markdown:', error);
                                        targetElement.textContent = accumulatedAnswer;
                                    }
                                } else {
                                    // Fallback if marked.js not loaded
                                    targetElement.textContent = accumulatedAnswer;
                                }

                                // Dispatch answer-updated event for fallback rendering too
                                window.dispatchEvent(new Event('answer-updated'));
                            } else {
                                console.error('Alpine markdown renderer elements not found in search-results container');
                            }
                        }
                    } catch (error) {
                        console.error('Error processing direct chat stream data:', error, e.data);
                    }
                });

                eventSource.addEventListener('error', (e) => {
                    console.error('Direct chat EventSource error:', e);

                    // Update via Alpine markdown renderer
                    let sourceElement = searchResults.querySelector('[x-ref="source"]');
                    if (sourceElement) {
                        sourceElement.textContent = '**Error:** Stream connection error. Please try again.';
                        sourceElement.dispatchEvent(new Event('input'));
                    }

                    eventSource.close();

                    // Update UI state
                    const component = Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id'));
                    if (component) {
                        component.set('isStreaming', false);
                        component.set('isThinking', false);
                    }
                });
            });
        });
        // Handle agent execution polling for workflows in research mode
        window.addEventListener('start-agent-polling', function(event) {
            const detail = event.detail || {};
            const interactionId = detail.interactionId;
            const executionId = detail.executionId;
            console.log('ResearchInterface: start-agent-polling received', detail);
            // Poll after initial delay
            setTimeout(function() {
                // Call Livewire method to poll agent execution
                const componentEl = document.querySelector('[wire\\:id]');
                const componentId = componentEl ? componentEl.getAttribute('wire:id') : null;
                if (componentId) {
                    Livewire.find(componentId).call('pollAgentExecution', interactionId, executionId);
                }
            }, 1000);
        });
        // Continue polling when prompted by backend
        window.addEventListener('continue-agent-polling', function(event) {
            const detail = event.detail || {};
            const interactionId = detail.interactionId;
            const executionId = detail.executionId;
            console.log('ResearchInterface: continue-agent-polling received', detail);
            setTimeout(function() {
                const componentEl = document.querySelector('[wire\\:id]');
                const componentId = componentEl ? componentEl.getAttribute('wire:id') : null;
                if (componentId) {
                    Livewire.find(componentId).call('pollAgentExecution', interactionId, executionId);
                }
            }, 1000);
        });
        // Handle completion of agent execution
        window.addEventListener('agent-completed', function(event) {
            const detail = event.detail || {};
            console.log('ResearchInterface: agent-completed', detail);
            const componentEl = document.querySelector('[wire\\:id]');
            const componentId = componentEl ? componentEl.getAttribute('wire:id') : null;
            if (componentId) {
                const component = Livewire.find(componentId);
                // Update steps and final answer
                if (detail.steps) {
                    component.call('updateResearchSteps', detail.steps);
                }
                if (detail.result) {
                    component.call('setFinalAnswer', detail.result);
                }
            }
        });
        // Handle agent execution failure
        window.addEventListener('agent-failed', function(event) {
            const detail = event.detail || {};
            console.log('ResearchInterface: agent-failed', detail);
            const componentEl = document.querySelector('[wire\\:id]');
            const componentId = componentEl ? componentEl.getAttribute('wire:id') : null;
            if (componentId && detail.error) {
                Livewire.find(componentId).call('setFinalAnswer', detail.error);
            }
        });

        // Initialize Marked.js WITHOUT Prism.js integration
        (function initMarked() {
            if (!window.marked) {
                return setTimeout(initMarked, 50);
            }

            window.marked.use({
                async: false,
                pedantic: false,
                gfm: true,
            });

            // Standard renderer - just create the HTML structure
            const renderer = new window.marked.Renderer();

            renderer.code = function(code, lang) {
                // Debug logging
                console.log('Markdown code renderer called with:', { code, lang, codeType: typeof code });

                // Handle structured code objects (from AI responses)
                let codeContent = '';
                let language = lang || 'plaintext';

                if (typeof code === 'string') {
                    codeContent = code;
                } else if (code && typeof code === 'object') {
                    // Handle structured code objects like {"type":"code","raw":"```python\n...","lang":"python","text":"..."}
                    if (code.text) {
                        codeContent = code.text;
                        language = code.lang || language;
                    } else if (code.raw) {
                        // Extract code from raw markdown
                        const rawCode = code.raw;
                        const codeMatch = rawCode.match(/```[a-zA-Z]*\n([\s\S]*?)```/);
                        if (codeMatch) {
                            codeContent = codeMatch[1];
                            language = code.lang || language;
                        } else {
                            codeContent = rawCode;
                        }
                    } else {
                        codeContent = JSON.stringify(code);
                    }
                } else {
                    codeContent = String(code || '');
                }

                // Normalize language aliases to supported Highlight.js language identifiers
                const languageMap = {
                    'js': 'javascript',
                    'ts': 'typescript', 
                    'py': 'python',
                    'rb': 'ruby',
                    'sh': 'bash',
                    'zsh': 'bash',
                    'fish': 'bash',
                    'ps1': 'powershell',
                    'cs': 'csharp',
                    'cpp': 'cpp',
                    'c++': 'cpp',
                    'hpp': 'cpp',
                    'h': 'c',
                    'dockerfile': 'docker',
                    'yml': 'yaml',
                    'toml': 'toml',
                    'conf': 'ini',
                    'config': 'ini',
                    'properties': 'ini'
                };

                language = languageMap[language.toLowerCase()] || language;

                // Properly escape HTML entities
                codeContent = codeContent.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

                // Create standard HTML structure for Highlight.js
                // Highlight.js will handle highlighting after markdown parsing
                return `<pre><code class="hljs language-${language}">${codeContent}</code></pre>`;
            };

            window.marked.use({ renderer });

            window.dispatchEvent(new Event('marked:ready'));
        })();

        // Alpine.js markdown renderer
        function markdownRenderer() {
            return {
                renderedHtml: '',
                init() {
                    this.render();

                    this.observer = new MutationObserver(() => this.render());
                    this.observer.observe(this.$refs.source, {
                        characterData: true,
                        childList: true,
                        subtree: true,
                    });

                    // Listen for streaming updates
                    this.$refs.source.addEventListener('input', () => this.render());

                    window.addEventListener('marked:ready', () => this.render());
                },
                render() {
                    if (!window.marked || !this.$refs.source || !this.$refs.target) return;

                    const raw = this.$refs.source.textContent.trim();

                    try {
                        // Configure marked.js for proper rendering
                        window.marked.setOptions({
                            gfm: true,           // GitHub Flavored Markdown
                            breaks: true,        // Convert \n to <br>
                            headerIds: true,     // Add IDs to headings
                            mangle: false,       // Don't mangle email addresses
                            pedantic: false,     // Don't be overly strict
                        });

                        // Extract mermaid blocks before markdown parsing
                        const mermaidBlocks = [];
                        let processedContent = raw;
                        const mermaidRegex = /```mermaid\s*\n([\s\S]*?)\n\s*```/g;
                        let match;
                        let index = 0;

                        while ((match = mermaidRegex.exec(raw)) !== null) {
                            // Use HTML comment format to avoid markdown processing
                            const marker = `<!--MERMAID-DIAGRAM-${index}-->`;
                            mermaidBlocks.push({
                                index: index,
                                code: match[1].trim(),
                                marker: marker,
                                fullMatch: match[0]
                            });
                            processedContent = processedContent.replace(match[0], marker);
                            index++;
                        }

                        // Step 1: Parse markdown to HTML (without mermaid blocks)
                        let html = window.marked.parse(processedContent);

                        // Restore mermaid blocks as pre elements
                        // Store original code in data attribute for theme re-rendering
                        mermaidBlocks.forEach(block => {
                            const escapedCode = block.code.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                            const mermaidHtml = `<pre class="mermaid" data-mermaid-code="${escapedCode}">${block.code}</pre>`;
                            // HTML comments pass through marked.js unchanged
                            html = html.replace(block.marker, mermaidHtml);
                        });

                        if (html !== this.renderedHtml) {
                            // Step 2: Update DOM with parsed HTML
                            this.$refs.target.innerHTML = html;
                            this.renderedHtml = html;

                            // Step 3: Apply Highlight.js highlighting (exclude mermaid blocks)
                            if (window.hljs) {
                                // Get all code elements EXCEPT mermaid blocks
                                const codeElements = this.$refs.target.querySelectorAll('pre:not(.mermaid) code');
                                codeElements.forEach(codeElement => {
                                    // Clear any previous highlighting state
                                    codeElement.className = codeElement.className.replace(/hljs\S*/g, '').trim();
                                    codeElement.removeAttribute('data-highlighted');

                                    // Apply highlighting to this specific element
                                    try {
                                        window.hljs.highlightElement(codeElement);
                                    } catch (error) {
                                        console.warn('Highlighting failed for element:', error);
                                        // Fallback: add basic hljs class for styling
                                        codeElement.classList.add('hljs');
                                    }
                                });
                            }

                            // Step 4: Render Mermaid diagrams (use nextTick to ensure DOM is ready)
                            if (window.mermaid && mermaidBlocks.length > 0) {
                                requestAnimationFrame(() => {
                                    try {
                                        const mermaidElements = this.$refs.target.querySelectorAll('pre.mermaid:not([data-processed="true"])');
                                        if (mermaidElements.length > 0) {
                                            mermaid.run({
                                                nodes: Array.from(mermaidElements)
                                            }).catch(err => {
                                                console.error('Mermaid rendering error:', err);
                                            });
                                        }
                                    } catch (error) {
                                        console.error('Mermaid rendering failed:', error);
                                    }
                                });
                            }

                            window.dispatchEvent(new CustomEvent('markdown-update'));
                        }
                    } catch (error) {
                        console.error('Markdown parsing error:', error);
                        // Fallback to showing raw content if parsing fails
                        this.$refs.target.innerHTML = `<pre>${raw}</pre>`;
                    }
                },
                destroy() {
                    this.observer && this.observer.disconnect();
                }
            };
        }
        
        // Simple auto-scroll functionality using global utility
        function scrollToBottom() {
            const container = document.getElementById('conversation-container');
            if (container && window.scrollToNewestMessage) {
                // Small delay to ensure DOM updates are complete
                setTimeout(() => {
                    window.scrollToNewestMessage(container);
                }, 100);
            }
        }
        
        // Auto-scroll event listeners
        window.addEventListener('interaction-added', scrollToBottom);
        window.addEventListener('streaming-started', scrollToBottom);
        window.addEventListener('answer-updated', scrollToBottom);
        window.addEventListener('session-loaded', function() {
            scrollToBottom();
            // Reset research step tracking when loading a different session
            window.activeResearchSteps.clear();
            window.stepCounter = 0;
            console.log('Reset activeResearchSteps and stepCounter for session load');
        });
        
        // Global Livewire event listener to re-highlight code after DOM updates
        document.addEventListener('livewire:init', () => {
            // Listen for all Livewire updates that might affect syntax highlighting
            Livewire.hook('morph.updated', (el, component) => {
                // Re-highlight any code blocks within the updated element
                if (window.hljs && el.querySelectorAll) {
                    const codeElements = el.querySelectorAll('pre code:not([data-highlighted])');
                    if (codeElements.length > 0) {
                        codeElements.forEach(codeElement => {
                            try {
                                // Clear any stale highlighting classes
                                codeElement.className = codeElement.className.replace(/hljs\\S*/g, '').trim();
                                codeElement.removeAttribute('data-highlighted');
                                
                                // Apply fresh highlighting
                                window.hljs.highlightElement(codeElement);
                                
                                // Add copy button if not already present
                                addCopyButtonToCodeBlock(codeElement);
                            } catch (error) {
                                console.warn('Failed to re-highlight code element:', error);
                            }
                        });
                    }
                }
            });

            // Function to add copy button to code blocks
            function addCopyButtonToCodeBlock(codeElement) {
                const preElement = codeElement.parentElement;
                if (!preElement || preElement.tagName !== 'PRE') return;
                
                // Check if copy button already exists
                if (preElement.querySelector('.hljs-copy-button')) return;
                
                // Create copy button
                const copyButton = document.createElement('button');
                copyButton.className = 'hljs-copy-button absolute top-2 right-2 px-2 py-1 text-xs bg-surface-elevated hover:bg-surface text-primary rounded border border-default opacity-0 group-hover:opacity-100 transition-opacity duration-200 z-10';
                copyButton.innerHTML = '📋 Copy';
                copyButton.title = 'Copy code to clipboard';
                
                // Add relative positioning and group class to pre element
                preElement.style.position = 'relative';
                preElement.classList.add('group');
                
                // Add click handler
                copyButton.addEventListener('click', async (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const codeText = codeElement.textContent || '';
                    
                    try {
                        if (navigator.clipboard && window.isSecureContext) {
                            await navigator.clipboard.writeText(codeText);
                        } else {
                            // Fallback for insecure contexts
                            const textArea = document.createElement('textarea');
                            textArea.value = codeText;
                            textArea.style.position = 'fixed';
                            textArea.style.left = '-999999px';
                            document.body.appendChild(textArea);
                            textArea.focus();
                            textArea.select();
                            document.execCommand('copy');
                            document.body.removeChild(textArea);
                        }
                        
                        // Show success feedback
                        const originalText = copyButton.innerHTML;
                        copyButton.innerHTML = '✅ Copied!';
                        copyButton.classList.add('bg-accent', 'hover:bg-accent-hover');
                        copyButton.classList.remove('bg-surface-elevated', 'hover:bg-surface');

                        setTimeout(() => {
                            copyButton.innerHTML = originalText;
                            copyButton.classList.remove('bg-accent', 'hover:bg-accent-hover');
                            copyButton.classList.add('bg-surface-elevated', 'hover:bg-surface');
                        }, 2000);
                        
                    } catch (error) {
                        console.error('Failed to copy code:', error);
                        
                        // Show error feedback
                        const originalText = copyButton.innerHTML;
                        copyButton.innerHTML = '❌ Failed';
                        copyButton.classList.add('bg-error', 'hover:opacity-90');
                        copyButton.classList.remove('bg-surface-elevated', 'hover:bg-surface');

                        setTimeout(() => {
                            copyButton.innerHTML = originalText;
                            copyButton.classList.remove('bg-error', 'hover:opacity-90');
                            copyButton.classList.add('bg-surface-elevated', 'hover:bg-surface');
                        }, 2000);
                    }
                });
                
                // Append copy button to pre element
                preElement.appendChild(copyButton);
            }

            // Utility function to re-highlight code elements
            function rehighlightCodeBlocks(container = document) {
                if (!window.hljs) return;
                
                // Ensure we have a valid DOM container
                if (!container || typeof container.querySelectorAll !== 'function') {
                    container = document;
                }
                
                // Find all code elements that need highlighting
                const codeElements = container.querySelectorAll('pre code');
                let highlighted = 0;
                
                codeElements.forEach(codeElement => {
                    try {
                        // Skip if already highlighted and looks correct
                        if (codeElement.dataset.highlighted === 'yes' && 
                            codeElement.classList.contains('hljs')) {
                            return;
                        }
                        
                        // Clear any stale highlighting classes
                        codeElement.className = codeElement.className.replace(/hljs\\S*/g, '').trim();
                        codeElement.removeAttribute('data-highlighted');
                        
                        // Apply fresh highlighting
                        window.hljs.highlightElement(codeElement);
                        
                        // Add copy button if not already present
                        addCopyButtonToCodeBlock(codeElement);
                        
                        highlighted++;
                    } catch (error) {
                        console.warn('Failed to re-highlight code element:', error);
                    }
                });
                
                if (highlighted > 0) {
                }
            }

            // Listen for Livewire DOM morphing updates
            Livewire.hook('morph.updated', (el, component) => {
                setTimeout(() => rehighlightCodeBlocks(el), 50);
            });

            // Listen for Livewire component updates
            Livewire.hook('message.processed', (message, component) => {
                setTimeout(() => rehighlightCodeBlocks(), 100);
            });

            // Listen for custom events that might update markdown content  
            window.addEventListener('markdown-update', () => {
                setTimeout(() => rehighlightCodeBlocks(), 50);
            });
        });
        
        // Scroll on page load
        document.addEventListener('DOMContentLoaded', scrollToBottom);
        document.addEventListener('livewire:init', () => {
            scrollToBottom();

            // Set up Echo subscriptions for existing interaction on page load
            const metaTag = document.querySelector('meta[name="interaction-id"]');

            if (metaTag) {
                const interactionId = metaTag.getAttribute('content');

                if (interactionId && interactionId !== '' && interactionId !== 'null') {
                    try {
                        setupEchoSubscriptions(interactionId);
                    } catch (error) {
                        console.error('Error setting up Echo subscriptions:', error);
                    }
                }
            }
        });

        // Listen for artifact deletion events from JavaScript
        window.addEventListener('artifact-deleted', (event) => {
            const artifactId = event.detail.artifactId;
            console.log('Artifact deleted event received:', artifactId);

            // Call Livewire method to handle deletion
            const componentEl = document.querySelector('[wire\\:id]');
            if (componentEl) {
                const component = Livewire.find(componentEl.getAttribute('wire:id'));
                if (component) {
                    component.call('handleArtifactDeleted', artifactId);
                }
            }
        });

        // Listen for notify events from JavaScript
        window.addEventListener('notify', (event) => {
            const { message, type } = event.detail;
            console.log('Notify event received:', { message, type });
        });
    </script>
    @endpush

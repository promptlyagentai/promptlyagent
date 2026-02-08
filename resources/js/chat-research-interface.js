/**
 * ChatResearchInterface - Main chat UI interaction manager
 *
 * Orchestrates the primary chat research interface including message handling,
 * streaming responses, voice input, file attachments, and real-time updates.
 * Integrates with WebSockets (via Reverb/Echo) for live status streams and
 * provides comprehensive UI state management.
 *
 * Key Features:
 * - WebSocket subscriptions for real-time status updates
 * - Markdown rendering with syntax highlighting (Prism.js)
 * - Thinking process visualization during AI responses
 * - Status stream management via StatusStreamManager
 * - Auto-scrolling and smooth UI interactions
 * - Debug utilities for troubleshooting WebSocket connections
 *
 * @module chat-research-interface
 */

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

function updateStatusDisplayDirectly(statusData, interactionId = null) {
    const agentSelect = document.querySelector('select[wire\\:model="selectedAgent"]');
    const isDirectChat = agentSelect && agentSelect.value === 'directly';

    if (isDirectChat) {
        console.log('[Direct Chat] Skipping status update:', statusData.message);
        return;
    }

    const statusMessage = `🔍 ${statusData.source}: ${statusData.message}`;
    const statusHTML = `<div class="flex items-center gap-2">
        <div class="animate-spin w-4 h-4 border-2 border-tropical-teal-500 border-t-transparent rounded-full"></div>
        ${statusMessage}
    </div>`;

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
            searchResults.classList.remove('hidden');
            updated = true;
            console.log('Status updated using search-results');

            // Auto-scroll to newest message in search-results container
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
        const currentResultsDiv = document.querySelector('.text-gray-600.dark\\:text-gray-400.text-sm');
        if (currentResultsDiv) {
            try {
                currentResultsDiv.innerHTML = statusHTML;
                updated = true;
                console.log('Status updated using generic status container');

                // Auto-scroll for generic status updates
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

// Memory optimization: Limit DOM elements in containers
const MAX_THINKING_STEPS = 200; // Limit thinking process steps to prevent memory bloat
const MAX_EVENT_HISTORY = 500; // Limit EventLogger history

// Cleanup old DOM elements to prevent memory leaks
function cleanupOldDOMElements(containerId, maxElements) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const steps = container.querySelectorAll(':scope > div');
    if (steps.length > maxElements) {
        const removeCount = steps.length - maxElements;
        console.log(`Cleaning up ${removeCount} old elements from ${containerId}`);

        // Remove oldest elements
        for (let i = 0; i < removeCount; i++) {
            steps[i].remove();
        }
    }
}

function updateThinkingProcess(statusData) {
    const agentSelect = document.querySelector('select[wire\\:model="selectedAgent"]');
    const isDirectChat = agentSelect && agentSelect.value === 'directly';

    if (isDirectChat) {
        console.log('[Direct Chat] Skipping thinking process update:', statusData.message);
        return;
    }

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

    // Cleanup old elements if we exceed the limit
    cleanupOldDOMElements('thinking-process-container', MAX_THINKING_STEPS);

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
                <div class="absolute left-4 w-4 h-4 bg-white dark:bg-zinc-900 border-2 border-tropical-teal-300 dark:border-tropical-teal-600 rounded-full flex items-center justify-center">
                    <div class="w-2 h-2 bg-tropical-teal-500 rounded-full"></div>
                </div>

                <!-- Content -->
                <div class="ml-12">
                    <div class="flex items-center justify-between mb-3 px-2">
                        <div class="font-medium text-sm text-zinc-900 dark:text-zinc-100 flex-1 pr-4">
                            ${formattedMessage}
                        </div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400 flex-shrink-0">
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
                <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded">
                    <div class="w-1.5 h-1.5 bg-green-500 rounded-full flex-shrink-0"></div>

                    <div class="text-zinc-600 dark:text-zinc-400 truncate flex-1 min-w-0">${formattedMessage}</div>
                    <span class="text-xs text-zinc-500 dark:text-zinc-400 flex-shrink-0">${step.timestamp}</span>
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
        '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-tropical-teal-600 dark:text-tropical-teal-400 underline hover:text-tropical-teal-800 dark:hover:text-tropical-teal-300">$1</a>'
    );

    // Bold search terms in quotes
    formatted = formatted.replace(
        /"([^"]+)"/g,
        '<strong class="font-semibold text-gray-900 dark:text-gray-100">"$1"</strong>'
    );

    // Bold key results with more patterns
    formatted = formatted.replace(
        /(found \d+ results?|completed in \d+[.\d]*\w*|validated \d+ URLs?|converting \d+ URLs?|downloading from|processing \d+ links?)/gi,
        '<strong class="font-semibold text-green-600 dark:text-green-400">$1</strong>'
    );

    // Bold domain names (but skip if already inside a link)
    formatted = formatted.replace(
        /(?<!href="|>)([a-zA-Z0-9.-]+\.(com|org|net|edu|gov|io|co|ai|dev|uk|de|fr|jp|ca|au)(?:[^\s<"']*)?)/gi,
        function(match, domain) {
            // Don't wrap if it's already inside an HTML tag
            return '<strong class="font-semibold text-tropical-teal-600 dark:text-tropical-teal-400">' + domain + '</strong>';
        }
    );

    // Specific markitdown patterns
    formatted = formatted.replace(
        /(Converting|Downloaded|Processing)(\s+\d+\s+characters?|\s+content)/gi,
        '<strong class="font-semibold text-purple-600 dark:text-purple-400">$1$2</strong>'
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

// Track active subscriptions for cleanup
if (typeof window.activeEchoSubscriptions === 'undefined') {
    window.activeEchoSubscriptions = new Map(); // channelName -> subscription
}

// Clean up all Echo subscriptions for memory management
function cleanupEchoSubscriptions() {
    if (!window.Echo) return;

    console.log('Cleaning up Echo subscriptions:', Array.from(window.activeEchoSubscriptions.keys()));

    for (const [channelName, subscription] of window.activeEchoSubscriptions.entries()) {
        try {
            window.Echo.leaveChannel(channelName);
            console.log('Left channel:', channelName);
        } catch (e) {
            console.warn('Failed to leave channel:', channelName, e);
        }
    }

    window.activeEchoSubscriptions.clear();
}

// Helper function to set up Echo subscriptions for an interaction
function setupEchoSubscriptions(interactionId) {
    if (!window.Echo || !interactionId) {
        console.log('Echo not available or no interaction ID provided');
        return;
    }

    console.log('Setting up Echo subscriptions for interaction:', interactionId);

    // Clean up previous subscriptions before setting up new ones
    cleanupEchoSubscriptions();

    // Subscribe to status stream for this interaction
    const channelName = `status-stream.${interactionId}`;
    console.log('Subscribing to status stream channel:', channelName);

    // REMOVED: Direct Echo listener for StatusStreamCreated
    // StatusStreamManager now handles all WebSocket subscriptions automatically
    // No manual Echo subscriptions needed for status updates
    console.log('StatusStream subscriptions handled by StatusStreamManager automatically');

    // Subscribe to queue status updates for real-time job tracking
    const queueChannelName = `chat-interaction.${interactionId}`;
    console.log('Setting up queue status WebSocket listener on channel:', queueChannelName);

    const queueChannel = window.Echo.channel(queueChannelName)
        .listen('QueueStatusUpdated', (e) => {
            console.log('Queue status update received:', e);

            // Dispatch Livewire event instead of calling $refresh for better performance
            Livewire.dispatch('queue-status-updated', e);

            // Log via EventLogger for tracking
            if (window.eventLogger) {
                window.eventLogger.logEvent('QueueStatusUpdate', queueChannelName, {
                    ...e.job_data,
                    source: 'websocket_queue_listener',
                    receivedAt: new Date().toISOString()
                });
            }
        });

    // Track subscription for cleanup
    window.activeEchoSubscriptions.set(queueChannelName, queueChannel);

    // Subscribe to chat interaction updates for Answers tab
    const answerChannelName = `chat-interaction.${interactionId}`;
    console.log('Subscribing to chat interaction channel:', answerChannelName);

    const answerChannel = window.Echo.channel(answerChannelName)
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
            const component = Livewire.find(document.querySelector('[wire\\:id]')?.getAttribute('wire:id'));
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
        });

    // Track subscription for cleanup
    window.activeEchoSubscriptions.set(answerChannelName, answerChannel);

    // Subscribe to source updates for Sources tab
    const sourcesChannelName = `sources-updated.${interactionId}`;
    console.log('Subscribing to sources channel:', sourcesChannelName);

    const sourcesChannel = window.Echo.channel(sourcesChannelName)
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

    // Track subscription for cleanup
    window.activeEchoSubscriptions.set(sourcesChannelName, sourcesChannel);

    // Subscribe to general source creation events
    console.log('Subscribing to general sources-updated channel');

    const generalSourcesChannel = window.Echo.channel('sources-updated')
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

    // Track subscription for cleanup
    window.activeEchoSubscriptions.set('sources-updated', generalSourcesChannel);

    // Subscribe to artifact updates for Artifacts tab
    const artifactsChannelName = `artifacts-updated.${interactionId}`;
    console.log('Subscribing to artifacts channel:', artifactsChannelName);

    const artifactsChannel = window.Echo.channel(artifactsChannelName)
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

    // Track subscription for cleanup
    window.activeEchoSubscriptions.set(artifactsChannelName, artifactsChannel);
}


function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func.apply(this, args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function throttle(func, wait) {
    let lastCall = 0;
    let timeout = null;

    return function executedFunction(...args) {
        const now = Date.now();
        const timeSinceLastCall = now - lastCall;

        if (timeSinceLastCall >= wait) {
            lastCall = now;
            func.apply(this, args);
        } else {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                lastCall = Date.now();
                func.apply(this, args);
            }, wait - timeSinceLastCall);
        }
    };
}

window.markdownRenderer = function() {
    return {
        renderedHtml: '',
        isRendering: false,
        isStreaming: false,
        lastUpdateTime: 0,
        observer: null,
        inputHandler: null,
        streamingCheckInterval: null,

        init() {
            this.throttledRender = throttle(() => this.render(), 100);
            this.render();

            this.observer = new MutationObserver(() => {
                this.isStreaming = true;
                this.lastUpdateTime = Date.now();
                this.throttledRender();
            });
            this.observer.observe(this.$refs.source, {
                characterData: true,
                childList: true,
                subtree: true,
            });

            this.inputHandler = () => {
                this.isStreaming = true;
                this.lastUpdateTime = Date.now();
                this.throttledRender();
            };
            this.$refs.source.addEventListener('input', this.inputHandler);

            this.streamingCheckInterval = setInterval(() => {
                if (this.isStreaming && Date.now() - this.lastUpdateTime > 2000) {
                    this.isStreaming = false;
                    this.render(true);
                }
            }, 2000);
        },

        render(forceHighlight = false) {
            if (this.isRendering) return;
            if (!window.marked || !this.$refs.source || !this.$refs.target) return;

            this.isRendering = true;

            try {
                const raw = this.$refs.source.textContent.trim();

                const markedInstance = forceHighlight ? window.markedWithHighlight : window.marked;
                console.log(`[Markdown] ${forceHighlight ? 'HIGHLIGHTING' : 'no highlighting'}, streaming: ${this.isStreaming}, length: ${raw.length}`);

                const html = markedInstance.parse(raw);
                if (html !== this.renderedHtml) {
                    this.$refs.target.innerHTML = html;
                    this.renderedHtml = html;
                    window.dispatchEvent(new CustomEvent('markdown-update'));
                }
            } catch (error) {
                console.error('Markdown parsing error:', error);
                this.$refs.target.innerHTML = `<pre>${raw}</pre>`;
            } finally {
                this.isRendering = false;
            }
        },

        destroy() {
            // Clean up observers and event listeners to prevent memory leaks
            if (this.observer) {
                this.observer.disconnect();
                this.observer = null;
            }

            if (this.inputHandler && this.$refs.source) {
                this.$refs.source.removeEventListener('input', this.inputHandler);
                this.inputHandler = null;
            }

            if (this.streamingCheckInterval) {
                clearInterval(this.streamingCheckInterval);
                this.streamingCheckInterval = null;
            }

            // Clear rendered content to free memory
            this.renderedHtml = '';
            if (this.$refs.target) {
                this.$refs.target.innerHTML = '';
            }
        }
    };
};

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

// Global cleanup function for memory management
function performMemoryCleanup() {
    console.log('Performing memory cleanup...');

    // 1. Cleanup Echo subscriptions
    cleanupEchoSubscriptions();

    // 2. Cleanup old DOM elements
    cleanupOldDOMElements('thinking-process-container', MAX_THINKING_STEPS);

    // 3. Reset global state counters periodically
    if (window.stepCounter > 1000) {
        console.log('Resetting step counter from', window.stepCounter);
        window.stepCounter = 0;
    }

    // 4. Clear old research steps (keep only last 50)
    if (window.activeResearchSteps && window.activeResearchSteps.size > 50) {
        const entries = Array.from(window.activeResearchSteps.entries());
        const keysToDelete = entries.slice(0, entries.length - 50).map(([key]) => key);
        keysToDelete.forEach(key => window.activeResearchSteps.delete(key));
        console.log('Cleared', keysToDelete.length, 'old research steps');
    }

    // 5. Trim EventLogger history
    if (window.eventLogger && window.eventLogger.eventHistory.length > MAX_EVENT_HISTORY) {
        const excess = window.eventLogger.eventHistory.length - MAX_EVENT_HISTORY;
        window.eventLogger.eventHistory.splice(0, excess);
        console.log('Trimmed', excess, 'old events from EventLogger');
    }

    // 6. Clear message cache if it gets too large
    if (window.statusStreamManager && window.statusStreamManager.messageCache.size > 1000) {
        window.statusStreamManager.messageCache.clear();
        console.log('Cleared StatusStreamManager message cache');
    }

    console.log('Memory cleanup complete');
}

// Setup Livewire lifecycle hooks for cleanup
document.addEventListener('livewire:init', () => {
    // Cleanup when navigating between chat sessions
    Livewire.hook('morph.updated', () => {
        console.log('Livewire morph updated - performing cleanup');
        performMemoryCleanup();
    });

    // Periodic cleanup every 2 minutes
    setInterval(() => {
        console.log('Periodic memory cleanup triggered');
        performMemoryCleanup();
    }, 120000); // 2 minutes
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    cleanupEchoSubscriptions();
});

// Export functions for use in Blade template
export {
    updateStatusDisplayDirectly,
    updateThinkingProcess,
    setupEchoSubscriptions,
    scrollToBottom,
    cleanupEchoSubscriptions,
    performMemoryCleanup
};

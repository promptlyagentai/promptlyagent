/**
 * StatusStream - WebSocket manager for real-time status updates
 *
 * Manages real-time communication between the backend and frontend during AI agent
 * execution. Provides unified event processing, connection health monitoring, and
 * comprehensive logging for debugging WebSocket-based status streams.
 *
 * @module status-stream
 *
 * Core Architecture:
 * - Unified event processing through processStatusEvent() method
 * - Three chat modes: regular, research, and agent
 * - Centralized chat mode detection with localStorage persistence
 * - Real-time DOM updates via thinking-process container
 *
 * Key Features:
 * - WebSocket connection health monitoring with automatic reconnection
 * - Exponential backoff retry strategy (max 5 attempts)
 * - Message deduplication using Set-based cache
 * - Consistent blue-dot timeline styling across all modes
 * - Laravel Echo/Reverb integration for real-time communication
 * - Comprehensive EventLogger for debugging and analysis
 *
 * Event Flow:
 * 1. WebSocket events → handleStatusStreamEvent()
 * 2. Event processing → processStatusEvent() [UNIFIED ENTRY POINT]
 * 3. DOM rendering → renderTimelineStep()
 * 4. Livewire sync → syncWithLivewire() (when needed)
 *
 * Event Types:
 * - StatusStreamCreated: New status stream initiated
 * - ChatInteractionUpdated: Interaction content updated
 * - SourceCreated: Research source added
 * - ExternalKnowledgeUpdated: External knowledge integration event
 * - AgentPhaseChanged: Multi-agent workflow phase transition
 * - ContentChunkReceived: Streaming content fragment
 * - ToolExecutionStarted/Completed: Tool usage lifecycle
 */

/**
 * EventLogger - Comprehensive logging for WebSocket events
 * 
 * Provides detailed logging of all WebSocket events for debugging and analysis.
 * Tracks event patterns, timing, and provides formatted console output.
 */
class EventLogger {
    constructor() {
        this.enabled = false; // Can be toggled via console: window.eventLogger.enabled = true
        this.eventHistory = [];
        this.eventCounts = new Map();
        this.eventStats = {
            totalEvents: 0,
            eventsByChannel: new Map(),
            eventsByType: new Map(),
            firstEventTime: null,
            lastEventTime: null
        };
        this.maxHistorySize = 500; // Keep last 500 events in memory (memory optimization)
        
        // Color coding for different event types
        this.colors = {
            StatusStreamCreated: '#2563eb', // blue
            ChatInteractionUpdated: '#059669', // green
            SourceCreated: '#7c3aed', // purple
            ExternalKnowledgeUpdated: '#dc2626', // red
            AgentPhaseChanged: '#ea580c', // orange
            AgentProgressUpdated: '#0891b2', // cyan
            ResearchComplete: '#16a34a', // green
            HolisticWorkflowCompleted: '#059669', // emerald
            HolisticWorkflowFailed: '#ef4444', // red
            ResearchFailed: '#ef4444', // red
            ToolStatusUpdated: '#ca8a04', // yellow
            ChatInteractionSourceCreated: '#be185d', // pink
            connection: '#6b7280', // gray
            error: '#ef4444', // red
            default: '#374151' // dark gray
        };
        
        console.log('%c🚀 EventLogger initialized - logging disabled by default', 'color: #2563eb; font-weight: bold;');
        console.log('%cEnable logging: window.eventLogger.enabled = true', 'color: #6b7280;');
        console.log('%cView statistics: window.eventLogger.getStats()', 'color: #6b7280;');
        console.log('%cView recent events: window.eventLogger.getRecentEvents(10)', 'color: #6b7280;');
    }
    
    /**
     * Log a WebSocket event with comprehensive details
     */
    logEvent(eventType, channel, payload = {}, processingTime = null, error = null) {
        if (!this.enabled) return;
        
        const timestamp = new Date();
        const eventData = {
            timestamp,
            eventType,
            channel,
            payload: JSON.parse(JSON.stringify(payload)), // Deep copy
            processingTime,
            error,
            id: this.generateEventId()
        };
        
        // Add to history
        this.addToHistory(eventData);
        
        // Update statistics
        this.updateStats(eventData);
        
        // Console output
        this.outputToConsole(eventData);
        
        // Dispatch custom event for external listeners
        window.dispatchEvent(new CustomEvent('websocket-event-logged', {
            detail: eventData
        }));
    }
    
    /**
     * Log WebSocket connection events
     */
    logConnection(event, details = {}) {
        this.logEvent('connection', 'system', {
            connectionEvent: event,
            ...details
        });
    }
    
    /**
     * Log WebSocket errors
     */
    logError(error, context = {}) {
        this.logEvent('error', 'system', {
            error: error.message || error,
            context
        }, null, error);
    }
    
    /**
     * Log channel subscription events
     */
    logChannelEvent(action, channel, details = {}) {
        this.logEvent('channel_operation', channel, {
            action, // 'subscribe', 'unsubscribe', 'listen'
            ...details
        });
    }
    
    /**
     * Add event to history with size management
     */
    addToHistory(eventData) {
        this.eventHistory.push(eventData);
        
        // Maintain history size
        if (this.eventHistory.length > this.maxHistorySize) {
            this.eventHistory.shift(); // Remove oldest event
        }
    }
    
    /**
     * Update event statistics
     */
    updateStats(eventData) {
        const { eventType, channel, timestamp } = eventData;
        
        this.eventStats.totalEvents++;
        
        // Track by channel
        const channelCount = this.eventStats.eventsByChannel.get(channel) || 0;
        this.eventStats.eventsByChannel.set(channel, channelCount + 1);
        
        // Track by event type
        const typeCount = this.eventStats.eventsByType.get(eventType) || 0;
        this.eventStats.eventsByType.set(eventType, typeCount + 1);
        
        // Track timing
        if (!this.eventStats.firstEventTime) {
            this.eventStats.firstEventTime = timestamp;
        }
        this.eventStats.lastEventTime = timestamp;
        
        // Update individual event count
        const key = `${channel}:${eventType}`;
        const count = this.eventCounts.get(key) || 0;
        this.eventCounts.set(key, count + 1);
    }
    
    /**
     * Output formatted event to console
     */
    outputToConsole(eventData) {
        const { timestamp, eventType, channel, payload, processingTime, error } = eventData;
        const color = this.colors[eventType] || this.colors.default;
        const timeStr = timestamp.toISOString().split('T')[1].replace('Z', '');
        
        // Main event log
        console.group(`%c📡 ${eventType}`, `color: ${color}; font-weight: bold;`);
        console.log(`%c⏰ Time: ${timeStr}`, 'color: #6b7280;');
        console.log(`%c📺 Channel: ${channel}`, 'color: #7c3aed;');
        
        if (processingTime !== null) {
            console.log(`%c⚡ Processing: ${processingTime}ms`, 'color: #059669;');
        }
        
        if (error) {
            console.log(`%c❌ Error: ${error.message || error}`, 'color: #ef4444;');
        }
        
        // Payload (collapsed by default)
        if (Object.keys(payload).length > 0) {
            console.log('%c📦 Payload:', 'color: #374151;', payload);
        }
        
        console.groupEnd();
    }
    
    /**
     * Generate unique event ID
     */
    generateEventId() {
        return `evt_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    }
    
    /**
     * Get event statistics
     */
    getStats() {
        const sessionDuration = this.eventStats.lastEventTime && this.eventStats.firstEventTime
            ? this.eventStats.lastEventTime - this.eventStats.firstEventTime
            : 0;
            
        return {
            ...this.eventStats,
            sessionDurationMs: sessionDuration,
            eventsPerMinute: sessionDuration > 0 ? (this.eventStats.totalEvents / (sessionDuration / 60000)).toFixed(2) : 0,
            topChannels: Array.from(this.eventStats.eventsByChannel.entries())
                .sort(([,a], [,b]) => b - a)
                .slice(0, 5),
            topEventTypes: Array.from(this.eventStats.eventsByType.entries())
                .sort(([,a], [,b]) => b - a)
                .slice(0, 5)
        };
    }
    
    /**
     * Get recent events
     */
    getRecentEvents(count = 10) {
        return this.eventHistory.slice(-count);
    }
    
    /**
     * Clear history and stats
     */
    clear() {
        this.eventHistory = [];
        this.eventCounts.clear();
        this.eventStats = {
            totalEvents: 0,
            eventsByChannel: new Map(),
            eventsByType: new Map(),
            firstEventTime: null,
            lastEventTime: null
        };
        console.log('%c🧹 EventLogger cleared', 'color: #059669; font-weight: bold;');
    }
    
    /**
     * Export events as JSON
     */
    exportEvents() {
        const exportData = {
            metadata: {
                exportTime: new Date().toISOString(),
                totalEvents: this.eventStats.totalEvents,
                sessionDuration: this.eventStats.lastEventTime && this.eventStats.firstEventTime
                    ? this.eventStats.lastEventTime - this.eventStats.firstEventTime
                    : 0
            },
            stats: this.getStats(),
            events: this.eventHistory
        };
        
        console.log('%c📁 Event export data:', 'color: #2563eb; font-weight: bold;', exportData);
        return exportData;
    }
}

class StatusStreamManager {
    constructor() {
        // Configuration
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 1000; // Start with 1 second
        this.healthCheckInterval = 30000; // 30 seconds (reduced from 10s for memory optimization)

        // Event logging
        this.eventLogger = new EventLogger();

        // State tracking
        this.currentInteractionId = null;
        this.activeSubscriptions = new Map(); // Channel name -> subscription object
        this.connectionStatus = 'disconnected';
        this.reconnectAttempts = 0;
        this.reconnectTimer = null;
        this.healthCheckTimer = null;
        this.messageCache = new Set(); // For deduplication (max 1000 items before auto-clear)

        // Processing state
        this.processingComplete = false; // Set to true when processing is complete
        this.chatMode = this.detectAndStoreChatMode(); // Detect which chat interface we're in ('research', 'regular', 'agent')

        // Keep track of initialized subscriptions to reset state
        this.initializedInteractionIds = new Set();

        // Memory optimization: Periodic cache cleanup
        this.setupPeriodicCleanup();
        

        // Bind methods
        this.bindMethods();
        
        // Initialize container visibility
        this.initializeThinkingProcessContainer();
        
        // Monitor Echo initialization
        this.waitForEchoInit();
    }
    
    /**
     * Bind all instance methods to this
     */
    bindMethods() {
        this.setupEchoSubscriptions = this.setupEchoSubscriptions.bind(this);
        this.unsubscribe = this.unsubscribe.bind(this);
        this.handleStatusStreamEvent = this.handleStatusStreamEvent.bind(this);
        this.processStatusEvent = this.processStatusEvent.bind(this);
        this.createStandardStep = this.createStandardStep.bind(this);
        this.renderTimelineStep = this.renderTimelineStep.bind(this);
        this.checkConnectionHealth = this.checkConnectionHealth.bind(this);
        this.reconnect = this.reconnect.bind(this);
        this.detectAndStoreChatMode = this.detectAndStoreChatMode.bind(this);
        this.setActiveChatMode = this.setActiveChatMode.bind(this);
        this.getOrCreateTimelineContainer = this.getOrCreateTimelineContainer.bind(this);
        this.createStepElement = this.createStepElement.bind(this);
        this.formatMessage = this.formatMessage.bind(this);
        this.formatTimestamp = this.formatTimestamp.bind(this);
        this.determineStepType = this.determineStepType.bind(this);
        this.syncWithLivewire = this.syncWithLivewire.bind(this);
        this.checkForCompletion = this.checkForCompletion.bind(this);
        this.isDuplicateMessage = this.isDuplicateMessage.bind(this);
        this.initializeThinkingProcessContainer = this.initializeThinkingProcessContainer.bind(this);
        this.scrollToNewest = this.scrollToNewest.bind(this);
    }
    
    /**
     * Ensure the thinking-process container is visible and properly initialized
     * @returns {boolean} - Whether initialization was successful
     */
    initializeThinkingProcessContainer() {
        // Find the container
        const container = document.getElementById('thinking-process-container');
        if (!container) {
                return false;
        }
        
        // Make it visible
        container.classList.remove('hidden');
        
        // For regular chat, hide the progress-log (unified container approach)
        if (this.chatMode === 'regular') {
            const progressLog = document.getElementById('progress-log');
            if (progressLog) {
                progressLog.classList.add('hidden');
            }
        }
        
        return true;
    }

    /**
     * Enhanced chat mode detection with centralized storage and DOM analysis
     * 
     * Detection Priority:
     * 1. localStorage (for current interaction) - most reliable
     * 2. DOM indicators - progress-log for regular, agent containers for agent mode
     * 3. Research-specific elements - answer/steps tabs, thinking-process containers
     * 4. Global window.isResearchMode flag - fallback
     * 5. Default to 'regular' mode
     * 
     * @returns {string} - Detected chat mode: 'research', 'regular', or 'agent'
     */
    detectAndStoreChatMode() {
        // Check localStorage first (highest priority)
        if (this.currentInteractionId) {
            try {
                const storedChatMode = localStorage.getItem(`chatMode-${this.currentInteractionId}`);
                if (storedChatMode) {
                    this.setActiveChatMode(storedChatMode);
                    return storedChatMode;
                }
            } catch (e) {
                console.warn('StatusStreamManager: Error reading from localStorage', e);
            }
        }

        // Check DOM indicators (second priority)
        if (document.getElementById('progress-log') && !document.getElementById('thinking-process-container')) {
            // Only use progress-log as an indicator if thinking-process is not present
            // This is for backward compatibility with pages that haven't been updated
            this.setActiveChatMode('regular');
            return 'regular';
        }
        
        // Check for agent-specific elements
        if (document.querySelector('.agent-execution-container') || 
            document.querySelector('[data-agent-execution]')) {
            this.setActiveChatMode('agent');
            return 'agent';
        }
        
        // Check for research-specific elements
        if (document.querySelector('[x-ref="answerContent"]') || 
            document.querySelector('[x-ref="stepsContent"]') ||
            (document.querySelector('button[role="tab"]') && 
             document.querySelector('button[role="tab"]').textContent.toLowerCase().includes('answer'))) {
            this.setActiveChatMode('research');
            return 'research';
        }
        
        // Check global variables last (least reliable)
        if (window.isResearchMode === true) {
            this.setActiveChatMode('research');
            return 'research';
        }

        // Default to regular mode
        this.setActiveChatMode('regular');
        return 'regular';
    }
    
    /**
     * Set active chat mode and ensure consistent state across the application
     * @param {string} mode - The chat mode to set ('research', 'regular', or 'agent')
     */
    setActiveChatMode(mode) {
        this.chatMode = mode;
        
        // Ensure global state is consistent
        window.isResearchMode = (mode === 'research');
        
        // Store in localStorage for current interaction
        if (this.currentInteractionId) {
            try {
                localStorage.setItem(`chatMode-${this.currentInteractionId}`, mode);
                localStorage.setItem('lastInteractionId', this.currentInteractionId);
            } catch (e) {
                console.warn('StatusStreamManager: Error writing to localStorage', e);
            }
        }
        
        // Reset processing state appropriately for this mode
        if (mode === 'regular') {
            // Regular chat should always keep processing
            this.processingComplete = false;
        }
        
        
        // Initialize the appropriate container
        this.initializeThinkingProcessContainer();
    }

    
    /**
     * Process a status stream event with unified approach for all chat modes
     * 
     * This is the main entry point for all status updates. It handles:
     * - Message deduplication
     * - Standardized step creation
     * - DOM updates via thinking-process container
     * - Livewire synchronization (when appropriate)
     * - Completion detection (research/agent modes only)
     * - AI response streaming (real-time text updates)
     * - Thinking process streaming (AI reasoning display)
     * 
     * @param {Object} eventData - The event data to process
     * @param {string} eventData.source - Event source (e.g., 'tool_result', 'agent_execution', 'ai_response_stream', 'thinking_process')
     * @param {string} eventData.message - Human-readable status message or streamed content
     * @param {string} [eventData.timestamp] - ISO timestamp or locale string
     * @param {boolean} [eventData.is_significant=false] - Whether this is a major update
     * @param {Object} [eventData.metadata={}] - Additional event metadata
     * @param {boolean} [eventData.create_event=true] - Whether to create DOM element
     */
    processStatusEvent(eventData) {
        const agentSelect = document.querySelector('select[wire\\:model="selectedAgent"]');
        const isDirectChat = agentSelect && agentSelect.value === 'directly';

        if (isDirectChat) {
            console.log('[Direct Chat] Blocking status event:', eventData.source, eventData.message);
            return;
        }

        const currentUrl = window.location.pathname;
        const isPwaRoute = currentUrl.startsWith('/pwa/');
        const isResearchRoute = currentUrl.includes('/dashboard/chat') || currentUrl.includes('/dashboard');

        const container = document.getElementById('thinking-process-container');
        if (container) {
            const containerInPwa = container.closest('[x-data*="pwaChat"]') || container.closest('.pwa-chat-container');
            const containerInResearch = container.closest('[data-current-interaction-id]') || !containerInPwa;

            if (isPwaRoute && !containerInPwa) {
                return;
            }

            if (isResearchRoute && containerInPwa) {
                return;
            }
        }

        // Note: We don't block status events based on processingComplete anymore
        // WebSocket should handle all status updates regardless of completion state for real-time experience
        // The original blocking logic was causing status updates to stop flowing prematurely

        // Handle special streaming event types first
        if (eventData.source === 'ai_response_stream') {
            this.handleAiResponseStream(eventData);
            return;
        }

        if (eventData.source === 'thinking_process') {
            this.handleThinkingProcessStream(eventData);
            return;
        }

        // Skip duplicate messages for regular status events
        if (this.isDuplicateMessage(eventData)) {
            return;
        }

        // Convert event data into a standardized status step
        const step = this.createStandardStep(eventData);

        // Get the thinking-process container (same for all modes)
        if (!container) {
                return;
        }

        // No longer checking data-updating since we removed wire:stream from Answer tab
        // WebSocket processing handles all status updates exclusively

        // Ensure parent container is visible (override Livewire inline style)
        const parentContainer = container.parentElement;
        if (parentContainer && (parentContainer.style.display === 'none' || parentContainer.style.display === '')) {
            parentContainer.style.display = 'block';
        }

        // Ensure container is visible
        container.classList.remove('hidden');

        // Hide progress-log completely - unified container handles all modes
        const progressLog = document.getElementById('progress-log');
        if (progressLog) {
            progressLog.classList.add('hidden');
        }

        // Render the step (identical for all modes)
        this.renderTimelineStep(container, step);
        
        // Auto-scroll to show the newest status message (with delay to ensure DOM is updated)
        setTimeout(() => {
            this.scrollToNewest(container);
        }, 50);
        
        // DISABLED: Livewire sync removed to prevent race conditions in multi-process environment
        // All status updates are now handled exclusively via WebSocket DOM updates
        // Original implementation caused competing updates between Livewire and WebSocket
        // if (step.is_significant || this.chatMode === 'regular') {
        //     this.syncWithLivewire(step);
        // }
        
        // Handle completion detection (only for research/agent modes)
        if (this.chatMode !== 'regular') {
            this.checkForCompletion(step);
        }
    }

    
    /**
     * Create a standardized status step from event data
     * @param {Object} eventData - The raw event data
     * @returns {Object} Standardized step object
     */
    createStandardStep(eventData) {
        // Process step data
        const stepType = eventData.metadata?.step_type || 
                       this.determineStepType(eventData.source, eventData.message);

        // Generate a unique ID for the step
        const messageKey = `${eventData.source}-${eventData.message}`;
        const stepId = `status-step-${messageKey.replace(/[^a-zA-Z0-9]/g, '-')}`;
        
        // Return standardized step object
        return {
            id: stepId,
            type: stepType,
            source: eventData.source,
            message: this.formatMessage(eventData.message),
            timestamp: this.formatTimestamp(eventData.timestamp),
            metadata: eventData.metadata || {},
            is_significant: eventData.is_significant || false,
            tool: eventData.source === 'tool_result' ? eventData.metadata?.tool_name : null
        };
    }
    
    /**
     * Render a timeline step in the container
     * @param {HTMLElement} container - The container element
     * @param {Object} step - The standardized step object
     */
    renderTimelineStep(container, step) {
        // Check if we already have this step
        if (document.getElementById(step.id)) {
            return;
        }

        // Get or create our timeline container
        let timelineContainer = this.getOrCreateTimelineContainer(container);

        // Create step element
        const stepElement = this.createStepElement(step);

        // Add to timeline container
        timelineContainer.appendChild(stepElement);

        // Ensure automatic scrolling to newest message
        this.scrollToNewest(container);
    }

    /**
     * Create a step element with consistent styling
     * @param {Object} step - The standardized step object
     * @returns {HTMLElement} The created step element
     */
    createStepElement(step) {
        // Create step wrapper
        const stepWrapper = document.createElement('div');
        stepWrapper.id = `wrapper-${step.id}`;
        stepWrapper.style.paddingLeft = '40px';
        
        // Create step element
        const stepElement = document.createElement('div');
        stepElement.className = 'flex items-center gap-3 py-1 px-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800 rounded';
        stepElement.id = step.id;
        
        // Create blue dot
        const blueDot = document.createElement('div');
        blueDot.className = 'w-1.5 h-1.5 bg-tropical-teal-500 rounded-full flex-shrink-0';
        stepElement.appendChild(blueDot);
        
        // Create message container
        const messageContainer = document.createElement('div');
        messageContainer.className = 'min-w-0 flex-1';
        messageContainer.textContent = step.message;
        stepElement.appendChild(messageContainer);
        
        // Create timestamp
        const timestampEl = document.createElement('div');
        timestampEl.className = 'text-xs text-gray-500 dark:text-gray-400 flex-shrink-0';
        timestampEl.textContent = step.timestamp;
        stepElement.appendChild(timestampEl);
        
        // Add to wrapper
        stepWrapper.appendChild(stepElement);
        
        return stepWrapper;
    }

    /**
     * Scroll to the newest message in the container or find appropriate parent
     * @param {HTMLElement} element - The element or container to scroll
     */
    scrollToNewest(element) {
        if (!element) return;
        
        
        // Use the global utility function
        window.scrollToNewestMessage(element);
    }

    /**
     * Get or create a timeline container in the parent element
     * @param {HTMLElement} parentElement - The parent container
     * @returns {HTMLElement} The timeline container
     */
    getOrCreateTimelineContainer(parentElement) {
        // Check for existing container
        let container = parentElement.querySelector('.status-timeline-container');
        
        if (!container) {
            // Create new container
            container = document.createElement('div');
            container.className = 'status-timeline-container space-y-1';
            
            try {
                // Find initial message if it exists
                const initialMessage = parentElement.querySelector('.researching-message, p');
                
                if (initialMessage && initialMessage.parentNode === parentElement) {
                    // Add spacing after initial message
                    container.style.marginTop = '1.5rem';
                    
                    // Insert after the initial message
                    if (initialMessage.nextSibling) {
                        parentElement.insertBefore(container, initialMessage.nextSibling);
                    } else {
                        parentElement.appendChild(container);
                    }
                } else {
                    // No initial message or parent mismatch, append to parent
                    parentElement.appendChild(container);
                }
            } catch (e) {
                console.warn('StatusStreamManager: Error creating timeline container', e);
                // Fallback to direct append
                parentElement.appendChild(container);
            }
        }
        
        return container;
    }
    
    /**
     * Helper function to format the message
     * @param {string} message - The raw message
     * @returns {string} The formatted message
     */
    formatMessage(message) {
        return message;
    }
    
    /**
     * Helper function to format the timestamp
     * @param {string|Date|undefined} timestamp - The raw timestamp
     * @returns {string} The formatted timestamp
     */
    formatTimestamp(timestamp) {
        if (timestamp) {
            if (typeof timestamp === 'string') {
                // Check if it's an ISO string (contains 'T' and 'Z' or timezone info)
                if (timestamp.includes('T') && (timestamp.includes('Z') || timestamp.includes('+') || timestamp.includes('-'))) {
                    // Parse ISO string and format as HH:MM:SS
                    try {
                        return new Date(timestamp).toTimeString().split(' ')[0];
                    } catch (e) {
                        console.warn('StatusStreamManager: Invalid timestamp format:', timestamp);
                        return new Date().toTimeString().split(' ')[0];
                    }
                }
                // If it's already a formatted string (like "14:23:45"), return as is
                return timestamp;
            }
            // Format Date object as HH:MM:SS
            return new Date(timestamp).toTimeString().split(' ')[0];
        }
        // Default to current time in HH:MM:SS format
        return new Date().toTimeString().split(' ')[0];
    }
    
    /**
     * Determine the type of step from source and message
     * @param {string} source - The source of the step
     * @param {string} message - The message content
     * @returns {string} The step type
     */
    determineStepType(source, message) {
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
    
    /**
     * @deprecated Sync step data with Livewire component - DISABLED for WebSocket-only updates
     * This method has been disabled to prevent race conditions between Livewire and WebSocket updates
     * in multi-process nginx/php-fpm environments. All status updates are now handled exclusively
     * via WebSocket DOM manipulation for consistent, real-time behavior.
     * @param {Object} step - The standardized step object
     */
    syncWithLivewire(step) {
        // DISABLED: This method now only logs for monitoring during transition
        console.info('StatusStreamManager: syncWithLivewire called but disabled for WebSocket-only updates', {
            step_message: step.message ? step.message.substring(0, 50) : 'no message',
            step_source: step.source,
            is_significant: step.is_significant,
            chat_mode: this.chatMode,
            interaction_id: this.currentInteractionId,
            transition_note: 'All updates now handled via WebSocket DOM manipulation'
        });
        
        // DO NOT call Livewire component - prevents race conditions
        // Original implementation:
        // component.call('handleStatusStreamUpdate', {...});
    }
    
    /**
     * Check if a step indicates completion of processing
     * @param {Object} step - The standardized step object
     */
    checkForCompletion(step) {
        // Check for completion indicators
        const messageLower = step.message.toLowerCase();
        
        if (messageLower.includes('finished') || 
            messageLower.includes('completed') || 
            messageLower.includes('done processing') || 
            (step.source === 'system' && messageLower.includes('complete')))
        {
            this.processingComplete = true;
        }
    }

    /**
     * Check if a message is a duplicate
     * @param {Object} eventData - The event data
     * @returns {boolean} Whether the message is a duplicate
     */
    isDuplicateMessage(eventData) {
        // Create unique identifier for this message
        const messageId = `${eventData.source}:${eventData.message}`;
        
        // Check if we've seen this message before
        if (this.messageCache.has(messageId)) {
            return true;
        }
        
        // Add to message cache to prevent duplicates
        this.messageCache.add(messageId);
        
        // Limit cache size
        if (this.messageCache.size > 1000) {
            // Convert to array
            const messages = Array.from(this.messageCache);
            // Remove oldest 200 messages
            const newMessages = messages.slice(200);
            this.messageCache = new Set(newMessages);
        }
        
        return false;
    }
    
    
    /**
     * Wait for Laravel Echo to be initialized
     */
    waitForEchoInit() {
        if (window.Echo) {
            this.initializeEchoMonitoring();
        } else {
            // Wait for Echo to be available
            document.addEventListener('livewire:init', () => {
                if (window.Echo) {
                    this.initializeEchoMonitoring();
                } else {
                    console.warn('StatusStreamManager: Echo not available after livewire:init');
                    setTimeout(() => this.waitForEchoInit(), 500);
                }
            });
        }
    }

    /**
     * Initialize Echo connection monitoring
     */
    initializeEchoMonitoring() {
        if (!window.Echo || !window.Echo.connector || !window.Echo.connector.pusher) {
            console.warn('StatusStreamManager: Echo connector structure not available');
            return;
        }

        // Monitor connection events
        window.Echo.connector.pusher.connection.bind('connected', () => {
            this.connectionStatus = 'connected';
            this.reconnectAttempts = 0;

            // Clear any pending reconnection
            if (this.reconnectTimer) {
                clearTimeout(this.reconnectTimer);
                this.reconnectTimer = null;
            }

            // Start health check
            this.startHealthCheck();

            // Re-subscribe to active channels if needed
            this.resubscribeIfNeeded();

            // Dispatch event for UI components
            window.dispatchEvent(new CustomEvent('status-stream:connected'));
        });

        window.Echo.connector.pusher.connection.bind('disconnected', () => {
            console.warn('StatusStreamManager: WebSocket disconnected');
            this.connectionStatus = 'disconnected';

            // Stop health check while disconnected
            this.stopHealthCheck();

            // Attempt reconnection
            this.reconnect();

            // Dispatch event for UI components
            window.dispatchEvent(new CustomEvent('status-stream:disconnected'));
        });

        window.Echo.connector.pusher.connection.bind('error', (error) => {
            console.error('StatusStreamManager: WebSocket error', error);
            this.connectionStatus = 'error';

            // Attempt reconnection on error
            this.reconnect();

            // Dispatch event for UI components
            window.dispatchEvent(new CustomEvent('status-stream:error', { detail: error }));
        });

        // Start initial health check
        this.startHealthCheck();

    }

    /**
     * Start health check interval
     */
    startHealthCheck() {
        // Clear any existing health check
        this.stopHealthCheck();

        // Set up new health check interval
        this.healthCheckTimer = setInterval(this.checkConnectionHealth, this.healthCheckInterval);
    }

    /**
     * Stop health check interval
     */
    stopHealthCheck() {
        if (this.healthCheckTimer) {
            clearInterval(this.healthCheckTimer);
            this.healthCheckTimer = null;
        }
    }

    /**
     * Check WebSocket connection health
     */
    checkConnectionHealth() {
        if (!window.Echo || !window.Echo.connector || !window.Echo.connector.pusher) {
            console.warn('StatusStreamManager: Echo not available during health check');
            return;
        }

        const connection = window.Echo.connector.pusher.connection;
        const currentState = connection.state;

        // Log connection state for debugging

        if (currentState !== 'connected') {
            console.warn(`StatusStreamManager: Unhealthy connection state: ${currentState}`);

            // Update internal tracking
            this.connectionStatus = currentState;

            // Attempt reconnection for disconnected/failed states
            if (['disconnected', 'failed', 'connecting'].includes(currentState)) {
                // Only reconnect if we haven't already started
                if (currentState !== 'connecting') {
                    this.reconnect();
                }
            }

            // Dispatch event for UI components
            window.dispatchEvent(new CustomEvent('status-stream:unhealthy', {
                detail: { state: currentState }
            }));
        }
    }

    /**
     * Attempt to reconnect with exponential backoff
     */
    reconnect() {
        // Skip if already at max attempts
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.error(`StatusStreamManager: Max reconnection attempts (${this.maxReconnectAttempts}) reached`);

            // Dispatch event for UI components to show error message
            window.dispatchEvent(new CustomEvent('status-stream:max-retries', {
                detail: { attempts: this.reconnectAttempts }
            }));
            return;
        }

        // Clear any existing reconnect timer
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
        }

        // Increment attempt counter
        this.reconnectAttempts++;

        // Calculate delay with exponential backoff (1s, 2s, 4s, 8s, 16s)
        const delay = this.reconnectDelay * Math.pow(2, this.reconnectAttempts - 1);


        // Update UI with reconnection status
        this.updateConnectionStatus({
            source: 'system',
            message: `Reconnecting to status stream... (${this.reconnectAttempts}/${this.maxReconnectAttempts})`,
            timestamp: new Date().toISOString(),
        });

        // Set timer for reconnection
        this.reconnectTimer = setTimeout(() => {
            // Force reconnection by disconnecting and connecting
            if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {

                // Attempt to disconnect gracefully
                try {
                    window.Echo.connector.pusher.disconnect();
                } catch (e) {
                    console.warn('StatusStreamManager: Error during disconnect', e);
                }

                // Short delay before reconnecting
                setTimeout(() => {
                    try {
                        window.Echo.connector.pusher.connect();
                    } catch (e) {
                        console.warn('StatusStreamManager: Error during connect', e);
                    }
                }, 500);
            }
        }, delay);

        // Dispatch event for UI components
        window.dispatchEvent(new CustomEvent('status-stream:reconnecting', {
            detail: { attempt: this.reconnectAttempts, maxAttempts: this.maxReconnectAttempts }
        }));
    }

    /**
     * Resubscribe to all active channels
     */
    resubscribeIfNeeded() {
        // Skip if no active subscriptions or no current interaction
        if (this.activeSubscriptions.size === 0 || !this.currentInteractionId) {
            return;
        }


        // Copy current subscriptions for safety
        const currentChannels = Array.from(this.activeSubscriptions.keys());

        // Clear current subscriptions (we'll re-add them)
        this.activeSubscriptions.clear();

        // Set up subscriptions again
        this.setupEchoSubscriptions(this.currentInteractionId);
    }

    /**
     * Update connection status display
     */
    updateConnectionStatus(statusData) {
        // Find status containers to update
        const containers = [
            document.getElementById('websocket-status-container'),
            document.getElementById('connection-status'),
        ].filter(Boolean);

        // Update any available containers
        for (const container of containers) {
            try {
                // Add chat mode indicator to status messages for debugging
            const chatModeIndicator = this.chatMode ? `[${this.chatMode} mode] ` : '';
            const statusHTML = `
                    <div class="flex items-center gap-2 py-1 px-2 text-sm bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 rounded">
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        ${chatModeIndicator}${statusData.message}
                    </div>
                `;
                container.innerHTML = statusHTML;
                container.classList.remove('hidden');
            } catch (e) {
                console.warn('StatusStreamManager: Failed to update connection status container', e);
            }
        }
    }

    /**
     * Set up WebSocket subscriptions for an interaction
     * @param {number} interactionId - The ID of the interaction to subscribe to
     */
    setupEchoSubscriptions(interactionId) {
        if (!window.Echo || !interactionId) {
            console.warn('StatusStreamManager: Echo not available or no interaction ID provided');
            this.eventLogger.logError('Echo setup failed', {
                echoAvailable: !!window.Echo,
                interactionId: interactionId || 'null'
            });
            return;
        }

        // Guard against duplicate subscriptions - check if we're already subscribed
        if (this.currentInteractionId === interactionId && this.activeSubscriptions.size > 0) {
            console.log(`StatusStreamManager: Already subscribed to interaction ${interactionId}, skipping duplicate subscription`);
            return;
        }

        // Guard against cross-window/tab duplicate subscriptions
        const subscriptionKey = `echo_sub_${interactionId}`;
        if (window[subscriptionKey]) {
            console.log(`StatusStreamManager: Interaction ${interactionId} already has active subscription in this window`);
            return;
        }

        // Immediately claim ownership
        window[subscriptionKey] = {
            route: window.location.pathname,
            timestamp: Date.now()
        };

        // Log subscription setup start
        this.eventLogger.logConnection('subscription_setup_start', {
            interactionId,
            previousInteractionId: this.currentInteractionId,
            currentRoute: window.location.pathname
        });

        // Store the current interaction ID
        this.currentInteractionId = interactionId;
        
        // Try to get chat mode from localStorage first
        let storedChatMode = null;
        try {
            storedChatMode = localStorage.getItem(`chatMode-${interactionId}`);
            if (storedChatMode) {
                this.chatMode = storedChatMode;
                window.isResearchMode = (storedChatMode === 'research');
            } else {
                // Re-detect chat mode (in case page has changed or navigation occurred)
                this.chatMode = this.detectAndStoreChatMode();
            }
        } catch (e) {
            // If localStorage fails, fallback to detection
            console.warn('StatusStreamManager: Error reading from localStorage, falling back to detection', e);
            this.chatMode = this.detectAndStoreChatMode();
        }
        
        
        // Check if this is a new interaction we haven't seen before
        if (!this.initializedInteractionIds.has(interactionId)) {
            // This is a new interaction, reset processing state
            this.processingComplete = false;
            // Add to our set of known interactions
            this.initializedInteractionIds.add(interactionId);
            
            // Keep the set size reasonable
            if (this.initializedInteractionIds.size > 10) {
                // Remove the oldest interaction ID (first item in the set)
                const oldestId = this.initializedInteractionIds.values().next().value;
                this.initializedInteractionIds.delete(oldestId);
            }
        }

        // Unsubscribe from any previous subscriptions first
        this.unsubscribeAll();

        // Clear message cache for fresh interaction
        this.messageCache.clear();

        // 1. Subscribe to status stream updates
        const statusChannel = `status-stream.${interactionId}`;


        try {
            this.eventLogger.logChannelEvent('subscribe', statusChannel, {
                interactionId,
                eventTypes: ['.StatusStreamCreated', 'StatusStreamCreated', 'App\\Events\\StatusStreamCreated', 'App.Events.StatusStreamCreated']
            });

            const statusSubscription = window.Echo.channel(statusChannel)
                .listen('.StatusStreamCreated', (e) => {
                    this.handleStatusStreamEvent(e);
                })
                .listen('StatusStreamCreated', (e) => {
                    this.handleStatusStreamEvent(e);
                })
                .listen('App\\Events\\StatusStreamCreated', (e) => {
                    this.handleStatusStreamEvent(e);
                })
                .listen('App.Events.StatusStreamCreated', (e) => {
                    this.handleStatusStreamEvent(e);
                });

            this.activeSubscriptions.set(statusChannel, statusSubscription);
            
            this.eventLogger.logChannelEvent('subscribed_successfully', statusChannel, {
                listenersCount: 4
            });
        } catch (e) {
            console.error('StatusStreamManager: Error setting up status subscription', e);
            this.eventLogger.logError(e, { 
                context: 'status_subscription_setup', 
                channel: statusChannel 
            });
        }

        // 2. Subscribe to chat interaction updates
        const answerChannel = `chat-interaction.${interactionId}`;

        this.eventLogger.logChannelEvent('subscribe', answerChannel, {
            interactionId,
            eventTypes: [
                'ChatInteractionUpdated',
                '.HolisticWorkflowCompleted', 'HolisticWorkflowCompleted', 'App\\Events\\HolisticWorkflowCompleted', 'App.Events.HolisticWorkflowCompleted',
                '.HolisticWorkflowFailed', 'HolisticWorkflowFailed', 'App\\Events\\HolisticWorkflowFailed', 'App.Events.HolisticWorkflowFailed',
                '.ResearchComplete', 'ResearchComplete', 'App\\Events\\ResearchComplete', 'App.Events.ResearchComplete',
                '.ResearchFailed', 'ResearchFailed', 'App\\Events\\ResearchFailed', 'App.Events.ResearchFailed'
            ]
        });

        const answerSubscription = window.Echo.channel(answerChannel)
            .listen('ChatInteractionUpdated', (e) => this.handleChatInteractionEvent(e))
            // HolisticWorkflowCompleted variations
            .listen('.HolisticWorkflowCompleted', (e) => this.handleHolisticWorkflowCompleted(e))
            .listen('HolisticWorkflowCompleted', (e) => this.handleHolisticWorkflowCompleted(e))
            .listen('App\\Events\\HolisticWorkflowCompleted', (e) => this.handleHolisticWorkflowCompleted(e))
            .listen('App.Events.HolisticWorkflowCompleted', (e) => this.handleHolisticWorkflowCompleted(e))
            // HolisticWorkflowFailed variations
            .listen('.HolisticWorkflowFailed', (e) => this.handleHolisticWorkflowFailed(e))
            .listen('HolisticWorkflowFailed', (e) => this.handleHolisticWorkflowFailed(e))
            .listen('App\\Events\\HolisticWorkflowFailed', (e) => this.handleHolisticWorkflowFailed(e))
            .listen('App.Events.HolisticWorkflowFailed', (e) => this.handleHolisticWorkflowFailed(e))
            // ResearchComplete variations
            .listen('.ResearchComplete', (e) => this.handleResearchComplete(e))
            .listen('ResearchComplete', (e) => this.handleResearchComplete(e))
            .listen('App\\Events\\ResearchComplete', (e) => this.handleResearchComplete(e))
            .listen('App.Events.ResearchComplete', (e) => this.handleResearchComplete(e))
            // ResearchFailed variations
            .listen('.ResearchFailed', (e) => this.handleResearchFailed(e))
            .listen('ResearchFailed', (e) => this.handleResearchFailed(e))
            .listen('App\\Events\\ResearchFailed', (e) => this.handleResearchFailed(e))
            .listen('App.Events.ResearchFailed', (e) => this.handleResearchFailed(e));

        this.activeSubscriptions.set(answerChannel, answerSubscription);

        this.eventLogger.logChannelEvent('subscribed_successfully', answerChannel, {
            listenersCount: 17
        });

        // 3. Subscribe to source updates for this interaction
        const sourcesChannel = `sources-updated.${interactionId}`;

        this.eventLogger.logChannelEvent('subscribe', sourcesChannel, {
            interactionId,
            eventTypes: ['ChatInteractionSourceCreated']
        });

        const sourcesSubscription = window.Echo.channel(sourcesChannel)
            .listen('ChatInteractionSourceCreated', (e) => this.handleSourceCreatedEvent(e));

        this.activeSubscriptions.set(sourcesChannel, sourcesSubscription);

        this.eventLogger.logChannelEvent('subscribed_successfully', sourcesChannel, {
            listenersCount: 1
        });

        // Log overall subscription setup completion
        this.eventLogger.logConnection('subscription_setup_completed', {
            interactionId,
            totalChannels: this.activeSubscriptions.size,
            channels: Array.from(this.activeSubscriptions.keys()),
            chatMode: this.chatMode
        });
    }

    /**
     * Unsubscribe from a specific channel
     * @param {string} channelName - The name of the channel to unsubscribe from
     */
    unsubscribe(channelName) {
        if (!window.Echo) return;

        try {
            window.Echo.leaveChannel(channelName);
            this.activeSubscriptions.delete(channelName);
        } catch (e) {
            console.warn('StatusStreamManager: Error unsubscribing from', channelName, e);
        }
    }

    /**
     * Unsubscribe from all active channels
     */
    unsubscribeAll() {
        for (const channelName of this.activeSubscriptions.keys()) {
            this.unsubscribe(channelName);
        }

        // Clear the map
        this.activeSubscriptions.clear();
    }

    /**
     * Setup periodic cleanup for memory optimization
     */
    setupPeriodicCleanup() {
        // Run cleanup every 3 minutes
        setInterval(() => {
            // Clear message cache if it exceeds 1000 items
            if (this.messageCache.size > 1000) {
                console.log('StatusStreamManager: Clearing message cache (size:', this.messageCache.size, ')');
                this.messageCache.clear();
            }

            // Trim EventLogger history
            if (this.eventLogger.eventHistory.length > this.eventLogger.maxHistorySize) {
                const excess = this.eventLogger.eventHistory.length - this.eventLogger.maxHistorySize;
                this.eventLogger.eventHistory.splice(0, excess);
                console.log('StatusStreamManager: Trimmed', excess, 'old events from EventLogger');
            }

            // Clean up old initialized interaction IDs (keep only last 10)
            if (this.initializedInteractionIds.size > 10) {
                const idsArray = Array.from(this.initializedInteractionIds);
                const toRemove = idsArray.slice(0, idsArray.length - 10);
                toRemove.forEach(id => this.initializedInteractionIds.delete(id));
                console.log('StatusStreamManager: Removed', toRemove.length, 'old interaction IDs');
            }
        }, 180000); // 3 minutes
    }

    /**
     * Handle status stream event
     * @param {Object} event - The event data from StatusStreamCreated event
     */
    handleStatusStreamEvent(event) {
        const startTime = performance.now();
        
        // Log the incoming event with comprehensive details
        const channel = this.currentInteractionId ? `status-stream.${this.currentInteractionId}` : 'unknown';
        
        // Use our centralized chat mode detection and storage
        if (this.currentInteractionId) {
            try {
                const storedChatMode = localStorage.getItem(`chatMode-${this.currentInteractionId}`);
                if (!storedChatMode) {
                    // Only re-detect if no stored mode is available
                    this.chatMode = this.detectAndStoreChatMode();
                }
            } catch (e) {
                console.warn('StatusStreamManager: Error reading from localStorage, using current chat mode', e);
                this.eventLogger.logError(e, { context: 'localStorage_chat_mode_detection' });
            }
        }

        // Format the event data for the processor
        const eventData = {
            id: event.id,
            source: event.source,
            message: event.message,
            timestamp: event.timestamp,
            is_significant: event.is_significant || false,
            metadata: event.metadata || {},
            create_event: event.create_event !== false, // Default to true unless explicitly false
        };

        // Log the full event details
        this.eventLogger.logEvent('StatusStreamCreated', channel, {
            ...eventData,
            chatMode: this.chatMode,
            interactionId: this.currentInteractionId
        });

        // Process through our unified pipeline
        const processingStartTime = performance.now();
        try {
            this.processStatusEvent(eventData);
            const processingTime = performance.now() - processingStartTime;
            
            // Log processing completion with timing
            if (processingTime > 10) { // Only log if processing took more than 10ms
                this.eventLogger.logEvent('event_processing_completed', channel, {
                    processingTimeMs: processingTime.toFixed(2),
                    eventType: 'StatusStreamCreated',
                    eventId: event.id
                });
            }
        } catch (error) {
            this.eventLogger.logError(error, { 
                context: 'processStatusEvent', 
                eventData 
            });
            throw error; // Re-throw to maintain original behavior
        }

        // Update markdown container if available for proper rendering (still needed)
        const markdownContainer = document.querySelector('[x-data="markdownRenderer()"]');
        if (markdownContainer) {
            // Trigger a refresh of the markdown renderer
            const event = new CustomEvent('markdown-update');
            window.dispatchEvent(event);
        }

        // Dispatch a custom event for any other listeners
        window.dispatchEvent(new CustomEvent('status-stream:event-received', {
            detail: { eventData }
        }));
    }

    /**
     * Handle chat interaction event
     * @param {Object} event - The event data from ChatInteractionUpdated event
     */
    handleChatInteractionEvent(event) {
        const startTime = performance.now();
        const channel = this.currentInteractionId ? `chat-interaction.${this.currentInteractionId}` : 'unknown';

        // Log the chat interaction event
        this.eventLogger.logEvent('ChatInteractionUpdated', channel, {
            ...event,
            chatMode: this.chatMode,
            interactionId: this.currentInteractionId
        });

        try {
            // IMPORTANT: Check if answer is truncated
            // If truncated, skip display update and refetch full answer from database
            if (event.answer_truncated) {
                console.log('StatusStreamManager: Answer was truncated in broadcast, loading full answer from DB');

                // Call Livewire to refresh from database instead of using truncated data
                const livewireElement = document.querySelector('[wire\\:id]');
                if (livewireElement && window.Livewire) {
                    const componentId = livewireElement.getAttribute('wire:id');
                    const component = window.Livewire.find(componentId);

                    if (component && typeof component.call === 'function') {
                        component.call('loadInteractions');
                    }
                }
            } else {
                // Update answer display if available (only for non-truncated answers)
                this.updateAnswerDisplay(event);

                // Notify Livewire component
                this.notifyLivewireComponent('handleChatInteractionUpdated', event);
            }

            const processingTime = performance.now() - startTime;
            if (processingTime > 5) { // Log if processing took more than 5ms
                this.eventLogger.logEvent('event_processing_completed', channel, {
                    processingTimeMs: processingTime.toFixed(2),
                    eventType: 'ChatInteractionUpdated'
                });
            }
        } catch (error) {
            this.eventLogger.logError(error, {
                context: 'handleChatInteractionEvent',
                event
            });
            throw error;
        }
    }

    /**
     * Handle source created event
     * @param {Object} event - The event data from ChatInteractionSourceCreated event
     */
    handleSourceCreatedEvent(event) {
        const startTime = performance.now();
        const channel = this.currentInteractionId ? `sources-updated.${this.currentInteractionId}` : 'unknown';

        // Log the source created event
        this.eventLogger.logEvent('ChatInteractionSourceCreated', channel, {
            ...event,
            chatMode: this.chatMode,
            interactionId: this.currentInteractionId
        });

        try {
            // Notify Livewire component
            this.notifyLivewireComponent('handleChatInteractionSourceCreated', event);

            const processingTime = performance.now() - startTime;
            if (processingTime > 5) {
                this.eventLogger.logEvent('event_processing_completed', channel, {
                    processingTimeMs: processingTime.toFixed(2),
                    eventType: 'ChatInteractionSourceCreated'
                });
            }
        } catch (error) {
            this.eventLogger.logError(error, { 
                context: 'handleSourceCreatedEvent', 
                event 
            });
            throw error;
        }
    }

    /**
     * Handle holistic workflow completion event
     * @param {Object} event - The event data from HolisticWorkflowCompleted event
     */
    handleHolisticWorkflowCompleted(event) {
        const startTime = performance.now();
        const channel = this.currentInteractionId ? `chat-interaction.${this.currentInteractionId}` : 'unknown';

        // Log the holistic workflow completion event
        this.eventLogger.logEvent('HolisticWorkflowCompleted', channel, {
            ...event,
            chatMode: this.chatMode,
            interactionId: this.currentInteractionId
        });

        try {
            // Update UI with the completion result
            console.log('🎉 Holistic Workflow Completed:', {
                interactionId: event.interaction_id,
                executionId: event.execution_id,
                resultLength: event.result?.length || 0,
                sourcesCount: event.sources?.length || 0,
                metadata: event.metadata
            });

            // Call Livewire component's setFinalAnswer method
            // Skip if broadcast was truncated - full answer is already in database
            const livewireElement = document.querySelector('[wire\\:id]');
            if (livewireElement && window.Livewire && event.result) {
                const componentId = livewireElement.getAttribute('wire:id');
                const component = window.Livewire.find(componentId);

                if (component && typeof component.call === 'function') {
                    const isTruncated = event.metadata?.broadcast_truncated || false;

                    if (!isTruncated) {
                        // Call the same method that the actual holistic workflow uses
                        component.call('setFinalAnswer', event.result);
                        console.log('StatusStreamManager: Called Livewire setFinalAnswer for holistic workflow');
                    } else {
                        console.log('StatusStreamManager: Skipped setFinalAnswer - broadcast was truncated, loading full answer from DB');
                    }

                    // Also set the UI state flags
                    component.set('isStreaming', false);
                    component.set('isThinking', false);

                    // Call loadInteractions to refresh the display (loads full answer from DB if truncated)
                    component.call('loadInteractions');

                    console.log('StatusStreamManager: Updated Livewire state for holistic workflow completion');
                }
            }
            
            // Mark processing as complete
            this.processingComplete = true;

            // Dispatch custom event for UI components to handle
            window.dispatchEvent(new CustomEvent('holistic-workflow-completed', {
                detail: event
            }));

            const processingTime = performance.now() - startTime;
            this.eventLogger.logEvent('event_processing_completed', channel, {
                processingTimeMs: processingTime.toFixed(2),
                eventType: 'HolisticWorkflowCompleted'
            });
        } catch (error) {
            this.eventLogger.logError(error, { 
                context: 'handleHolisticWorkflowCompleted', 
                event 
            });
            throw error;
        }
    }

    /**
     * Handle holistic workflow failure event
     * @param {Object} event - The event data from HolisticWorkflowFailed event
     */
    handleHolisticWorkflowFailed(event) {
        const startTime = performance.now();
        const channel = this.currentInteractionId ? `chat-interaction.${this.currentInteractionId}` : 'unknown';

        // Log the holistic workflow failure event
        this.eventLogger.logEvent('HolisticWorkflowFailed', channel, {
            ...event,
            chatMode: this.chatMode,
            interactionId: this.currentInteractionId
        });

        try {
            // Update UI with the failure information
            console.error('❌ Holistic Workflow Failed:', {
                interactionId: event.interaction_id,
                executionId: event.execution_id,
                error: event.error,
                phase: event.phase,
                metadata: event.metadata
            });

            // Dispatch custom event for UI components to handle
            window.dispatchEvent(new CustomEvent('holistic-workflow-failed', {
                detail: event
            }));

            const processingTime = performance.now() - startTime;
            this.eventLogger.logEvent('event_processing_completed', channel, {
                processingTimeMs: processingTime.toFixed(2),
                eventType: 'HolisticWorkflowFailed'
            });
        } catch (error) {
            this.eventLogger.logError(error, { 
                context: 'handleHolisticWorkflowFailed', 
                event 
            });
            throw error;
        }
    }

    /**
     * Handle single-agent research completion event
     * @param {Object} event - The event data from ResearchComplete event
     */
    handleResearchComplete(event) {
        const startTime = performance.now();
        const channel = this.currentInteractionId ? `chat-interaction.${this.currentInteractionId}` : 'unknown';

        // Log the research completion event
        this.eventLogger.logEvent('ResearchComplete', channel, {
            ...event,
            chatMode: this.chatMode,
            interactionId: this.currentInteractionId
        });

        try {
            // Update UI with the completion result
            console.log('✅ Single-Agent Research Completed:', {
                interactionId: event.interaction_id,
                executionId: event.execution_id,
                resultLength: event.result?.length || 0,
                metadata: event.metadata
            });

            // Call Livewire component's setFinalAnswer method like holistic workflow does
            // Skip if broadcast was truncated - full answer is already in database
            const livewireElement = document.querySelector('[wire\\:id]');
            if (livewireElement && window.Livewire && event.result) {
                const componentId = livewireElement.getAttribute('wire:id');
                const component = window.Livewire.find(componentId);

                if (component && typeof component.call === 'function') {
                    const isTruncated = event.metadata?.broadcast_truncated || false;

                    if (!isTruncated) {
                        // Call the same method that holistic workflow uses
                        component.call('setFinalAnswer', event.result);
                        console.log('StatusStreamManager: Called Livewire setFinalAnswer for single-agent research');
                    } else {
                        console.log('StatusStreamManager: Skipped setFinalAnswer - broadcast was truncated, loading full answer from DB');
                    }

                    // Also set the UI state flags like holistic workflow does
                    component.set('isStreaming', false);
                    component.set('isThinking', false);

                    // Call loadInteractions to refresh the display (loads full answer from DB if truncated)
                    component.call('loadInteractions');

                    console.log('StatusStreamManager: Updated Livewire state for single-agent research completion');
                }
            }
            
            // Mark processing as complete
            this.processingComplete = true;

            // Dispatch custom event for UI components to handle
            window.dispatchEvent(new CustomEvent('research-completed', {
                detail: event
            }));

            const processingTime = performance.now() - startTime;
            this.eventLogger.logEvent('event_processing_completed', channel, {
                processingTimeMs: processingTime.toFixed(2),
                eventType: 'ResearchComplete'
            });
        } catch (error) {
            this.eventLogger.logError(error, { 
                context: 'handleResearchComplete', 
                event 
            });
            throw error;
        }
    }

    /**
     * Handle single-agent research failure event
     * @param {Object} event - The event data from ResearchFailed event
     */
    handleResearchFailed(event) {
        const startTime = performance.now();
        const channel = this.currentInteractionId ? `chat-interaction.${this.currentInteractionId}` : 'unknown';

        // Log the research failure event
        this.eventLogger.logEvent('ResearchFailed', channel, {
            ...event,
            chatMode: this.chatMode,
            interactionId: this.currentInteractionId
        });

        try {
            // Update UI with the failure information
            console.error('❌ Single-Agent Research Failed:', {
                interactionId: event.interaction_id,
                executionId: event.execution_id,
                error: event.error,
                metadata: event.metadata
            });

            // Dispatch custom event for UI components to handle
            window.dispatchEvent(new CustomEvent('research-failed', {
                detail: event
            }));

            const processingTime = performance.now() - startTime;
            this.eventLogger.logEvent('event_processing_completed', channel, {
                processingTimeMs: processingTime.toFixed(2),
                eventType: 'ResearchFailed'
            });
        } catch (error) {
            this.eventLogger.logError(error, { 
                context: 'handleResearchFailed', 
                event 
            });
            throw error;
        }
    }

    /**
     * Update answer display in UI if available
     */
    updateAnswerDisplay(event) {
        const answerContainer = document.getElementById('answer-container');
        if (!answerContainer) return;

        const answer = event.answer;
        if (!answer) return;

        try {
            // Create a temporary container for the answer HTML
            const tempContainer = document.createElement('div');
            tempContainer.innerHTML = answer;

            // Replace the answer container with the new content
            answerContainer.innerHTML = tempContainer.innerHTML;

        } catch (e) {
            console.warn('StatusStreamManager: Failed to update answer display', e);
        }
    }
    
    /**
     * Update chat interaction with final answer from research completion
     * @param {string} answer - The final answer/result
     * @param {Object} metadata - Additional metadata
     */
    updateChatInteractionAnswer(answer, metadata = {}) {
        if (!answer) {
            console.warn('StatusStreamManager: No answer provided to update chat interaction');
            return;
        }

        try {
            // Update answer content in research mode (x-ref="answerContent")
            const answerContent = document.querySelector('[x-ref="answerContent"]');
            if (answerContent) {
                // Create markdown renderer structure like existing answers
                const wrapper = document.createElement('div');
                wrapper.className = 'w-[80%] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg p-3 mb-4';
                wrapper.setAttribute('x-data', 'markdownRenderer()');
                
                // Create hidden source element for content
                const sourceElement = document.createElement('span');
                sourceElement.setAttribute('x-ref', 'source');
                sourceElement.className = 'hidden';
                sourceElement.textContent = answer;
                
                // Create target element for rendered markdown
                const targetElement = document.createElement('div');
                targetElement.setAttribute('x-ref', 'target');
                targetElement.className = 'markdown text-gray-900 dark:text-gray-100 text-left';
                targetElement.setAttribute('x-html', 'renderedHtml');
                
                wrapper.appendChild(sourceElement);
                wrapper.appendChild(targetElement);
                
                // Clear existing content and add new answer
                answerContent.innerHTML = '';
                answerContent.appendChild(wrapper);
                
                // Trigger markdown rendering
                sourceElement.dispatchEvent(new Event('input', { bubbles: true }));
                
                console.log('StatusStreamManager: Updated answer content in research mode');
            }
            
            // Update regular answer container as fallback
            const answerContainer = document.getElementById('answer-container');
            if (answerContainer) {
                answerContainer.innerHTML = `<div class="markdown">${answer}</div>`;
                console.log('StatusStreamManager: Updated answer container as fallback');
            }
            
            // Hide the thinking process container since we now have an answer
            const thinkingContainer = document.getElementById('thinking-process-container');
            if (thinkingContainer) {
                thinkingContainer.classList.add('hidden');
            }
            
            // Notify Livewire component to update its state
            const livewireElement = document.querySelector('[wire\\:id]');
            if (livewireElement && window.Livewire) {
                const componentId = livewireElement.getAttribute('wire:id');
                const component = window.Livewire.find(componentId);
                
                if (component && typeof component.call === 'function') {
                    // Call Livewire method to set the final answer
                    component.call('setFinalAnswer', {
                        answer: answer,
                        metadata: metadata,
                        interaction_id: this.currentInteractionId
                    });
                    console.log('StatusStreamManager: Notified Livewire component about final answer');
                }
            }
            
            // Dispatch event for other components that might need to know
            window.dispatchEvent(new CustomEvent('research-answer-ready', {
                detail: {
                    answer: answer,
                    metadata: metadata,
                    interactionId: this.currentInteractionId
                }
            }));
            
        } catch (error) {
            console.error('StatusStreamManager: Failed to update chat interaction answer', error);
            this.eventLogger.logError(error, {
                context: 'updateChatInteractionAnswer',
                answerLength: answer?.length || 0,
                interactionId: this.currentInteractionId
            });
        }
    }

    /**
     * Notify Livewire component of event
     * @param {string} method - The Livewire method to call
     * @param {Object} data - The data to pass to the method
     * @deprecated - No longer needed as processStatusEvent now handles all updates directly
     */
    notifyLivewireComponent(method, data) {
        // Keep this for backward compatibility, but it's no longer used

        const livewireElement = document.querySelector('[wire\\:id]');
        if (!livewireElement) {
            console.warn('StatusStreamManager: No Livewire component found for', method);
            return;
        }

        try {
            if (window.Livewire) {
                const componentId = livewireElement.getAttribute('wire:id');
                const component = window.Livewire.find(componentId);

                if (component && typeof component.call === 'function') {
                    component.call(method, data);
                }
            }
        } catch (e) {
            console.warn('StatusStreamManager: Error notifying Livewire component', e);
        }
    }


    /**
     * Handle AI response streaming events
     * @param {Object} eventData - The streaming event data
     */
    handleAiResponseStream(eventData) {
        const streamType = eventData.metadata?.stream_type || 'agent_response';
        const content = eventData.message;
        
        // Find or create streaming response source element
        const sourceElement = this.getOrCreateStreamingResponseContainer();
        if (!sourceElement) {
            console.warn('StatusStreamManager: No streaming response container available');
            return;
        }
        
        // Accumulate content in the source element - Alpine.js markdownRenderer() will handle conversion
        sourceElement.textContent += content;
        
        // Trigger an input event to notify the Alpine.js markdown renderer of changes
        sourceElement.dispatchEvent(new Event('input', { bubbles: true }));
        
        // Auto-scroll to show the newest content (target element or source for fallback)
        const targetElement = document.getElementById('streaming-response-target');
        this.scrollToNewest(targetElement || sourceElement);
        
        // Dispatch event for any other listeners
        window.dispatchEvent(new CustomEvent('ai-response-streamed', {
            detail: { content, streamType, metadata: eventData.metadata }
        }));
    }
    
    /**
     * Handle thinking process streaming events
     * @param {Object} eventData - The thinking process event data
     */
    handleThinkingProcessStream(eventData) {
        const reasoning = eventData.message;
        
        // Find or create thinking process container
        const container = this.getOrCreateThinkingProcessContainer();
        if (!container) {
            console.warn('StatusStreamManager: No thinking process container available');
            return;
        }
        
        // Update thinking process content (replace, not append)
        container.innerHTML = `
            <div class="flex items-center mb-2">
                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-tropical-teal-500 mr-2"></div>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">AI is thinking...</span>
            </div>
            <div class="text-sm text-gray-600 dark:text-gray-400 whitespace-pre-wrap">${this.sanitizeHtml(reasoning)}</div>
        `;
        
        // Show the thinking container
        container.classList.remove('hidden');
        
        // Auto-scroll to show the thinking process
        this.scrollToNewest(container);
        
        // Dispatch event for any other listeners
        window.dispatchEvent(new CustomEvent('thinking-process-updated', {
            detail: { reasoning, metadata: eventData.metadata }
        }));
    }
    
    /**
     * Get or create streaming response container
     * @returns {HTMLElement|null} The streaming response container
     */
    getOrCreateStreamingResponseContainer() {
        // Look for existing streaming response source (for updating content)
        let sourceElement = document.getElementById('streaming-response-source');
        
        if (!sourceElement) {
            // Look for answer content area in research mode
            const answerContent = document.querySelector('[x-ref="answerContent"]');
            if (answerContent) {
                // Create a markdown renderer structure like the final answer
                const wrapper = document.createElement('div');
                wrapper.id = 'streaming-response-wrapper';
                wrapper.className = 'w-[80%] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg p-3 mb-4';
                wrapper.setAttribute('x-data', 'markdownRenderer()');
                
                // Create hidden source element for content
                sourceElement = document.createElement('span');
                sourceElement.id = 'streaming-response-source';
                sourceElement.setAttribute('x-ref', 'source');
                sourceElement.className = 'hidden';
                
                // Create target element for rendered markdown
                const targetElement = document.createElement('div');
                targetElement.id = 'streaming-response-target';
                targetElement.setAttribute('x-ref', 'target');
                targetElement.className = 'markdown text-gray-900 dark:text-gray-100 text-left';
                targetElement.setAttribute('x-html', 'renderedHtml');
                
                wrapper.appendChild(sourceElement);
                wrapper.appendChild(targetElement);
                answerContent.appendChild(wrapper);
            } else {
                // Fallback: create in thinking process container without markdown rendering
                const thinkingContainer = document.getElementById('thinking-process-container');
                if (thinkingContainer) {
                    sourceElement = document.createElement('div');
                    sourceElement.id = 'streaming-response-source';
                    sourceElement.className = 'streaming-response bg-white dark:bg-gray-800 border rounded-lg p-4 mt-4';
                    thinkingContainer.appendChild(sourceElement);
                }
            }
        }
        
        return sourceElement;
    }
    
    /**
     * Get or create thinking process container
     * @returns {HTMLElement|null} The thinking process container
     */
    getOrCreateThinkingProcessContainer() {
        // Look for existing thinking process display
        let container = document.getElementById('thinking-process-display');
        
        if (!container) {
            // Look for main thinking process container
            const mainContainer = document.getElementById('thinking-process-container');
            if (mainContainer) {
                container = document.createElement('div');
                container.id = 'thinking-process-display';
                container.className = 'thinking-process-display bg-tropical-teal-50 dark:bg-tropical-teal-900 border-l-4 border-tropical-teal-500 p-4 mb-4 hidden';
                
                // Insert at the beginning of the thinking container
                mainContainer.insertBefore(container, mainContainer.firstChild);
            }
        }
        
        return container;
    }
    
    /**
     * Sanitize HTML content to prevent XSS
     * @param {string} html - The HTML content to sanitize
     * @returns {string} Sanitized HTML
     */
    sanitizeHtml(html) {
        const div = document.createElement('div');
        div.textContent = html;
        return div.innerHTML;
    }
    
    /**
     * Format thinking message for consistency with timeline-step component
     * @param {string} message - The raw message
     * @returns {string} The formatted message
     * @deprecated - This method will be removed when we fully migrate to template-based approach
     */
    formatThinkingMessage(message) {
        // For the "Downloaded:" prefix, we need special handling to avoid displaying HTML attributes
        if (message.startsWith('Downloaded:')) {
            // Extract just the URL from messages like "Downloaded: https://example.com - 123 words"
            const urlMatch = message.match(/Downloaded:\s+(https?:\/\/[^\s"<>']+)/);
            if (urlMatch && urlMatch[1]) {
                // Just return the text without any HTML to match the design
                const url = urlMatch[1];
                const restOfMessage = message.substring(message.indexOf(url) + url.length);
                return `Downloaded: ${url}${restOfMessage}`;
            }
        }

        // For all other messages, just return the plain text to match the design in the timeline-step component
        return message;
    }
}

// Initialize when the page loads
document.addEventListener('DOMContentLoaded', function() {

    // First check for progress-log which indicates regular chat mode
    if (document.getElementById('progress-log')) {
        window.isResearchMode = false;
    } else {
        // Check for answer tab - research mode specific
        const answerTab = document.querySelector('button[role="tab"][aria-controls="answer"]') ||
                          (document.querySelector('button:not(.active):not(.selected)') &&
                           document.querySelector('button:not(.active)').textContent.toLowerCase().includes('answer'));
        
        if (answerTab) {
            window.isResearchMode = true;
        } else {
            // Otherwise check for research-specific elements
            window.isResearchMode = !!document.querySelector('[wire\\:stream="thinking-process"]') ||
                                  !!document.querySelector('#real-time-status-container') ||
                                  !!document.querySelector('[x-ref="answerContent"]') ||
                                  !!document.querySelector('[x-ref="stepsContent"]');
            
        }
    }
    

    // Create global instances
    window.eventLogger = new EventLogger();
    window.statusStreamManager = new StatusStreamManager();

    // Set up global Echo interceptor for comprehensive event logging
    function setupEchoInterceptor() {
        if (!window.Echo) {
            // Wait for Echo to be available
            setTimeout(setupEchoInterceptor, 100);
            return;
        }

        window.eventLogger.logConnection('echo_interceptor_setup', {
            echoAvailable: true,
            timestamp: new Date().toISOString()
        });

        // Store original Echo methods
        const originalChannel = window.Echo.channel;
        const originalPrivate = window.Echo.private;

        // Intercept public channel creation
        window.Echo.channel = function(channel) {
            window.eventLogger.logChannelEvent('intercept_channel_create', channel, {
                channelType: 'public',
                timestamp: new Date().toISOString()
            });

            const channelInstance = originalChannel.call(this, channel);
            
            // Wrap the listen method to log all events
            const originalListen = channelInstance.listen;
            channelInstance.listen = function(event, callback) {
                window.eventLogger.logChannelEvent('listen_registered', channel, {
                    eventName: event,
                    channelType: 'public',
                    timestamp: new Date().toISOString()
                });

                // Wrap the callback to log when events are actually received
                const wrappedCallback = function(data) {
                    const startTime = performance.now();
                    
                    window.eventLogger.logEvent(event, channel, {
                        ...data,
                        intercepted: true,
                        receivedAt: new Date().toISOString()
                    });

                    try {
                        const result = callback.call(this, data);
                        const processingTime = performance.now() - startTime;
                        
                        if (processingTime > 5) {
                            window.eventLogger.logEvent('intercepted_event_processed', channel, {
                                eventName: event,
                                processingTimeMs: processingTime.toFixed(2)
                            });
                        }
                        
                        return result;
                    } catch (error) {
                        window.eventLogger.logError(error, {
                            context: 'intercepted_event_callback',
                            eventName: event,
                            channel: channel,
                            data
                        });
                        throw error;
                    }
                };

                return originalListen.call(this, event, wrappedCallback);
            };

            return channelInstance;
        };

        // Intercept private channel creation
        window.Echo.private = function(channel) {
            window.eventLogger.logChannelEvent('intercept_channel_create', channel, {
                channelType: 'private',
                timestamp: new Date().toISOString()
            });

            const channelInstance = originalPrivate.call(this, channel);
            
            // Wrap the listen method for private channels too
            const originalListen = channelInstance.listen;
            channelInstance.listen = function(event, callback) {
                window.eventLogger.logChannelEvent('listen_registered', channel, {
                    eventName: event,
                    channelType: 'private',
                    timestamp: new Date().toISOString()
                });

                const wrappedCallback = function(data) {
                    const startTime = performance.now();
                    
                    window.eventLogger.logEvent(event, channel, {
                        ...data,
                        intercepted: true,
                        channelType: 'private',
                        receivedAt: new Date().toISOString()
                    });

                    try {
                        const result = callback.call(this, data);
                        const processingTime = performance.now() - startTime;
                        
                        if (processingTime > 5) {
                            window.eventLogger.logEvent('intercepted_event_processed', channel, {
                                eventName: event,
                                processingTimeMs: processingTime.toFixed(2)
                            });
                        }
                        
                        return result;
                    } catch (error) {
                        window.eventLogger.logError(error, {
                            context: 'intercepted_private_event_callback',
                            eventName: event,
                            channel: channel,
                            data
                        });
                        throw error;
                    }
                };

                return originalListen.call(this, event, wrappedCallback);
            };

            return channelInstance;
        };

        console.log('%c🕵️ Echo interceptor active - all WebSocket events will be logged', 'color: #059669; font-weight: bold;');
        window.eventLogger.logConnection('echo_interceptor_active', {
            interceptedMethods: ['channel', 'private'],
            timestamp: new Date().toISOString()
        });
    }

    // Set up the Echo interceptor
    setupEchoInterceptor();

    // Set up interaction ID detection - run initially and set up mutation observer
    detectAndSubscribeToInteraction();

    // Set up a mutation observer to detect when new chat interactions are created
    // This is critical for detecting when Livewire creates a new interaction
    // without explicitly dispatching an event
    setupInteractionObserver();

    // When the page loads/reloads, we need to make sure we subscribe to status streams
    // for any existing interaction ID that might be present
    window.addEventListener('DOMContentLoaded', function() {
        // Use a slight delay to ensure meta tags are fully processed
        setTimeout(detectAndSubscribeToInteraction, 100);

        // Check if we are on a research page with a thinking container
        // If so, ensure isThinking state is properly set
        setTimeout(() => {
            const thinkingContainer = document.querySelector('[wire\\:stream="thinking-process"]') ||
                                    document.querySelector('[wire\\3A stream="thinking-process"]');

            // If there's a thinking container and we have an ongoing interaction, ensure the research view isn't in fallback mode
            if (thinkingContainer && (
                document.querySelector('[data-current-interaction-id]') ||
                document.querySelector('meta[name="interaction-id"]')
            )) {
                // Restore state and ensure WebSockets are connected
                restoreResearchSessionState();
            }
        }, 300);
    });

    // Track if we've already restored a session to prevent duplicate executions
    let sessionRestored = false;

    // Force re-check chat mode after a short delay to account for dynamic content loading
    setTimeout(() => {
        if (window.statusStreamManager) {
            const previousMode = window.statusStreamManager.chatMode;
            window.statusStreamManager.chatMode = window.statusStreamManager.detectAndStoreChatMode();
            if (previousMode !== window.statusStreamManager.chatMode) {
                // Reset processing state for mode change
                if (window.statusStreamManager.chatMode === 'regular') {
                    window.statusStreamManager.processingComplete = false;
                    window.isResearchMode = false;
                }
            }
        }
    }, 1000);

    function restoreResearchSessionState() {
        // Skip if we've already restored a session on this page load
        if (sessionRestored) {
            return;
        }

        // Find the Livewire component
        const livewireElement = document.querySelector('[wire\\:id]');
        if (!livewireElement) return;

        const componentId = livewireElement.getAttribute('wire:id');
        const component = window.Livewire && window.Livewire.find(componentId);
        if (!component) return;

        // Get the current interaction ID
        let interactionId = getCurrentInteractionId();
        if (!interactionId) {
            return;
        }

        // 1. Set isThinking to true to ensure the thinking-process stream container is shown
        component.set('isThinking', true);

        // 2. Force detection and subscription to the current interaction
        const success = detectAndSubscribeToInteraction();

        // 3. If subscription successful, dispatch a session restoration event that Livewire can listen for
        if (success) {
            // Mark as restored to prevent duplicate executions
            sessionRestored = true;

            window.dispatchEvent(new CustomEvent('session-restored', {
                detail: { interactionId: interactionId }
            }));

            // If Livewire is available, dispatch directly to the component as well
            if (component.dispatchEvent) {
                component.dispatchEvent('session-restored');
            }
        }
    }

    function detectAndSubscribeToInteraction() {
        // First priority: Check meta tag
        const interactionIdMeta = document.querySelector('meta[name="interaction-id"]');
        if (interactionIdMeta) {
            const interactionId = interactionIdMeta.getAttribute('content');
            if (interactionId) {
                
                // Try to get chat mode from localStorage before setting up subscriptions
                try {
                    const storedChatMode = localStorage.getItem(`chatMode-${interactionId}`);
                    if (storedChatMode && window.statusStreamManager) {
                        window.statusStreamManager.chatMode = storedChatMode;
                        window.isResearchMode = (storedChatMode === 'research');
                    }
                } catch (e) {
                    console.warn('StatusStreamManager: Could not read from localStorage', e);
                }
                
                window.statusStreamManager.setupEchoSubscriptions(interactionId);
                return true;
            }
        }

        // Second priority: Check data-current-interaction-id attribute
        const interactionIdElement = document.querySelector('[data-current-interaction-id]');
        if (interactionIdElement) {
            const interactionId = interactionIdElement.getAttribute('data-current-interaction-id');
            if (interactionId) {
                
                // Try to get chat mode from localStorage before setting up subscriptions
                try {
                    const storedChatMode = localStorage.getItem(`chatMode-${interactionId}`);
                    if (storedChatMode && window.statusStreamManager) {
                        window.statusStreamManager.chatMode = storedChatMode;
                        window.isResearchMode = (storedChatMode === 'research');
                    }
                } catch (e) {
                    console.warn('StatusStreamManager: Could not read from localStorage', e);
                }
                
                window.statusStreamManager.setupEchoSubscriptions(interactionId);
                return true;
            }
        }
        
        // Third priority: Try last interaction ID from localStorage
        try {
            const lastInteractionId = localStorage.getItem('lastInteractionId');
            if (lastInteractionId) {
                const storedChatMode = localStorage.getItem(`chatMode-${lastInteractionId}`);
                if (storedChatMode && window.statusStreamManager) {
                    window.statusStreamManager.chatMode = storedChatMode;
                    window.isResearchMode = (storedChatMode === 'research');
                    
                    // If this is regular chat, make sure processingComplete is reset
                    if (storedChatMode === 'regular' && window.statusStreamManager) {
                        window.statusStreamManager.processingComplete = false;
                    }
                    
                    window.statusStreamManager.setupEchoSubscriptions(lastInteractionId);
                    return true;
                }
            }
        } catch (e) {
            console.warn('StatusStreamManager: Could not read last interaction ID from localStorage', e);
        }

        // No interaction ID found
        return false;
    }

    function setupInteractionObserver() {
        // Set up mutation observer to detect changes to the data-current-interaction-id attribute
        // or when new elements with this attribute are added to the DOM
        const observer = new MutationObserver(mutations => {
            let shouldCheckForInteraction = false;

            // Check if any mutation involves our interaction ID attribute
            for (const mutation of mutations) {
                // For attribute changes
                if (mutation.type === 'attributes' &&
                    mutation.attributeName === 'data-current-interaction-id') {
                    shouldCheckForInteraction = true;
                    break;
                }

                // For DOM node additions
                if (mutation.type === 'childList') {
                    // Check added nodes
                    for (const node of mutation.addedNodes) {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            // Check if the node or any of its descendants have data-current-interaction-id
                            if (node.hasAttribute('data-current-interaction-id') ||
                                node.querySelector('[data-current-interaction-id]')) {
                                shouldCheckForInteraction = true;
                                break;
                            }
                        }
                    }

                    if (shouldCheckForInteraction) break;
                }
            }

            // If relevant changes detected, check for interaction ID
            if (shouldCheckForInteraction) {
                detectAndSubscribeToInteraction();
            }
        });

        // Start observing the document with the configured parameters
        observer.observe(document.body, {
            childList: true,      // Watch for DOM node additions/removals
            subtree: true,       // Watch all descendants of body
            attributes: true,    // Watch for attribute changes
            attributeFilter: ['data-current-interaction-id']  // Only care about this attribute
        });

    }

    // Debounce map to prevent duplicate event handler processing
    window._interactionSetupDebounce = window._interactionSetupDebounce || {};

    // Listen for new chat interactions - redundant event handler to ensure maximum compatibility
    document.addEventListener('chat-interaction-created', function(event) {
        if (event.detail && event.detail.id) {
            const interactionId = event.detail.id;
            const now = Date.now();

            // Debounce: skip if we just processed this interaction within last 500ms
            if (window._interactionSetupDebounce[interactionId] && (now - window._interactionSetupDebounce[interactionId]) < 500) {
                console.log(`Skipping duplicate chat-interaction-created for ${interactionId}`);
                return;
            }
            window._interactionSetupDebounce[interactionId] = now;

            // Reset processing state for new chats and re-detect chat mode
            if (window.statusStreamManager) {
                window.statusStreamManager.chatMode = window.statusStreamManager.detectAndStoreChatMode();
                window.statusStreamManager.processingComplete = false;
            }

            // Unsubscribe from any existing channels first
            window.statusStreamManager.unsubscribeAll();
            window.statusStreamManager.setupEchoSubscriptions(interactionId);
        }
    });

    // Listen for interaction-created events (dispatched by Livewire)
    document.addEventListener('interaction-created', function(event) {
        
        // Handle both array-format and object-format event data
        if (Array.isArray(event.detail) && event.detail.length > 0) {
            // Array format - extract the first item
            const firstItem = event.detail[0];
            if (firstItem) {
                const interactionId = firstItem.interactionId;
                
                // Use the agent parameter to determine chat mode
                const agent = firstItem.agent || 'research';
                let detectedMode = 'research'; // Default
                
                // Map agent parameter to chat mode
                if (agent === 'chat') {
                    detectedMode = 'regular';
                } else if (agent.includes('agent') || agent === 'workflow') {
                    detectedMode = 'agent';
                }
                
                
                // Store in localStorage for persistence across page loads
                try {
                    localStorage.setItem(`chatMode-${interactionId}`, detectedMode);
                    localStorage.setItem('lastInteractionId', interactionId);
                    
                    // Ensure correct isResearchMode flag is set immediately
                    window.isResearchMode = (detectedMode === 'research');
                } catch (e) {
                    console.warn('StatusStreamManager: Could not store chat mode in localStorage', e);
                }
                
                // Apply the detected mode and reset processing state
                if (window.statusStreamManager && interactionId) {
                    // Set the mode based on the agent parameter
                    window.statusStreamManager.chatMode = detectedMode;
                    window.isResearchMode = (detectedMode === 'research');
                    window.statusStreamManager.processingComplete = false;
                    
                    
                    // Unsubscribe from any existing channels first
                    window.statusStreamManager.unsubscribeAll();
                    window.statusStreamManager.setupEchoSubscriptions(interactionId);
                }
            }
        } else if (event.detail && event.detail.interactionId) {
            // Original object format
            const interactionId = event.detail.interactionId;
            
            // Check localStorage first before falling back to DOM detection
            let chatMode;
            try {
                chatMode = localStorage.getItem(`chatMode-${interactionId}`);
            } catch (e) {
                console.warn('StatusStreamManager: Could not read from localStorage', e);
            }
            
            // Reset processing state for new chats
            if (window.statusStreamManager) {
                // Use localStorage value if available, otherwise detect from DOM
                if (chatMode) {
                    window.statusStreamManager.chatMode = chatMode;
                    window.isResearchMode = (chatMode === 'research');
                } else {
                    window.statusStreamManager.chatMode = window.statusStreamManager.detectAndStoreChatMode();
                }
                
                window.statusStreamManager.processingComplete = false;
            }
            
            // Unsubscribe from any existing channels first
            window.statusStreamManager.unsubscribeAll();
            window.statusStreamManager.setupEchoSubscriptions(interactionId);
        }
    });

    // Listen for Livewire-specific interaction-created events
    document.addEventListener('livewire:init', function() {
        if (window.Livewire) {
            // Handle both namespaced and non-namespaced events for maximum compatibility
            ['interaction-created', 'chat-interaction-created', 'agent-interaction-created'].forEach(eventName => {
                window.Livewire.on(eventName, function(data) {

                    // Handle different data formats across various events
                    const interactionId = data && (data.interactionId || data.id ||
                        (typeof data === 'object' && Object.values(data)[0]?.interactionId));

                    if (interactionId) {
                        // Reset processing state for new interaction and re-detect chat mode
                        if (window.statusStreamManager) {
                            window.statusStreamManager.chatMode = window.statusStreamManager.detectAndStoreChatMode();
                            window.statusStreamManager.processingComplete = false;
                        }
                        // Unsubscribe from any existing channels first
                        window.statusStreamManager.unsubscribeAll();
                        window.statusStreamManager.setupEchoSubscriptions(interactionId);
                    }
                });
            });
            
            // Listen for research-complete event from the server
            window.Livewire.on('research-complete', function(data) {
                if (window.statusStreamManager) {
                    window.statusStreamManager.processingComplete = true;
                    
                    // Also dispatch a DOM event to maximize chances of detection
                    document.dispatchEvent(new CustomEvent('research-complete', { 
                        detail: data 
                    }));
                }
            });
            
            // Also listen for generic "completion" events that might indicate research is done
            const completionEventNames = [
                'research-complete', 'research:complete', 'chat-interaction-updated',
                'answer-ready', 'answer:ready', 'processing-complete', 'processing:complete'
            ];
            
            completionEventNames.forEach(eventName => {
                window.Livewire.on(eventName, function(data) {
                    
                    // If the event includes an answer, we're definitely done
                    const hasAnswer = data && (
                        (data.answer && data.answer.length > 10) ||
                        (data.has_answer === true) ||
                        (typeof data === 'string' && data.length > 10)
                    );
                    
                    if (hasAnswer || eventName.includes('complete') || eventName.includes('ready')) {
                        if (window.statusStreamManager) {
                            window.statusStreamManager.processingComplete = true;
                        }
                    }
                });
            });
            
            // Listen for chat-interaction-updated event when answer is available
            window.Livewire.on('chat-interaction-updated', function(data) {
                // Check if this update contains an answer, indicating processing is complete
                if (data && data.answer && data.answer.length > 0) {
                    if (window.statusStreamManager) {
                        // Only mark as complete in research mode, not in regular chat
                        if (window.statusStreamManager.chatMode !== 'regular') {
                            window.statusStreamManager.processingComplete = true;
                        } else {
                            window.statusStreamManager.processingComplete = false;
                        }
                    }
                }
            });
        }
    });

    // Listen for session-loaded events to set up Echo for existing interactions
    document.addEventListener('session-loaded', function(event) {
        detectAndSubscribeToInteraction(); // Use the same detection function
    });
    
    // Listen for research-complete event
    document.addEventListener('research-complete', function(event) {
        if (window.statusStreamManager) {
            // Only mark as complete in research mode, not in regular chat
            if (window.statusStreamManager.chatMode !== 'regular') {
                window.statusStreamManager.processingComplete = true;
            } else {
                window.statusStreamManager.processingComplete = false;
            }
        }
    });
    
    // Proactively detect answer content becoming available and stop updates
    // But only in research mode, never in regular chat mode
    const answerObserver = new MutationObserver(mutations => {
        for (const mutation of mutations) {
            if (mutation.type === 'childList' || mutation.type === 'characterData') {
                const answerContent = document.querySelector('[x-ref="answerContent"]');
                if (answerContent && answerContent.textContent && answerContent.textContent.trim().length > 20) {
                    if (window.statusStreamManager) {
                        // Only mark as complete in research mode, not in regular chat
                        if (window.statusStreamManager.chatMode !== 'regular') {
                            window.statusStreamManager.processingComplete = true;
                        } else {
                            window.statusStreamManager.processingComplete = false;
                        }
                    }
                }
            }
        }
    });
    
    // Start observing the document body
    answerObserver.observe(document.body, {
        childList: true,
        subtree: true,
        characterData: true
    });
    
    // Listen for answer-ready event
    document.addEventListener('answer-ready', function(event) {
        if (window.statusStreamManager) {
            // Only mark as complete in research mode, not in regular chat
            if (window.statusStreamManager.chatMode !== 'regular') {
                window.statusStreamManager.processingComplete = true;
            } else {
                window.statusStreamManager.processingComplete = false;
            }
        }
    });

    // Listen for form submissions that might create new interactions
    document.addEventListener('submit', function(event) {
        // Wait a short time for Livewire to process the form and potentially create a new interaction
        setTimeout(detectAndSubscribeToInteraction, 500);
    });

});

/**
 * Global utility function to scroll to newest message
 * Can be used by both JavaScript and template code
 * @param {HTMLElement} element - The element to scroll or find scrollable parent for
 */
window.scrollToNewestMessage = function(element) {
    if (!element) return;

    // Guard against cross-interface scroll conflicts
    // Check if element belongs to PWA interface and we're not in PWA context
    const isPwaElement = element.closest('[x-data*="pwaChat"]') || element.closest('.pwa-chat-container');
    const isResearchElement = element.closest('[data-current-interaction-id]') || element.id === 'thinking-process-container';

    // Detect current interface context
    const currentUrl = window.location.pathname;
    const isPwaRoute = currentUrl.startsWith('/pwa/');
    const isResearchRoute = currentUrl.includes('/dashboard/chat') || currentUrl.includes('/dashboard');

    // Prevent PWA scroll from affecting research interface
    if (isPwaElement && isResearchRoute) {
        return;
    }

    // Prevent research scroll from affecting PWA interface
    if (isResearchElement && isPwaRoute) {
        return;
    }

    // Find the appropriate scrollable container - try multiple strategies
    let scrollContainer = null;
    
    // Strategy 1: Check if current element is scrollable
    if (element.scrollHeight > element.clientHeight) {
        scrollContainer = element;
    }
    
    // Strategy 2: Look for common scrollable selectors
    if (!scrollContainer) {
        scrollContainer = element.closest('.overflow-y-auto, .overflow-auto, [data-scroll-container]');
    }
    
    // Strategy 3: Look for main content areas
    if (!scrollContainer) {
        scrollContainer = element.closest('.flex-1, .main-content, .chat-container');
    }
    
    // Strategy 4: Find any parent with overflow styling
    if (!scrollContainer) {
        let parent = element.parentElement;
        while (parent && parent !== document.body) {
            const computedStyle = window.getComputedStyle(parent);
            if (computedStyle.overflowY === 'auto' || computedStyle.overflowY === 'scroll' || 
                parent.scrollHeight > parent.clientHeight) {
                scrollContainer = parent;
                break;
            }
            parent = parent.parentElement;
        }
    }
    
    // Strategy 5: Fallback to page scroll
    if (!scrollContainer) {
        scrollContainer = document.documentElement || document.body;
    }
    
    
    if (!scrollContainer) return;
    
    // Check if user has manually scrolled up - if so, don't auto-scroll
    // User is considered "scrolled up" if they're more than 100px from the bottom
    const isNearBottom = (scrollContainer.scrollHeight - scrollContainer.scrollTop - scrollContainer.clientHeight) < 100;

    if (!isNearBottom) {
        return;
    }

    // Use requestAnimationFrame to ensure DOM updates are complete
    requestAnimationFrame(() => {
        try {
            // Always attempt to scroll to bottom, regardless of current scrollable state
            // This ensures we scroll even during early streaming when content is minimal
            scrollContainer.scrollTo({
                top: scrollContainer.scrollHeight,
                behavior: 'smooth'
            });

        } catch (e) {
            // Fallback to immediate scroll if smooth scroll fails
            try {
                scrollContainer.scrollTop = scrollContainer.scrollHeight;
            } catch (fallbackError) {
                console.warn('Failed to scroll to newest message', fallbackError);
            }
        }
    });
};

// Export for module use
export default window.statusStreamManager;

/**
 * ChatStream - Server-Sent Events (SSE) streaming service for real-time chat
 *
 * Manages real-time streaming of chat responses from the API using the Fetch API
 * with streaming response body. Supports file attachments via FormData and provides
 * event-driven callbacks for different response types.
 *
 * @module pwa/chat-stream
 */

import { AuthService } from './auth'

/**
 * @typedef {Object} StreamCallbacks
 * @property {Function} [onChunk] - Called when content chunk is received
 * @property {Function} [onComplete] - Called when stream completes successfully
 * @property {Function} [onError] - Called when an error occurs
 * @property {Function} [onToolCall] - Called when AI uses a tool
 * @property {Function} [onStatusUpdate] - Called for status updates
 */

/**
 * ChatStream - Handles streaming chat responses from the API
 *
 * @class
 */
export class ChatStream {
    constructor() {
        this.auth = new AuthService()
        this.abortController = null
    }

    /**
     * Start streaming chat using fetch() with POST
     *
     * @param {string} message - The user message to send
     * @param {number|null} sessionId - Optional session ID for conversation continuity
     * @param {number|null} agentId - Optional agent ID to use for response
     * @param {StreamCallbacks} callbacks - Event callbacks for stream events
     * @param {File[]} files - Optional file attachments
     * @returns {Promise<{success: boolean, error?: string}>} Stream result
     */
    async startStream(message, sessionId, agentId, callbacks, files = []) {
        const {
            onChunk,
            onComplete,
            onError,
            onToolCall,
            onStatusUpdate
        } = callbacks

        try {
            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            if (!headers.Authorization) {
                const error = new Error('No API token configured')
                console.error('ChatStream: Authentication failed - no API token', {
                    serverUrl,
                    hasSessionId: !!sessionId,
                    hasAgentId: !!agentId
                })
                throw error
            }

            // Build request body
            let body

            // If files are present, use FormData
            if (files && files.length > 0) {
                body = new FormData()
                body.append('message', message)

                if (sessionId) {
                    body.append('session_id', sessionId)
                }

                if (agentId) {
                    body.append('agent_id', agentId)
                }

                // Append files with 'attachments' field name (API expects this)
                // FormData automatically creates an array when multiple files use the same key
                files.forEach((file) => {
                    body.append('attachments', file)
                })

                // Remove Content-Type header for FormData (browser sets it with boundary)
                delete headers['Content-Type']

                console.log('ChatStream: Sending request with file attachments', {
                    fileCount: files.length,
                    totalSize: files.reduce((sum, f) => sum + f.size, 0),
                    types: [...new Set(files.map(f => f.type))]
                })
            } else {
                // No files, use JSON
                body = JSON.stringify({
                    message: message,
                    session_id: sessionId,
                    agent_id: agentId
                })
            }

            // Create abort controller for cancellation
            this.abortController = new AbortController()

            // Use fetch with streaming response
            const response = await fetch(`${serverUrl}/api/v1/chat/stream`, {
                method: 'POST',
                headers: headers,
                body: body,
                signal: this.abortController.signal
            })

            if (!response.ok) {
                // Try to parse error response
                let errorMessage = `Stream failed: ${response.status} ${response.statusText}`
                try {
                    const errorData = await response.json()
                    if (errorData.message) {
                        errorMessage = errorData.message
                    }
                } catch (e) {
                    console.warn('ChatStream: Could not parse error response as JSON', {
                        status: response.status,
                        statusText: response.statusText
                    })
                }
                console.error('ChatStream: Stream request failed', {
                    status: response.status,
                    statusText: response.statusText,
                    errorMessage,
                    hasSessionId: !!sessionId,
                    hasAgentId: !!agentId,
                    hasFiles: files.length > 0
                })
                throw new Error(errorMessage)
            }

            // Read the stream
            const reader = response.body.getReader()
            const decoder = new TextDecoder()
            let buffer = ''

            // Process the stream
            let currentEvent = null

            while (true) {
                const { done, value } = await reader.read()

                if (done) {
                    onComplete && onComplete()
                    break
                }

                // Decode the chunk
                buffer += decoder.decode(value, { stream: true })

                // Process complete SSE messages (separated by double newline)
                const messages = buffer.split('\n\n')
                buffer = messages.pop() // Keep incomplete message in buffer

                for (const message of messages) {
                    if (!message.trim()) {
                        continue // Skip empty messages
                    }

                    const lines = message.split('\n')
                    let eventType = 'message' // Default event type
                    let eventData = null

                    // Parse SSE message lines
                    for (const line of lines) {
                        if (line.startsWith('event: ')) {
                            eventType = line.substring(7).trim()
                        } else if (line.startsWith('data: ')) {
                            const dataStr = line.substring(6)

                            // Skip non-JSON lines (like "</stream>")
                            if (!dataStr.trim().startsWith('{') && !dataStr.trim().startsWith('[')) {
                                continue
                            }

                            try {
                                eventData = JSON.parse(dataStr)
                            } catch (error) {
                                console.error('ChatStream: Failed to parse SSE data', {
                                    error: error.message,
                                    line: line.substring(0, 100),
                                    eventType
                                })
                            }
                        }
                    }

                    // Process the event if we have data
                    if (eventData) {
                        // Handle different event types
                        switch (eventData.type) {
                            case 'answer_stream':
                            case 'content':
                                onChunk && onChunk(eventData)
                                break
                            case 'tool_call':
                                onToolCall && onToolCall(eventData)
                                break
                            case 'status':
                                onStatusUpdate && onStatusUpdate(eventData)
                                break
                            case 'session':
                                onChunk && onChunk(eventData)
                                break
                            case 'complete':
                                onComplete && onComplete(eventData)
                                return { success: true }
                            case 'error':
                                onError && onError(new Error(eventData.content || 'Stream error'))
                                break
                            default:
                                onChunk && onChunk(eventData)
                        }
                    }
                }
            }

            return { success: true }

        } catch (error) {
            console.error('ChatStream: Stream failed', {
                error: error.message,
                stack: error.stack,
                hasSessionId: !!sessionId,
                hasAgentId: !!agentId,
                messageLength: message?.length || 0,
                fileCount: files.length
            })
            onError && onError(error)
            return {
                success: false,
                error: error.message
            }
        }
    }

    /**
     * Abort the fetch stream
     *
     * @returns {void}
     */
    close() {
        if (this.abortController) {
            this.abortController.abort()
            this.abortController = null
        }
    }

    /**
     * Check if stream is active
     *
     * @returns {boolean} True if stream is currently active
     */
    isActive() {
        return this.abortController !== null
    }
}

export default ChatStream

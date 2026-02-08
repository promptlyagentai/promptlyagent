/**
 * SessionAPI - Chat session management for PWA
 *
 * Provides API methods for retrieving chat sessions and their interaction history.
 * Handles authentication automatically through AuthService integration.
 *
 * @module pwa/session-api
 */

import { AuthService } from './auth'

/**
 * @typedef {Object} ChatSession
 * @property {number} id - Session ID
 * @property {string} title - Session title
 * @property {string} created_at - Creation timestamp
 * @property {string} updated_at - Last update timestamp
 */

/**
 * @typedef {Object} ChatInteraction
 * @property {number} id - Interaction ID
 * @property {number} session_id - Parent session ID
 * @property {string} question - User question
 * @property {string} answer - AI response
 * @property {string} created_at - Creation timestamp
 */

/**
 * SessionAPI - Manages chat session retrieval
 *
 * @class
 */
export class SessionAPI {
    constructor() {
        this.auth = new AuthService()
    }

    /**
     * List all chat sessions for authenticated user
     *
     * @param {Object} [filters] - Filter options
     * @param {string} [filters.search] - Search query for session titles/content
     * @param {string} [filters.source_type] - Filter by source type (web|api|webhook|slack|trigger|all)
     * @param {boolean} [filters.include_archived] - Include archived sessions
     * @param {boolean} [filters.kept_only] - Only show kept sessions
     * @param {number} [filters.page] - Page number for pagination
     * @param {number} [filters.per_page] - Items per page (max 50)
     * @returns {Promise<{success: boolean, data: ChatSession[], error?: string}>}
     */
    async listSessions(filters = {}) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            // For GET requests, remove Content-Type header
            delete headers['Content-Type']

            // Build query string from filters
            const params = new URLSearchParams()
            if (filters.search) params.append('search', filters.search)
            if (filters.source_type) params.append('source_type', filters.source_type)
            if (filters.include_archived !== undefined) params.append('include_archived', filters.include_archived ? '1' : '0')
            if (filters.kept_only !== undefined) params.append('kept_only', filters.kept_only ? '1' : '0')
            if (filters.page) params.append('page', filters.page)
            if (filters.per_page) params.append('per_page', filters.per_page)

            const queryString = params.toString()
            const url = `${serverUrl}/api/v1/chat/sessions${queryString ? '?' + queryString : ''}`

            const response = await fetch(url, {
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`HTTP ${response.status}: ${response.statusText}`)
                console.error('SessionAPI: Failed to list sessions', {
                    status: response.status,
                    statusText: response.statusText,
                    url: url
                })
                throw error
            }

            const result = await response.json()

            if (result.success) {
                return {
                    success: true,
                    data: result.sessions || []
                }
            } else {
                console.warn('SessionAPI: API returned unsuccessful response', {
                    message: result.message
                })
                return {
                    success: false,
                    data: [],
                    error: result.message || 'Failed to fetch sessions'
                }
            }
        } catch (error) {
            console.error('SessionAPI: List sessions failed', {
                error: error.message,
                stack: error.stack
            })
            return {
                success: false,
                data: [],
                error: error.message
            }
        }
    }

    /**
     * Get specific session with full chat history
     *
     * @param {number} sessionId - The session ID to retrieve
     * @returns {Promise<{success: boolean, session?: ChatSession, interactions?: ChatInteraction[], error?: string}>}
     */
    async getSession(sessionId) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            // For GET requests, remove Content-Type header
            delete headers['Content-Type']

            const response = await fetch(`${serverUrl}/api/v1/chat/sessions/${sessionId}`, {
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`HTTP ${response.status}: ${response.statusText}`)
                console.error('SessionAPI: Failed to get session', {
                    sessionId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const result = await response.json()

            if (result.success) {
                return {
                    success: true,
                    session: result.session,
                    interactions: result.interactions || []
                }
            } else {
                console.warn('SessionAPI: API returned unsuccessful response for session', {
                    sessionId,
                    message: result.message
                })
                return {
                    success: false,
                    error: result.message || 'Failed to fetch session'
                }
            }
        } catch (error) {
            console.error('SessionAPI: Get session failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return {
                success: false,
                error: error.message
            }
        }
    }

    /**
     * Toggle keep flag on a session
     *
     * @param {number} sessionId - The session ID to toggle keep flag
     * @returns {Promise<{success: boolean, session?: Object, error?: string}>}
     */
    async toggleKeep(sessionId) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            const response = await fetch(`${serverUrl}/api/v1/chat/sessions/${sessionId}/keep`, {
                method: 'POST',
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`HTTP ${response.status}: ${response.statusText}`)
                console.error('SessionAPI: Failed to toggle keep flag', {
                    sessionId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const result = await response.json()

            if (result.success) {
                return {
                    success: true,
                    session: result.session
                }
            } else {
                console.warn('SessionAPI: API returned unsuccessful response for toggle keep', {
                    sessionId,
                    message: result.message
                })
                return {
                    success: false,
                    error: result.message || 'Failed to toggle keep flag'
                }
            }
        } catch (error) {
            console.error('SessionAPI: Toggle keep failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return {
                success: false,
                error: error.message
            }
        }
    }

    /**
     * Archive a session
     *
     * @param {number} sessionId - The session ID to archive
     * @returns {Promise<{success: boolean, session?: Object, error?: string}>}
     */
    async archiveSession(sessionId) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            const response = await fetch(`${serverUrl}/api/v1/chat/sessions/${sessionId}/archive`, {
                method: 'POST',
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`HTTP ${response.status}: ${response.statusText}`)
                console.error('SessionAPI: Failed to archive session', {
                    sessionId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const result = await response.json()

            if (result.success) {
                return {
                    success: true,
                    session: result.session
                }
            } else {
                console.warn('SessionAPI: API returned unsuccessful response for archive', {
                    sessionId,
                    message: result.message
                })
                return {
                    success: false,
                    error: result.message || 'Failed to archive session'
                }
            }
        } catch (error) {
            console.error('SessionAPI: Archive session failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return {
                success: false,
                error: error.message
            }
        }
    }

    /**
     * Unarchive a session
     *
     * @param {number} sessionId - The session ID to unarchive
     * @returns {Promise<{success: boolean, session?: Object, error?: string}>}
     */
    async unarchiveSession(sessionId) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            const response = await fetch(`${serverUrl}/api/v1/chat/sessions/${sessionId}/unarchive`, {
                method: 'POST',
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`HTTP ${response.status}: ${response.statusText}`)
                console.error('SessionAPI: Failed to unarchive session', {
                    sessionId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const result = await response.json()

            if (result.success) {
                return {
                    success: true,
                    session: result.session
                }
            } else {
                console.warn('SessionAPI: API returned unsuccessful response for unarchive', {
                    sessionId,
                    message: result.message
                })
                return {
                    success: false,
                    error: result.message || 'Failed to unarchive session'
                }
            }
        } catch (error) {
            console.error('SessionAPI: Unarchive session failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return {
                success: false,
                error: error.message
            }
        }
    }

    /**
     * Delete a session
     *
     * @param {number} sessionId - The session ID to delete
     * @returns {Promise<{success: boolean, error?: string}>}
     */
    async deleteSession(sessionId) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            const response = await fetch(`${serverUrl}/api/v1/chat/sessions/${sessionId}`, {
                method: 'DELETE',
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`HTTP ${response.status}: ${response.statusText}`)
                console.error('SessionAPI: Failed to delete session', {
                    sessionId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const result = await response.json()

            if (result.success) {
                return {
                    success: true
                }
            } else {
                console.warn('SessionAPI: API returned unsuccessful response for delete', {
                    sessionId,
                    message: result.message
                })
                return {
                    success: false,
                    error: result.message || 'Failed to delete session'
                }
            }
        } catch (error) {
            console.error('SessionAPI: Delete session failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return {
                success: false,
                error: error.message
            }
        }
    }

    /**
     * Share a session publicly
     *
     * @param {number} sessionId - The session ID to share
     * @param {number} [expiresInDays] - Optional expiration in days (1-365)
     * @returns {Promise<{success: boolean, session?: Object, error?: string}>}
     */
    async shareSession(sessionId, expiresInDays = null) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            const body = expiresInDays ? JSON.stringify({ expires_in_days: expiresInDays }) : null

            const response = await fetch(`${serverUrl}/api/v1/chat/sessions/${sessionId}/share`, {
                method: 'POST',
                headers: headers,
                body: body
            })

            if (!response.ok) {
                const error = new Error(`HTTP ${response.status}: ${response.statusText}`)
                console.error('SessionAPI: Failed to share session', {
                    sessionId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const result = await response.json()

            if (result.success) {
                return {
                    success: true,
                    session: result.session
                }
            } else {
                console.warn('SessionAPI: API returned unsuccessful response for share', {
                    sessionId,
                    message: result.message
                })
                return {
                    success: false,
                    error: result.message || 'Failed to share session'
                }
            }
        } catch (error) {
            console.error('SessionAPI: Share session failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return {
                success: false,
                error: error.message
            }
        }
    }

    /**
     * Unshare a public session (make it private)
     *
     * @param {number} sessionId - The session ID to unshare
     * @returns {Promise<{success: boolean, session?: Object, error?: string}>}
     */
    async unshareSession(sessionId) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            const response = await fetch(`${serverUrl}/api/v1/chat/sessions/${sessionId}/unshare`, {
                method: 'POST',
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`HTTP ${response.status}: ${response.statusText}`)
                console.error('SessionAPI: Failed to unshare session', {
                    sessionId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const result = await response.json()

            if (result.success) {
                return {
                    success: true,
                    session: result.session
                }
            } else {
                console.warn('SessionAPI: API returned unsuccessful response for unshare', {
                    sessionId,
                    message: result.message
                })
                return {
                    success: false,
                    error: result.message || 'Failed to unshare session'
                }
            }
        } catch (error) {
            console.error('SessionAPI: Unshare session failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return {
                success: false,
                error: error.message
            }
        }
    }

    /**
     * Perform bulk operation on multiple sessions
     *
     * @param {number[]} sessionIds - Array of session IDs
     * @param {string} operation - Operation type: 'keep', 'archive', 'unarchive', 'delete'
     * @param {Function} onProgress - Progress callback (current, total)
     * @returns {Promise<{success: boolean, successCount: number, failedCount: number, errors: string[]}>}
     */
    async bulkOperation(sessionIds, operation, onProgress = null) {
        const results = {
            success: true,
            successCount: 0,
            failedCount: 0,
            errors: []
        }

        for (let i = 0; i < sessionIds.length; i++) {
            const sessionId = sessionIds[i]

            if (onProgress) {
                onProgress(i + 1, sessionIds.length)
            }

            try {
                let result
                switch (operation) {
                    case 'keep':
                        result = await this.toggleKeep(sessionId)
                        break
                    case 'archive':
                        result = await this.archiveSession(sessionId)
                        break
                    case 'unarchive':
                        result = await this.unarchiveSession(sessionId)
                        break
                    case 'delete':
                        result = await this.deleteSession(sessionId)
                        break
                    default:
                        throw new Error(`Unknown operation: ${operation}`)
                }

                if (result.success) {
                    results.successCount++
                } else {
                    results.failedCount++
                    results.errors.push(`Session ${sessionId}: ${result.error}`)
                }
            } catch (error) {
                results.failedCount++
                results.errors.push(`Session ${sessionId}: ${error.message}`)
            }
        }

        return results
    }
}

export default SessionAPI

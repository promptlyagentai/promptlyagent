/**
 * SyncService - Data synchronization service for PWA offline support
 *
 * Manages bidirectional synchronization between the API and IndexedDB for
 * sessions, interactions, and knowledge documents. Implements cache pruning
 * and staleness detection for optimal offline experience.
 *
 * @module pwa/sync
 */

import db from './db'
import { AuthService } from './auth'

/**
 * @typedef {Object} SyncResult
 * @property {boolean} success - Whether sync succeeded
 * @property {number} [count] - Number of items synced
 * @property {string} [error] - Error message if failed
 */

/**
 * @typedef {Object} SyncStatus
 * @property {Object} sessions - Session sync info
 * @property {number} sessions.count - Number of cached sessions
 * @property {number|null} sessions.last_sync - Last sync timestamp
 * @property {Object} interactions - Interaction sync info
 * @property {number} interactions.count - Number of cached interactions
 * @property {Object} knowledge - Knowledge document sync info
 * @property {number} knowledge.count - Number of cached documents
 * @property {Object|null} storage - Storage quota estimate
 */

/**
 * SyncService - Handles data synchronization with the API
 *
 * @class
 */
export class SyncService {
    constructor() {
        this.auth = new AuthService()
        this.maxSessions = 100 // Keep last 100 sessions cached
    }

    /**
     * Fetch sessions from API and cache in IndexedDB
     *
     * @returns {Promise<SyncResult>}
     */
    async syncSessions() {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const token = await this.auth.getToken()

            if (!token) {
                console.warn('SyncService: No API token available for sync')
                return { success: false, error: 'No API token' }
            }

            const response = await fetch(`${serverUrl}/api/v1/chat/sessions`, {
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json'
                }
            })

            if (!response.ok) {
                const error = new Error(`API error: ${response.status}`)
                console.error('SyncService: Sessions sync failed', {
                    status: response.status,
                    statusText: response.statusText,
                    url: `${serverUrl}/api/v1/chat/sessions`
                })
                throw error
            }

            const sessions = await response.json()

            // Store sessions in IndexedDB
            for (const session of sessions) {
                await db.put('sessions', {
                    ...session,
                    synced_at: Date.now()
                })
            }

            // Update sync metadata
            await db.put('sync_metadata', {
                key: 'sessions_last_sync',
                timestamp: Date.now(),
                count: sessions.length
            })

            // Prune old sessions
            await this.pruneOldSessions()

            console.log('SyncService: Sessions synced successfully', {
                count: sessions.length,
                timestamp: Date.now()
            })

            return { success: true, count: sessions.length }
        } catch (error) {
            console.error('SyncService: Session sync failed', {
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Sync a single session with all its interactions
     *
     * @param {number} sessionId - The session ID to sync
     * @returns {Promise<SyncResult>}
     */
    async syncSession(sessionId) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const token = await this.auth.getToken()

            if (!token) {
                console.warn('SyncService: No API token for session sync', { sessionId })
                return { success: false, error: 'No API token' }
            }

            const response = await fetch(`${serverUrl}/api/v1/chat/sessions/${sessionId}`, {
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json'
                }
            })

            if (!response.ok) {
                const error = new Error(`API error: ${response.status}`)
                console.error('SyncService: Session sync failed', {
                    sessionId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const sessionData = await response.json()

            // Store session
            await db.put('sessions', {
                ...sessionData.session,
                synced_at: Date.now()
            })

            // Store interactions
            if (sessionData.interactions) {
                for (const interaction of sessionData.interactions) {
                    await db.put('interactions', {
                        ...interaction,
                        session_id: sessionId,
                        synced_at: Date.now()
                    })
                }
            }

            return { success: true, session: sessionData }
        } catch (error) {
            console.error('SyncService: Session sync failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Cache management: keep only recent sessions
     *
     * @returns {Promise<void>}
     * @private
     */
    async pruneOldSessions() {
        try {
            // Get all sessions sorted by updated_at
            const sessions = await db.getAllFromIndex('sessions', 'updated_at')

            // Keep only the most recent maxSessions
            if (sessions.length > this.maxSessions) {
                const sessionsToDelete = sessions.slice(0, sessions.length - this.maxSessions)

                for (const session of sessionsToDelete) {
                    // Delete session
                    await db.delete('sessions', session.id)

                    // Delete associated interactions
                    const interactions = await db.getAllFromIndex('interactions', 'session_id', session.id)
                    for (const interaction of interactions) {
                        await db.delete('interactions', interaction.id)
                    }
                }

                console.log('SyncService: Pruned old sessions', {
                    pruned: sessionsToDelete.length,
                    remaining: this.maxSessions
                })
            }
        } catch (error) {
            console.error('SyncService: Session pruning failed', {
                error: error.message,
                stack: error.stack
            })
        }
    }

    /**
     * Sync knowledge documents (search results)
     *
     * @param {string} query - The search query
     * @returns {Promise<SyncResult>}
     */
    async syncKnowledge(query) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const token = await this.auth.getToken()

            if (!token) {
                console.warn('SyncService: No API token for knowledge sync', { query })
                return { success: false, error: 'No API token' }
            }

            const response = await fetch(`${serverUrl}/api/v1/knowledge/hybrid-search`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ query })
            })

            if (!response.ok) {
                const error = new Error(`API error: ${response.status}`)
                console.error('SyncService: Knowledge sync failed', {
                    query,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const results = await response.json()

            // Cache results
            if (results.data) {
                for (const doc of results.data) {
                    await db.put('knowledge', {
                        ...doc,
                        cached_at: Date.now()
                    })
                }
            }

            return { success: true, results }
        } catch (error) {
            console.error('SyncService: Knowledge sync failed', {
                query,
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Check if data is stale and needs refresh
     *
     * @param {number|null} lastSyncTimestamp - Last sync timestamp in ms
     * @param {number} maxAgeMs - Maximum age before stale (default 5 minutes)
     * @returns {boolean} True if data needs refresh
     */
    needsSync(lastSyncTimestamp, maxAgeMs = 5 * 60 * 1000) {
        if (!lastSyncTimestamp) return true
        return (Date.now() - lastSyncTimestamp) > maxAgeMs
    }

    /**
     * Get sync status
     *
     * @returns {Promise<SyncStatus>}
     */
    async getSyncStatus() {
        const sessionsSyncMeta = await db.get('sync_metadata', 'sessions_last_sync')
        const sessionsCount = await db.count('sessions')
        const interactionsCount = await db.count('interactions')
        const knowledgeCount = await db.count('knowledge')
        const storageEstimate = await db.getStorageEstimate()

        return {
            sessions: {
                count: sessionsCount,
                last_sync: sessionsSyncMeta?.timestamp || null
            },
            interactions: {
                count: interactionsCount
            },
            knowledge: {
                count: knowledgeCount
            },
            storage: storageEstimate
        }
    }

    /**
     * Clear all cached data
     *
     * @returns {Promise<void>}
     */
    async clearAllCache() {
        await db.clear('sessions')
        await db.clear('interactions')
        await db.clear('knowledge')
        await db.clear('sync_metadata')
        console.log('SyncService: All cache cleared')
    }
}

export default SyncService

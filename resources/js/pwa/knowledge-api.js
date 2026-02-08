/**
 * KnowledgeAPI - Knowledge base search and document management for PWA
 *
 * Provides offline-first knowledge base access with intelligent caching.
 * Supports hybrid, semantic, and keyword search with automatic fallback to cached results.
 *
 * @module pwa/knowledge-api
 */

import db from './db'
import { AuthService } from './auth'

/**
 * @typedef {Object} KnowledgeDocument
 * @property {number} id - Document ID
 * @property {string} title - Document title
 * @property {string} content - Document content
 * @property {string[]} [tags] - Associated tags
 * @property {string} created_at - Creation timestamp
 * @property {number} [cached_at] - Cache timestamp
 */

/**
 * @typedef {Object} CacheStats
 * @property {number} count - Number of cached documents
 * @property {number} estimatedSize - Estimated size in bytes
 * @property {string} estimatedSizeMB - Estimated size in MB
 */

/**
 * KnowledgeAPI - Manages knowledge base search and document retrieval
 *
 * @class
 */
export class KnowledgeAPI {
    constructor() {
        this.auth = new AuthService()
    }

    /**
     * Search knowledge base
     *
     * @param {string} query - Search query
     * @param {string} type - Search type: 'hybrid', 'semantic', or 'keyword'
     * @param {string[]} tags - Optional tags to filter by
     * @param {boolean} useCache - Whether to use cached results
     * @returns {Promise<KnowledgeDocument[]>} Search results
     */
    async search(query, type = 'hybrid', tags = [], useCache = true) {
        try {
            // Try cache first if online and cache is enabled
            if (useCache && navigator.onLine) {
                const cached = await this.getCachedSearchResults(query, type, tags)
                if (cached && cached.length > 0) {
                    return cached
                }
            }

            // If offline, return cached results only
            if (!navigator.onLine) {
                const cached = await this.getCachedSearchResults(query, type, tags)
                if (cached && cached.length > 0) {
                    console.log('KnowledgeAPI: Using cached results (offline)', {
                        query,
                        type,
                        resultCount: cached.length
                    })
                    return cached
                }
                const error = new Error('No cached results available offline')
                console.warn('KnowledgeAPI: No cached results for offline query', { query, type })
                throw error
            }

            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            const endpoint = type === 'hybrid' ? 'hybrid-search' :
                           type === 'semantic' ? 'semantic-search' : 'search'

            const body = { query }
            if (tags && tags.length > 0) {
                body.tags = tags
            }

            const response = await fetch(`${serverUrl}/api/v1/knowledge/${endpoint}`, {
                method: 'POST',
                headers,
                body: JSON.stringify(body)
            })

            if (!response.ok) {
                const error = new Error(`Search failed: ${response.status}`)
                console.error('KnowledgeAPI: Search request failed', {
                    query,
                    type,
                    tags,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const results = await response.json()
            const data = results.data || results

            // Cache results
            await this.cacheSearchResults(query, type, data)

            return data
        } catch (error) {
            console.error('KnowledgeAPI: Search failed', {
                query,
                type,
                tags,
                error: error.message,
                stack: error.stack
            })
            throw error
        }
    }

    /**
     * Get a specific document
     *
     * @param {number} id - Document ID
     * @param {boolean} useCache - Whether to use cached version
     * @returns {Promise<KnowledgeDocument>} The document
     */
    async getDocument(id, useCache = true) {
        try {
            // Try cache first
            if (useCache) {
                const cached = await db.get('knowledge', id)
                if (cached && !this.isStale(cached.cached_at)) {
                    return cached
                }
            }

            // If offline, return cached only
            if (!navigator.onLine) {
                const cached = await db.get('knowledge', id)
                if (cached) {
                    console.log('KnowledgeAPI: Using cached document (offline)', { id })
                    return cached
                }
                const error = new Error('Document not available offline')
                console.warn('KnowledgeAPI: Document not cached for offline access', { id })
                throw error
            }

            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            // For GET requests, remove Content-Type header
            delete headers['Content-Type']

            const response = await fetch(`${serverUrl}/api/v1/knowledge/${id}`, {
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`Failed to fetch document: ${response.status}`)
                console.error('KnowledgeAPI: Document fetch failed', {
                    id,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const doc = await response.json()

            // Cache for offline use
            await db.put('knowledge', {
                ...doc,
                cached_at: Date.now()
            })

            return doc
        } catch (error) {
            console.error('KnowledgeAPI: Failed to get document', {
                id,
                error: error.message,
                stack: error.stack
            })
            throw error
        }
    }

    /**
     * Get recent documents
     *
     * @param {number} limit - Maximum number of documents to return
     * @returns {Promise<KnowledgeDocument[]>} Recent documents
     */
    async getRecentDocuments(limit = 20) {
        try {
            // Try cache first if offline
            if (!navigator.onLine) {
                const docs = await db.getAll('knowledge')
                return docs
                    .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
                    .slice(0, limit)
            }

            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            // Remove Content-Type for GET requests
            delete headers['Content-Type']

            const response = await fetch(`${serverUrl}/api/v1/knowledge/recent?limit=${limit}`, {
                headers
            })

            if (!response.ok) {
                const error = new Error(`Failed to fetch recent documents: ${response.status}`)
                console.error('KnowledgeAPI: Recent documents fetch failed', {
                    limit,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const result = await response.json()
            const data = result.data || result

            // Cache documents for offline use
            if (Array.isArray(data)) {
                for (const doc of data) {
                    await db.put('knowledge', {
                        ...doc,
                        cached_at: Date.now()
                    })
                }
            }

            return data
        } catch (error) {
            console.warn('KnowledgeAPI: Failed to fetch recent documents, using cache', {
                limit,
                error: error.message
            })
            // Fallback to cache
            const docs = await db.getAll('knowledge')
            return docs
                .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
                .slice(0, limit)
        }
    }

    /**
     * Download document file
     *
     * @param {number} id - Document ID to download
     * @returns {Promise<{success: boolean, error?: string}>} Download result
     */
    async downloadDocument(id) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const token = await this.auth.getToken()

            const url = `${serverUrl}/api/v1/knowledge/${id}/download?token=${token}`

            // Open in new tab for download
            window.open(url, '_blank')

            return { success: true }
        } catch (error) {
            console.error('KnowledgeAPI: Document download failed', {
                id,
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Cache search results
     *
     * @param {string} query - Search query
     * @param {string} type - Search type
     * @param {KnowledgeDocument[]} results - Results to cache
     * @returns {Promise<void>}
     * @private
     */
    async cacheSearchResults(query, type, results) {
        if (!results || !Array.isArray(results)) return

        for (const doc of results) {
            await db.put('knowledge', {
                ...doc,
                cached_at: Date.now(),
                cached_query: query,
                cached_type: type
            })
        }
    }

    /**
     * Get cached search results
     *
     * @param {string} query - Search query
     * @param {string} type - Search type
     * @param {string[]} tags - Optional tags filter
     * @returns {Promise<KnowledgeDocument[]>} Cached results
     * @private
     */
    async getCachedSearchResults(query, type, tags = []) {
        // This is a simplified approach - in production you might want
        // to store search queries separately
        const allDocs = await db.getAll('knowledge')

        return allDocs.filter(doc => {
            const matchesQuery = doc.cached_query === query
            const matchesType = doc.cached_type === type
            const notStale = !this.isStale(doc.cached_at)
            const matchesTags = tags.length === 0 ||
                (doc.tags && tags.some(tag => doc.tags.includes(tag)))

            return matchesQuery && matchesType && notStale && matchesTags
        })
    }

    /**
     * Check if cached data is stale
     *
     * @param {number} timestamp - Cache timestamp
     * @param {number} maxAge - Maximum age in milliseconds (default 30 minutes)
     * @returns {boolean} True if data is stale
     * @private
     */
    isStale(timestamp, maxAge = 30 * 60 * 1000) { // 30 minutes
        return !timestamp || (Date.now() - timestamp) > maxAge
    }

    /**
     * Clear knowledge cache
     *
     * @returns {Promise<void>}
     */
    async clearCache() {
        await db.clear('knowledge')
    }

    /**
     * Get cache statistics
     *
     * @returns {Promise<CacheStats>} Cache statistics
     */
    async getCacheStats() {
        const count = await db.count('knowledge')
        const docs = await db.getAll('knowledge')

        let totalSize = 0
        docs.forEach(doc => {
            // Rough size estimation
            totalSize += JSON.stringify(doc).length
        })

        return {
            count,
            estimatedSize: totalSize,
            estimatedSizeMB: (totalSize / (1024 * 1024)).toFixed(2)
        }
    }
}

export default KnowledgeAPI

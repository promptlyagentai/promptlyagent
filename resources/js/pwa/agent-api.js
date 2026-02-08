/**
 * AgentAPI - Agent management API for PWA
 *
 * Provides offline-first access to agent configurations with intelligent caching.
 * Handles agent listing, retrieval, and tool management with automatic cache fallback.
 *
 * @module pwa/agent-api
 */

import db from './db'
import { AuthService } from './auth'

/**
 * @typedef {Object} Agent
 * @property {number} id - Agent ID
 * @property {string} name - Agent name
 * @property {string} type - Agent type (direct, research, etc.)
 * @property {string} description - Agent description
 */

/**
 * @typedef {Object} AgentResponse
 * @property {boolean} success - Whether operation succeeded
 * @property {Agent|Agent[]} [data] - Agent data
 * @property {string} [error] - Error message if failed
 * @property {boolean} [cached] - Whether data is from cache
 * @property {boolean} [offline] - Whether response is offline-only
 */

/**
 * AgentAPI - Manages agent retrieval and caching
 *
 * @class
 */
export class AgentAPI {
    constructor() {
        this.auth = new AuthService()
        this.cacheKey = 'agents_cache'
        this.cacheExpiry = 60 * 60 * 1000 // 1 hour
    }

    /**
     * List all available agents
     *
     * @param {boolean} useCache - Whether to use cached results
     * @returns {Promise<AgentResponse>} Agent list response
     */
    async listAgents(useCache = true) {
        try {
            // Try cache first
            if (useCache) {
                const cached = await db.get('settings', this.cacheKey)
                if (cached && !this.isCacheExpired(cached.timestamp)) {

                    // Ensure cached value is an array (handle old cache format)
                    let agents = cached.value;
                    if (agents && typeof agents === 'object' && !Array.isArray(agents)) {
                        // Old cache format: { success: true, agents: [...] }
                        agents = agents.agents || agents.data || [];
                    }

                    return { success: true, data: agents, cached: true }
                }
            }

            // If offline, return cached only
            if (!navigator.onLine) {
                const cached = await db.get('settings', this.cacheKey)
                if (cached) {
                    // Ensure cached value is an array (handle old cache format)
                    let agents = cached.value;
                    if (agents && typeof agents === 'object' && !Array.isArray(agents)) {
                        agents = agents.agents || agents.data || [];
                    }

                    console.log('AgentAPI: Using cached agents (offline)', { count: agents.length })

                    return {
                        success: true,
                        data: agents,
                        cached: true,
                        offline: true
                    }
                }
                const error = new Error('No cached agents available offline')
                console.warn('AgentAPI: No cached agents for offline access')
                throw error
            }

            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            // For GET requests, remove Content-Type header
            delete headers['Content-Type']

            const response = await fetch(`${serverUrl}/api/v1/agents`, {
                headers: headers
            })

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}))
                const error = new Error(errorData.message || `Failed to fetch agents: ${response.status}`)
                console.error('AgentAPI: Failed to fetch agents', {
                    status: response.status,
                    statusText: response.statusText,
                    errorData
                })
                throw error
            }

            const result = await response.json()

            // Handle different response formats
            // API returns { success: true, agents: [...] }
            const agents = result.agents || result.data || []

            // Cache ONLY the agents array, not the wrapper
            await db.put('settings', {
                key: this.cacheKey,
                value: agents,  // Store just the array
                timestamp: Date.now()
            })

            return { success: true, data: agents }
        } catch (error) {
            console.error('AgentAPI: Failed to list agents', {
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Get a specific agent
     *
     * @param {number} agentId - The agent ID
     * @param {boolean} useCache - Whether to use cached version
     * @returns {Promise<AgentResponse>} Agent response
     */
    async getAgent(agentId, useCache = true) {
        try {
            // Try to get from cached agents list first
            if (useCache) {
                const cached = await db.get('settings', this.cacheKey)
                if (cached && !this.isCacheExpired(cached.timestamp)) {
                    const agent = cached.value.find(a => a.id === agentId)
                    if (agent) {
                        return { success: true, data: agent, cached: true }
                    }
                }
            }

            // If offline, try cached list
            if (!navigator.onLine) {
                const cached = await db.get('settings', this.cacheKey)
                if (cached) {
                    const agent = cached.value.find(a => a.id === agentId)
                    if (agent) {
                        console.log('AgentAPI: Using cached agent (offline)', { agentId })
                        return {
                            success: true,
                            data: agent,
                            cached: true,
                            offline: true
                        }
                    }
                }
                const error = new Error('Agent not available offline')
                console.warn('AgentAPI: Agent not cached for offline access', { agentId })
                throw error
            }

            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            // For GET requests, remove Content-Type header
            delete headers['Content-Type']

            const response = await fetch(`${serverUrl}/api/v1/agents/${agentId}`, {
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`Failed to fetch agent: ${response.status}`)
                console.error('AgentAPI: Failed to fetch agent', {
                    agentId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const agent = await response.json()

            return { success: true, data: agent }
        } catch (error) {
            console.error('AgentAPI: Failed to get agent', {
                agentId,
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Get agent tools
     *
     * @param {number} agentId - The agent ID
     * @returns {Promise<AgentResponse>} Agent tools response
     */
    async getAgentTools(agentId) {
        try {
            // If offline, return empty array
            if (!navigator.onLine) {
                return {
                    success: true,
                    data: [],
                    offline: true
                }
            }

            const serverUrl = await this.auth.getServerUrl()
            const headers = await this.auth.getAuthHeaders()

            // For GET requests, remove Content-Type header
            delete headers['Content-Type']

            const response = await fetch(`${serverUrl}/api/v1/agents/${agentId}/tools`, {
                headers: headers
            })

            if (!response.ok) {
                const error = new Error(`Failed to fetch agent tools: ${response.status}`)
                console.error('AgentAPI: Failed to fetch agent tools', {
                    agentId,
                    status: response.status,
                    statusText: response.statusText
                })
                throw error
            }

            const tools = await response.json()

            return { success: true, data: tools }
        } catch (error) {
            console.error('AgentAPI: Failed to get agent tools', {
                agentId,
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Get the default Direct Chat Agent
     *
     * @returns {Promise<AgentResponse>} Default agent response
     */
    async getDefaultAgent() {
        const result = await this.listAgents()

        if (!result.success) {
            return result
        }

        // Find the Direct Chat Agent
        const defaultAgent = result.data.find(agent =>
            agent.type === 'direct' || agent.name.toLowerCase().includes('direct')
        )

        if (defaultAgent) {
            return { success: true, data: defaultAgent }
        }

        // If no direct agent found, return the first agent
        if (result.data.length > 0) {
            return { success: true, data: result.data[0] }
        }

        return { success: false, error: 'No agents available' }
    }

    /**
     * Filter agents by type
     *
     * @param {string} type - Agent type to filter by
     * @returns {Promise<AgentResponse>} Filtered agents response
     */
    async getAgentsByType(type) {
        const result = await this.listAgents()

        if (!result.success) {
            return result
        }

        const filtered = result.data.filter(agent => agent.type === type)

        return { success: true, data: filtered }
    }

    /**
     * Check if cache is expired
     *
     * @param {number} timestamp - Cache timestamp
     * @returns {boolean} True if cache is expired
     * @private
     */
    isCacheExpired(timestamp) {
        if (!timestamp) return true
        return (Date.now() - timestamp) > this.cacheExpiry
    }

    /**
     * Clear agents cache
     *
     * @returns {Promise<void>}
     */
    async clearCache() {
        await db.delete('settings', this.cacheKey)
    }

    /**
     * Force refresh agents cache
     *
     * @returns {Promise<AgentResponse>} Fresh agent list
     */
    async refreshCache() {
        await this.clearCache()
        return await this.listAgents(false)
    }
}

export default AgentAPI

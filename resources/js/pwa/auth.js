/**
 * Authentication Service for PWA
 *
 * Manages API tokens, server URLs, and user preferences in IndexedDB
 * for offline-capable authentication. Provides methods for token management,
 * connection testing, and preference storage.
 *
 * Storage:
 * - API tokens stored encrypted in IndexedDB
 * - Server URL configurable for multi-tenant deployments
 * - User preferences persisted locally
 *
 * @class AuthService
 * @module pwa/auth
 */

import db from './db'

export class AuthService {
    /**
     * Save API token and server URL to IndexedDB
     *
     * @param {string} token - Bearer token for API authentication
     * @param {string|null} [serverUrl=null] - Optional server URL override
     * @returns {Promise<void>}
     * @throws {Error} When IndexedDB write fails
     */
    async saveToken(token, serverUrl = null) {
        await db.put('settings', {
            key: 'api_token',
            value: token,
            updated_at: Date.now()
        })

        if (serverUrl) {
            await db.put('settings', {
                key: 'server_url',
                value: serverUrl,
                updated_at: Date.now()
            })
        }
    }

    /**
     * Get stored API token from IndexedDB
     *
     * @returns {Promise<string|null>} API token or null if not found
     */
    async getToken() {
        try {
            const setting = await db.get('settings', 'api_token')
            return setting?.value || null
        } catch (error) {
            console.error('AuthService: Failed to retrieve token', error)
            return null
        }
    }

    /**
     * Get server URL from IndexedDB or fallback to current origin
     *
     * @returns {Promise<string>} Server URL
     */
    async getServerUrl() {
        try {
            const setting = await db.get('settings', 'server_url')
            return setting?.value || window.location.origin
        } catch (error) {
            console.warn('AuthService: Failed to retrieve server URL, using origin fallback', error)
            return window.location.origin
        }
    }

    /**
     * Get authentication headers for API requests
     *
     * @returns {Promise<Object>} Headers object with Authorization, Accept, and Content-Type
     * @throws {Error} When no API token is configured
     */
    async getAuthHeaders() {
        const token = await this.getToken()

        if (!token) {
            console.error('AuthService: No API token configured')
            throw new Error('No API token configured')
        }

        return {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        }
    }

    /**
     * Test API connection by attempting to fetch agents list
     *
     * @returns {Promise<Object>} Result object with success status and details
     * @returns {boolean} result.success - Whether connection test succeeded
     * @returns {number} result.status - HTTP status code
     * @returns {string} [result.error] - Error message if failed
     * @returns {string} [result.message] - Success message
     * @returns {number} [result.agents_count] - Number of agents found
     */
    async testConnection() {
        try {
            const serverUrl = await this.getServerUrl()
            const headers = await this.getAuthHeaders()

            // For GET requests, remove Content-Type header
            delete headers['Content-Type']

            const response = await fetch(`${serverUrl}/api/v1/agents`, {
                headers: headers
            })

            if (!response.ok) {
                if (response.status === 401) {
                    console.warn('AuthService: Invalid API token detected during connection test')
                    return {
                        success: false,
                        error: 'Invalid API token',
                        status: 401
                    }
                }
                console.error('AuthService: Connection test failed with HTTP status', response.status)
                throw new Error(`HTTP ${response.status}`)
            }

            const data = await response.json()

            console.log('AuthService: Connection test successful', { agents_count: data.length || 0 })
            return {
                success: true,
                status: 200,
                message: 'Connection successful',
                agents_count: data.length || 0
            }
        } catch (error) {
            console.error('AuthService: Connection test failed', error)
            return {
                success: false,
                error: error.message,
                status: 0
            }
        }
    }

    /**
     * Check if user is authenticated (has valid token)
     *
     * @returns {Promise<boolean>} True if authenticated, false otherwise
     */
    async isAuthenticated() {
        const token = await this.getToken()
        return !!token
    }

    /**
     * Clear authentication data from IndexedDB
     *
     * @returns {Promise<void>}
     */
    async logout() {
        try {
            await db.delete('settings', 'api_token')
            await db.delete('settings', 'server_url')
            console.log('AuthService: User logged out successfully')
        } catch (error) {
            console.error('AuthService: Logout failed', error)
            throw error
        }
    }

    /**
     * Save user preference to IndexedDB
     *
     * @param {string} key - Preference key
     * @param {*} value - Preference value (must be JSON-serializable)
     * @returns {Promise<void>}
     */
    async savePreference(key, value) {
        try {
            await db.put('settings', {
                key: `pref_${key}`,
                value: value,
                updated_at: Date.now()
            })
        } catch (error) {
            console.error('AuthService: Failed to save preference', { key, error })
            throw error
        }
    }

    /**
     * Get user preference from IndexedDB
     *
     * @param {string} key - Preference key
     * @param {*} [defaultValue=null] - Default value if preference not found
     * @returns {Promise<*>} Preference value or default
     */
    async getPreference(key, defaultValue = null) {
        try {
            const setting = await db.get('settings', `pref_${key}`)
            return setting?.value ?? defaultValue
        } catch (error) {
            console.warn('AuthService: Failed to retrieve preference, using default', { key, defaultValue, error })
            return defaultValue
        }
    }
}

export default AuthService

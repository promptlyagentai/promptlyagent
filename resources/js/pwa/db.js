/**
 * DB - IndexedDB wrapper for PWA offline storage
 *
 * Manages IndexedDB initialization and provides a simplified API for CRUD operations.
 * Stores chat sessions, interactions, knowledge documents, settings, and sync metadata.
 *
 * @module pwa/db
 */

import { openDB } from 'idb'

const DB_NAME = 'promptlyagent'
const DB_VERSION = 1

/**
 * Open and initialize the IndexedDB database
 *
 * Creates object stores for sessions, interactions, knowledge, settings, and sync metadata.
 * Automatically runs upgrade logic on first initialization or version changes.
 *
 * @returns {Promise<IDBDatabase>}
 */
export const initDB = async () => {
    try {
        return await openDB(DB_NAME, DB_VERSION, {
            upgrade(db, oldVersion, newVersion, transaction) {
            // Chat sessions store
            if (!db.objectStoreNames.contains('sessions')) {
                const sessionsStore = db.createObjectStore('sessions', {
                    keyPath: 'id'
                })
                sessionsStore.createIndex('updated_at', 'updated_at')
                sessionsStore.createIndex('user_id', 'user_id')
            }

            // Chat interactions store
            if (!db.objectStoreNames.contains('interactions')) {
                const interactionsStore = db.createObjectStore('interactions', {
                    keyPath: 'id'
                })
                interactionsStore.createIndex('session_id', 'session_id')
                interactionsStore.createIndex('created_at', 'created_at')
            }

            // Knowledge documents store
            if (!db.objectStoreNames.contains('knowledge')) {
                const knowledgeStore = db.createObjectStore('knowledge', {
                    keyPath: 'id'
                })
                knowledgeStore.createIndex('title', 'title')
                knowledgeStore.createIndex('created_at', 'created_at')
            }

            // Settings store (for API tokens, preferences, etc.)
            if (!db.objectStoreNames.contains('settings')) {
                db.createObjectStore('settings', { keyPath: 'key' })
            }

            // Sync metadata store (track last sync times)
            if (!db.objectStoreNames.contains('sync_metadata')) {
                db.createObjectStore('sync_metadata', { keyPath: 'key' })
            }
        }
    })
    } catch (error) {
        console.error('DB: Failed to initialize IndexedDB', {
            error: error.message,
            dbName: DB_NAME,
            version: DB_VERSION
        })
        throw error
    }
}

// Initialize the database on module load
let dbPromise = initDB()

/**
 * Get the database instance
 *
 * @returns {Promise<IDBDatabase>}
 */
export const getDB = async () => {
    return await dbPromise
}

/**
 * Database helper functions
 */
export const db = {
    /**
     * Get a value from a store
     *
     * @param {string} storeName - The object store name
     * @param {any} key - The key to retrieve
     * @returns {Promise<any>} The stored value
     */
    async get(storeName, key) {
        try {
            const database = await getDB()
            return await database.get(storeName, key)
        } catch (error) {
            console.error('DB: Get operation failed', { storeName, key, error: error.message })
            throw error
        }
    },

    /**
     * Get all values from a store
     *
     * @param {string} storeName - The object store name
     * @param {IDBKeyRange} [query] - Optional query range
     * @param {number} [count] - Maximum number of results
     * @returns {Promise<any[]>} Array of stored values
     */
    async getAll(storeName, query, count) {
        try {
            const database = await getDB()
            return await database.getAll(storeName, query, count)
        } catch (error) {
            console.error('DB: GetAll operation failed', { storeName, error: error.message })
            throw error
        }
    },

    /**
     * Get all values from a store by index
     *
     * @param {string} storeName - The object store name
     * @param {string} indexName - The index name
     * @param {any} [query] - Optional query value
     * @param {number} [count] - Maximum number of results
     * @returns {Promise<any[]>} Array of stored values
     */
    async getAllFromIndex(storeName, indexName, query, count) {
        try {
            const database = await getDB()
            return await database.getAllFromIndex(storeName, indexName, query, count)
        } catch (error) {
            console.error('DB: GetAllFromIndex operation failed', {
                storeName,
                indexName,
                error: error.message
            })
            throw error
        }
    },

    /**
     * Put a value into a store (insert or update)
     *
     * @param {string} storeName - The object store name
     * @param {any} value - The value to store
     * @returns {Promise<any>} The key of the stored value
     */
    async put(storeName, value) {
        try {
            const database = await getDB()
            return await database.put(storeName, value)
        } catch (error) {
            console.error('DB: Put operation failed', {
                storeName,
                error: error.message
            })
            throw error
        }
    },

    /**
     * Add a value to a store (insert only, fails if exists)
     *
     * @param {string} storeName - The object store name
     * @param {any} value - The value to store
     * @returns {Promise<any>} The key of the stored value
     */
    async add(storeName, value) {
        try {
            const database = await getDB()
            return await database.add(storeName, value)
        } catch (error) {
            console.error('DB: Add operation failed', {
                storeName,
                error: error.message
            })
            throw error
        }
    },

    /**
     * Delete a value from a store
     *
     * @param {string} storeName - The object store name
     * @param {any} key - The key to delete
     * @returns {Promise<void>}
     */
    async delete(storeName, key) {
        try {
            const database = await getDB()
            return await database.delete(storeName, key)
        } catch (error) {
            console.error('DB: Delete operation failed', {
                storeName,
                key,
                error: error.message
            })
            throw error
        }
    },

    /**
     * Clear all values from a store
     *
     * @param {string} storeName - The object store name
     * @returns {Promise<void>}
     */
    async clear(storeName) {
        try {
            const database = await getDB()
            return await database.clear(storeName)
        } catch (error) {
            console.error('DB: Clear operation failed', {
                storeName,
                error: error.message
            })
            throw error
        }
    },

    /**
     * Count entries in a store
     *
     * @param {string} storeName - The object store name
     * @param {IDBKeyRange} [query] - Optional query range
     * @returns {Promise<number>} Number of entries
     */
    async count(storeName, query) {
        try {
            const database = await getDB()
            return await database.count(storeName, query)
        } catch (error) {
            console.error('DB: Count operation failed', {
                storeName,
                error: error.message
            })
            throw error
        }
    },

    /**
     * Get database size estimation
     *
     * @returns {Promise<Object|null>} Storage estimate or null if not supported
     */
    async getStorageEstimate() {
        if (navigator.storage && navigator.storage.estimate) {
            try {
                return await navigator.storage.estimate()
            } catch (error) {
                console.warn('DB: Storage estimate failed', { error: error.message })
                return null
            }
        }
        return null
    }
}

export default db

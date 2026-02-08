/**
 * File Handler Service for PWA
 *
 * Provides file upload, camera capture, and file validation functionality
 * for Progressive Web App features. Handles camera access, file selection,
 * and upload to the chat API with validation.
 *
 * Features:
 * - Camera photo capture with environment camera preference
 * - File picker with customizable MIME type filtering
 * - File upload to chat API with FormData
 * - File validation (size, type)
 * - Image preview generation via FileReader API
 * - File size formatting utilities
 *
 * @class FileHandler
 * @module pwa/file-handler
 */

import { AuthService } from './auth'

export class FileHandler {
    /**
     * Create a FileHandler instance
     */
    constructor() {
        this.auth = new AuthService()
    }

    /**
     * Open device camera for photo capture
     *
     * Uses the rear (environment) camera by default on mobile devices.
     * Prompts user for camera permission if not already granted.
     *
     * @returns {Promise<File>} Captured photo file
     * @throws {Error} When camera capture is cancelled or no file selected
     */
    async capturePhoto() {
        return new Promise((resolve, reject) => {
            const input = document.createElement('input')
            input.type = 'file'
            input.accept = 'image/*'
            input.capture = 'environment' // Use rear camera by default

            input.onchange = (e) => {
                const file = e.target.files[0]
                if (file) {
                    console.log('FileHandler: Photo captured successfully', {
                        name: file.name,
                        size: this.formatFileSize(file.size),
                        type: file.type
                    })
                    resolve(file)
                } else {
                    console.warn('FileHandler: No file selected from camera')
                    reject(new Error('No file selected'))
                }
            }

            input.oncancel = () => {
                console.log('FileHandler: Camera capture cancelled by user')
                reject(new Error('Camera capture cancelled'))
            }

            input.click()
        })
    }

    /**
     * Open file picker dialog
     *
     * Allows user to select a file from their device with optional MIME type filtering.
     *
     * @param {string} [accept] - MIME type filter for file selection
     * @returns {Promise<File>} Selected file
     * @throws {Error} When file selection is cancelled or no file selected
     */
    async selectFile(accept = '*/*') {
        return new Promise((resolve, reject) => {
            const input = document.createElement('input')
            input.type = 'file'
            input.accept = accept

            input.onchange = (e) => {
                const file = e.target.files[0]
                if (file) {
                    console.log('FileHandler: File selected successfully', {
                        name: file.name,
                        size: this.formatFileSize(file.size),
                        type: file.type
                    })
                    resolve(file)
                } else {
                    console.warn('FileHandler: No file selected from picker')
                    reject(new Error('No file selected'))
                }
            }

            input.oncancel = () => {
                console.log('FileHandler: File selection cancelled by user')
                reject(new Error('File selection cancelled'))
            }

            input.click()
        })
    }

    /**
     * Upload file to chat API endpoint
     *
     * Uploads a file using FormData with multipart/form-data encoding.
     * Requires authenticated session with valid API token.
     *
     * @param {File} file - File to upload
     * @param {number|null} [sessionId=null] - Optional chat session ID to associate with upload
     * @returns {Promise<Object>} Upload result
     * @returns {boolean} result.success - Whether upload succeeded
     * @returns {Object} [result.data] - Response data from API
     * @returns {string} [result.error] - Error message if failed
     */
    async uploadFile(file, sessionId = null) {
        try {
            const serverUrl = await this.auth.getServerUrl()
            const token = await this.auth.getToken()

            if (!token) {
                console.error('FileHandler: Upload failed - no API token configured')
                throw new Error('No API token configured')
            }

            console.log('FileHandler: Starting file upload', {
                fileName: file.name,
                fileSize: this.formatFileSize(file.size),
                sessionId: sessionId || 'none'
            })

            const formData = new FormData()
            formData.append('file', file)

            if (sessionId) {
                formData.append('session_id', sessionId)
            }

            const response = await fetch(`${serverUrl}/api/v1/chat/stream`, {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${token}`,
                    'Accept': 'application/json'
                },
                body: formData
            })

            if (!response.ok) {
                console.error('FileHandler: Upload failed with HTTP error', { status: response.status, statusText: response.statusText })
                throw new Error(`Upload failed: ${response.status}`)
            }

            const data = await response.json()
            console.log('FileHandler: File uploaded successfully', { fileName: file.name })

            return {
                success: true,
                data: data
            }
        } catch (error) {
            console.error('FileHandler: File upload failed', { fileName: file.name, error: error.message, stack: error.stack })
            return {
                success: false,
                error: error.message
            }
        }
    }

    /**
     * Validate file before upload
     *
     * Checks file existence and size constraints. Does not validate MIME type.
     *
     * @param {File|null} file - File to validate
     * @param {number} [maxSize=20971520] - Maximum file size in bytes (default 20MB)
     * @returns {Object} Validation result
     * @returns {boolean} result.valid - Whether file is valid
     * @returns {string} [result.error] - Error message if invalid
     */
    validateFile(file, maxSize = 20 * 1024 * 1024) { // 20MB default
        if (!file) {
            console.warn('FileHandler: Validation failed - no file provided')
            return { valid: false, error: 'No file provided' }
        }

        if (file.size > maxSize) {
            console.warn('FileHandler: Validation failed - file too large', {
                fileName: file.name,
                fileSize: this.formatFileSize(file.size),
                maxSize: this.formatFileSize(maxSize)
            })
            return {
                valid: false,
                error: `File too large. Maximum size: ${this.formatFileSize(maxSize)}`
            }
        }

        return { valid: true }
    }

    /**
     * Format file size for human-readable display
     *
     * Converts bytes to appropriate unit (Bytes, KB, MB, GB) with 2 decimal precision.
     *
     * @param {number} bytes - File size in bytes
     * @returns {string} Formatted file size (e.g., "1.5 MB")
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes'

        const k = 1024
        const sizes = ['Bytes', 'KB', 'MB', 'GB']
        const i = Math.floor(Math.log(bytes) / Math.log(k))

        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
    }

    /**
     * Generate base64 preview for image files
     *
     * Uses FileReader API to convert image file to data URL for preview display.
     * Returns null for non-image files.
     *
     * @param {File} file - Image file to preview
     * @returns {Promise<string|null>} Base64 data URL or null if not an image
     * @throws {Error} When file reading fails
     */
    async getFilePreview(file) {
        if (!file.type.startsWith('image/')) {
            console.log('FileHandler: Skipping preview - not an image file', { type: file.type })
            return null
        }

        return new Promise((resolve, reject) => {
            const reader = new FileReader()

            reader.onload = (e) => {
                console.log('FileHandler: Image preview generated successfully', { fileName: file.name })
                resolve(e.target.result)
            }

            reader.onerror = (error) => {
                console.error('FileHandler: Failed to read file for preview', { fileName: file.name, error })
                reject(new Error('Failed to read file'))
            }

            reader.readAsDataURL(file)
        })
    }
}

export default FileHandler

/**
 * PWA Services Module
 *
 * Central module for exporting all Progressive Web App (PWA) services.
 * These services provide offline-first functionality with IndexedDB caching
 * and graceful degradation when network is unavailable.
 *
 * Services Overview:
 * - AuthService: Manages API tokens and authentication state
 * - SyncService: Background synchronization of sessions and knowledge
 * - ChatStream: Real-time Server-Sent Events (SSE) streaming for chat
 * - FileHandler: File uploads, camera capture, and file validation
 * - VoiceInput: Speech-to-text recognition using Web Speech API
 * - TextToSpeech: Text-to-speech synthesis using Web Speech API
 * - ChatExporter: Export chat sessions as Markdown or JSON
 * - KnowledgeAPI: Search and cache knowledge base documents
 * - AgentAPI: Manage and cache AI agent configurations
 * - db: IndexedDB wrapper with helper utilities
 *
 * Usage in Alpine.js:
 * ```javascript
 * import { AuthService } from './pwa-services'
 * const auth = new AuthService()
 * await auth.saveToken('token', 'https://api.example.com')
 * ```
 *
 * @module pwa-services
 */

// Export all PWA services for use in Alpine.js components
export { AuthService } from './pwa/auth'
export { SyncService } from './pwa/sync'
export { ChatStream } from './pwa/chat-stream'
export { FileHandler } from './pwa/file-handler'
export { VoiceInput } from './pwa/voice-input'
export { TextToSpeech } from './pwa/text-to-speech'
export { ChatExporter } from './pwa/export'
export { KnowledgeAPI } from './pwa/knowledge-api'
export { AgentAPI } from './pwa/agent-api'
export { db } from './pwa/db'

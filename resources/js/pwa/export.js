/**
 * ChatExporter - Export chat sessions and interactions to various formats
 *
 * Provides export functionality for chat data stored in IndexedDB. Supports
 * Markdown and JSON formats with proper formatting and metadata preservation.
 *
 * @module pwa/export
 */

import db from './db'

/**
 * @typedef {Object} ExportResult
 * @property {boolean} success - Whether export succeeded
 * @property {string} [error] - Error message if failed
 * @property {number} [count] - Number of items exported (for bulk operations)
 */

/**
 * ChatExporter - Handles chat data export operations
 *
 * @class
 */
export class ChatExporter {
    /**
     * Download a complete chat session as Markdown
     *
     * @param {number} sessionId - The session ID to export
     * @returns {Promise<ExportResult>} Export result
     */
    async downloadSession(sessionId) {
        try {
            // Get session from IndexedDB
            const session = await db.get('sessions', sessionId)

            if (!session) {
                const error = new Error('Session not found')
                console.error('ChatExporter: Session not found for export', { sessionId })
                throw error
            }

            // Get all interactions for this session
            const interactions = await db.getAllFromIndex('interactions', 'session_id', sessionId)

            // Sort by created_at
            interactions.sort((a, b) => new Date(a.created_at) - new Date(b.created_at))

            // Format as Markdown
            const markdown = this.formatSessionAsMarkdown(session, interactions)

            // Trigger download
            this.downloadFile(
                markdown,
                `chat-${session.title || 'session'}-${Date.now()}.md`,
                'text/markdown'
            )

            console.log('ChatExporter: Session exported successfully', {
                sessionId,
                interactionCount: interactions.length
            })

            return { success: true }
        } catch (error) {
            console.error('ChatExporter: Session export failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Download a single interaction as Markdown
     *
     * @param {number} interactionId - The interaction ID to export
     * @returns {Promise<ExportResult>} Export result
     */
    async downloadInteraction(interactionId) {
        try {
            const interaction = await db.get('interactions', interactionId)

            if (!interaction) {
                const error = new Error('Interaction not found')
                console.error('ChatExporter: Interaction not found for export', { interactionId })
                throw error
            }

            const markdown = this.formatInteractionAsMarkdown(interaction)

            this.downloadFile(
                markdown,
                `interaction-${interactionId}-${Date.now()}.md`,
                'text/markdown'
            )

            return { success: true }
        } catch (error) {
            console.error('ChatExporter: Interaction export failed', {
                interactionId,
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Format session as Markdown
     *
     * @param {Object} session - The session object
     * @param {Array} interactions - Array of interactions
     * @returns {string} Formatted Markdown content
     * @private
     */
    formatSessionAsMarkdown(session, interactions) {
        let md = `# ${session.title || 'Chat Session'}\n\n`

        // Add metadata
        md += `**Session ID:** ${session.id}\n`
        md += `**Created:** ${new Date(session.created_at).toLocaleString()}\n`
        md += `**Updated:** ${new Date(session.updated_at).toLocaleString()}\n\n`
        md += `---\n\n`

        // Add interactions
        interactions.forEach((interaction, index) => {
            md += this.formatInteractionAsMarkdown(interaction, index + 1)
            md += '\n\n---\n\n'
        })

        return md
    }

    /**
     * Format single interaction as Markdown
     *
     * @param {Object} interaction - The interaction object
     * @param {number|null} number - Optional interaction number for display
     * @returns {string} Formatted Markdown content
     * @private
     */
    formatInteractionAsMarkdown(interaction, number = null) {
        let md = ''

        if (number) {
            md += `## Interaction ${number}\n\n`
        }

        // Question
        md += `### Question\n\n`
        md += `${interaction.question}\n\n`

        // Answer
        if (interaction.answer) {
            md += `### Answer\n\n`
            md += `${interaction.answer}\n\n`
        }

        // Summary
        if (interaction.summary) {
            md += `### Summary\n\n`
            md += `${interaction.summary}\n\n`
        }

        // Sources
        if (interaction.sources && interaction.sources.length > 0) {
            md += `### Sources\n\n`
            interaction.sources.forEach(source => {
                md += `- [${source.title || source.url}](${source.url})\n`
            })
            md += '\n'
        }

        // Knowledge sources
        if (interaction.knowledge_sources && interaction.knowledge_sources.length > 0) {
            md += `### Knowledge Base References\n\n`
            interaction.knowledge_sources.forEach(source => {
                md += `- ${source.title || 'Unknown'}\n`
                if (source.content_excerpt) {
                    md += `  > ${source.content_excerpt.substring(0, 200)}...\n`
                }
            })
            md += '\n'
        }

        // Metadata
        md += `*Created: ${new Date(interaction.created_at).toLocaleString()}*\n`

        if (interaction.agent_id) {
            md += `*Agent ID: ${interaction.agent_id}*\n`
        }

        return md
    }

    /**
     * Download session as JSON (for data portability)
     *
     * @param {number} sessionId - The session ID to export
     * @returns {Promise<ExportResult>} Export result
     */
    async downloadSessionAsJSON(sessionId) {
        try {
            const session = await db.get('sessions', sessionId)

            if (!session) {
                const error = new Error('Session not found')
                console.error('ChatExporter: Session not found for JSON export', { sessionId })
                throw error
            }

            const interactions = await db.getAllFromIndex('interactions', 'session_id', sessionId)

            const data = {
                session,
                interactions,
                exported_at: new Date().toISOString(),
                version: '1.0'
            }

            this.downloadFile(
                JSON.stringify(data, null, 2),
                `chat-${session.title || 'session'}-${Date.now()}.json`,
                'application/json'
            )

            console.log('ChatExporter: JSON export successful', {
                sessionId,
                interactionCount: interactions.length
            })

            return { success: true }
        } catch (error) {
            console.error('ChatExporter: JSON export failed', {
                sessionId,
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }

    /**
     * Trigger file download in browser
     *
     * @param {string} content - File content
     * @param {string} filename - Download filename
     * @param {string} mimeType - MIME type
     * @returns {void}
     * @private
     */
    downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType })
        const url = URL.createObjectURL(blob)

        const a = document.createElement('a')
        a.href = url
        a.download = filename
        a.style.display = 'none'

        document.body.appendChild(a)
        a.click()

        // Cleanup
        document.body.removeChild(a)
        URL.revokeObjectURL(url)
    }

    /**
     * Export all sessions as individual files
     *
     * Note: Exports each session as a separate download with delay between files
     *
     * @returns {Promise<ExportResult>} Export result with count
     */
    async exportAllSessions() {
        try {
            const sessions = await db.getAll('sessions')

            console.log('ChatExporter: Starting bulk export', { count: sessions.length })

            for (const session of sessions) {
                await this.downloadSession(session.id)
                // Add small delay between downloads
                await new Promise(resolve => setTimeout(resolve, 500))
            }

            console.log('ChatExporter: Bulk export completed', { count: sessions.length })

            return { success: true, count: sessions.length }
        } catch (error) {
            console.error('ChatExporter: Bulk export failed', {
                error: error.message,
                stack: error.stack
            })
            return { success: false, error: error.message }
        }
    }
}

export default ChatExporter

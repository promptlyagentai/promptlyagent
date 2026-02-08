/**
 * TiptapEditor - Rich text editor component using Tiptap
 *
 * Provides WYSIWYG editing with Markdown toggle support. Built on Tiptap with
 * StarterKit extensions for formatting, lists, and code blocks. Includes
 * bidirectional HTML ↔ Markdown conversion.
 *
 * @module components/tiptap-editor
 */

import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'

/**
 * Tiptap editor Alpine.js component
 *
 * @returns {Object} Alpine component data and methods
 */
export default function tiptapEditor() {
    return {
        editor: null,
        content: '',
        showMarkdown: false,

        /**
         * Initialize the component
         *
         * @returns {void}
         */
        init() {
            this.$nextTick(() => {
                this.initEditor()
            })
        },

        /**
         * Initialize Tiptap editor
         *
         * @returns {void}
         */
        initEditor() {
            const element = this.$refs.editor
            if (!element) {
                console.error('TiptapEditor: Editor element not found')
                return
            }

            try {
                this.editor = new Editor({
                element: element,
                extensions: [
                    StarterKit,
                ],
                content: this.content || '<p></p>',
                editorProps: {
                    attributes: {
                        class: 'prose prose-sm max-w-none focus:outline-none min-h-[200px] p-4',
                    },
                },
                onUpdate: ({ editor }) => {
                    this.content = editor.getHTML()
                    this.$dispatch('content-updated', { content: this.content })
                },
            })
            } catch (error) {
                console.error('TiptapEditor: Failed to initialize editor', {
                    error: error.message,
                    stack: error.stack
                })
            }
        },

        /**
         * Destroy the editor instance
         *
         * @returns {void}
         */
        destroy() {
            if (this.editor) {
                this.editor.destroy()
            }
        },

        /**
         * Toggle between rich text and Markdown view
         *
         * @returns {void}
         */
        toggleMarkdownView() {
            this.showMarkdown = !this.showMarkdown
            
            if (this.showMarkdown) {
                // Convert to markdown when showing markdown view
                this.content = this.htmlToMarkdown(this.editor.getHTML())
            } else {
                // Convert back to HTML when showing rich text view
                const html = this.markdownToHtml(this.content)
                this.editor.commands.setContent(html)
            }
        },
        
        /**
         * Update content from Markdown textarea
         *
         * @param {string} value - Markdown content
         * @returns {void}
         */
        updateMarkdownContent(value) {
            this.content = value
            this.$dispatch('content-updated', { content: this.content })
        },

        /**
         * Convert HTML to Markdown
         *
         * @param {string} html - HTML content
         * @returns {string} Markdown formatted text
         * @private
         */
        htmlToMarkdown(html) {
            let markdown = html
            
            // Convert headings
            markdown = markdown.replace(/<h([1-6])>(.*?)<\/h[1-6]>/g, (match, level, text) => {
                return '#'.repeat(parseInt(level)) + ' ' + text + '\n\n'
            })
            
            // Convert paragraphs
            markdown = markdown.replace(/<p>(.*?)<\/p>/g, '$1\n\n')
            
            // Convert bold
            markdown = markdown.replace(/<strong>(.*?)<\/strong>/g, '**$1**')
            
            // Convert italic
            markdown = markdown.replace(/<em>(.*?)<\/em>/g, '*$1*')
            
            // Convert code
            markdown = markdown.replace(/<code>(.*?)<\/code>/g, '`$1`')
            
            // Convert lists
            markdown = markdown.replace(/<ul>(.*?)<\/ul>/gs, (match, content) => {
                return content.replace(/<li>(.*?)<\/li>/g, '- $1\n') + '\n'
            })
            
            markdown = markdown.replace(/<ol>(.*?)<\/ol>/gs, (match, content) => {
                let counter = 1
                return content.replace(/<li>(.*?)<\/li>/g, () => {
                    return `${counter++}. $1\n`
                }) + '\n'
            })
            
            // Convert blockquotes
            markdown = markdown.replace(/<blockquote>(.*?)<\/blockquote>/gs, (match, content) => {
                return '> ' + content.trim() + '\n\n'
            })
            
            // Clean up
            markdown = markdown.replace(/\n{3,}/g, '\n\n').trim()
            
            return markdown
        },
        
        /**
         * Convert Markdown to HTML
         *
         * @param {string} markdown - Markdown content
         * @returns {string} HTML formatted text
         * @private
         */
        markdownToHtml(markdown) {
            let html = markdown
            
            // Convert headings
            html = html.replace(/^#{6}\s+(.+)$/gm, '<h6>$1</h6>')
            html = html.replace(/^#{5}\s+(.+)$/gm, '<h5>$1</h5>')
            html = html.replace(/^#{4}\s+(.+)$/gm, '<h4>$1</h4>')
            html = html.replace(/^#{3}\s+(.+)$/gm, '<h3>$1</h3>')
            html = html.replace(/^#{2}\s+(.+)$/gm, '<h2>$1</h2>')
            html = html.replace(/^#{1}\s+(.+)$/gm, '<h1>$1</h1>')
            
            // Convert code
            html = html.replace(/`([^`]+)`/g, '<code>$1</code>')
            
            // Convert bold
            html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            
            // Convert italic
            html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>')
            
            // Convert lists
            html = html.replace(/^[\s]*-[\s]+(.+)$/gm, '<li>$1</li>')
            html = html.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>')
            
            html = html.replace(/^[\s]*\d+\.[\s]+(.+)$/gm, '<li>$1</li>')
            html = html.replace(/(<li>.*<\/li>)/s, '<ol>$1</ol>')
            
            // Convert blockquotes
            html = html.replace(/^>\s+(.+)$/gm, '<blockquote>$1</blockquote>')
            
            // Convert paragraphs
            html = html.replace(/\n\n/g, '</p><p>')
            html = '<p>' + html + '</p>'
            
            // Clean up
            html = html.replace(/<p><\/p>/g, '')
            html = html.replace(/<p>(<h[1-6]>)/g, '$1')
            html = html.replace(/(<\/h[1-6]>)<\/p>/g, '$1')
            html = html.replace(/<p>(<ul>|<ol>|<blockquote>)/g, '$1')
            html = html.replace(/(<\/ul>|<\/ol>|<\/blockquote>)<\/p>/g, '$1')
            
            return html
        },
        
        /**
         * Toggle bold formatting
         * @returns {void}
         */
        bold() {
            this.editor.chain().focus().toggleBold().run()
        },

        /**
         * Toggle italic formatting
         * @returns {void}
         */
        italic() {
            this.editor.chain().focus().toggleItalic().run()
        },

        /**
         * Toggle inline code formatting
         * @returns {void}
         */
        code() {
            this.editor.chain().focus().toggleCode().run()
        },

        /**
         * Toggle heading level
         * @param {number} level - Heading level (1-6)
         * @returns {void}
         */
        heading(level) {
            this.editor.chain().focus().toggleHeading({ level }).run()
        },

        /**
         * Toggle bullet list
         * @returns {void}
         */
        bulletList() {
            this.editor.chain().focus().toggleBulletList().run()
        },

        /**
         * Toggle ordered list
         * @returns {void}
         */
        orderedList() {
            this.editor.chain().focus().toggleOrderedList().run()
        },

        /**
         * Toggle blockquote
         * @returns {void}
         */
        blockquote() {
            this.editor.chain().focus().toggleBlockquote().run()
        },

        /**
         * Toggle code block
         * @returns {void}
         */
        codeBlock() {
            this.editor.chain().focus().toggleCodeBlock().run()
        },

        /**
         * Check if format is active
         *
         * @param {string} format - Format name
         * @param {Object} options - Format options
         * @returns {boolean} True if format is active
         */
        isActive(format, options = {}) {
            if (!this.editor) return false
            return this.editor.isActive(format, options)
        },

        /**
         * Get character count
         *
         * @returns {number} Number of characters
         */
        getCharacterCount() {
            if (!this.editor) return 0
            return this.editor.storage.characterCount?.characters() || this.editor.getText().length
        },

        /**
         * Get word count
         *
         * @returns {number} Number of words
         */
        getWordCount() {
            if (!this.editor) return 0
            return this.editor.storage.characterCount?.words() || this.editor.getText().split(/\s+/).filter(word => word.length > 0).length
        }
    }
}
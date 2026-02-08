/**
 * MarkdownEditor - Plain text Markdown editor with live preview
 *
 * Provides a simple textarea-based Markdown editor with live HTML preview.
 * Includes toolbar shortcuts for common formatting and real-time rendering
 * with Tailwind-styled output.
 *
 * @module components/markdown-editor
 */

/**
 * Markdown editor Alpine.js component
 *
 * @returns {Object} Alpine component data and methods
 */
export default function markdownEditor() {
    return {
        content: '',
        showPreview: false,

        /**
         * Initialize the component
         *
         * @returns {void}
         */
        init() {
            // Simple initialization
        },

        /**
         * Toggle preview mode
         *
         * @returns {void}
         */
        togglePreview() {
            this.showPreview = !this.showPreview
        },

        /**
         * Update content and emit event
         *
         * @param {string} value - New content value
         * @returns {void}
         */
        updateContent(value) {
            this.content = value
            this.$dispatch('content-updated', { content: this.content })
        },

        /**
         * Convert Markdown to HTML for preview
         *
         * @param {string} markdown - Markdown content
         * @returns {string} HTML with Tailwind classes
         */
        markdownToHtml(markdown) {
            if (!markdown.trim()) {
                return '<p class="text-gray-500 italic">Start typing to see your markdown preview...</p>'
            }
            
            let html = markdown
            
            // Convert code blocks first (before other processing)
            html = html.replace(/```([\w]*)\n([\s\S]*?)\n```/g, '<pre><code class="language-$1 bg-gray-100 dark:bg-gray-800 rounded p-2 block">$2</code></pre>')
            
            // Convert headings
            html = html.replace(/^#{6}\s+(.+)$/gm, '<h6 class="text-sm font-semibold mt-4 mb-2">$1</h6>')
            html = html.replace(/^#{5}\s+(.+)$/gm, '<h5 class="text-base font-semibold mt-4 mb-2">$1</h5>')
            html = html.replace(/^#{4}\s+(.+)$/gm, '<h4 class="text-lg font-semibold mt-4 mb-2">$1</h4>')
            html = html.replace(/^#{3}\s+(.+)$/gm, '<h3 class="text-xl font-semibold mt-4 mb-2">$1</h3>')
            html = html.replace(/^#{2}\s+(.+)$/gm, '<h2 class="text-2xl font-bold mt-6 mb-3">$1</h2>')
            html = html.replace(/^#{1}\s+(.+)$/gm, '<h1 class="text-3xl font-bold mt-6 mb-4">$1</h1>')
            
            // Convert inline code
            html = html.replace(/`([^`]+)`/g, '<code class="bg-gray-100 dark:bg-gray-800 px-1 rounded text-sm">$1</code>')
            
            // Convert bold
            html = html.replace(/\*\*([^*]+)\*\*/g, '<strong class="font-semibold">$1</strong>')
            
            // Convert italic
            html = html.replace(/\*([^*]+)\*/g, '<em class="italic">$1</em>')
            
            // Convert links (with URL validation to prevent javascript: XSS)
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, text, url) => {
                // Only allow http, https, mailto, tel protocols
                if (url.match(/^(https?|mailto|tel):/i) || url.match(/^[/#]/)) {
                    return `<a href="${url}" class="text-tropical-teal-600 dark:text-tropical-teal-400 hover:underline">${text}</a>`;
                }
                // For relative URLs or unknown protocols, return plain text
                return text;
            })
            
            // Convert horizontal rules
            html = html.replace(/^---$/gm, '<hr class="border-gray-300 dark:border-gray-600 my-4">')
            
            // Convert blockquotes
            html = html.replace(/^>\s+(.+)$/gm, '<blockquote class="border-l-4 border-gray-300 dark:border-gray-600 pl-4 italic text-gray-700 dark:text-gray-300">$1</blockquote>')
            
            // Convert lists - improved to handle multiple lines properly
            const lines = html.split('\n')
            let result = []
            let inList = false
            let listType = null
            
            for (let i = 0; i < lines.length; i++) {
                const line = lines[i]
                const bulletMatch = line.match(/^[\s]*-[\s]+(.+)$/)
                const numberedMatch = line.match(/^[\s]*\d+\.[\s]+(.+)$/)
                
                if (bulletMatch) {
                    if (!inList || listType !== 'ul') {
                        if (inList) result.push(`</${listType}>`)
                        result.push('<ul class="list-disc list-inside space-y-1 my-2">')
                        listType = 'ul'
                        inList = true
                    }
                    result.push(`<li class="ml-4">${bulletMatch[1]}</li>`)
                } else if (numberedMatch) {
                    if (!inList || listType !== 'ol') {
                        if (inList) result.push(`</${listType}>`)
                        result.push('<ol class="list-decimal list-inside space-y-1 my-2">')
                        listType = 'ol'
                        inList = true
                    }
                    result.push(`<li class="ml-4">${numberedMatch[1]}</li>`)
                } else {
                    if (inList) {
                        result.push(`</${listType}>`)
                        inList = false
                        listType = null
                    }
                    result.push(line)
                }
            }
            
            if (inList) {
                result.push(`</${listType}>`)
            }
            
            html = result.join('\n')
            
            // Convert paragraphs
            html = html.split('\n\n').map(paragraph => {
                paragraph = paragraph.trim()
                if (paragraph && 
                    !paragraph.startsWith('<h') && 
                    !paragraph.startsWith('<ul') && 
                    !paragraph.startsWith('<ol') && 
                    !paragraph.startsWith('<blockquote') &&
                    !paragraph.startsWith('<pre') &&
                    !paragraph.startsWith('<hr') &&
                    !paragraph.includes('<li>')) {
                    return `<p class="mb-3 leading-relaxed">${paragraph}</p>`
                }
                return paragraph
            }).join('\n\n')
            
            return html
        },
        
        /**
         * Get character count
         *
         * @returns {number} Number of characters
         */
        getCharacterCount() {
            return this.content.length
        },

        /**
         * Get word count
         *
         * @returns {number} Number of words
         */
        getWordCount() {
            return this.content.split(/\s+/).filter(word => word.length > 0).length
        },

        /**
         * Insert Markdown syntax at cursor position
         *
         * @param {string} syntax - Syntax type (bold, italic, code, h1, h2, h3, list, quote, link)
         * @returns {void}
         */
        insertMarkdown(syntax) {
            const textarea = this.$refs.textarea
            if (!textarea) return
            
            const start = textarea.selectionStart
            const end = textarea.selectionEnd
            const selectedText = this.content.substring(start, end)
            
            let insertText = ''
            
            switch (syntax) {
                case 'bold':
                    insertText = `**${selectedText || 'bold text'}**`
                    break
                case 'italic':
                    insertText = `*${selectedText || 'italic text'}*`
                    break
                case 'code':
                    insertText = `\`${selectedText || 'code'}\``
                    break
                case 'h1':
                    insertText = `# ${selectedText || 'Heading 1'}`
                    break
                case 'h2':
                    insertText = `## ${selectedText || 'Heading 2'}`
                    break
                case 'h3':
                    insertText = `### ${selectedText || 'Heading 3'}`
                    break
                case 'list':
                    insertText = `- ${selectedText || 'List item'}`
                    break
                case 'quote':
                    insertText = `> ${selectedText || 'Quote'}`
                    break
                case 'link':
                    insertText = `[${selectedText || 'Link text'}](url)`
                    break
            }
            
            this.content = this.content.substring(0, start) + insertText + this.content.substring(end)
            this.updateContent(this.content)
            
            // Restore focus and set cursor position
            this.$nextTick(() => {
                textarea.focus()
                const newCursorPos = start + insertText.length
                textarea.setSelectionRange(newCursorPos, newCursorPos)
            })
        }
    }
}
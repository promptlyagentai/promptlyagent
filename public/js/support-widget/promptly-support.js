/**
 * PromptlyAgent Support Widget
 *
 * Embeddable support widget that integrates with PromptlyAgent's chat API.
 * Allows users to ask questions about web pages and select specific DOM elements for contextual help.
 *
 * ⚠️ SECURITY WARNING - DEMO/DEVELOPMENT ONLY ⚠️
 * This implementation exposes API credentials in client-side JavaScript.
 * DO NOT USE IN PRODUCTION without implementing proper security (Widget Account System or Backend Proxy).
 *
 * @version 1.0.0
 * @license MIT
 */

(function() {
    'use strict';

    // Main widget namespace
    const PromptlySupport = {
        // Configuration
        config: {
            apiBaseUrl: null,           // Required: PromptlyAgent instance URL
            apiToken: null,             // Optional: Bearer token for external widgets (auto-detected)
            agentId: null,              // Required: Agent ID (Promptly Manual)
            position: 'bottom-right',   // Widget position: bottom-right, bottom-left, top-right, top-left
            primaryColor: '#3b82f6',    // Theme color (blue-600)
            sessionCookieName: 'promptly_session_id',
            sessionCookieExpiry: 30,    // Days
            fabText: '?',               // Floating action button text
            widgetTitle: 'Help & Support',
            placeholderText: 'Ask a question...',
            welcomeMessage: 'Hi! How can I help you today?',
            debug: false,               // Enable debug logging
            authMode: null              // Auto-detected: 'session' or 'token'
        },

        // Widget state
        state: {
            isOpen: false,
            isSelecting: false,
            selectedElement: null,
            selectedFiles: [],
            chatSession: null,
            messages: [],
            isStreaming: false,
            currentStreamingMessageId: null,
            elementHighlight: null,
            // Bug report mode
            bugReportMode: false,
            bugReportData: {
                title: '',
                description: '',
                stepsToReproduce: '',
                expectedBehavior: '',
                screenshot: null,
                consoleLogs: null,
            },
        },

        // UI module
        ui: {
            elements: {},

            /**
             * Initialize UI - inject styles, render widget, attach event listeners
             */
            init() {
                PromptlySupport.log('Initializing UI...');
                this.injectStyles();
                this.renderWidget();
                this.attachEventListeners();
                PromptlySupport.log('UI initialized');
            },

            /**
             * Inject inline CSS styles
             */
            injectStyles() {
                const style = document.createElement('style');
                style.textContent = `
                    /* PromptlySupport Widget Styles */
.promptly-support-fab {
    position: fixed;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: var(--color-accent);
    color: var(--color-accent-foreground);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: bold;
    cursor: pointer;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 999999;
    transition: all 0.2s ease;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.promptly-support-fab:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
    background: var(--color-accent-hover);
}

.promptly-support-fab.bottom-right { bottom: 24px; right: 24px; }
.promptly-support-fab.bottom-left { bottom: 24px; left: 24px; }
.promptly-support-fab.top-right { top: 24px; right: 24px; }
.promptly-support-fab.top-left { top: 24px; left: 24px; }

.promptly-support-chat {
    position: fixed;
    width: 400px;
    max-width: calc(100vw - 32px);
    height: 600px;
    max-height: calc(100vh - 100px);
    background: var(--color-surface-bg);
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
    z-index: 1000000;
    display: none;
    flex-direction: column;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    overflow: hidden;
}

.promptly-support-chat.visible { display: flex; }
.promptly-support-chat.bottom-right { bottom: 90px; right: 24px; }
.promptly-support-chat.bottom-left { bottom: 90px; left: 24px; }
.promptly-support-chat.top-right { top: 90px; right: 24px; }
.promptly-support-chat.top-left { top: 90px; left: 24px; }

.promptly-support-header {
    padding: 16px 20px;
    background: var(--color-accent);
    color: var(--color-accent-foreground);
    border-radius: 12px 12px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.promptly-support-header-title {
    font-size: 16px;
    font-weight: 600;
}

.promptly-support-header-actions {
    display: flex;
    gap: 8px;
}

.promptly-support-header-btn {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: var(--color-accent-foreground);
    width: 32px;
    height: 32px;
    border-radius: 6px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
    font-size: 18px;
}

.promptly-support-header-btn:hover {
    background: rgba(255, 255, 255, 0.3);
}

.promptly-support-header-btn svg {
    width: 18px;
    height: 18px;
    fill: currentColor;
    vertical-align: middle;
}

.promptly-support-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    background: rgba(255, 255, 255, 0.02);
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.06);
}

.promptly-support-message {
    display: flex;
    gap: 8px;
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

.promptly-support-message.user {
    flex-direction: row-reverse;
}

.promptly-support-message-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--color-accent);
    color: var(--color-accent-foreground);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: 600;
    flex-shrink: 0;
}

.promptly-support-message.assistant .promptly-support-message-avatar {
    background: var(--palette-zinc-500);
    color: white;
}

.promptly-support-message-content {
    max-width: 85%;
    padding: 10px 14px;
    border-radius: 12px;
    background: var(--color-surface-bg-elevated);
    border: 1px solid var(--color-surface-border);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    font-size: 14px;
    line-height: 1.5;
    word-wrap: break-word;
    color: var(--color-text-primary);
}

.promptly-support-message.user .promptly-support-message-content {
    background: var(--palette-primary-400);
    color: var(--palette-neutral-900) !important;
    border-color: var(--palette-primary-400);
}

.promptly-support-message.user .promptly-support-message-avatar {
    background: var(--palette-primary-400);
    color: var(--palette-neutral-900) !important;
}

.promptly-support-message.user .promptly-support-message-content p,
.promptly-support-message.user .promptly-support-message-content strong,
.promptly-support-message.user .promptly-support-message-content * {
    color: var(--palette-neutral-900) !important;
}

.promptly-support-message-content p {
    margin: 0 0 8px 0;
}

.promptly-support-message-content p:last-child {
    margin-bottom: 0;
}

.promptly-support-message-streaming::after {
    content: '▋';
    animation: blink 1s infinite;
}

@keyframes blink {
    0%, 50% { opacity: 1; }
    51%, 100% { opacity: 0; }
}

.promptly-support-status {
    padding: 8px 12px;
    background: var(--color-surface-bg);
    border-left: 3px solid var(--color-accent);
    border-radius: 4px;
    font-size: 13px;
    color: var(--color-text-secondary);
    font-style: italic;
    margin: 8px 0;
    animation: fadeIn 0.2s ease;
}

.promptly-support-input-area {
    padding: 16px;
    border-top: 1px solid var(--color-surface-border);
    background: var(--color-surface-bg-elevated);
    border-radius: 0 0 12px 12px;
}

.promptly-support-input-controls {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
}

.promptly-support-select-btn {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--color-surface-border);
    background: var(--color-surface-bg);
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    color: var(--color-text-primary);
    transition: all 0.2s;
}

.promptly-support-select-btn:hover {
    background: var(--color-surface-bg-elevated);
    border-color: var(--color-accent);
}

.promptly-support-select-btn.active {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: var(--color-accent-foreground);
}

.promptly-support-input-wrapper {
    display: flex;
    gap: 8px;
}

.promptly-support-input {
    flex: 1;
    padding: 10px 14px;
    border: 1px solid var(--color-surface-border);
    border-radius: 8px;
    font-size: 14px;
    font-family: inherit;
    resize: none;
    min-height: 42px;
    max-height: 120px;
    background: var(--color-surface-bg);
    color: var(--color-text-primary);
}

.promptly-support-input:focus {
    outline: none;
    border-color: var(--color-accent);
    box-shadow: 0 0 0 3px var(--color-accent-subtle);
}

.promptly-support-input::placeholder {
    color: var(--color-text-tertiary);
}

.promptly-support-send-btn {
    padding: 0 16px;
    background: var(--color-accent);
    color: var(--color-accent-foreground);
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: opacity 0.2s;
}

.promptly-support-send-btn:hover:not(:disabled) {
    background: var(--color-accent-hover);
}

.promptly-support-send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Element highlight overlay */
.promptly-support-highlight {
    position: absolute;
    border: 3px solid var(--color-accent);
    background: var(--color-accent-subtle);
    pointer-events: none;
    z-index: 999998;
    transition: all 0.1s ease;
}

/* Selection mode overlay */
.promptly-support-selector-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0, 0, 0, 0.3);
    z-index: 999997;
    cursor: crosshair;
    pointer-events: none;
}

.promptly-support-selector-hint {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--color-surface-bg-elevated);
    color: var(--color-text-primary);
    padding: 12px 24px;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    font-size: 14px;
    z-index: 999999;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

/* Selected element badge */
.promptly-support-selected-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    background: var(--color-accent-subtle);
    color: var(--color-accent);
    border-radius: 6px;
    font-size: 12px;
    margin-top: 8px;
}

.promptly-support-selected-badge button {
    background: none;
    border: none;
    color: var(--color-accent);
    cursor: pointer;
    padding: 0;
    font-size: 16px;
    line-height: 1;
}

/* Markdown styles */
.markdown {
    font-size: 14px;
    line-height: 1.6;
    word-wrap: break-word;
}

.markdown h1, .markdown h2, .markdown h3, .markdown h4, .markdown h5, .markdown h6 {
    font-weight: 600;
    margin-top: 1em;
    margin-bottom: 0.5em;
    line-height: 1.3;
}

.markdown h1 { font-size: 1.5em; }
.markdown h2 { font-size: 1.3em; }
.markdown h3 { font-size: 1.15em; }

.markdown p {
    margin: 0 0 0.75em 0;
}

.markdown p:last-child {
    margin-bottom: 0;
}

.markdown strong {
    font-weight: 600;
}

.markdown ul, .markdown ol {
    margin: 0.75em 0;
    padding-left: 1.5em;
}

.markdown li {
    margin: 0.25em 0;
}

.markdown code {
    background: var(--color-code-bg);
    color: var(--color-code-text);
    padding: 2px 6px;
    border-radius: 3px;
    font-family: monospace;
    font-size: 0.9em;
}

.markdown pre {
    background: var(--color-code-bg);
    color: var(--color-code-text);
    padding: 12px;
    border-radius: 6px;
    overflow-x: auto;
    margin: 0.75em 0;
}

.markdown pre code {
    background: none;
    padding: 0;
    color: inherit;
    font-size: 0.85em;
}

.markdown a {
    color: var(--color-accent);
    text-decoration: underline;
}

.markdown blockquote {
    border-left: 3px solid var(--color-accent);
    padding-left: 12px;
    margin: 0.75em 0;
    color: var(--color-text-secondary);
    font-style: italic;
}

.markdown table {
    border-collapse: collapse;
    width: 100%;
    margin: 0.75em 0;
}

.markdown table th {
    background: var(--color-surface-bg);
    padding: 8px;
    text-align: left;
    font-weight: 600;
    border: 1px solid var(--color-surface-border);
}

.markdown table td {
    padding: 8px;
    border: 1px solid var(--color-surface-border);
}

.markdown .table-wrapper {
    overflow-x: auto;
    margin: 0.75em 0;
}

/* Mobile responsive */
@media (max-width: 640px) {
    .promptly-support-chat {
        width: calc(100vw - 32px);
        height: calc(100vh - 100px);
        bottom: 16px !important;
        right: 16px !important;
        left: 16px !important;
    }

    .promptly-support-fab {
        width: 48px;
        height: 48px;
        font-size: 20px;
    }

    .markdown pre {
        font-size: 0.75em;
    }

    .markdown table {
        font-size: 0.85em;
    }
}

/* Bug Report Form Styles */
.promptly-support-bug-report-form {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.promptly-support-bug-report-form h3,
.promptly-support-bug-report-form p {
    color: var(--palette-neutral-900);
}

.dark .promptly-support-bug-report-form h3,
.dark .promptly-support-bug-report-form p {
    color: var(--palette-neutral-50);
}

.promptly-support-bug-report-form strong {
    color: var(--palette-neutral-900);
    font-weight: 600;
}

.dark .promptly-support-bug-report-form strong {
    color: var(--palette-neutral-100);
}

.promptly-support-form-group {
    margin-bottom: 16px;
}

.promptly-support-form-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: var(--palette-neutral-800);
    margin-bottom: 6px;
}

.dark .promptly-support-form-label {
    color: var(--palette-neutral-50);
}

.promptly-support-form-input,
.promptly-support-form-textarea {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--palette-neutral-300);
    border-radius: 6px;
    font-size: 14px;
    font-family: inherit;
    background: var(--palette-white);
    color: var(--palette-neutral-800);
}

.dark .promptly-support-form-input,
.dark .promptly-support-form-textarea {
    background: var(--palette-neutral-700);
    border-color: var(--palette-neutral-600);
    color: var(--palette-neutral-50);
}

.promptly-support-form-textarea {
    resize: vertical;
    min-height: 80px;
}

.promptly-support-form-content {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: rgba(255, 255, 255, 0.02);
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.06);
    min-height: 0;
}

.promptly-support-bug-report-input-area {
    padding: 16px;
    border-top: 1px solid var(--color-surface-border);
    background: var(--color-surface-bg-elevated);
}

.promptly-support-bug-report-controls {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    flex-wrap: nowrap;
}

.promptly-support-bug-report-controls .promptly-support-select-btn {
    flex: 1;
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.promptly-support-bug-report-controls #bug-attach-file {
    flex: 0.6;
}

#bug-attached-files {
    margin-top: 8px;
    margin-bottom: 12px;
}

.promptly-support-form-actions {
    display: flex;
    gap: 8px;
}

.promptly-support-form-btn {
    flex: 1;
    padding: 10px 16px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.promptly-support-form-btn-primary {
    background: var(--color-accent);
    color: var(--color-accent-foreground);
    border: none;
    border-radius: 8px;
}

.promptly-support-form-btn-primary:hover:not(:disabled) {
    background: var(--color-accent-hover);
}

.promptly-support-form-btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.promptly-support-form-btn-secondary {
    background: var(--color-surface-bg);
    color: var(--color-text-primary);
    border: 1px solid var(--color-surface-border);
    border-radius: 6px;
}

.promptly-support-form-btn-secondary:hover:not(:disabled) {
    background: var(--color-surface-bg-elevated);
    border-color: var(--color-accent);
}

.promptly-support-form-btn-secondary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.promptly-support-form-help {
    font-size: 12px;
    color: var(--palette-neutral-500);
    margin-top: 4px;
}

.dark .promptly-support-form-help {
    color: var(--palette-neutral-400);
}

/* Bug Report Preview Styles */
.promptly-support-preview-box {
    background: var(--palette-white);
    border: 1px solid var(--palette-neutral-200);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
    color: var(--palette-neutral-800);
}

.dark .promptly-support-preview-box {
    background: var(--palette-neutral-700);
    border-color: var(--palette-neutral-600);
    color: var(--palette-neutral-50);
}

.promptly-support-preview-heading {
    margin: 0 0 12px 0;
    font-size: 14px;
    font-weight: 600;
    color: var(--palette-neutral-900);
}

.dark .promptly-support-preview-heading {
    color: var(--palette-neutral-50);
}

.promptly-support-preview-item {
    margin-bottom: 8px;
    font-size: 14px;
    line-height: 1.5;
    color: var(--palette-neutral-700);
}

.dark .promptly-support-preview-item {
    color: var(--palette-neutral-200);
}
                `;
                document.head.appendChild(style);
            },

            /**
             * Render widget DOM structure
             */
            renderWidget() {
                // Create FAB (Floating Action Button)
                const fab = document.createElement('div');
                fab.className = `promptly-support-fab ${PromptlySupport.config.position}`;
                fab.textContent = PromptlySupport.config.fabText;
                fab.setAttribute('role', 'button');
                fab.setAttribute('aria-label', 'Open support chat');
                this.elements.fab = fab;
                document.body.appendChild(fab);

                // Create chat window
                const chat = document.createElement('div');
                chat.className = `promptly-support-chat ${PromptlySupport.config.position}`;
                chat.innerHTML = `
                    <div class="promptly-support-header">
                        <div class="promptly-support-header-title" id="promptly-header-title">${PromptlySupport.config.widgetTitle}</div>
                        <div class="promptly-support-header-actions">
                            <button class="promptly-support-header-btn" id="promptly-report-bug" title="Report Bug" aria-label="Report bug"></button>
                            <button class="promptly-support-header-btn" id="promptly-new-session" title="New conversation" aria-label="Start new conversation">
                                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M6 10H10.5C11.3284 10 12 9.32843 12 8.5V4" stroke="currentColor" stroke-width="1.5"></path>
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M9.85355 3.73223C10.3224 3.26339 10.9583 3 11.6213 3H16.5C17.8807 3 19 4.11929 19 5.5V18.5C19 19.8807 17.8807 21 16.5 21H7.5C6.11929 21 5 19.8807 5 18.5V9.62132C5 8.95828 5.26339 8.3224 5.73223 7.85355L9.85355 3.73223ZM11.6213 5C11.4887 5 11.3615 5.05268 11.2678 5.14645L7.14645 9.26777C7.05268 9.36154 7 9.48871 7 9.62132V18.5C7 18.7761 7.22386 19 7.5 19H16.5C16.7761 19 17 18.7761 17 18.5V5.5C17 5.22386 16.7761 5 16.5 5H11.6213Z" fill="currentColor"></path>
                                    <path d="M10 14.5H14M12 12.5V16.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                                </svg>
                            </button>
                            <button class="promptly-support-header-btn" id="promptly-close" title="Close" aria-label="Close chat">✕</button>
                        </div>
                    </div>
                    <div class="promptly-support-messages" id="promptly-messages"></div>
                    <div class="promptly-support-bug-report-form" id="promptly-bug-report-form" style="display: none;"></div>
                    <div class="promptly-support-input-area">
                        <div class="promptly-support-input-controls">
                            <button class="promptly-support-select-btn" id="promptly-select-element">
                                🎯 Select Element
                            </button>
                            <button class="promptly-support-select-btn" id="promptly-attach-file">
                                📎 Attach File
                            </button>
                            <input type="file" id="promptly-file-input" style="display: none;" multiple accept="image/*,.pdf,.txt,.doc,.docx" />
                        </div>
                        <div id="promptly-selected-element-badge"></div>
                        <div id="promptly-attached-files"></div>
                        <div class="promptly-support-input-wrapper">
                            <textarea
                                class="promptly-support-input"
                                id="promptly-input"
                                placeholder="${PromptlySupport.config.placeholderText}"
                                rows="1"
                            ></textarea>
                            <button class="promptly-support-send-btn" id="promptly-send">Send</button>
                        </div>
                    </div>
                `;
                this.elements.chat = chat;
                this.elements.messages = chat.querySelector('#promptly-messages');
                this.elements.bugReportForm = chat.querySelector('#promptly-bug-report-form');
                this.elements.bugReportBtn = chat.querySelector('#promptly-report-bug');
                this.elements.headerTitle = chat.querySelector('#promptly-header-title');
                this.elements.input = chat.querySelector('#promptly-input');
                this.elements.sendBtn = chat.querySelector('#promptly-send');
                this.elements.selectBtn = chat.querySelector('#promptly-select-element');
                this.elements.attachFileBtn = chat.querySelector('#promptly-attach-file');
                this.elements.fileInput = chat.querySelector('#promptly-file-input');
                this.elements.selectedBadge = chat.querySelector('#promptly-selected-element-badge');
                this.elements.attachedFilesContainer = chat.querySelector('#promptly-attached-files');
                this.elements.closeBtn = chat.querySelector('#promptly-close');
                this.elements.newSessionBtn = chat.querySelector('#promptly-new-session');
                this.elements.inputArea = chat.querySelector('.promptly-support-input-area');

                document.body.appendChild(chat);

                // Initialize bug report button icon
                PromptlySupport.bugReport.updateToggleButton();

                // Show welcome message
                this.addMessage('assistant', PromptlySupport.config.welcomeMessage);
            },

            /**
             * Attach event listeners
             */
            attachEventListeners() {
                // FAB click
                this.elements.fab.addEventListener('click', () => this.toggleChat());

                // Close button
                this.elements.closeBtn.addEventListener('click', () => this.toggleChat());

                // New session button
                this.elements.newSessionBtn.addEventListener('click', () => {
                    if (confirm('Start a new conversation? This will clear your current chat history.')) {
                        PromptlySupport.session.clearSession();
                    }
                });

                // Report bug button
                this.elements.bugReportBtn.addEventListener('click', () => {
                    PromptlySupport.bugReport.toggleMode();
                });

                // Select element button
                this.elements.selectBtn.addEventListener('click', () => {
                    PromptlySupport.selector.enable();
                    this.toggleChat(); // Close chat while selecting
                });

                // Attach file button
                this.elements.attachFileBtn.addEventListener('click', () => {
                    this.elements.fileInput.click();
                });

                // File input change
                this.elements.fileInput.addEventListener('change', (e) => {
                    const files = Array.from(e.target.files);
                    files.forEach(file => this.addFile(file));
                    e.target.value = ''; // Reset input
                });

                // Send button
                this.elements.sendBtn.addEventListener('click', () => this.handleSend());

                // Input - Enter key (Shift+Enter for new line)
                this.elements.input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.handleSend();
                    }
                });

                // Auto-resize textarea
                this.elements.input.addEventListener('input', () => {
                    this.elements.input.style.height = 'auto';
                    this.elements.input.style.height = this.elements.input.scrollHeight + 'px';
                });
            },

            /**
             * Toggle chat window visibility
             */
            toggleChat() {
                PromptlySupport.state.isOpen = !PromptlySupport.state.isOpen;

                if (PromptlySupport.state.isOpen) {
                    this.elements.chat.classList.add('visible');
                    this.elements.input.focus();
                    this.scrollToBottom();
                } else {
                    this.elements.chat.classList.remove('visible');
                }
            },

            /**
             * Handle send message
             */
            async handleSend() {
                let message = this.elements.input.value.trim();
                if (!message || PromptlySupport.state.isStreaming) return;

                // Build enhanced message with page and element context
                let enhancedMessage = `[PAGE CONTEXT]\nURL: ${window.location.href}\nTitle: ${document.title}\n\n`;

                // Add selected element details if present
                if (PromptlySupport.state.selectedElement) {
                    const el = PromptlySupport.state.selectedElement;
                    const elementDesc = el.textContent
                        ? `"${el.textContent.substring(0, 50)}${el.textContent.length > 50 ? '...' : ''}"`
                        : `<${el.tagName}>`;

                    enhancedMessage += `[SELECTED ELEMENT]\n`;
                    enhancedMessage += `Text: ${elementDesc}\n`;
                    enhancedMessage += `Selector: ${el.cssSelector || el.xpath}\n`;
                    enhancedMessage += `Tag: ${el.tagName}\n`;
                    if (el.id) enhancedMessage += `ID: ${el.id}\n`;
                    if (el.className) enhancedMessage += `Class: ${el.className}\n`;
                    if (el.boundingBox) {
                        enhancedMessage += `Position: x=${Math.round(el.boundingBox.x)}, y=${Math.round(el.boundingBox.y)}, `;
                        enhancedMessage += `width=${Math.round(el.boundingBox.width)}, height=${Math.round(el.boundingBox.height)}\n`;
                    }
                    enhancedMessage += `\n`;
                }

                enhancedMessage += `[USER QUESTION]\n${message}`;

                // Add user message (display original, send enhanced)
                this.addMessage('user', message);
                this.elements.input.value = '';
                this.elements.input.style.height = 'auto';

                // Disable input during streaming
                this.setInputEnabled(false);

                try {
                    // Send enhanced message to API
                    await PromptlySupport.api.sendMessage(enhancedMessage);

                    // Clear attached files after successful send
                    PromptlySupport.state.selectedFiles = [];
                    this.updateAttachedFilesUI();
                } catch (error) {
                    PromptlySupport.error('Failed to send message:', error);

                    // Display user-friendly error message
                    const errorMessage = error.message || 'Sorry, I encountered an error. Please try again.';
                    this.addMessage('assistant', `❌ ${errorMessage}`);
                } finally {
                    this.setInputEnabled(true);
                }
            },

            /**
             * Add message to chat
             */
            addMessage(role, content, streaming = false) {
                const messageEl = document.createElement('div');
                messageEl.className = `promptly-support-message ${role}`;

                const avatar = role === 'user' ? 'U' : 'AI';
                const messageId = `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
                messageEl.id = messageId;

                messageEl.innerHTML = `
                    <div class="promptly-support-message-avatar">${avatar}</div>
                    <div class="promptly-support-message-content markdown ${streaming ? 'promptly-support-message-streaming' : ''}">
                        ${this.formatMessageContent(content)}
                    </div>
                `;

                this.elements.messages.appendChild(messageEl);
                this.scrollToBottom();

                // Store in state
                PromptlySupport.state.messages.push({ role, content, id: messageId });

                if (streaming) {
                    PromptlySupport.state.currentStreamingMessageId = messageId;
                }

                return messageId;
            },

            /**
             * Update streaming message content
             */
            updateStreamingMessage(content) {
                if (!PromptlySupport.state.currentStreamingMessageId) return;

                const messageEl = document.getElementById(PromptlySupport.state.currentStreamingMessageId);
                if (messageEl) {
                    const contentEl = messageEl.querySelector('.promptly-support-message-content');
                    contentEl.innerHTML = this.formatMessageContent(content);

                    // Apply syntax highlighting to code blocks (same as PWA)
                    if (window.hljs) {
                        setTimeout(() => {
                            contentEl.querySelectorAll('pre code').forEach((block) => {
                                window.hljs.highlightElement(block);
                            });
                        }, 10);
                    }

                    this.scrollToBottom();
                }
            },

            /**
             * Finalize streaming message
             */
            finalizeStreamingMessage() {
                if (!PromptlySupport.state.currentStreamingMessageId) return;

                const messageEl = document.getElementById(PromptlySupport.state.currentStreamingMessageId);
                if (messageEl) {
                    const contentEl = messageEl.querySelector('.promptly-support-message-content');
                    contentEl.classList.remove('promptly-support-message-streaming');

                    // Apply syntax highlighting to final message (same as PWA)
                    if (window.hljs) {
                        setTimeout(() => {
                            contentEl.querySelectorAll('pre code').forEach((block) => {
                                window.hljs.highlightElement(block);
                            });
                        }, 10);
                    }
                }

                // Remove status indicator when message is finalized
                this.hideStatus();

                PromptlySupport.state.currentStreamingMessageId = null;
            },

            /**
             * Show status message (updates in place, single line)
             */
            showStatus(message) {
                // Reuse existing status element or create new one
                let statusEl = this.elements.messages.querySelector('.promptly-support-status');

                if (!statusEl) {
                    statusEl = document.createElement('div');
                    statusEl.className = 'promptly-support-status';
                }

                // Update status text
                statusEl.textContent = message;
                statusEl.style.display = 'block';

                // Position status after the last user message
                const userMessages = this.elements.messages.querySelectorAll('.promptly-support-message.user');
                if (userMessages.length > 0) {
                    const lastUserMessage = userMessages[userMessages.length - 1];
                    // Insert after the last user message
                    if (lastUserMessage.nextSibling !== statusEl) {
                        if (statusEl.parentNode) {
                            statusEl.parentNode.removeChild(statusEl);
                        }
                        lastUserMessage.parentNode.insertBefore(statusEl, lastUserMessage.nextSibling);
                    }
                } else {
                    // Fallback: append to end if no user messages yet
                    if (!statusEl.parentNode) {
                        this.elements.messages.appendChild(statusEl);
                    }
                }

                this.scrollToBottom();
            },

            /**
             * Hide status message
             */
            hideStatus() {
                const statusEl = this.elements.messages.querySelector('.promptly-support-status');
                if (statusEl) {
                    statusEl.style.display = 'none';
                }
            },

            /**
             * Format message content using marked.js markdown parser
             */
            formatMessageContent(content) {
                if (!content) return '';

                try {
                    // Use marked.js for markdown parsing (same as PWA)
                    if (window.marked && typeof window.marked.parse === 'function') {
                        PromptlySupport.log('Rendering markdown with marked.parse');
                        let html = window.marked.parse(content);

                        // Wrap tables in scrollable container for mobile (same as PWA)
                        html = html.replace(
                            /<table>/g,
                            '<div class="table-wrapper"><table>'
                        ).replace(
                            /<\/table>/g,
                            '</table></div>'
                        );

                        PromptlySupport.log('Markdown rendered successfully');
                        return html;
                    } else {
                        PromptlySupport.log('marked.js not available, using fallback');
                        // Fallback if marked not loaded: escape and preserve line breaks
                        return '<p>' + this.escapeHtml(content).replace(/\n/g, '<br>') + '</p>';
                    }
                } catch (error) {
                    PromptlySupport.error('Markdown parsing error:', error);
                    return '<pre>' + this.escapeHtml(content) + '</pre>';
                }
            },

            /**
             * Escape HTML to prevent XSS
             */
            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            },

            /**
             * Scroll messages to bottom
             */
            scrollToBottom() {
                setTimeout(() => {
                    this.elements.messages.scrollTop = this.elements.messages.scrollHeight;
                }, 0);
            },

            /**
             * Enable/disable input during streaming
             */
            setInputEnabled(enabled) {
                this.elements.input.disabled = !enabled;
                this.elements.sendBtn.disabled = !enabled;
                this.elements.selectBtn.disabled = !enabled;
            },

            /**
             * Show element highlight
             */
            showElementHighlight(element) {
                this.clearElementHighlight();

                const rect = element.getBoundingClientRect();
                const highlight = document.createElement('div');
                highlight.className = 'promptly-support-highlight';
                highlight.style.top = (rect.top + window.scrollY) + 'px';
                highlight.style.left = (rect.left + window.scrollX) + 'px';
                highlight.style.width = rect.width + 'px';
                highlight.style.height = rect.height + 'px';

                document.body.appendChild(highlight);
                PromptlySupport.state.elementHighlight = highlight;
            },

            /**
             * Clear element highlight
             */
            clearElementHighlight() {
                if (PromptlySupport.state.elementHighlight) {
                    PromptlySupport.state.elementHighlight.remove();
                    PromptlySupport.state.elementHighlight = null;
                }
            },

            /**
             * Show selected element badge
             */
            showSelectedElementBadge(element) {
                const tag = element.tagName.toLowerCase();
                const id = element.id ? `#${element.id}` : '';
                const className = element.className ? `.${element.className.split(' ')[0]}` : '';

                // Build descriptive label
                let label = `${tag}${id}${className}`;

                // Add text content if no ID/class and element has text (limit to 30 chars)
                if (!id && !className && element.textContent) {
                    const text = element.textContent.trim().substring(0, 30);
                    if (text) {
                        label = `${tag}: "${text}${element.textContent.length > 30 ? '...' : ''}"`;
                    }
                }

                this.elements.selectedBadge.innerHTML = `
                    <span class="promptly-support-selected-badge">
                        <span>Selected: <strong>${this.escapeHtml(label)}</strong></span>
                        <button onclick="PromptlySupport.selector.clearSelection()" aria-label="Clear selection">✕</button>
                    </span>
                `;
            },

            /**
             * Clear selected element badge
             */
            clearSelectedElementBadge() {
                this.elements.selectedBadge.innerHTML = '';
            },

            /**
             * Add file to attached files list
             */
            addFile(file) {
                // Check file size (max 10MB)
                const maxSize = 10 * 1024 * 1024;
                if (file.size > maxSize) {
                    alert(`File "${file.name}" is too large. Maximum size is 10MB.`);
                    return;
                }

                // Generate unique ID
                const fileId = `file-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

                // Add to state
                PromptlySupport.state.selectedFiles.push({
                    id: fileId,
                    file: file,
                    name: file.name,
                    size: file.size,
                    type: file.type
                });

                PromptlySupport.log('File attached:', file.name, file.size, 'bytes');

                // Update UI
                this.updateAttachedFilesUI();
            },

            /**
             * Remove file from attached files list
             */
            removeFile(fileId) {
                PromptlySupport.state.selectedFiles = PromptlySupport.state.selectedFiles.filter(f => f.id !== fileId);
                this.updateAttachedFilesUI();
                PromptlySupport.log('File removed:', fileId);
            },

            /**
             * Update attached files UI
             */
            updateAttachedFilesUI() {
                if (PromptlySupport.state.selectedFiles.length === 0) {
                    this.elements.attachedFilesContainer.innerHTML = '';
                    return;
                }

                const filesHtml = PromptlySupport.state.selectedFiles.map(fileData => {
                    const sizeKB = (fileData.size / 1024).toFixed(1);
                    return `
                        <span class="promptly-support-selected-badge">
                            <span>📎 <strong>${this.escapeHtml(fileData.name)}</strong> (${sizeKB} KB)</span>
                            <button onclick="PromptlySupport.ui.removeFile('${fileData.id}')" aria-label="Remove file">✕</button>
                        </span>
                    `;
                }).join('');

                this.elements.attachedFilesContainer.innerHTML = filesHtml;
            }
        },

        // Element Selector module
        selector: {
            overlay: null,
            hint: null,
            hoveredElement: null,

            /**
             * Enable element selection mode
             */
            enable() {
                if (PromptlySupport.state.isSelecting) return;

                PromptlySupport.state.isSelecting = true;
                PromptlySupport.log('Enabling element selection mode');

                // Create overlay
                this.overlay = document.createElement('div');
                this.overlay.className = 'promptly-support-selector-overlay';
                document.body.appendChild(this.overlay);

                // Create hint
                this.hint = document.createElement('div');
                this.hint.className = 'promptly-support-selector-hint';
                this.hint.textContent = 'Click on any element to select it, or press Escape to cancel';
                document.body.appendChild(this.hint);

                // Attach event listeners
                document.addEventListener('mouseover', this.handleMouseOver);
                document.addEventListener('mouseout', this.handleMouseOut);
                document.addEventListener('click', this.handleClick, true);
                document.addEventListener('keydown', this.handleKeyDown);

                // Update button state
                PromptlySupport.ui.elements.selectBtn.classList.add('active');
            },

            /**
             * Disable element selection mode
             */
            disable() {
                if (!PromptlySupport.state.isSelecting) return;

                PromptlySupport.state.isSelecting = false;
                PromptlySupport.log('Disabling element selection mode');

                // Remove overlay and hint
                if (this.overlay) {
                    this.overlay.remove();
                    this.overlay = null;
                }
                if (this.hint) {
                    this.hint.remove();
                    this.hint = null;
                }

                // Clear highlight
                PromptlySupport.ui.clearElementHighlight();

                // Remove event listeners
                document.removeEventListener('mouseover', this.handleMouseOver);
                document.removeEventListener('mouseout', this.handleMouseOut);
                document.removeEventListener('click', this.handleClick, true);
                document.removeEventListener('keydown', this.handleKeyDown);

                // Update button state
                PromptlySupport.ui.elements.selectBtn.classList.remove('active');
            },

            /**
             * Handle mouse over element
             */
            handleMouseOver: (e) => {
                const selector = PromptlySupport.selector;
                const target = e.target;

                // Ignore widget elements and overlay
                if (target.closest('.promptly-support-fab') ||
                    target.closest('.promptly-support-chat') ||
                    target.classList.contains('promptly-support-selector-overlay') ||
                    target.classList.contains('promptly-support-selector-hint') ||
                    target.classList.contains('promptly-support-highlight')) {
                    return;
                }

                selector.hoveredElement = target;
                PromptlySupport.ui.showElementHighlight(target);
            },

            /**
             * Handle mouse out element
             */
            handleMouseOut: () => {
                PromptlySupport.selector.hoveredElement = null;
                PromptlySupport.ui.clearElementHighlight();
            },

            /**
             * Handle click on element
             */
            handleClick: (e) => {
                e.preventDefault();
                e.stopPropagation();

                const selector = PromptlySupport.selector;
                const target = e.target;

                // Ignore widget elements
                if (target.closest('.promptly-support-fab') ||
                    target.closest('.promptly-support-chat') ||
                    target.classList.contains('promptly-support-selector-overlay') ||
                    target.classList.contains('promptly-support-selector-hint')) {
                    return;
                }

                // Capture element
                selector.captureElement(target);
                selector.disable();

                // Reopen chat
                if (!PromptlySupport.state.isOpen) {
                    PromptlySupport.ui.toggleChat();
                }
            },

            /**
             * Handle keydown (Escape to cancel)
             */
            handleKeyDown: (e) => {
                if (e.key === 'Escape') {
                    PromptlySupport.selector.disable();

                    // Reopen chat if it was open
                    if (!PromptlySupport.state.isOpen) {
                        PromptlySupport.ui.toggleChat();
                    }
                }
            },

            /**
             * Capture element details
             */
            captureElement(element) {
                PromptlySupport.log('Capturing element:', element);

                const elementData = {
                    tagName: element.tagName.toLowerCase(),
                    id: element.id || null,
                    className: element.className || null,
                    textContent: element.textContent ? element.textContent.substring(0, 200) : null,
                    outerHTML: element.outerHTML ? element.outerHTML.substring(0, 500) : null,
                    attributes: this.getAttributes(element),
                    xpath: this.getXPath(element),
                    cssSelector: this.getCssSelector(element),
                    boundingBox: element.getBoundingClientRect().toJSON()
                };

                PromptlySupport.state.selectedElement = elementData;
                PromptlySupport.ui.showSelectedElementBadge(element);
                PromptlySupport.ui.showElementHighlight(element);

                PromptlySupport.log('Element captured:', elementData);
            },

            /**
             * Clear selected element
             */
            clearSelection() {
                PromptlySupport.state.selectedElement = null;
                PromptlySupport.ui.clearSelectedElementBadge();
                PromptlySupport.ui.clearElementHighlight();
                PromptlySupport.log('Element selection cleared');
            },

            /**
             * Get element attributes
             */
            getAttributes(element) {
                const attrs = {};
                for (let i = 0; i < element.attributes.length; i++) {
                    const attr = element.attributes[i];
                    attrs[attr.name] = attr.value;
                }
                return attrs;
            },

            /**
             * Generate XPath for element
             */
            getXPath(element) {
                if (element.id) {
                    return `//*[@id="${element.id}"]`;
                }

                const parts = [];
                while (element && element.nodeType === Node.ELEMENT_NODE) {
                    let index = 1;
                    let sibling = element.previousSibling;

                    while (sibling) {
                        if (sibling.nodeType === Node.ELEMENT_NODE && sibling.tagName === element.tagName) {
                            index++;
                        }
                        sibling = sibling.previousSibling;
                    }

                    const tagName = element.tagName.toLowerCase();
                    const pathPart = `${tagName}[${index}]`;
                    parts.unshift(pathPart);

                    element = element.parentNode;
                }

                return '/' + parts.join('/');
            },

            /**
             * Generate precise CSS selector for element
             */
            getCssSelector(element) {
                // If element has unique ID, use it
                if (element.id) {
                    return `#${element.id}`;
                }

                const path = [];
                let current = element;

                while (current && current.nodeType === Node.ELEMENT_NODE && current.tagName.toLowerCase() !== 'html') {
                    let selector = current.tagName.toLowerCase();

                    // Add classes if present
                    if (current.className && typeof current.className === 'string') {
                        const classes = current.className.trim().split(/\s+/).filter(c => c);
                        if (classes.length > 0) {
                            selector += '.' + classes.join('.');
                        }
                    }

                    // Add nth-child if needed for uniqueness
                    if (current.parentNode) {
                        const siblings = Array.from(current.parentNode.children);
                        const sameTagSiblings = siblings.filter(el => el.tagName === current.tagName);

                        if (sameTagSiblings.length > 1) {
                            const index = sameTagSiblings.indexOf(current) + 1;
                            selector += `:nth-child(${index})`;
                        }
                    }

                    path.unshift(selector);
                    current = current.parentNode;

                    // Stop at body or after 5 levels for reasonable selector length
                    if (current && current.tagName.toLowerCase() === 'body' || path.length >= 5) {
                        break;
                    }
                }

                return path.join(' > ');
            }
        },

        // Context Capture module
        capture: {
            /**
             * Capture comprehensive page context
             */
            async capturePageContext() {
                PromptlySupport.log('Capturing page context...');

                const context = {
                    url: window.location.href,
                    title: document.title,
                    viewport: {
                        width: window.innerWidth,
                        height: window.innerHeight,
                        scrollX: window.scrollX,
                        scrollY: window.scrollY
                    },
                    userAgent: navigator.userAgent,
                    timestamp: new Date().toISOString()
                };

                // Add selected element if available
                if (PromptlySupport.state.selectedElement) {
                    context.selectedElement = PromptlySupport.state.selectedElement;
                }

                PromptlySupport.log('Page context captured:', context);
                return context;
            }
        },

        // Bug Report module
        bugReport: {
            // SVG icons for toggle button
            bugIcon: `<svg viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                <path d="M304 280h416c4.4 0 8-3.6 8-8 0-40-8.8-76.7-25.9-108.1-17.2-31.5-42.5-56.8-74-74C596.7 72.8 560 64 520 64h-16c-40 0-76.7 8.8-108.1 25.9-31.5 17.2-56.8 42.5-74 74C304.8 195.3 296 232 296 272c0 4.4 3.6 8 8 8z"></path>
                <path d="M940 512H792V412c76.8 0 139-62.2 139-139 0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8 0 34.8-28.2 63-63 63H232c-34.8 0-63-28.2-63-63 0-4.4-3.6-8-8-8h-60c-4.4 0-8 3.6-8 8 0 76.8 62.2 139 139 139v100H84c-4.4 0-8 3.6-8 8v56c0 4.4 3.6 8 8 8h148v96c0 6.5.2 13 .7 19.3C164.1 728.6 116 796.7 116 876c0 4.4 3.6 8 8 8h56c4.4 0 8-3.6 8-8 0-44.2 23.9-82.9 59.6-103.7 6 17.2 13.6 33.6 22.7 49 24.3 41.5 59 76.2 100.5 100.5 28.9 16.9 61 28.8 95.3 34.5 4.4 0 8-3.6 8-8V484c0-4.4 3.6-8 8-8h60c4.4 0 8 3.6 8 8v464.2c0 4.4 3.6 8 8 8 34.3-5.7 66.4-17.6 95.3-34.5 41.5-24.3 76.2-59 100.5-100.5 9.1-15.5 16.7-31.9 22.7-49C812.1 793.1 836 831.8 836 876c0 4.4 3.6 8 8 8h56c4.4 0 8-3.6 8-8 0-79.3-48.1-147.4-116.7-176.7.4-6.4.7-12.8.7-19.3v-96h148c4.4 0 8-3.6 8-8v-56c0-4.4-3.6-8-8-8z"></path>
            </svg>`,

            chatIcon: `<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M15 9L11 13L9 11M7.12357 18.7012L5.59961 19.9203C4.76744 20.5861 4.35115 20.9191 4.00098 20.9195C3.69644 20.9198 3.40845 20.7813 3.21846 20.5433C3 20.2696 3 19.7369 3 18.6712V7.2002C3 6.08009 3 5.51962 3.21799 5.0918C3.40973 4.71547 3.71547 4.40973 4.0918 4.21799C4.51962 4 5.08009 4 6.2002 4H17.8002C18.9203 4 19.4801 4 19.9079 4.21799C20.2842 4.40973 20.5905 4.71547 20.7822 5.0918C21 5.5192 21 6.07899 21 7.19691V14.8036C21 15.9215 21 16.4805 20.7822 16.9079C20.5905 17.2842 20.2843 17.5905 19.908 17.7822C19.4806 18 18.9215 18 17.8036 18H9.12256C8.70652 18 8.49829 18 8.29932 18.0408C8.12279 18.0771 7.95216 18.1368 7.79168 18.2188C7.61149 18.3108 7.44964 18.4403 7.12722 18.6982L7.12357 18.7012Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
            </svg>`,

            /**
             * Toggle bug report mode
             */
            toggleMode() {
                PromptlySupport.state.bugReportMode = !PromptlySupport.state.bugReportMode;

                if (PromptlySupport.state.bugReportMode) {
                    this.showForm();
                } else {
                    this.hideForm();
                }

                // Update button icon and tooltip
                this.updateToggleButton();
            },

            /**
             * Update toggle button icon based on current mode
             */
            updateToggleButton() {
                const btn = PromptlySupport.ui.elements.bugReportBtn;
                if (PromptlySupport.state.bugReportMode) {
                    // In bug report mode - show chat icon to go back
                    btn.innerHTML = this.chatIcon;
                    btn.title = 'Back to Chat';
                    btn.setAttribute('aria-label', 'Back to chat');
                } else {
                    // In chat mode - show bug icon to report
                    btn.innerHTML = this.bugIcon;
                    btn.title = 'Report Bug';
                    btn.setAttribute('aria-label', 'Report bug');
                }
            },

            /**
             * Show bug report form
             */
            showForm() {
                PromptlySupport.log('Showing bug report form');

                // Hide chat messages, show form
                PromptlySupport.ui.elements.messages.style.display = 'none';
                PromptlySupport.ui.elements.bugReportForm.style.display = 'flex';
                PromptlySupport.ui.elements.inputArea.style.display = 'none';

                // Update header title
                PromptlySupport.ui.elements.headerTitle.textContent = 'Report a Bug';

                // Render form
                this.renderForm();

                // Capture initial context
                this.captureContext();
            },

            /**
             * Hide bug report form
             */
            hideForm() {
                PromptlySupport.log('Hiding bug report form');

                // Show chat messages, hide form
                PromptlySupport.ui.elements.messages.style.display = 'flex';
                PromptlySupport.ui.elements.bugReportForm.style.display = 'none';
                PromptlySupport.ui.elements.inputArea.style.display = 'block';

                // Reset header title
                PromptlySupport.ui.elements.headerTitle.textContent = PromptlySupport.config.widgetTitle;

                // Clear form data
                PromptlySupport.state.bugReportData = {
                    title: '',
                    description: '',
                    stepsToReproduce: '',
                    expectedBehavior: '',
                    screenshot: null,
                    consoleLogs: null,
                };
            },

            /**
             * Render bug report form
             */
            renderForm() {
                const data = PromptlySupport.state.bugReportData;

                PromptlySupport.ui.elements.bugReportForm.innerHTML = `
                    <div class="promptly-support-form-content">
                        <h3 style="margin-top: 0; font-size: 16px; font-weight: 600;">Tell us what went wrong</h3>

                        <div class="promptly-support-form-group">
                            <label class="promptly-support-form-label" for="bug-title">Bug Title *</label>
                            <input
                                type="text"
                                id="bug-title"
                                class="promptly-support-form-input"
                                placeholder="Brief description of the issue"
                                value="${PromptlySupport.ui.escapeHtml(data.title)}"
                            />
                        </div>

                        <div class="promptly-support-form-group">
                            <label class="promptly-support-form-label" for="bug-description">Description *</label>
                            <textarea
                                id="bug-description"
                                class="promptly-support-form-textarea"
                                placeholder="What happened? What did you expect to happen?"
                                rows="4"
                            >${PromptlySupport.ui.escapeHtml(data.description)}</textarea>
                            <div class="promptly-support-form-help">Describe the problem in detail</div>
                        </div>

                        <div class="promptly-support-form-group">
                            <label class="promptly-support-form-label" for="bug-steps">Steps to Reproduce</label>
                            <textarea
                                id="bug-steps"
                                class="promptly-support-form-textarea"
                                placeholder="1. Go to...&#10;2. Click on...&#10;3. Notice that..."
                                rows="4"
                            >${PromptlySupport.ui.escapeHtml(data.stepsToReproduce)}</textarea>
                            <div class="promptly-support-form-help">Help us reproduce the issue</div>
                        </div>

                        <div class="promptly-support-form-group">
                            <label class="promptly-support-form-label" for="bug-expected">Expected Behavior</label>
                            <textarea
                                id="bug-expected"
                                class="promptly-support-form-textarea"
                                placeholder="What did you expect to happen?"
                                rows="3"
                            >${PromptlySupport.ui.escapeHtml(data.expectedBehavior)}</textarea>
                        </div>
                    </div>

                    <div class="promptly-support-bug-report-input-area">
                        <div class="promptly-support-bug-report-controls">
                            <button class="promptly-support-select-btn" id="bug-select-element">
                                🎯 Select Element
                            </button>
                            <button class="promptly-support-select-btn" id="bug-attach-file">
                                📎 File
                            </button>
                            <button class="promptly-support-select-btn" id="bug-capture-screenshot">
                                📸 Screenshot
                            </button>
                        </div>
                        <input type="file" id="bug-file-input" style="display: none;" multiple accept="image/*,.pdf,.txt,.doc,.docx" />
                        <div id="bug-attached-files"></div>
                        <div class="promptly-support-form-actions">
                            <button class="promptly-support-form-btn promptly-support-form-btn-secondary" id="bug-cancel">
                                Cancel
                            </button>
                            <button class="promptly-support-form-btn promptly-support-form-btn-primary" id="bug-submit">
                                Submit Bug Report
                            </button>
                        </div>
                    </div>
                `;

                // Attach event listeners
                document.getElementById('bug-cancel').addEventListener('click', () => {
                    this.toggleMode();
                });

                document.getElementById('bug-submit').addEventListener('click', () => {
                    this.submitBugReport();
                });

                document.getElementById('bug-select-element').addEventListener('click', () => {
                    PromptlySupport.selector.enable();
                    PromptlySupport.ui.toggleChat(); // Close widget during selection
                });

                document.getElementById('bug-attach-file').addEventListener('click', () => {
                    document.getElementById('bug-file-input').click();
                });

                document.getElementById('bug-file-input').addEventListener('change', (e) => {
                    const files = Array.from(e.target.files);
                    files.forEach(file => PromptlySupport.ui.addFile(file));
                    e.target.value = ''; // Reset input
                    this.updateAttachedFilesUI();
                });

                document.getElementById('bug-capture-screenshot').addEventListener('click', async () => {
                    // Use global ScreenshotCapture (defined in bug-report-capture.js)
                    if (typeof window.ScreenshotCapture !== 'undefined') {
                        const screenshot = await window.ScreenshotCapture.capture();
                        if (screenshot) {
                            PromptlySupport.state.bugReportData.screenshot = screenshot;
                            document.getElementById('bug-capture-screenshot').textContent = '📸 Capture Screenshot (Captured)';
                        }
                    } else {
                        alert('Screenshot capture is not available. Please refresh the page.');
                    }
                });

                // Auto-save form data on input
                ['bug-title', 'bug-description', 'bug-steps', 'bug-expected'].forEach(id => {
                    document.getElementById(id)?.addEventListener('input', (e) => {
                        const field = id.replace('bug-', '');
                        const fieldMap = {
                            'title': 'title',
                            'description': 'description',
                            'steps': 'stepsToReproduce',
                            'expected': 'expectedBehavior',
                        };
                        PromptlySupport.state.bugReportData[fieldMap[field]] = e.target.value;
                    });
                });
            },

            /**
             * Update attached files UI in bug report form
             */
            updateAttachedFilesUI() {
                const container = document.getElementById('bug-attached-files');
                if (!container) return;

                if (PromptlySupport.state.selectedFiles.length === 0) {
                    container.innerHTML = '';
                    return;
                }

                const filesHtml = PromptlySupport.state.selectedFiles.map(fileData => {
                    const sizeKB = (fileData.size / 1024).toFixed(1);
                    return `
                        <span class="promptly-support-selected-badge">
                            <span>📎 <strong>${PromptlySupport.ui.escapeHtml(fileData.name)}</strong> (${sizeKB} KB)</span>
                            <button onclick="PromptlySupport.ui.removeFile('${fileData.id}'); PromptlySupport.bugReport.updateAttachedFilesUI();" aria-label="Remove file">✕</button>
                        </span>
                    `;
                }).join('');

                container.innerHTML = filesHtml;
            },

            /**
             * Capture context for bug report
             */
            async captureContext() {
                // Capture console logs
                if (window.consoleLogger) {
                    PromptlySupport.state.bugReportData.consoleLogs = window.consoleLogger.sanitize();
                }
            },

            /**
             * Submit bug report - show preview first
             */
            async submitBugReport() {
                const data = PromptlySupport.state.bugReportData;

                // Validate required fields
                if (!data.title?.trim()) {
                    alert('Please enter a bug title');
                    return;
                }

                if (!data.description?.trim()) {
                    alert('Please enter a bug description');
                    return;
                }

                // Show preview
                this.showPreview();
            },

            /**
             * Show bug report preview before submission
             */
            showPreview() {
                const data = PromptlySupport.state.bugReportData;

                // Build preview content
                let content = `<h3 style="margin-top: 0; font-size: 16px; font-weight: 600;">Review Bug Report</h3>`;
                content += `<p class="promptly-support-form-help" style="margin-bottom: 20px;">Please review all information before submitting to the AI agent.</p>`;

                content += `<div class="promptly-support-preview-box">`;
                content += `<h4 class="promptly-support-preview-heading">Bug Details</h4>`;
                content += `<div class="promptly-support-preview-item"><strong>Title:</strong> ${PromptlySupport.ui.escapeHtml(data.title)}</div>`;
                content += `<div class="promptly-support-preview-item"><strong>Description:</strong><br/>${PromptlySupport.ui.escapeHtml(data.description).replace(/\n/g, '<br/>')}</div>`;

                if (data.stepsToReproduce?.trim()) {
                    content += `<div class="promptly-support-preview-item"><strong>Steps:</strong><br/>${PromptlySupport.ui.escapeHtml(data.stepsToReproduce).replace(/\n/g, '<br/>')}</div>`;
                }

                if (data.expectedBehavior?.trim()) {
                    content += `<div class="promptly-support-preview-item" style="margin-bottom: 0;"><strong>Expected:</strong><br/>${PromptlySupport.ui.escapeHtml(data.expectedBehavior).replace(/\n/g, '<br/>')}</div>`;
                }
                content += `</div>`;

                // Metadata section
                content += `<div class="promptly-support-preview-box">`;
                content += `<h4 class="promptly-support-preview-heading">Automatically Collected Data</h4>`;
                content += `<div class="promptly-support-form-help">`;
                content += `<div style="margin-bottom: 4px;">📍 <strong>URL:</strong> ${window.location.href}</div>`;
                content += `<div style="margin-bottom: 4px;">🌐 <strong>Browser:</strong> ${navigator.userAgent.split(' ').pop()}</div>`;

                if (PromptlySupport.state.selectedElement) {
                    const el = PromptlySupport.state.selectedElement;
                    content += `<div style="margin-bottom: 4px;">🎯 <strong>Element:</strong> ${PromptlySupport.ui.escapeHtml(el.cssSelector || el.xpath)}</div>`;
                }

                if (data.screenshot) {
                    content += `<div style="margin-bottom: 4px;">📸 <strong>Screenshot:</strong> Captured</div>`;
                }

                if (PromptlySupport.state.selectedFiles.length > 0) {
                    content += `<div style="margin-bottom: 4px;">📎 <strong>Attached Files:</strong> ${PromptlySupport.state.selectedFiles.length} file(s)</div>`;
                }

                if (data.consoleLogs) {
                    const logLines = data.consoleLogs.split('\n').length;
                    content += `<div style="margin-bottom: 4px;">📝 <strong>Console Logs:</strong> ${logLines} lines</div>`;
                }

                content += `</div></div>`;

                // Build full preview with proper structure
                let preview = `
                    <div class="promptly-support-form-content">
                        ${content}
                    </div>
                    <div class="promptly-support-bug-report-input-area">
                        <div class="promptly-support-form-actions">
                            <button class="promptly-support-form-btn promptly-support-form-btn-secondary" id="bug-preview-back">← Back to Edit</button>
                            <button class="promptly-support-form-btn promptly-support-form-btn-primary" id="bug-preview-submit">Submit to Agent →</button>
                        </div>
                    </div>
                `;

                // Show preview
                PromptlySupport.ui.elements.bugReportForm.innerHTML = preview;

                // Attach event listeners
                document.getElementById('bug-preview-back').addEventListener('click', () => {
                    this.renderForm(); // Go back to form
                });

                document.getElementById('bug-preview-submit').addEventListener('click', () => {
                    this.confirmSubmit(); // Actually submit
                });
            },

            /**
             * Confirm and submit bug report to AI agent
             */
            async confirmSubmit() {
                const data = PromptlySupport.state.bugReportData;

                // Build comprehensive bug report message
                let bugReportMessage = `I would like to report a bug:\n\n`;
                bugReportMessage += `**Title**: ${data.title}\n\n`;
                bugReportMessage += `**Description**:\n${data.description}\n\n`;

                if (data.stepsToReproduce?.trim()) {
                    bugReportMessage += `**Steps to Reproduce**:\n${data.stepsToReproduce}\n\n`;
                }

                if (data.expectedBehavior?.trim()) {
                    bugReportMessage += `**Expected Behavior**:\n${data.expectedBehavior}\n\n`;
                }

                bugReportMessage += `---\n\n`;
                bugReportMessage += `**Page**: ${window.location.href}\n`;
                bugReportMessage += `**Browser**: ${navigator.userAgent}\n`;

                if (PromptlySupport.state.selectedElement) {
                    bugReportMessage += `**Selected Element**: ${PromptlySupport.state.selectedElement.cssSelector || PromptlySupport.state.selectedElement.xpath}\n`;
                }

                if (data.consoleLogs) {
                    bugReportMessage += `\n**Console Logs**:\n\`\`\`\n${data.consoleLogs.substring(0, 2000)}\n\`\`\`\n`;
                }

                bugReportMessage += `\nPlease help me create a GitHub issue for this bug report. Ask me any clarifying questions if needed.`;

                // Clear any selected elements and files from previous chat interactions
                PromptlySupport.state.selectedElement = null;
                PromptlySupport.state.selectedFiles = [];
                PromptlySupport.ui.clearSelectedElementBadge();
                PromptlySupport.ui.updateAttachedFilesUI();

                // Add screenshot as attachment if available
                if (data.screenshot) {
                    try {
                        // Convert Blob to File with timestamp filename
                        const timestamp = new Date().getTime();
                        const screenshotFile = new File(
                            [data.screenshot],
                            `bug-report-screenshot-${timestamp}.png`,
                            { type: 'image/png' }
                        );

                        // Add to selected files for upload
                        PromptlySupport.state.selectedFiles.push({
                            id: `screenshot-${timestamp}`,
                            name: screenshotFile.name,
                            file: screenshotFile,
                            size: screenshotFile.size,
                            type: screenshotFile.type,
                        });

                        bugReportMessage += `\n\n**Screenshot**: Attached (${screenshotFile.name})\n`;

                        PromptlySupport.log('Screenshot added as attachment:', screenshotFile.name);
                    } catch (error) {
                        PromptlySupport.log('Error adding screenshot as attachment:', error);
                    }
                }

                // Switch back to chat mode
                this.hideForm();

                // Open widget if closed
                if (!PromptlySupport.state.isOpen) {
                    PromptlySupport.ui.toggleChat();
                }

                // Send bug report as message to agent
                // Don't manually add message - handleSend will do it
                PromptlySupport.ui.elements.input.value = bugReportMessage;

                // Trigger resize of textarea to fit content
                PromptlySupport.ui.elements.input.style.height = 'auto';
                PromptlySupport.ui.elements.input.style.height = PromptlySupport.ui.elements.input.scrollHeight + 'px';

                await PromptlySupport.ui.handleSend();
            },
        },

        // Session Management module
        session: {
            /**
             * Initialize session - restore from cookie if exists
             */
            async initialize() {
                PromptlySupport.log('Initializing session...');
                PromptlySupport.log('Cookie name:', PromptlySupport.config.sessionCookieName);
                PromptlySupport.log('All cookies:', document.cookie);

                // Check for existing session in cookie
                const existingSessionId = this.getCookie(PromptlySupport.config.sessionCookieName);

                if (existingSessionId) {
                    PromptlySupport.log('Found existing session cookie:', existingSessionId);
                    // Store session ID
                    PromptlySupport.state.chatSession = { id: parseInt(existingSessionId) };
                    PromptlySupport.log('Session state set:', PromptlySupport.state.chatSession);

                    // Load session history from API
                    await this.loadSessionHistory(parseInt(existingSessionId));
                } else {
                    PromptlySupport.log('No existing session - will be created on first message');
                }
            },

            /**
             * Load session history from API
             */
            async loadSessionHistory(sessionId) {
                try {
                    PromptlySupport.log('Loading session history:', sessionId);

                    // Build headers and credentials based on authentication mode
                    const headers = {
                        'Accept': 'application/json'
                    };

                    let credentials = 'omit';

                    if (PromptlySupport.config.authMode === 'session') {
                        // Dashboard mode - Sanctum uses session cookies
                        credentials = 'include';
                    } else {
                        // External widget mode - use Bearer token
                        headers['Authorization'] = `Bearer ${PromptlySupport.config.apiToken}`;
                    }

                    const response = await fetch(`${PromptlySupport.config.apiBaseUrl}/api/v1/chat/sessions/${sessionId}`, {
                        method: 'GET',
                        headers: headers,
                        credentials: credentials
                    });

                    if (!response.ok) {
                        PromptlySupport.log('Failed to load session history:', response.status);
                        return;
                    }

                    const data = await response.json();

                    if (data.success && data.interactions && data.interactions.length > 0) {
                        PromptlySupport.log('Session history loaded:', data.interactions.length, 'interactions');

                        // Clear welcome message
                        PromptlySupport.ui.elements.messages.innerHTML = '';
                        PromptlySupport.state.messages = [];

                        // Add historical interactions
                        data.interactions.forEach(interaction => {
                            if (interaction.question) {
                                // Extract just the user question, not the full enhanced context
                                const cleanQuestion = this.extractUserQuestion(interaction.question);
                                PromptlySupport.ui.addMessage('user', cleanQuestion);
                            }
                            if (interaction.answer) {
                                PromptlySupport.ui.addMessage('assistant', interaction.answer);
                            }
                        });

                        PromptlySupport.log('Session history restored successfully');
                    } else {
                        PromptlySupport.log('No history found for session');
                    }

                } catch (error) {
                    PromptlySupport.error('Failed to load session history:', error);
                    // Continue with empty session - not fatal
                }
            },

            /**
             * Extract user question from enhanced message format
             * Enhanced messages contain [PAGE CONTEXT], [SELECTED ELEMENT], and [USER QUESTION] sections
             * We only want to display the actual user question in the UI
             */
            extractUserQuestion(enhancedMessage) {
                // Since [USER QUESTION] is always the last section, extract everything after it
                const markerText = '[USER QUESTION]';
                const index = enhancedMessage.indexOf(markerText);

                if (index !== -1) {
                    // Get everything after [USER QUESTION]
                    const afterMarker = enhancedMessage.substring(index + markerText.length);
                    // Remove leading newlines and whitespace
                    const userQuestion = afterMarker.replace(/^[\r\n\s]+/, '').trim();
                    return userQuestion;
                }

                // Fallback: if no [USER QUESTION] section found, return the original message
                // This handles cases where messages were stored before the enhancement feature
                return enhancedMessage;
            },

            /**
             * Store session ID after it's created by backend
             */
            storeSessionId(sessionId) {
                PromptlySupport.log('Storing session ID:', sessionId);

                // Store in state
                PromptlySupport.state.chatSession = { id: parseInt(sessionId) };

                // Store in cookie
                this.setCookie(
                    PromptlySupport.config.sessionCookieName,
                    sessionId,
                    PromptlySupport.config.sessionCookieExpiry
                );

                PromptlySupport.log('Cookie set, all cookies now:', document.cookie);
            },

            /**
             * Clear session
             */
            async clearSession() {
                PromptlySupport.log('Clearing session...');

                // Delete cookie
                this.deleteCookie(PromptlySupport.config.sessionCookieName);

                // Clear state
                PromptlySupport.state.chatSession = null;
                PromptlySupport.state.messages = [];
                PromptlySupport.state.selectedElement = null;
                PromptlySupport.state.selectedFiles = [];

                // Clear UI
                PromptlySupport.ui.elements.messages.innerHTML = '';
                PromptlySupport.ui.clearSelectedElementBadge();
                PromptlySupport.ui.clearElementHighlight();
                PromptlySupport.ui.updateAttachedFilesUI();

                // Show welcome message
                PromptlySupport.ui.addMessage('assistant', PromptlySupport.config.welcomeMessage);

                PromptlySupport.log('Session cleared - new session will be created on next message');
            },

            /**
             * Get cookie value
             * Handles multiple cookies with same name by selecting the most specific domain match
             */
            getCookie(name) {
                const value = `; ${document.cookie}`;
                const parts = value.split(`; ${name}=`);
                if (parts.length >= 2) {
                    // Take the first occurrence (most specific domain)
                    // Browsers send cookies ordered by most-specific-domain-first
                    return parts[1].split(';').shift();
                }
                return null;
            },

            /**
             * Set cookie
             */
            setCookie(name, value, days) {
                const expires = new Date();
                expires.setTime(expires.getTime() + days * 24 * 60 * 60 * 1000);

                // RFC 6265 compliant: spaces after semicolons
                // No explicit domain - browser defaults to current hostname
                let cookieString = `${name}=${value}; expires=${expires.toUTCString()}; path=/; SameSite=Lax`;

                // Add Secure flag for HTTPS
                if (window.location.protocol === 'https:') {
                    cookieString += '; Secure';
                }

                document.cookie = cookieString;

                PromptlySupport.log('Setting cookie:', cookieString);
                PromptlySupport.log('Cookie set:', name, value);
                PromptlySupport.log('Expires:', expires.toUTCString());

                // Verify cookie was set
                setTimeout(() => {
                    const testRead = this.getCookie(name);
                    PromptlySupport.log('Cookie verification read:', testRead);
                    if (testRead !== value.toString()) {
                        PromptlySupport.error('Cookie was not set correctly!', 'Expected:', value, 'Got:', testRead);
                    }
                }, 100);
            },

            /**
             * Delete cookie
             */
            deleteCookie(name) {
                document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;`;
                PromptlySupport.log('Cookie deleted:', name);
            }
        },

        // API Client module
        api: {
            /**
             * Send message with SSE streaming
             *
             * Session is created automatically by backend if not provided
             */
            async sendMessage(message) {
                PromptlySupport.state.isStreaming = true;

                PromptlySupport.log('Sending message:', message);
                PromptlySupport.log('Current session state:', PromptlySupport.state.chatSession);

                // Capture context
                const context = await PromptlySupport.capture.capturePageContext();

                // Create FormData for multipart upload
                const formData = new FormData();
                formData.append('message', message);
                formData.append('agent_id', PromptlySupport.config.agentId);

                // Include session_id if we have one (optional)
                if (PromptlySupport.state.chatSession?.id) {
                    PromptlySupport.log('Including session_id in request:', PromptlySupport.state.chatSession.id);
                    formData.append('session_id', PromptlySupport.state.chatSession.id);
                } else {
                    PromptlySupport.log('No session_id - backend will create new session');
                }

                // Add context as metadata
                formData.append('context', JSON.stringify(context));

                // Add attached files
                if (PromptlySupport.state.selectedFiles.length > 0) {
                    PromptlySupport.log('Including', PromptlySupport.state.selectedFiles.length, 'attached files');
                    PromptlySupport.state.selectedFiles.forEach(fileData => {
                        formData.append('attachments', fileData.file, fileData.name);
                    });
                }

                // Add streaming message placeholder
                const streamingMessageId = PromptlySupport.ui.addMessage('assistant', '', true);
                let fullResponse = '';

                try {
                    // Build headers and credentials based on authentication mode
                    const headers = {
                        'Accept': 'text/event-stream'
                    };

                    let credentials = 'omit';

                    if (PromptlySupport.config.authMode === 'session') {
                        // Dashboard mode - Sanctum uses session cookies (no header needed)
                        credentials = 'include';  // Send session cookies
                    } else {
                        // External widget mode - use Bearer token
                        headers['Authorization'] = `Bearer ${PromptlySupport.config.apiToken}`;
                    }

                    const response = await fetch(`${PromptlySupport.config.apiBaseUrl}/api/v1/chat/stream`, {
                        method: 'POST',
                        headers: headers,
                        body: formData,
                        credentials: credentials
                    });

                    if (!response.ok) {
                        // Handle validation errors (422)
                        if (response.status === 422) {
                            const errorData = await response.json();

                            // Check for invalid session_id - this needs retry logic
                            if (errorData.errors?.session_id) {
                                PromptlySupport.log('Invalid session_id detected, clearing and retrying...');
                                // Clear invalid session
                                PromptlySupport.session.deleteCookie(PromptlySupport.config.sessionCookieName);
                                PromptlySupport.state.chatSession = null;

                                // Retry without session_id (backend will create new session)
                                const retryFormData = new FormData();
                                retryFormData.append('message', message);
                                retryFormData.append('agent_id', PromptlySupport.config.agentId);
                                retryFormData.append('context', JSON.stringify(context));

                                // Re-add attached files if any
                                if (PromptlySupport.state.selectedFiles.length > 0) {
                                    PromptlySupport.state.selectedFiles.forEach(fileData => {
                                        retryFormData.append('attachments', fileData.file, fileData.name);
                                    });
                                }

                                const retryResponse = await fetch(`${PromptlySupport.config.apiBaseUrl}/api/v1/chat/stream`, {
                                    method: 'POST',
                                    headers: headers,
                                    body: retryFormData,
                                    credentials: credentials
                                });

                                if (!retryResponse.ok) {
                                    throw new Error(`HTTP ${retryResponse.status}: ${retryResponse.statusText}`);
                                }

                                // Continue with retry response
                                return await this.processStreamResponse(retryResponse, streamingMessageId);
                            }

                            // Handle other validation errors (file upload, etc.)
                            // Extract user-friendly error message
                            let errorMessage = 'Validation error';
                            if (errorData.message) {
                                errorMessage = errorData.message;
                            } else if (errorData.errors) {
                                // Get first error from errors object
                                const firstError = Object.values(errorData.errors)[0];
                                if (Array.isArray(firstError) && firstError.length > 0) {
                                    errorMessage = firstError[0];
                                } else if (typeof firstError === 'string') {
                                    errorMessage = firstError;
                                }
                            }

                            throw new Error(errorMessage);
                        }

                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }

                    // Process successful response
                    await this.processStreamResponse(response);

                } catch (error) {
                    PromptlySupport.error('Streaming failed:', error);
                    throw error;
                } finally {
                    PromptlySupport.state.isStreaming = false;
                }
            },

            /**
             * Process SSE stream response
             */
            async processStreamResponse(response) {
                let fullResponse = '';

                // Read SSE stream (same pattern as PWA chat-stream.js)
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) {
                        PromptlySupport.log('Stream complete');
                        break;
                    }

                    // Decode the chunk
                    buffer += decoder.decode(value, { stream: true });

                    // Process complete SSE messages (separated by double newline)
                    const messages = buffer.split('\n\n');
                    buffer = messages.pop() || ''; // Keep incomplete message in buffer

                    for (const message of messages) {
                        if (!message.trim()) {
                            continue; // Skip empty messages
                        }

                        const lines = message.split('\n');
                        let eventType = 'message'; // Default event type
                        let eventData = null;

                        // Parse SSE message lines
                        for (const line of lines) {
                            if (line.startsWith('event: ')) {
                                eventType = line.substring(7).trim();
                            } else if (line.startsWith('data: ')) {
                                const dataStr = line.substring(6).trim();

                                // Skip non-JSON lines (like "</stream>")
                                if (!dataStr || !dataStr.startsWith('{')) {
                                    PromptlySupport.log('Skipping non-JSON SSE line:', dataStr);
                                    continue;
                                }

                                try {
                                    eventData = JSON.parse(dataStr);
                                } catch (error) {
                                    PromptlySupport.error('Error parsing SSE data:', error, line);
                                }
                            }
                        }

                        // Process the event if we have data
                        if (eventData) {
                            PromptlySupport.log('SSE Event:', eventType, eventData);

                            // Check for session_id in ANY event
                            if (eventData.session_id && !PromptlySupport.state.chatSession) {
                                PromptlySupport.session.storeSessionId(eventData.session_id);
                            }

                            // Handle different event types
                            switch (eventData.type) {
                                case 'session':
                                    if (eventData.session_id) {
                                        PromptlySupport.session.storeSessionId(eventData.session_id);
                                    }
                                    break;

                                case 'answer_stream':
                                case 'content':
                                    if (eventData.content) {
                                        fullResponse = eventData.content;
                                        PromptlySupport.ui.updateStreamingMessage(fullResponse);
                                    }
                                    break;

                                case 'interaction_updated':
                                    if (eventData.data?.answer) {
                                        fullResponse = eventData.data.answer;
                                        PromptlySupport.ui.updateStreamingMessage(fullResponse);
                                    }
                                    break;

                                case 'keepalive':
                                    PromptlySupport.log('Keepalive received');
                                    break;

                                case 'sessionInitStep':
                                case 'step_added':
                                case 'step_updated':
                                case 'research_step':
                                    const statusMessage = eventData.message || eventData.content || eventData.status || eventData.data?.message;
                                    if (statusMessage) {
                                        PromptlySupport.ui.showStatus(statusMessage);
                                    }
                                    break;

                                case 'complete':
                                    PromptlySupport.log('Streaming completed');
                                    break;

                                case 'error':
                                    const errorMsg = eventData.content || eventData.message || 'An error occurred';
                                    fullResponse = errorMsg;
                                    PromptlySupport.ui.updateStreamingMessage(fullResponse);
                                    break;

                                default:
                                    if (eventData.content) {
                                        if (eventData.content.length > 50 || eventData.type === 'research_complete') {
                                            fullResponse = eventData.content;
                                            PromptlySupport.ui.updateStreamingMessage(fullResponse);
                                        } else {
                                            PromptlySupport.ui.showStatus(eventData.content);
                                        }
                                    }
                            }
                        }
                    }
                }

                // Finalize message
                PromptlySupport.ui.finalizeStreamingMessage();
            }
        },

        // Utility methods
        log(...args) {
            if (this.config.debug) {
                console.log('[PromptlySupport]', ...args);
            }
        },

        error(...args) {
            console.error('[PromptlySupport]', ...args);
        },

        /**
         * Initialize widget
         */
        init(config = {}) {
            // Merge configuration
            Object.assign(this.config, config);

            // Auto-detect authentication mode
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta && csrfMeta.content) {
                // Dashboard mode - user is already authenticated via Laravel session
                // Sanctum will use session cookies automatically
                this.config.authMode = 'session';
                this.log('Authentication mode: session (dashboard user)');
            } else if (this.config.apiToken) {
                // External widget mode - use API token
                this.config.authMode = 'token';
                this.log('Authentication mode: token (external widget)');
            } else {
                throw new Error('PromptlySupport: No authentication available (missing CSRF meta tag or apiToken)');
            }

            // Validate required configuration
            if (!this.config.apiBaseUrl) {
                throw new Error('PromptlySupport: apiBaseUrl is required');
            }
            if (!this.config.agentId) {
                throw new Error('PromptlySupport: agentId is required');
            }

            this.log('Initializing PromptlySupport widget...');

            // Initialize marked.js if available (same configuration as PWA)
            this.initializeMarked();

            // Initialize UI
            this.ui.init();

            // Initialize session
            this.session.initialize().catch((error) => {
                this.error('Failed to initialize session:', error);
                this.ui.addMessage('assistant', 'Failed to initialize chat session. Please refresh the page.');
            });

            this.log('PromptlySupport widget initialized successfully');
        },

        /**
         * Initialize marked.js markdown parser
         */
        initializeMarked() {
            if (!window.marked) {
                this.error('marked.js not loaded - markdown rendering will use fallback! Make sure marked.js is loaded before promptly-support.js');
                console.warn('PromptlySupport: Add <script src="https://cdn.jsdelivr.net/npm/marked@16.1.0/lib/marked.umd.min.js"></script> before the widget script');
                return;
            }

            if (typeof window.marked.parse !== 'function') {
                this.error('marked.parse is not a function - wrong version of marked.js?');
                return;
            }

            // Configure marked.js (same as PWA configuration)
            window.marked.setOptions({
                gfm: true,           // GitHub Flavored Markdown
                breaks: true,        // Convert \n to <br>
                headerIds: true,     // Add IDs to headings
                mangle: false,       // Don't mangle email addresses
                pedantic: false,     // Don't be overly strict
            });

            this.log('marked.js v' + (window.marked.version || 'unknown') + ' configured for markdown rendering');
        }
    };

    // Export to global scope
    window.PromptlySupport = PromptlySupport;

    // Auto-initialize if config is provided in data attribute
    // Wait for DOMContentLoaded AND window.load to ensure CDN scripts are loaded
    const autoInit = () => {
        const scriptTag = document.currentScript || document.querySelector('script[data-promptly-config]');
        if (scriptTag && scriptTag.dataset.promptlyConfig) {
            try {
                const config = JSON.parse(scriptTag.dataset.promptlyConfig);
                PromptlySupport.init(config);
            } catch (error) {
                console.error('PromptlySupport: Failed to parse config from data attribute:', error);
            }
        }
    };

    // Try DOMContentLoaded first (if not already fired)
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoInit);
    } else {
        // DOM already loaded, init immediately
        autoInit();
    }

})();

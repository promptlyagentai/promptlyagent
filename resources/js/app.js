/**
 * Main Application Bootstrap
 *
 * This file serves as the entry point for the PromptlyAgent frontend application.
 * It initializes and configures all core JavaScript dependencies including:
 * - Laravel Echo for WebSocket real-time communication
 * - Status stream manager for agent execution updates
 * - PWA service worker registration
 * - Syntax highlighting via Highlight.js
 * - Markdown rendering via Marked.js
 * - PWA services for offline functionality
 *
 * Architecture:
 * - Echo/Reverb: Real-time bidirectional communication with Laravel backend
 * - Status Stream: WebSocket-based status updates for chat interactions
 * - Highlight.js: Code syntax highlighting with 30+ language support
 * - Marked.js: GitHub Flavored Markdown parsing
 * - PWA Services: Offline-capable IndexedDB-backed data management
 *
 * Global Exports:
 * - window.hljs: Syntax highlighter
 * - window.marked: Markdown parser
 * - window.markdownEditor: Alpine.js markdown editor component
 * - window.PWA: Collection of PWA service classes
 *
 * @module app
 */

import './echo';
import './status-stream';
import './pwa/sw-register';

// Import Highlight.js for robust code highlighting with better Livewire compatibility
import hljs from 'highlight.js';

// Import language support
import javascript from 'highlight.js/lib/languages/javascript';
import typescript from 'highlight.js/lib/languages/typescript';
import python from 'highlight.js/lib/languages/python';
import php from 'highlight.js/lib/languages/php';
import css from 'highlight.js/lib/languages/css';
import scss from 'highlight.js/lib/languages/scss';
import json from 'highlight.js/lib/languages/json';
import bash from 'highlight.js/lib/languages/bash';
import sql from 'highlight.js/lib/languages/sql';
import yaml from 'highlight.js/lib/languages/yaml';
import markdown from 'highlight.js/lib/languages/markdown';
import xml from 'highlight.js/lib/languages/xml'; // for HTML/XML
import java from 'highlight.js/lib/languages/java';
import c from 'highlight.js/lib/languages/c';
import cpp from 'highlight.js/lib/languages/cpp';
import csharp from 'highlight.js/lib/languages/csharp';
import go from 'highlight.js/lib/languages/go';
import rust from 'highlight.js/lib/languages/rust';
import ruby from 'highlight.js/lib/languages/ruby';
import swift from 'highlight.js/lib/languages/swift';
import kotlin from 'highlight.js/lib/languages/kotlin';
import scala from 'highlight.js/lib/languages/scala';
import docker from 'highlight.js/lib/languages/dockerfile';
import nginx from 'highlight.js/lib/languages/nginx';
import ini from 'highlight.js/lib/languages/ini';
import powershell from 'highlight.js/lib/languages/powershell';
import diff from 'highlight.js/lib/languages/diff';

// Register languages
hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('typescript', typescript);
hljs.registerLanguage('python', python);
hljs.registerLanguage('php', php);
hljs.registerLanguage('css', css);
hljs.registerLanguage('scss', scss);
hljs.registerLanguage('json', json);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('sql', sql);
hljs.registerLanguage('yaml', yaml);
hljs.registerLanguage('markdown', markdown);
hljs.registerLanguage('xml', xml);
hljs.registerLanguage('html', xml);
hljs.registerLanguage('java', java);
hljs.registerLanguage('c', c);
hljs.registerLanguage('cpp', cpp);
hljs.registerLanguage('csharp', csharp);
hljs.registerLanguage('go', go);
hljs.registerLanguage('rust', rust);
hljs.registerLanguage('ruby', ruby);
hljs.registerLanguage('swift', swift);
hljs.registerLanguage('kotlin', kotlin);
hljs.registerLanguage('scala', scala);
hljs.registerLanguage('docker', docker);
hljs.registerLanguage('dockerfile', docker);
hljs.registerLanguage('nginx', nginx);
hljs.registerLanguage('ini', ini);
hljs.registerLanguage('toml', ini); // ini works for toml
hljs.registerLanguage('powershell', powershell);
hljs.registerLanguage('diff', diff);

// Import custom theme that matches our semantic theming system
import '../css/hljs-custom-theme.css';

// Make Highlight.js available globally
window.hljs = hljs;

// Configure Highlight.js
hljs.configure({
    ignoreUnescapedHTML: true,
    throwUnescapedHTML: false
});

import { Marked } from 'marked';
import { markedHighlight } from 'marked-highlight';
import { customRendererExtension } from './markdown-renderer.js';

const markedNoHighlight = new Marked();
markedNoHighlight.use(customRendererExtension);
markedNoHighlight.setOptions({
    breaks: true,
    gfm: true,
    pedantic: false,
    smartypants: true
});

const markedWithHighlight = new Marked(
  markedHighlight({
    emptyLangClass: 'hljs',
    langPrefix: 'hljs language-',
    highlight(code, lang, info) {
      const language = hljs.getLanguage(lang) ? lang : 'plaintext';
      return hljs.highlight(code, { language }).value;
    }
  })
);
markedWithHighlight.use(customRendererExtension);
markedWithHighlight.setOptions({
    breaks: true,
    gfm: true,
    pedantic: false,
    smartypants: true
});

window.marked = markedNoHighlight;
window.markedWithHighlight = markedWithHighlight;

// Import Markdown Editor component
import markdownEditor from './components/markdown-editor.js';

// Make Markdown editor available globally for Alpine.js
window.markdownEditor = markdownEditor;

// Import and expose PWA services globally
import { AuthService } from './pwa/auth';
import { SyncService } from './pwa/sync';
import { ChatStream } from './pwa/chat-stream';
import { FileHandler } from './pwa/file-handler';
import { VoiceInput } from './pwa/voice-input';
import { TextToSpeech } from './pwa/text-to-speech';
import { ChatExporter } from './pwa/export';
import { KnowledgeAPI } from './pwa/knowledge-api';
import { AgentAPI } from './pwa/agent-api';
import { SessionAPI } from './pwa/session-api';
import { db, getDB, initDB } from './pwa/db';

/**
 * PWA Services Global Export
 *
 * Exposes all PWA service classes globally for use in Alpine.js components
 * and other parts of the application. These services provide offline-first
 * functionality with IndexedDB caching.
 *
 * Available Services:
 * - AuthService: API token management and authentication
 * - SyncService: Background data synchronization
 * - ChatStream: Real-time SSE chat streaming
 * - FileHandler: File uploads and camera capture
 * - VoiceInput: Speech-to-text recognition
 * - TextToSpeech: Text-to-speech synthesis
 * - ChatExporter: Export chats as Markdown/JSON
 * - KnowledgeAPI: Knowledge base search and caching
 * - AgentAPI: Agent management and configuration
 * - SessionAPI: Chat session management
 * - db: IndexedDB helper utilities
 *
 * @global
 * @namespace window.PWA
 */
window.PWA = {
    AuthService,
    SyncService,
    ChatStream,
    FileHandler,
    VoiceInput,
    TextToSpeech,
    ChatExporter,
    KnowledgeAPI,
    AgentAPI,
    SessionAPI,
    db,
    getDB,
    initDB
};

console.log('PWA services loaded and available:', window.PWA);

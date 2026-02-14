{{--
    PWA Chat Interface

    Mobile-optimized chat with offline support (IndexedDB), real-time streaming, and file attachments.
--}}
<x-layouts.pwa>
 <x-slot name="title">Chat</x-slot>

 <div class="flex flex-col h-full" x-data="chatInterface(@js($sessionId))">
 <div class="flex-shrink-0 bg-surface px-4 py-2" style="border-bottom: 1px solid var(--palette-primary-800);">
 <div class="flex items-center justify-between">
 <div class="flex-1 min-w-0">
 <h1 class="text-lg font-semibold truncate" x-text="currentSession?.title || 'New Chat'"></h1>
 <div class="flex items-center gap-2 text-xs text-tertiary">
 <span x-show="selectedAgent" x-text="selectedAgent?.name"></span>
 <span x-show="selectedAgent && currentSession">•</span>
 <span x-show="currentSession" x-text="`${interactions.length} messages`"></span>
 </div>
 </div>

 <div class="flex items-center space-x-2">
 <button @click="showAgentSelector = true" class="p-2 hover:bg-surface rounded-lg">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
 </svg>
 </button>

 <button @click="showSessions = true" class="p-2 hover:bg-surface rounded-lg">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
 </svg>
 </button>

 <button @click="showShareModal = true" :disabled="!currentSession" class="p-2 hover:bg-surface rounded-lg disabled:opacity-50" :class="currentSession?.is_public ? 'text-accent' : ''">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
 </svg>
 </button>

 <button @click="downloadSession()" :disabled="!currentSession" class="p-2 hover:bg-surface rounded-lg disabled:opacity-50">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
 </svg>
 </button>
 </div>
 </div>
 </div>

 <div class="flex-1 overflow-y-auto px-4 py-4 space-y-4" x-ref="messagesContainer">
 <template x-if="interactions.length === 0">
 <div class="text-center py-12">
 <div class="inline-flex items-center justify-center w-16 h-16 bg-accent/20 rounded-full mb-4">
 <svg class="w-8 h-8 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
 </svg>
 </div>
 <h2 class="text-xl font-semibold mb-2">Start a conversation</h2>
 <p class="text-tertiary ">Ask me anything!</p>
 </div>
 </template>

 <template x-for="(interaction, index) in interactions" :key="interaction.id || index">
 <div>
 <div class="flex justify-end mb-2">
 <div class="max-w-[80%] bg-accent text-white rounded-2xl rounded-tr-sm px-4 py-2">
 <p class="text-sm whitespace-pre-wrap" x-text="interaction.question"></p>
 </div>
 </div>

 <div class="flex justify-start" x-show="interaction.answer || interaction.streaming || interaction.status">
 <div class="max-w-[80%] bg-surface rounded-2xl rounded-tl-sm px-4 py-2">
 <div x-show="interaction.streaming && !interaction.answer && !interaction.status" class="flex items-center space-x-2">
 <div class="w-2 h-2 bg-tertiary rounded-full animate-bounce"></div>
 <div class="w-2 h-2 bg-tertiary rounded-full animate-bounce" style="animation-delay: 0.1s"></div>
 <div class="w-2 h-2 bg-tertiary rounded-full animate-bounce" style="animation-delay: 0.2s"></div>
 </div>

 <!-- Status updates (for research agents) -->
 <div x-show="interaction.status && !interaction.answer" class="flex items-start space-x-2 text-sm text-tertiary ">
 <svg class="w-4 h-4 flex-shrink-0 animate-spin mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
 </svg>
 <span x-text="interaction.status"></span>
 </div>

 <div x-show="interaction.answer" class="markdown" x-html="renderMarkdown(interaction.answer)"></div>

 <!-- Action buttons for each answer -->
 <div x-show="interaction.answer" class="flex gap-2 mt-2 opacity-75">
 <!-- Retry Button -->
 <button @click="retryInteraction(interaction)"
 class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-surface rounded-lg transition-colors"
 title="Retry this question">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
 </svg>
 </button>

 <!-- Copy Answer Button -->
 <button @click="copyAnswer(interaction)"
 :disabled="interaction.id > 1000000000000"
 class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-surface rounded-lg transition-colors disabled:opacity-50"
 :title="interaction.id > 1000000000000 ? 'Preparing...' : 'Copy answer'">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
 </svg>
 </button>

 <!-- Create Artifact Button -->
 <button @click="createArtifact(interaction)"
 :disabled="interaction.creatingArtifact || interaction.id > 1000000000000"
 class="p-2 text-gray-500 hover:text-accent dark:text-gray-400 hover:bg-surface rounded-lg transition-colors disabled:opacity-50"
 :title="interaction.id > 1000000000000 ? 'Preparing...' : 'Create artifact'">
 <!-- Icon (shown when not loading) -->
 <svg x-show="!interaction.creatingArtifact" class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
 </svg>
 <!-- Loading spinner -->
 <svg x-show="interaction.creatingArtifact" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.984l2-2.693z"></path>
 </svg>
 </button>

 <!-- Export Button -->
 <button @click="exportInteraction(interaction)"
 :disabled="interaction.id > 1000000000000"
 class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-surface rounded-lg transition-colors disabled:opacity-50"
 :title="interaction.id > 1000000000000 ? 'Preparing...' : 'Export as Markdown'">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
 </svg>
 </button>
 </div>

 <!-- Artifact Chips - show if interaction has artifacts -->
 <template x-if="interaction.artifacts && interaction.artifacts.length > 0">
 <div class="mt-3 space-y-2">
 <template x-for="(artifact, artifactIndex) in interaction.artifacts" :key="'artifact-' + interaction.id + '-' + (typeof artifact.id === 'number' ? artifact.id : artifactIndex)">
 <div class="flex items-center gap-2 px-3 py-2 bg-surface border border-default rounded-lg hover:shadow-md hover:border-accent transition-all duration-200 max-w-[200px]">
 <!-- File Type Icon -->
 <div class="flex-shrink-0">
 <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
 </svg>
 </div>

 <!-- Title (Clickable for preview) -->
 <button
 @click="openArtifactDrawer(artifact)"
 class="flex-1 text-left text-sm font-medium text-primary hover:text-accent truncate transition-colors min-w-0"
 :title="artifact.title || 'Untitled Artifact'"
 x-text="artifact.title || 'Untitled Artifact'">
 </button>

 <!-- File Type Badge -->
 <span x-show="artifact.filetype" class="text-xs px-1.5 py-0.5 bg-accent/20 text-accent rounded font-medium flex-shrink-0" x-text="artifact.filetype ? artifact.filetype.toUpperCase() : ''"></span>
 </div>
 </template>
 </div>
 </template>
 </div>
 </div>
 </div>
 </template>
 </div>

 <div class="flex-shrink-0 bg-surface px-4 pt-3 pb-2" style="border-top: 1px solid var(--palette-primary-800);">
 <div x-show="selectedFiles.length > 0" class="mb-2 flex flex-wrap gap-2">
 <template x-for="(file, index) in selectedFiles" :key="index">
 <div class="relative inline-flex items-center bg-surface-elevated rounded-lg px-3 py-2">
 <span class="text-sm truncate max-w-[150px]" x-text="file.name"></span>
 <button @click="removeFile(index)" class="ml-2 text-tertiary hover:text-secondary">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
 </svg>
 </button>
 </div>
 </template>
 </div>

 <div class="flex items-end space-x-2">
 <button @click="$refs.fileInput.click()" class="p-2 hover:bg-surface rounded-lg flex-shrink-0">
 <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
 </svg>
 </button>
 <input type="file" x-ref="fileInput" @change="handleFileSelect($event)" multiple class="hidden">

 <button @click="capturePhoto()" class="p-2 hover:bg-surface rounded-lg flex-shrink-0">
 <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
 </svg>
 </button>

 <textarea x-ref="messageInput" x-model="message" @keydown.enter.prevent="if (!$event.shiftKey) sendMessage()" placeholder="Type a message..." rows="1" class="flex-1 px-4 py-2 bg-surface border border-default rounded-lg focus:ring-2 focus:ring-accent focus:border-transparent resize-none max-h-32" :disabled="sending || !isOnline"></textarea>

 <!-- Voice Input Button -->
 <button x-show="voiceInputSupported" @click="toggleVoiceInput()" :disabled="sending || !isOnline || voiceInputCooldown" class="p-2 hover:bg-surface rounded-lg flex-shrink-0 transition-colors disabled:opacity-50 disabled:cursor-not-allowed" :class="{ 'text-red-500 animate-pulse': isListening, 'text-gray-500': !isListening }" :title="voiceInputCooldown ? 'Please wait...' : (isListening ? 'Stop listening' : 'Voice input')">
 <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/>
 </svg>
 </button>

 <button @click="sendMessage()" :disabled="!message.trim() && selectedFiles.length === 0 || sending || !isOnline" class="p-2 bg-accent hover:bg-accent disabled:bg-accent/50 disabled:opacity-50 text-white rounded-lg flex-shrink-0">
 <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
 </svg>
 </button>
 </div>
 </div>

 <div x-show="showAgentSelector" @click.self="showAgentSelector = false" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-end">
 <div class="bg-surface w-full rounded-t-2xl max-h-[80vh] overflow-y-auto">
 <div class="sticky top-0 bg-surface border-b border-default px-4 py-3 flex items-center justify-between">
 <h2 class="text-lg font-semibold">Select Agent</h2>
 <button @click="showAgentSelector = false" class="p-2 hover:bg-surface rounded-lg">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
 </svg>
 </button>
 </div>

 <div class="p-4">
 <div x-show="agents.length === 0" class="text-center py-8">
 <svg class="w-12 h-12 text-tertiary mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
 </svg>
 <h3 class="font-semibold mb-2">No Agents Available</h3>
 <p class="text-sm text-tertiary mb-4">
 Make sure your API token has the <code class="px-1 py-0.5 bg-surface rounded text-xs">agent:view</code> ability.
 </p>
 <a href="/pwa/settings" class="inline-block px-4 py-2 bg-accent hover:bg-accent text-white rounded-lg text-sm font-medium">
 Go to Settings
 </a>
 </div>

 <div x-show="agents.length > 0" class="space-y-2">
 <template x-for="agent in agents" :key="agent.id">
 <button @click="selectAgent(agent)" class="w-full text-left p-4 rounded-lg border border-default hover:border-accent flex items-start gap-3" :class="{'border-accent bg-accent/10/20': selectedAgent?.id === agent.id}">
 <svg x-show="selectedAgent?.id === agent.id" class="w-5 h-5 text-accent flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
 </svg>
 <div class="flex-1 min-w-0">
 <h3 class="font-semibold" x-text="agent.name"></h3>
 <p class="text-sm text-tertiary mt-1" x-text="agent.description"></p>
 </div>
 </button>
 </template>
 </div>
 </div>
 </div>
 </div>

 <div x-show="showShareModal" @click.self="showShareModal = false" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-end">
 <div class="bg-surface w-full rounded-t-2xl max-h-[80vh] overflow-y-auto">
 <div class="sticky top-0 bg-surface border-b border-default px-4 py-3 flex items-center justify-between">
 <h2 class="text-lg font-semibold">Share Session</h2>
 <button @click="showShareModal = false" class="p-2 hover:bg-surface rounded-lg">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
 </svg>
 </button>
 </div>

 <div class="p-4 space-y-6">
 <div class="flex items-center justify-between">
 <div>
 <h4 class="font-medium">Public Sharing</h4>
 <p class="text-sm text-tertiary mt-1">
 <span x-text="currentSession?.is_public ? 'Anyone with the link can view this session' : 'Only you can view this session'"></span>
 </p>
 </div>
 <button @click="toggleShare()"
  class="relative inline-flex items-center h-8 w-14 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
  :class="currentSession?.is_public ? 'bg-accent' : 'bg-zinc-300 dark:bg-zinc-600'">
 <span class="inline-block h-6 w-6 transform rounded-full bg-white transition-transform"
  :class="currentSession?.is_public ? 'translate-x-7' : 'translate-x-1'">
 </span>
 </button>
 </div>

 <div x-show="currentSession?.is_public" class="space-y-2">
 <label class="block text-sm font-medium text-tertiary">Shareable Link</label>
 <div class="flex items-center gap-2">
 <input type="text"
  :value="shareUrl || (currentSession?.uuid ? window.location.origin + '/public/sessions/' + currentSession.uuid : '')"
  readonly
  class="flex-1 px-3 py-2 bg-surface-elevated border border-default rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent">
 <button @click="navigator.clipboard.writeText(shareUrl || (window.location.origin + '/public/sessions/' + currentSession.uuid)); $el.textContent = 'Copied!'; setTimeout(() => $el.textContent = 'Copy', 2000)"
  class="px-4 py-2 bg-accent hover:bg-accent text-white rounded-lg text-sm font-medium whitespace-nowrap transition-colors">
  Copy
 </button>
 </div>
 </div>
 </div>
 </div>
 </div>

 <div x-show="showSessions" @click.self="showSessions = false" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-end">
 <div class="bg-surface w-full rounded-t-2xl max-h-[80vh] flex flex-col">
 <div class="sticky top-0 bg-surface border-b border-default px-4 py-3">
 <div class="flex items-center justify-between mb-3">
 <h2 class="text-lg font-semibold">Chat Sessions</h2>
 <button @click="showSessions = false" class="p-2 hover:bg-surface rounded-lg">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
 </svg>
 </button>
 </div>

 <div class="relative mb-3">
 <input type="text"
 x-model="sessionSearch"
 @input.debounce.300ms="updateFilters()"
 placeholder="Search sessions..."
 class="w-full px-3 py-2 pl-9 text-base bg-surface border border-default rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
 <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-tertiary" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
 </svg>
 </div>

 <div class="flex flex-wrap gap-1 mb-3">
 <template x-for="sourceType in sourceTypes" :key="sourceType.key">
 <button @click="sessionSourceFilter = sourceType.key; updateFilters()"
 class="px-2.5 py-1 text-xs rounded-md transition-colors"
 :class="sessionSourceFilter === sourceType.key ? 'bg-blue-500 text-white' : 'bg-surface-secondary text-secondary hover:bg-surface'">
 <span x-text="sourceType.icon + ' ' + sourceType.label"></span>
 </button>
 </template>
 </div>

 <div class="flex items-center justify-between mb-3">
 <div class="flex gap-4 text-sm">
 <label class="flex items-center gap-2 cursor-pointer">
 <input type="checkbox"
 x-model="showArchived"
 @change="updateFilters()"
 class="rounded border-default text-blue-500 focus:ring-blue-500">
 <span class="text-secondary">Show archived</span>
 </label>
 <label class="flex items-center gap-2 cursor-pointer">
 <input type="checkbox"
 x-model="showStarred"
 @change="updateFilters()"
 class="rounded border-default text-blue-500 focus:ring-blue-500">
 <span class="text-secondary">Show starred</span>
 </label>
 </div>

 <button @click="toggleBulkEditMode()"
 class="inline-flex items-center gap-1.5 px-2 py-1.5 text-sm rounded hover:bg-surface transition-colors"
 :class="bulkEditMode ? 'text-blue-500 bg-blue-50 dark:bg-blue-900' : 'text-secondary hover:text-primary'">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <rect x="4" y="4" width="16" height="16" rx="2" ry="2"/>
 <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/>
 </svg>
 <span>Bulk Edit</span>
 </button>
 </div>

 <div x-show="bulkEditMode" class="flex items-center justify-between text-sm pt-2 border-t border-default">
 <button @click="toggleSelectAll()" class="px-3 py-1.5 text-xs font-medium rounded-md bg-surface-secondary text-secondary hover:bg-surface">
 <span x-text="selectAll ? 'Deselect All' : 'Select All'"></span>
 </button>
 <span class="text-tertiary text-xs" x-text="`${selectedSessionIds.length} selected`"></span>
 </div>
 </div>

 <div class="flex-1 overflow-y-auto p-4">
 <button @click="startNewChat()" class="w-full mb-4 p-4 bg-accent hover:bg-accent text-white rounded-lg font-medium">
 Start New Chat
 </button>

 <div class="space-y-2">
 <template x-for="session in sessions" :key="session.id">
 <!-- Swipe container -->
 <div class="relative overflow-hidden swipe-container"
 @touchstart="handleSwipeStart($event, session.id)"
 @touchmove="handleSwipeMove($event, session.id)"
 @touchend="handleSwipeEnd($event, session.id)"
 @touchcancel="resetSwipe()">

 <!-- Background reveal layer (shows icons during swipe) -->
 <div x-show="swipeState.sessionId === session.id && swipeState.isSwiping"
 class="absolute inset-0 flex items-center justify-between px-6"
 :style="'background-color: ' + getSwipeBackground(session.id, session)">

 <!-- Star/Keep or Unarchive action (revealed when swiping RIGHT) -->
 <div x-show="swipeState.direction === 'right'"
 class="flex items-center gap-2 text-white transition-all duration-150">
 <template x-if="session.archived_at">
 <!-- Unarchive icon for archived sessions -->
 <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
 </svg>
 </template>
 <template x-if="!session.archived_at">
 <!-- Star icon for regular sessions -->
 <svg class="w-6 h-6" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
 :fill="session.is_kept ? 'currentColor' : 'none'">
 <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
 </svg>
 </template>
 </div>

 <!-- Archive/Delete action (revealed when swiping LEFT) -->
 <div x-show="swipeState.direction === 'left'"
 class="flex items-center gap-2 text-white ml-auto transition-all duration-150">
 <template x-if="session.archived_at">
 <!-- Delete icon for archived sessions -->
 <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
 </svg>
 </template>
 <template x-if="!session.archived_at && !session.is_kept">
 <!-- Archive icon for regular sessions -->
 <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
 </svg>
 </template>
 <template x-if="!session.archived_at && session.is_kept">
 <!-- Locked icon for kept sessions -->
 <svg class="w-6 h-6 opacity-50" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
 </svg>
 </template>
 </div>
 </div>

 <!-- Main session content (slides horizontally) -->
 <div class="relative p-4 border-b border-subtle hover:bg-surface transition-all"
 :class="{
 'bg-surface border-l-4 border-l-blue-500': currentSession?.id === session.id,
 'pl-12': bulkEditMode
 }"
 :style="swipeState.sessionId === session.id && swipeState.isSwiping ?
 'transform: ' + getSwipeTransform(session.id) + '; transition: none;' :
 'transform: translateX(0); transition: transform 200ms ease-out;'">
 <div x-show="bulkEditMode" class="absolute left-4 top-1/2 -translate-y-1/2 z-10">
 <input type="checkbox"
 @click.stop="toggleSessionSelection(session.id)"
 :checked="selectedSessionIds.includes(session.id)"
 class="w-4 h-4 rounded border-default text-blue-500 focus:ring-blue-500 cursor-pointer">
 </div>

 <button @click="!bulkEditMode && loadSession(session)" class="block w-full text-left">
 <div class="flex items-center gap-2 font-medium text-sm text-primary pr-20">
 <span class="flex-shrink-0 text-base" x-text="getSourceIcon(session.source_type)"></span>

 <template x-if="session.is_kept">
 <span class="text-yellow-500">⭐</span>
 </template>

 <span class="truncate" x-text="session.title || session.name || 'New Chat Session'"></span>
 </div>

 <div class="text-xs text-tertiary mt-1">
 <span x-text="formatDate(session.updated_at)"></span>
 </div>
 </button>

 <div class="absolute bottom-2 right-2 flex gap-1.5">
 <template x-if="session.attachments_count > 0">
 <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-surface-secondary text-tertiary text-xs rounded">
 <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
 </svg>
 <span x-text="session.attachments_count"></span>
 </span>
 </template>
 <template x-if="session.artifacts_count > 0">
 <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-surface-secondary text-tertiary text-xs rounded">
 <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
 </svg>
 <span x-text="session.artifacts_count"></span>
 </span>
 </template>
 <template x-if="session.sources_count > 0">
 <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-surface-secondary text-tertiary text-xs rounded">
 <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
 </svg>
 <span x-text="session.sources_count"></span>
 </span>
 </template>
 <template x-if="session.archived_at">
 <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 text-xs rounded">
 Archived
 </span>
 </template>
 </div>

 <template x-if="!bulkEditMode && !isTouchDevice">
 <div class="absolute top-2 right-2 flex items-center gap-1">
 <button @click.stop="toggleSessionKeep(session.id, $event)"
 class="p-1 rounded transition-colors"
 :class="session.is_kept ? 'text-yellow-500' : 'text-tertiary hover:text-yellow-500'">
 <svg class="w-4 h-4" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"
 :fill="session.is_kept ? 'currentColor' : 'none'">
 <path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
 </svg>
 </button>

 <template x-if="session.archived_at">
 <button @click.stop="unarchiveSession(session.id, $event)"
 class="p-1 text-tertiary hover:text-blue-500 rounded transition-colors">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
 </svg>
 </button>
 </template>
 <template x-if="!session.archived_at">
 <button @click.stop="archiveSession(session.id, $event)"
 class="p-1 rounded transition-colors"
 :class="session.is_kept ? 'text-tertiary opacity-50 cursor-not-allowed' : 'text-tertiary hover:text-orange-500'"
 :disabled="session.is_kept">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
 </svg>
 </button>
 </template>

 <button @click.stop="if(confirm('Are you sure you want to delete this session? This cannot be undone.')) { deleteSessionAction(session.id, $event) }"
 class="p-1 text-tertiary hover:text-red-700 rounded transition-colors">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
 </svg>
 </button>
 </div>
 </template>
 </div>
 <!-- End of main session content -->
 </div>
 <!-- End of swipe container -->
 </template>
 </div>
 </div>

 <div x-show="bulkEditMode && selectedSessionIds.length > 0"
 class="sticky bottom-0 bg-surface border-t border-default p-4 space-y-3">
 <div class="flex items-center justify-between text-sm">
 <span class="font-medium" x-text="`${selectedSessionIds.length} session(s) selected`"></span>
 </div>

 <div class="grid grid-cols-2 gap-2">
 <button @click="performBulkOperation('keep')" :disabled="bulkOperationInProgress"
 class="px-4 py-2 text-sm font-medium rounded-lg bg-surface-secondary text-secondary hover:bg-surface disabled:opacity-50">
 Keep
 </button>
 <button @click="performBulkOperation('archive')" :disabled="bulkOperationInProgress"
 class="px-4 py-2 text-sm font-medium rounded-lg bg-surface-secondary text-secondary hover:bg-surface disabled:opacity-50">
 Archive
 </button>
 <button @click="performBulkOperation('unarchive')" :disabled="bulkOperationInProgress"
 class="px-4 py-2 text-sm font-medium rounded-lg bg-surface-secondary text-secondary hover:bg-surface disabled:opacity-50">
 Unarchive
 </button>
 <button @click="performBulkOperation('delete')" :disabled="bulkOperationInProgress"
 class="px-4 py-2 text-sm font-medium rounded-lg bg-red-500 text-white hover:bg-red-600 disabled:opacity-50">
 Delete
 </button>
 </div>

 <div x-show="bulkOperationInProgress" class="space-y-2">
 <div class="flex items-center justify-between text-xs text-tertiary">
 <span x-text="bulkOperationProgress.action"></span>
 <span x-text="`${bulkOperationProgress.current} / ${bulkOperationProgress.total}`"></span>
 </div>
 <div class="w-full bg-surface-secondary rounded-full h-1.5">
 <div class="bg-blue-500 h-1.5 rounded-full transition-all duration-300"
 :style="`width: ${bulkOperationProgress.total > 0 ? (bulkOperationProgress.current / bulkOperationProgress.total * 100) : 0}%`"></div>
 </div>
 </div>
 </div>
 </div> <!-- Close Sessions modal inner content div (line 293) -->
 </div> <!-- Close Sessions modal outer backdrop div (line 292) -->

 <!-- Artifact Drawer Modal -->
 <div x-show="showArtifactDrawer" @click.self="closeArtifactDrawer()" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-end">
 <div class="bg-surface w-full rounded-t-2xl max-h-[90vh] overflow-y-auto flex flex-col">
 <!-- Header -->
 <div class="sticky top-0 bg-surface border-b border-default px-4 py-3">
 <!-- Mode Toggle Buttons -->
 <div class="flex items-center gap-2 mb-3">
 <button
 @click="switchArtifactMode('preview')"
 class="flex-1 px-4 py-2 text-sm font-medium rounded-lg transition-colors"
 :class="artifactMode === 'preview' ? 'bg-accent text-white' : 'bg-surface-elevated text-secondary hover:bg-surface'">
 Preview
 </button>
 <button
 @click="switchArtifactMode('edit')"
 class="flex-1 px-4 py-2 text-sm font-medium rounded-lg transition-colors"
 :class="artifactMode === 'edit' ? 'bg-accent text-white' : 'bg-surface-elevated text-secondary hover:bg-surface'">
 Edit
 </button>
 </div>

 <!-- Title and Action Buttons -->
 <div class="flex items-center justify-between">
 <h2 class="text-lg font-semibold truncate flex-1" x-text="selectedArtifact?.title || 'Artifact'"></h2>
 <div class="flex items-center gap-2">
 <!-- Save Changes Button (only in edit mode) -->
 <button
 x-show="artifactMode === 'edit'"
 @click="saveArtifact()"
 :disabled="savingArtifact || !hasUnsavedArtifactChanges()"
 class="px-3 py-1.5 bg-accent text-white rounded-lg text-sm font-medium disabled:opacity-50 disabled:cursor-not-allowed">
 <span x-show="!savingArtifact">Save</span>
 <span x-show="savingArtifact">Saving...</span>
 </button>

 <!-- Cancel Button (only in edit mode) -->
 <button
 x-show="artifactMode === 'edit'"
 @click="cancelArtifactEdit()"
 class="p-2 hover:bg-surface-elevated rounded-lg">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
 </svg>
 </button>

 <!-- Save As Button (only in preview mode) -->
 <button
 x-show="artifactMode === 'preview'"
 @click="showSaveAsMenu = !showSaveAsMenu"
 class="p-2 hover:bg-surface-elevated rounded-lg relative">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/>
 </svg>
 </button>

 <!-- Download Button (only in preview mode) -->
 <button
 x-show="artifactMode === 'preview'"
 @click="downloadArtifact(selectedArtifact)"
 class="p-2 hover:bg-surface-elevated rounded-lg">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
 </svg>
 </button>

 <!-- Close Button -->
 <button @click="closeArtifactDrawer()" class="p-2 hover:bg-surface-elevated rounded-lg">
 <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
 </svg>
 </button>
 </div>
 </div>
 </div>

 <!-- Artifact Content -->
 <div class="flex-1 overflow-y-auto p-4">
 <!-- Loading State -->
 <div x-show="loadingArtifact" class="flex items-center justify-center py-12">
 <svg class="w-8 h-8 animate-spin text-accent" fill="none" viewBox="0 0 24 24">
 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.984l2-2.693z"></path>
 </svg>
 </div>

 <!-- Preview Mode -->
 <div x-show="!loadingArtifact && artifactMode === 'preview'">
 <!-- Metadata -->
 <template x-if="selectedArtifact">
 <div class="mb-4 flex flex-wrap gap-2 text-sm text-tertiary">
 <span x-show="selectedArtifact.filetype">
 Type: <span class="text-primary" x-text="selectedArtifact.filetype ? selectedArtifact.filetype.toUpperCase() : ''"></span>
 </span>
 <span x-show="selectedArtifact.created_at">•</span>
 <span x-show="selectedArtifact.created_at" x-text="selectedArtifact.created_at ? formatDate(selectedArtifact.created_at) : ''"></span>
 <span>•</span>
 <span x-text="selectedArtifact.privacy_level ? selectedArtifact.privacy_level : 'private'"></span>
 </div>
 </template>

 <!-- Content Preview -->
 <div class="bg-surface-elevated rounded-lg p-4">
 <template x-if="selectedArtifact && (selectedArtifact.filetype === 'md' || selectedArtifact.filetype === 'markdown')">
 <div class="markdown" x-html="artifactContent"></div>
 </template>
 <template x-if="selectedArtifact && selectedArtifact.filetype !== 'md' && selectedArtifact.filetype !== 'markdown'">
 <pre class="text-sm whitespace-pre-wrap" x-text="artifactContent"></pre>
 </template>
 </div>

 <!-- Conversions Section -->
 <div class="mt-6" x-show="artifactConversions.length > 0 || loadingConversions">
 <h3 class="text-sm font-semibold text-secondary mb-3">Conversions</h3>

 <div x-show="loadingConversions" class="flex items-center justify-center py-4">
 <svg class="w-5 h-5 animate-spin text-accent" fill="none" viewBox="0 0 24 24">
 <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
 <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.984l2-2.693z"></path>
 </svg>
 </div>

 <div x-show="!loadingConversions" class="space-y-2">
 <template x-for="conversion in artifactConversions" :key="conversion.id">
 <div class="flex items-center justify-between p-3 bg-surface-elevated rounded-lg">
 <div class="flex items-center gap-3 flex-1">
 <!-- Status Icon -->
 <span x-show="conversion.status === 'completed'" class="text-green-500">✓</span>
 <span x-show="conversion.status === 'failed'" class="text-red-500">✗</span>
 <span x-show="conversion.status === 'processing'" class="text-blue-500">⟳</span>
 <span x-show="conversion.status === 'pending'" class="text-gray-400">○</span>

 <!-- Format and Info -->
 <div class="flex-1 min-w-0">
 <div class="flex items-center gap-2">
 <span class="font-medium text-sm" x-text="conversion.format"></span>
 <span x-show="conversion.template" class="text-xs px-1.5 py-0.5 bg-accent/10 text-accent rounded" x-text="conversion.template"></span>
 </div>
 <div class="text-xs text-tertiary mt-0.5">
 <span x-text="conversion.created_at"></span>
 <span x-show="conversion.file_size"> • </span>
 <span x-show="conversion.file_size" x-text="conversion.file_size"></span>
 </div>
 <div x-show="conversion.error_message" class="text-xs text-red-500 mt-1" x-text="conversion.error_message"></div>
 </div>
 </div>

 <!-- Download Button -->
 <a x-show="conversion.status === 'completed' && conversion.download_url"
 :href="conversion.download_url"
 target="_blank"
 @click="showToast('Downloading...', 'success')"
 class="flex-shrink-0 p-2 text-accent hover:bg-surface rounded-lg"
 title="Download file">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
 </svg>
 </a>
 </div>
 </template>
 </div>
 </div>
 </div>

 <!-- Edit Mode -->
 <div x-show="!loadingArtifact && artifactMode === 'edit'" class="space-y-4">
 <!-- Title -->
 <div>
 <label class="block text-sm font-medium text-secondary mb-2">Title</label>
 <input
 type="text"
 x-model="editFormData.title"
 placeholder="Artifact title"
 class="w-full px-3 py-2 bg-surface border border-default rounded-lg focus:outline-none focus:ring-2 focus:ring-accent"
 :class="artifactErrors.title ? 'border-red-500' : ''">
 <p x-show="artifactErrors.title" class="mt-1 text-sm text-red-500" x-text="artifactErrors.title"></p>
 </div>

 <!-- Description -->
 <div>
 <label class="block text-sm font-medium text-secondary mb-2">Description</label>
 <textarea
 x-model="editFormData.description"
 rows="2"
 placeholder="Optional description"
 class="w-full px-3 py-2 bg-surface border border-default rounded-lg focus:outline-none focus:ring-2 focus:ring-accent resize-none"></textarea>
 </div>

 <!-- Privacy Level -->
 <div>
 <label class="block text-sm font-medium text-secondary mb-2">Privacy</label>
 <select
 x-model="editFormData.privacy_level"
 class="w-full px-3 py-2 bg-surface border border-default rounded-lg focus:outline-none focus:ring-2 focus:ring-accent">
 <option value="private">Private</option>
 <option value="public">Public</option>
 </select>
 </div>

 <!-- Content -->
 <div>
 <label class="block text-sm font-medium text-secondary mb-2">Content</label>
 <textarea
 x-model="editFormData.content"
 rows="15"
 placeholder="Artifact content"
 class="w-full px-3 py-2 bg-surface border border-default rounded-lg focus:outline-none focus:ring-2 focus:ring-accent font-mono text-sm resize-none"
 :class="artifactErrors.content ? 'border-red-500' : ''"></textarea>
 <p x-show="artifactErrors.content" class="mt-1 text-sm text-red-500" x-text="artifactErrors.content"></p>
 </div>
 </div>
 </div>

 <!-- Save As Dropdown Menu (positioned absolutely) -->
 <div
 x-show="showSaveAsMenu && artifactMode === 'preview'"
 @click.away="showSaveAsMenu = false"
 class="absolute right-4 top-16 bg-surface border border-default rounded-lg shadow-lg z-10 w-56 py-2">

 <button
 @click="convertArtifact('pdf')"
 :disabled="convertingArtifact"
 class="w-full px-4 py-2 text-left hover:bg-surface-elevated disabled:opacity-50 flex items-center gap-2">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
 </svg>
 <span>Export as PDF</span>
 </button>

 <button
 @click="convertArtifact('docx')"
 :disabled="convertingArtifact"
 class="w-full px-4 py-2 text-left hover:bg-surface-elevated disabled:opacity-50 flex items-center gap-2">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
 </svg>
 <span>Export as DOCX</span>
 </button>

 <button
 @click="convertArtifact('odt')"
 :disabled="convertingArtifact"
 class="w-full px-4 py-2 text-left hover:bg-surface-elevated disabled:opacity-50 flex items-center gap-2">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
 </svg>
 <span>Export as ODT</span>
 </button>

 <button
 @click="convertArtifact('latex')"
 :disabled="convertingArtifact"
 class="w-full px-4 py-2 text-left hover:bg-surface-elevated disabled:opacity-50 flex items-center gap-2">
 <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
 </svg>
 <span>Export as LaTeX</span>
 </button>
 </div>
 </div>
 </div>
 </div>

 @push('scripts')
 <script>
 function chatInterface(initialSessionId = null) {
 return {
 // State
 message: '',
 interactions: [],
 sessions: [],
 agents: [],
 currentSession: null,
 selectedAgent: null,
 selectedFiles: [],
 sending: false,
 showAgentSelector: false,
 showSessions: false,
 showShareModal: false,
 shareUrl: '',
 isOnline: navigator.onLine,
 initialSessionId: initialSessionId,

 // Voice input state
 voiceInputSupported: false,
 isListening: false,
 recognition: null,
 voiceInputCooldown: false,

 // Bulk edit state
 bulkEditMode: false,
 selectedSessionIds: [],
 selectAll: false,
 bulkOperationInProgress: false,
 bulkOperationProgress: { current: 0, total: 0, action: '' },

 // Session filters
 sessionSearch: '',
 sessionSourceFilter: 'all',
 showArchived: false,
 showStarred: false,
 sourceTypes: [],

 // Swipe gesture state
 swipeState: {
  sessionId: null,
  startX: 0,
  currentX: 0,
  startY: 0,
  currentY: 0,
  deltaX: 0,
  deltaY: 0,
  isScrolling: null,
  isSwiping: false,
  direction: null,
  velocity: 0,
  lastMoveTime: 0
 },

 // Swipe configuration
 swipeConfig: {
  threshold: 80,
  velocityThreshold: 0.3,
  scrollLockThreshold: 10,
  maxDistance: 200,
  snapBackDuration: 200
 },

 // Device detection
 isTouchDevice: ('ontouchstart' in window) || (navigator.maxTouchPoints > 0),

 // Artifact drawer state
 showArtifactDrawer: false,
 selectedArtifact: null,
 loadingArtifact: false,
 artifactContent: '',

 // Artifact edit mode state
 artifactMode: 'preview',           // 'preview' or 'edit'
 editFormData: {                    // Edit form fields
     title: '',
     description: '',
     content: '',
     privacy_level: 'private'
 },
 originalContent: '',               // For detecting changes
 savingArtifact: false,             // Save button loading state
 artifactErrors: {},                // Validation errors

 // Save As state
 showSaveAsMenu: false,             // Toggle dropdown menu
 convertingArtifact: false,         // Conversion in progress

 // Conversions state
 artifactConversions: [],           // List of conversions for current artifact
 loadingConversions: false,         // Loading conversions state

 // Echo channel tracking
 currentArtifactChannel: null,      // Track current artifact channel subscription
 sessionChannel: null,               // Track current session channel subscription

 // Services
 auth: null,
 chatStream: null,
 fileHandler: null,
 exporter: null,
 agentAPI: null,
 sessionAPI: null,
 sync: null,

 async init() {
 // Wait for PWA services
 await this.waitForPWA();

 // Configure marked.js for proper rendering
 this.initializeMarked();

 // Initialize services
 this.auth = new window.PWA.AuthService();
 this.chatStream = new window.PWA.ChatStream();
 this.fileHandler = new window.PWA.FileHandler();
 this.exporter = new window.PWA.ChatExporter();
 this.agentAPI = new window.PWA.AgentAPI();
 this.sessionAPI = new window.PWA.SessionAPI();
 this.sync = new window.PWA.SyncService();

 // Load data
 this.loadSourceTypes();
 await this.loadAgents();
 await this.loadSessions();

 // Load initial session from URL if provided
 if (this.initialSessionId) {
 console.log('Loading initial session from URL:', this.initialSessionId);
 const session = this.sessions.find(s => s.id === this.initialSessionId);
 if (session) {
 await this.loadSession(session);
 } else {
 console.warn('Initial session not found in session list:', this.initialSessionId);
 }
 }

 // Listen for online/offline
 window.addEventListener('online', () => this.isOnline = true);
 window.addEventListener('offline', () => this.isOnline = false);

 // Listen for artifact conversion completion, artifact creation, and session updates via Echo
 this.setupConversionListener();
 this.setupArtifactListener();
 this.setupSessionListener();

 // Initialize voice input if supported
 this.initVoiceInput();
 },

 async setupArtifactListener() {
 if (!window.Echo) {
 console.warn('Cannot setup artifact listener: Echo not available');
 return;
 }

 // Listen for artifact creation events on session-level channel
 // This captures artifacts created during agent execution
 window.addEventListener('session-changed', () => {
 if (!this.currentSession?.id || !window.Echo) {
 return;
 }

 const channelName = `artifacts-updated.${this.currentSession.id}`;

 // Unsubscribe from previous channel if different
 if (this.currentArtifactChannel && this.currentArtifactChannel !== channelName) {
 console.log('Leaving previous artifact channel:', this.currentArtifactChannel);
 window.Echo.leave(this.currentArtifactChannel);
 this.currentArtifactChannel = null;
 }

 // Subscribe to new channel if not already subscribed
 if (this.currentArtifactChannel !== channelName) {
 console.log('Subscribing to artifacts channel:', channelName);
 this.currentArtifactChannel = channelName;

 window.Echo.channel(channelName)
 .listen('ChatInteractionArtifactCreated', (data) => {
 console.log('Artifact created event received:', data);
 console.log('Artifact object:', data.artifact);
 console.log('Artifact ID type:', typeof data.artifact?.id, 'Value:', data.artifact?.id);

 // Find the interaction and add the artifact
 // Echo broadcasts snake_case fields from Laravel
 const interactionId = data.chat_interaction_id;
 if (!interactionId) {
 console.error('No interaction ID found in artifact event:', data);
 return;
 }

 const index = this.interactions.findIndex(i => i.id === interactionId);
 if (index === -1) {
 console.warn('Interaction not found for ID:', interactionId);
 return;
 }

 if (!this.interactions[index].artifacts) {
 this.interactions[index].artifacts = [];
 }

 // Ensure artifact has a valid ID
 if (!data.artifact || !data.artifact.id) {
 console.error('Invalid artifact received - missing ID:', data.artifact);
 return;
 }

 // Check if artifact already exists (avoid duplicates)
 const artifactExists = this.interactions[index].artifacts.some(a => a.id === data.artifact.id);
 if (artifactExists) {
 console.log('Artifact already exists, skipping:', data.artifact.id);
 return;
 }

 this.interactions[index].artifacts.push(data.artifact);
 console.log('Added artifact to interaction:', {
 artifact_id: data.artifact.id,
 title: data.artifact.title,
 interaction_id: interactionId,
 total_artifacts: this.interactions[index].artifacts.length
 });
 });
 }
 });

 // Trigger initial setup if session already exists
 if (this.currentSession?.id) {
 window.dispatchEvent(new CustomEvent('session-changed'));
 }
 },

 async setupSessionListener() {
 if (!window.Echo) {
 console.warn('Cannot setup session listener: Echo not available');
 return;
 }

 // Listen for session updates (title changes) via Echo
 // Similar pattern to artifact listener
 window.addEventListener('session-changed', () => {
 if (!this.currentSession?.id || !window.Echo) {
 return;
 }

 const channelName = `session.${this.currentSession.id}`;

 // Unsubscribe from previous channel if different
 if (this.sessionChannel && this.sessionChannel !== channelName) {
 console.log('Leaving previous session channel:', this.sessionChannel);
 window.Echo.leave(this.sessionChannel);
 this.sessionChannel = null;
 }

 // Subscribe to new channel if not already subscribed
 if (this.sessionChannel !== channelName) {
 console.log('Subscribing to session channel:', channelName);
 this.sessionChannel = channelName;

 // Use public channel (not private) to avoid auth issues in PWA
 window.Echo.channel(channelName)
 .listen('ChatSessionUpdated', (data) => {
 console.log('Session title updated:', data);
 if (data.title && this.currentSession) {
 this.currentSession.title = data.title;
 this.currentSession.updated_at = data.updated_at;
 console.log('Session title updated to:', data.title);
 }
 });
 }
 });

 // Trigger initial setup if session already exists
 if (this.currentSession?.id) {
 window.dispatchEvent(new CustomEvent('session-changed'));
 }
 },

 /**
 * Refresh current session data from API
 */
 async refreshSession() {
 if (!this.currentSession?.id) return;

 try {
 const result = await this.sessionAPI.getSession(this.currentSession.id);
 if (result.success && result.session) {
 this.currentSession = result.session;
 console.log('Session refreshed:', this.currentSession.title);
 }
 } catch (error) {
 console.error('Failed to refresh session:', error);
 }
 },

 /**
 * Initialize Web Speech API for voice input
 */
 initVoiceInput() {
 // Check if Web Speech API is supported
 const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

 if (!SpeechRecognition) {
 console.log('Web Speech API not supported in this browser');
 this.voiceInputSupported = false;
 return;
 }

 console.log('Speech Recognition API:', SpeechRecognition.name, 'Browser:', navigator.userAgent.split(' ').slice(-2).join(' '));

 this.voiceInputSupported = true;
 this.recognition = new SpeechRecognition();

 // Configure recognition
 this.recognition.continuous = false; // Stop after single phrase
 this.recognition.interimResults = false; // Only final results
 this.recognition.lang = 'en-US'; // Default language

 // Handle recognition start
 this.recognition.onstart = () => {
 console.log('Speech recognition started successfully');
 this.isListening = true;
 };

 // Handle successful recognition
 this.recognition.onresult = (event) => {
 const transcript = event.results[0][0].transcript;
 console.log('Voice input recognized:', transcript);

 // Insert at cursor position or append
 const textarea = this.$refs.messageInput;
 const start = textarea.selectionStart;
 const end = textarea.selectionEnd;
 const currentMessage = this.message || '';

 // Add space before if there's existing text and cursor is not at start
 const prefix = (start > 0 && currentMessage[start - 1] !== ' ') ? ' ' : '';
 // Add space after if there's text after cursor
 const suffix = (end < currentMessage.length && currentMessage[end] !== ' ') ? ' ' : '';

 this.message = currentMessage.substring(0, start) + prefix + transcript + suffix + currentMessage.substring(end);

 // Set cursor position after inserted text
 this.$nextTick(() => {
 const newPosition = start + prefix.length + transcript.length + suffix.length;
 textarea.setSelectionRange(newPosition, newPosition);
 textarea.focus();
 });

 this.isListening = false;
 };

 // Handle errors
 this.recognition.onerror = (event) => {
 console.error('Speech recognition error:', event.error, 'Event:', event);
 this.isListening = false;

 if (event.error === 'not-allowed') {
 this.showToast('Microphone permission denied. Please allow microphone access.', 'error');
 this.voiceInputSupported = false; // Disable until page reload
 } else if (event.error === 'no-speech') {
 this.showToast('No speech detected. Please try again.', 'warning');
 } else if (event.error === 'network') {
 // Network errors mean the browser's speech service is unavailable
 console.error('Speech recognition service unavailable. Browser:', navigator.userAgent);

 // Detect if it's Brave or mobile mode
 const isBrave = navigator.brave && navigator.brave.isBrave;
 const isMobile = /Mobile|Android/i.test(navigator.userAgent);

 let message = 'Speech service unavailable.';
 if (isBrave) {
 message += ' Try disabling Brave Shields for this site.';
 } else if (isMobile) {
 message += ' Mobile mode may have limited support.';
 } else {
 message += ' Check your internet connection.';
 }

 this.showToast(message, 'error');

 // Disable voice input if network errors persist
 this.voiceInputCooldown = true;
 setTimeout(() => { this.voiceInputCooldown = false; }, 5000);
 } else if (event.error === 'audio-capture') {
 this.showToast('No microphone detected. Please connect a microphone.', 'error');
 } else if (event.error !== 'aborted') {
 this.showToast('Voice input error: ' + event.error, 'error');
 }
 };

 // Handle end of recognition
 this.recognition.onend = () => {
 this.isListening = false;
 };

 console.log('Voice input initialized successfully');
 },

 /**
 * Toggle voice input recording
 */
 toggleVoiceInput() {
 if (!this.voiceInputSupported || !this.recognition) {
 return;
 }

 // Check cooldown period
 if (this.voiceInputCooldown) {
 console.log('Voice input on cooldown, please wait');
 return;
 }

 if (this.isListening) {
 // Stop listening
 try {
 this.recognition.stop();
 this.isListening = false;
 console.log('Voice input stopped');
 } catch (error) {
 console.error('Failed to stop voice input:', error);
 this.isListening = false;
 }
 } else {
 // Start listening
 try {
 // Guard against calling start() when already running
 if (this.recognition && this.recognition.state !== 'running') {
 console.log('Calling recognition.start()...');
 this.recognition.start();
 // isListening will be set to true in onstart handler
 } else {
 console.warn('Voice recognition already running');
 }
 } catch (error) {
 console.error('Failed to start voice input:', error);

 // Handle specific error types
 if (error.name === 'InvalidStateError') {
 console.warn('Speech recognition already started');
 } else {
 this.showToast('Failed to start voice input', 'error');
 }

 this.isListening = false;
 }
 }
 },

 async setupConversionListener() {
 if (!window.Echo) {
 console.warn('Cannot setup conversion listener: Echo not available');
 return;
 }

 try {
 // Fetch user ID from API
 const response = await fetch('/api/user', {
 headers: {
 'Authorization': `Bearer ${await this.auth.getToken()}`,
 'Accept': 'application/json'
 }
 });

 if (!response.ok) {
 console.warn('Cannot setup conversion listener: failed to fetch user');
 return;
 }

 const user = await response.json();
 const userId = user.id;

 console.log('Setting up conversion listener for user:', userId);

 // Listen to artifact conversion completion channel
 window.Echo.channel(`artifact-conversion.${userId}`)
 .listen('.conversion.completed', (data) => {
 console.log('Artifact conversion completed:', data);

 // Show notification
 this.showConversionNotification(data);

 // If artifact drawer is open and showing the same artifact, refresh conversions
 if (this.showArtifactDrawer && this.selectedArtifact?.id === data.artifact_id) {
 this.loadConversions();
 }
 });
 } catch (error) {
 console.error('Failed to setup conversion listener:', error);
 }
 },

 async showConversionNotification(data) {
 const { artifact_title, format, status, download_url, error_message } = data;

 if (status === 'completed' && download_url) {
 // Use the signed URL provided by the backend (secure, time-limited)
 try {
 // Open in new tab - let Content-Disposition header handle download
 window.open(download_url, '_blank');
 console.log('Download triggered via signed URL');
 } catch (error) {
 console.error('Failed to trigger download:', error);
 this.showToast('Failed to trigger download', 'error');
 }

 // Show in-app notification
 Livewire.dispatch('notify', {
 message: `${format} conversion complete for "${artifact_title}". Downloading...`,
 type: 'success'
 });

 // Show native mobile notification via Service Worker
 if ('serviceWorker' in navigator && 'Notification' in window) {
 try {
 // Request permission if not already granted
 if (Notification.permission === 'default') {
 const permission = await Notification.requestPermission();
 if (permission !== 'granted') {
 return;
 }
 }

 if (Notification.permission === 'granted') {
 // Get the service worker registration
 const registration = await navigator.serviceWorker.ready;

 // Post message to service worker to show notification
 if (registration.active) {
 // Download URL is already a signed URL, no token needed
 registration.active.postMessage({
 type: 'SHOW_NOTIFICATION',
 title: `${format} Conversion Complete`,
 options: {
 body: `"${artifact_title}" is ready to download`,
 icon: '/pwa-192x192.png',
 badge: '/pwa-64x64.png',
 tag: `conversion-${data.conversion_id}`,
 data: {
 url: '/pwa/chat',
 downloadUrl: download_url,
 artifactId: data.artifact_id,
 conversionId: data.conversion_id
 },
 actions: [
 {
 action: 'download',
 title: 'Download',
 icon: '/pwa-64x64.png'
 }
 ],
 // Mobile-specific options
 vibrate: [200, 100, 200],
 requireInteraction: false,
 silent: false
 }
 });
 }
 }
 } catch (error) {
 console.error('Failed to show notification:', error);
 }
 }

 // If artifact drawer is open, refresh conversions list
 if (this.showArtifactDrawer && this.selectedArtifact?.id === data.artifact_id) {
 this.loadConversions();
 }
 } else if (status === 'failed') {
 // Error notification
 Livewire.dispatch('notify', {
 message: `${format} conversion failed: ${error_message || 'An error occurred'}`,
 type: 'error'
 });

 // Show error notification via Service Worker
 if ('serviceWorker' in navigator && Notification.permission === 'granted') {
 try {
 const registration = await navigator.serviceWorker.ready;
 if (registration.active) {
 registration.active.postMessage({
 type: 'SHOW_NOTIFICATION',
 title: `${format} Conversion Failed`,
 options: {
 body: `"${artifact_title}": ${error_message || 'An error occurred'}`,
 icon: '/pwa-192x192.png',
 badge: '/pwa-64x64.png',
 tag: `conversion-error-${data.conversion_id}`,
 requireInteraction: false
 }
 });
 }
 } catch (error) {
 console.error('Failed to show error notification:', error);
 }
 }
 }
 },

 initializeMarked() {
 if (!window.marked) return;

 // Configure marked.js options (same as main chat interface)
 window.marked.setOptions({
 gfm: true, // GitHub Flavored Markdown
 breaks: true, // Convert \n to <br>
 headerIds: true, // Add IDs to headings
 mangle: false, // Don't mangle email addresses
 pedantic: false, // Don't be overly strict
 });
 },

 async waitForPWA() {
 let attempts = 0;
 while (!window.PWA && attempts < 100) {
 await new Promise(resolve => setTimeout(resolve, 100));
 attempts++;
 }
 if (!window.PWA) {
 throw new Error('PWA services failed to load');
 }
 },

 async loadAgents() {
 try {
 console.log('Loading agents...');
 const result = await this.agentAPI.listAgents();
 console.log('Agent API result:', result);

 if (result.success && result.data) {
 this.agents = Array.isArray(result.data) ? result.data : [];
 console.log('Loaded agents:', this.agents);

 // Select default agent by priority: promptly > direct > first available
 if (this.agents.length > 0 && !this.selectedAgent) {
 // Priority 1: First agent of type 'promptly'
 let defaultAgent = this.agents.find(agent => agent.agent_type === 'promptly');

 // Priority 2: First agent of type 'direct'
 if (!defaultAgent) {
 defaultAgent = this.agents.find(agent => agent.agent_type === 'direct');
 }

 // Priority 3: First available agent
 if (!defaultAgent) {
 defaultAgent = this.agents[0];
 }

 this.selectedAgent = defaultAgent;
 console.log('Selected default agent:', this.selectedAgent, `(type: ${defaultAgent.agent_type})`);
 }
 } else {
 console.error('Failed to load agents:', result.error);
 this.agents = [];
 }
 } catch (error) {
 console.error('Failed to load agents exception:', error);
 this.agents = [];
 }
 },

 async loadSessions() {
 try {
 console.log('Loading sessions from API with filters...');

 // Build filters object
 const filters = {};
 if (this.sessionSearch) filters.search = this.sessionSearch;
 if (this.sessionSourceFilter && this.sessionSourceFilter !== 'all') {
 filters.source_type = this.sessionSourceFilter;
 }
 filters.include_archived = this.showArchived;
 filters.kept_only = this.showStarred;

 const result = await this.sessionAPI.listSessions(filters);

 if (result.success && result.data) {
 this.sessions = result.data;
 console.log('Loaded sessions:', this.sessions);
 } else {
 console.error('Failed to load sessions:', result.error);
 this.sessions = [];
 }
 } catch (error) {
 console.error('Failed to load sessions exception:', error);
 this.sessions = [];
 }
 },

 loadSourceTypes() {
 // Match SourceTypeRegistry icons exactly
 this.sourceTypes = [
 { key: 'all', label: 'All', icon: '📋' },
 { key: 'web', label: 'Web', icon: '🌐' },
 { key: 'api', label: 'API', icon: '🔌' },
 { key: 'trigger', label: 'Trigger', icon: '⚡' },
 { key: 'webhook', label: 'Webhook', icon: '🔗' },
 { key: 'slack', label: 'Slack', icon: '💬' }
 ];
 },

 getSourceIcon(sourceType) {
 const source = this.sourceTypes.find(s => s.key === sourceType);
 return source ? source.icon : '💬';
 },

 getSourceLabel(sourceType) {
 const source = this.sourceTypes.find(s => s.key === sourceType);
 return source ? source.label : sourceType;
 },

 async loadSession(session) {
 try {
 console.log('Loading session:', session.id);

 // Fetch full session details with chat history from API
 const result = await this.sessionAPI.getSession(session.id);

 if (result.success) {
 // Preserve the title/name from the session list item
 this.currentSession = {
 ...result.session,
 title: session.title || result.session.title,
 name: session.name || result.session.name
 };

 // Convert API interactions to display format
 this.interactions = result.interactions.map(interaction => ({
 id: interaction.id,
 question: interaction.question,
 answer: interaction.answer,
 streaming: false,
 created_at: interaction.created_at,
 agent_name: interaction.agent_name,
 sources: interaction.sources,
 artifacts: interaction.artifacts || [],
 creatingArtifact: false
 }));

 console.log('Loaded session with interactions:', this.interactions);
 this.showSessions = false;

 // Update URL to reflect loaded session
 if (this.currentSession?.id) {
 window.history.pushState({}, '', `/pwa/chat/${this.currentSession.id}`);
 // Trigger artifact listener setup for this session
 window.dispatchEvent(new CustomEvent('session-changed'));
 }

 this.scrollToBottom();
 } else {
 console.error('Failed to load session:', result.error);
 alert('Failed to load session: ' + result.error);
 }
 } catch (error) {
 console.error('Failed to load session exception:', error);
 alert('Failed to load session: ' + error.message);
 }
 },

 startNewChat() {
 this.currentSession = null;
 this.interactions = [];
 this.showSessions = false;

 // Update URL to reflect new chat (no session ID)
 window.history.pushState({}, '', '/pwa/chat');
 },

 selectAgent(agent) {
 this.selectedAgent = agent;
 this.showAgentSelector = false;
 },

 async sendMessage() {
 if ((!this.message.trim() && this.selectedFiles.length === 0) || this.sending || !this.isOnline) {
 return;
 }

 this.sending = true;

 try {
 // Add user message to UI
 const userInteraction = {
 id: Date.now(),
 question: this.message,
 answer: '',
 streaming: true,
 created_at: new Date().toISOString(),
 artifacts: [],
 creatingArtifact: false
 };
 this.interactions.push(userInteraction);

 const question = this.message;
 const files = [...this.selectedFiles]; // Copy files array
 this.message = '';
 this.selectedFiles = []; // Clear selected files
 this.scrollToBottom();

 // Start streaming
 const interactionId = userInteraction.id;

 await this.chatStream.startStream(
 question,
 this.currentSession?.id,
 this.selectedAgent?.id,
 {
 onChunk: (chunk) => {
 // Extract session_id from ANY event that contains it
 if (chunk.session_id && typeof chunk.session_id === 'number' && !this.currentSession?.id) {
 this.currentSession = { id: chunk.session_id };
 window.history.replaceState({}, '', `/pwa/chat/${chunk.session_id}`);
 // Trigger artifact listener setup for new session
 window.dispatchEvent(new CustomEvent('session-changed'));
 }

 const index = this.interactions.findIndex(i => i.id === interactionId);
 if (index === -1) return;

 // Handle different event types
 if (chunk.type === 'answer_stream' || chunk.type === 'content') {
 // Direct agent streaming answer OR research agent final answer
 const answer = chunk.content || chunk.data || '';
 this.interactions[index] = {
 ...this.interactions[index],
 answer: answer,
 status: null, // Clear status when answer arrives
 streaming: false
 };
 this.scrollToBottom();
 } else if (chunk.type === 'research_step') {
 // Research agent status update with custom status field
 const status = chunk.status || chunk.data?.status || '';
 this.interactions[index] = {
 ...this.interactions[index],
 status: status,
 streaming: true
 };
 this.scrollToBottom();
 } else if (chunk.type === 'step_added') {
 // Research agent step with message in data
 const message = chunk.data?.message || chunk.message || '';
 const source = chunk.data?.source || '';
 const status = source ? `[${source}] ${message}` : message;
 this.interactions[index] = {
 ...this.interactions[index],
 status: status,
 streaming: true
 };
 this.scrollToBottom();
 } else if (chunk.type === 'research_complete') {
 // Research complete - clear status, wait for answer_stream
 this.interactions[index] = {
 ...this.interactions[index],
 status: 'Finalizing...',
 streaming: true
 };
 this.scrollToBottom();
 } else if (chunk.type === 'session') {
 // Backend sends session_id as a flat field, wrap it in an object
 const sessionId = chunk.session_id || chunk.data?.id;
 if (sessionId && typeof sessionId === 'number') {
 this.currentSession = { id: sessionId };
 // Update URL to reflect current session
 window.history.replaceState({}, '', `/pwa/chat/${sessionId}`);
 }
 }
 },
 onComplete: async (data) => {
 // Trigger Alpine reactivity by reassigning array element
 const index = this.interactions.findIndex(i => i.id === interactionId);
 if (index !== -1) {
 this.interactions[index] = {
 ...this.interactions[index],
 streaming: false
 };
 }

 // Save to IndexedDB and refresh session data
 if (this.currentSession?.id) {
 // Delay to ensure title generation completes on backend
 await new Promise(resolve => setTimeout(resolve, 2000));

 await this.sync.syncSession(this.currentSession.id);
 await this.loadSessions();

 // Fetch full session data to get updated title and artifacts
 const result = await this.sessionAPI.getSession(this.currentSession.id);
 if (result.success && result.session) {
 this.currentSession = result.session;

 // Update interactions with latest artifacts from API (merge, don't replace)
 if (result.interactions) {
 result.interactions.forEach(apiInteraction => {
 // Try to find by real ID first
 let localIndex = this.interactions.findIndex(i => i.id === apiInteraction.id);

 // If not found by real ID, try to match by question (for temp ID interactions)
 if (localIndex === -1) {
 localIndex = this.interactions.findIndex(i =>
 i.question === apiInteraction.question &&
 i.id > 1000000000000 // Has temporary timestamp ID
 );

 // Update the temporary ID to the real database ID
 if (localIndex !== -1) {
 console.log(`Updating temp ID ${this.interactions[localIndex].id} to real ID ${apiInteraction.id}`);
 this.interactions[localIndex].id = apiInteraction.id;
 }
 }

 if (localIndex !== -1 && apiInteraction.artifacts) {
 console.log('API artifacts for interaction', apiInteraction.id, ':', apiInteraction.artifacts);
 apiInteraction.artifacts.forEach((artifact, idx) => {
 console.log(`API artifact[${idx}] ID type:`, typeof artifact.id, 'Value:', artifact.id);
 });

 // Merge artifacts, avoiding duplicates
 const existingArtifacts = this.interactions[localIndex].artifacts || [];
 const newArtifacts = apiInteraction.artifacts.filter(apiArtifact =>
 !existingArtifacts.some(existing => existing.id === apiArtifact.id)
 );

 console.log('Existing artifacts count:', existingArtifacts.length);
 console.log('New artifacts from API:', newArtifacts.length);

 this.interactions[localIndex].artifacts = [...existingArtifacts, ...newArtifacts];

 console.log('Total artifacts after merge:', this.interactions[localIndex].artifacts.length);
 }
 });
 }
 }
 }
 },
 onError: (error) => {
 console.error('Stream error:', error);
 // Trigger Alpine reactivity by reassigning array element
 const index = this.interactions.findIndex(i => i.id === interactionId);
 if (index !== -1) {
 this.interactions[index] = {
 ...this.interactions[index],
 streaming: false,
 answer: 'Error: ' + error.message
 };
 }
 }
 },
 files // Pass files as 5th parameter
 );

 } catch (error) {
 console.error('Send message error:', error);
 } finally {
 this.sending = false;
 }
 },

 handleFileSelect(event) {
 this.selectedFiles = Array.from(event.target.files);
 },

 removeFile(index) {
 this.selectedFiles.splice(index, 1);
 },

 async capturePhoto() {
 try {
 const file = await this.fileHandler.capturePhoto();
 if (file) {
 this.selectedFiles.push(file);
 }
 } catch (error) {
 console.error('Camera error:', error);
 }
 },

 async downloadSession() {
 if (!this.currentSession) return;

 try {
 const result = await this.exporter.downloadSession(this.currentSession.id);

 // If export failed due to missing data, try syncing first
 if (!result.success && result.error === 'Session not found') {
 console.log('Session not cached locally, syncing from server...');

 // Attempt to sync the session from server
 const syncResult = await this.sync.syncSession(this.currentSession.id);

 if (syncResult.success) {
 console.log('Session synced successfully, retrying download...');
 // Retry the download
 const retryResult = await this.exporter.downloadSession(this.currentSession.id);

 if (!retryResult.success) {
 alert('Failed to download session: ' + retryResult.error);
 }
 } else {
 alert('Failed to sync session from server: ' + syncResult.error);
 }
 } else if (!result.success) {
 alert('Failed to download session: ' + result.error);
 }
 } catch (error) {
 console.error('Download error:', error);
 alert('Failed to download session: ' + error.message);
 }
 },

 async toggleShare() {
 if (!this.currentSession) return;

 try {
  if (this.currentSession.is_public) {
   // Unshare the session
   const result = await this.sessionAPI.unshareSession(this.currentSession.id);
   if (result.success) {
    this.currentSession.is_public = false;
    this.currentSession.uuid = null;
    this.shareUrl = '';
   } else {
    alert('Failed to unshare session: ' + result.error);
   }
  } else {
   // Share the session
   const result = await this.sessionAPI.shareSession(this.currentSession.id);
   if (result.success && result.session) {
    this.currentSession.is_public = true;
    this.currentSession.uuid = result.session.uuid;

    // Get the server URL to construct the public URL
    const serverUrl = await this.auth.getServerUrl();
    this.shareUrl = `${serverUrl}/public/sessions/${result.session.uuid}`;
   } else {
    alert('Failed to share session: ' + result.error);
   }
  }

  // Reload sessions list to update the is_public indicator
  await this.loadSessions();
 } catch (error) {
  console.error('Share toggle error:', error);
  alert('Failed to toggle share: ' + error.message);
 }
 },

 renderMarkdown(text) {
 if (!text) return '';

 try {
 // Parse markdown to HTML
 let html = window.marked.parse(text);

 // Wrap tables in scrollable container for mobile
 html = html.replace(
 /<table>/g,
 '<div class="table-wrapper"><table>'
 ).replace(
 /<\/table>/g,
 '</table></div>'
 );

 // Apply syntax highlighting after a short delay
 // (allows DOM to update with new content)
 this.$nextTick(() => {
 setTimeout(() => {
 document.querySelectorAll('pre code').forEach((block) => {
 if (window.hljs) {
 window.hljs.highlightElement(block);
 }
 });
 }, 10);
 });

 return html;
 } catch (error) {
 console.error('Markdown parsing error:', error);
 return `<pre>${text}</pre>`;
 }
 },

 formatDate(dateString) {
 const date = new Date(dateString);
 const now = new Date();
 const diffMs = now - date;
 const diffMins = Math.floor(diffMs / 60000);
 const diffHours = Math.floor(diffMs / 3600000);
 const diffDays = Math.floor(diffMs / 86400000);

 if (diffMins < 1) return 'Just now';
 if (diffMins < 60) return `${diffMins}m ago`;
 if (diffHours < 24) return `${diffHours}h ago`;
 if (diffDays < 7) return `${diffDays}d ago`;
 return date.toLocaleDateString();
 },

 scrollToBottom() {
 this.$nextTick(() => {
 const container = this.$refs.messagesContainer;
 if (container) {
 container.scrollTop = container.scrollHeight;
 }
 });
 },

 // Bulk Edit Methods
 toggleBulkEditMode() {
 this.bulkEditMode = !this.bulkEditMode;
 if (!this.bulkEditMode) {
 this.clearBulkSelections();
 }
 },

 toggleSessionSelection(sessionId) {
 const index = this.selectedSessionIds.indexOf(sessionId);
 if (index > -1) {
 this.selectedSessionIds.splice(index, 1);
 } else {
 this.selectedSessionIds.push(sessionId);
 }
 this.updateSelectAllState();
 },

 toggleSelectAll() {
 this.selectAll = !this.selectAll;
 if (this.selectAll) {
 this.selectedSessionIds = this.sessions.map(s => s.id);
 } else {
 this.selectedSessionIds = [];
 }
 },

 updateSelectAllState() {
 const visibleIds = this.sessions.map(s => s.id);
 this.selectAll = visibleIds.length > 0 &&
 this.selectedSessionIds.filter(id => visibleIds.includes(id)).length === visibleIds.length;
 },

 clearBulkSelections() {
 this.selectedSessionIds = [];
 this.selectAll = false;
 },

 async performBulkOperation(operation) {
 if (this.selectedSessionIds.length === 0) return;

 // Confirm delete operation
 if (operation === 'delete') {
 if (!confirm(`Are you sure you want to delete ${this.selectedSessionIds.length} session(s)? This cannot be undone.`)) {
 return;
 }
 }

 this.bulkOperationInProgress = true;
 this.bulkOperationProgress = {
 current: 0,
 total: this.selectedSessionIds.length,
 action: operation.charAt(0).toUpperCase() + operation.slice(1) + 'ing sessions'
 };

 const result = await this.sessionAPI.bulkOperation(
 this.selectedSessionIds,
 operation,
 (current, total) => {
 this.bulkOperationProgress.current = current;
 this.bulkOperationProgress.total = total;
 }
 );

 this.bulkOperationInProgress = false;

 // Show result
 if (result.successCount > 0) {
 console.log(`${operation}: ${result.successCount} sessions processed successfully`);
 }
 if (result.failedCount > 0) {
 console.error(`${operation}: ${result.failedCount} sessions failed`, result.errors);
 alert(`${result.successCount} succeeded, ${result.failedCount} failed. Check console for details.`);
 }

 // Clear selections and reload
 this.clearBulkSelections();
 await this.loadSessions();

 // If current session was affected, start new chat
 if (operation === 'delete' && this.currentSession && this.selectedSessionIds.includes(this.currentSession.id)) {
 this.startNewChat();
 }
 },

 // Individual Session Actions
 async toggleSessionKeep(sessionId, event) {
 event?.stopPropagation();
 event?.preventDefault();

 try {
 const result = await this.sessionAPI.toggleKeep(sessionId);
 if (result.success) {
 await this.loadSessions();
 }
 } catch (error) {
 console.error('Failed to toggle keep:', error);
 alert('Failed to toggle keep: ' + error.message);
 }
 },

 async archiveSession(sessionId, event) {
 event?.stopPropagation();
 event?.preventDefault();

 try {
 const result = await this.sessionAPI.archiveSession(sessionId);
 if (result.success) {
 await this.loadSessions();

 // If we archived current session, start new chat
 if (this.currentSession?.id === sessionId) {
 this.startNewChat();
 }
 } else {
 alert('Failed to archive: ' + result.error);
 }
 } catch (error) {
 console.error('Failed to archive session:', error);
 alert('Failed to archive: ' + error.message);
 }
 },

 async unarchiveSession(sessionId, event) {
 event?.stopPropagation();
 event?.preventDefault();

 try {
 const result = await this.sessionAPI.unarchiveSession(sessionId);
 if (result.success) {
 await this.loadSessions();
 } else {
 alert('Failed to unarchive: ' + result.error);
 }
 } catch (error) {
 console.error('Failed to unarchive session:', error);
 alert('Failed to unarchive: ' + error.message);
 }
 },

 async deleteSessionAction(sessionId, event) {
 event?.stopPropagation();
 event?.preventDefault();

 if (!confirm('Are you sure you want to delete this session? This cannot be undone.')) {
 return;
 }

 try {
 const result = await this.sessionAPI.deleteSession(sessionId);
 if (result.success) {
 await this.loadSessions();

 // If we deleted current session, start new chat
 if (this.currentSession?.id === sessionId) {
 this.startNewChat();
 }
 } else {
 alert('Failed to delete: ' + result.error);
 }
 } catch (error) {
 console.error('Failed to delete session:', error);
 alert('Failed to delete: ' + error.message);
 }
 },

 // Filter watchers - reload sessions when filters change
 updateFilters() {
 this.loadSessions();
 if (this.bulkEditMode) {
 this.clearBulkSelections();
 }
 },

 // ============================================
 // SWIPE GESTURE HANDLERS
 // ============================================

 // Initialize swipe on touch start
 handleSwipeStart(event, sessionId) {
 // Don't interfere with bulk edit mode or current session
 if (this.bulkEditMode || this.currentSession?.id === sessionId) {
 return;
 }

 const touch = event.touches[0];

 this.swipeState = {
 ...this.swipeState,
 sessionId: sessionId,
 startX: touch.clientX,
 currentX: touch.clientX,
 startY: touch.clientY,
 currentY: touch.clientY,
 deltaX: 0,
 deltaY: 0,
 isScrolling: null,
 isSwiping: false,
 direction: null,
 velocity: 0,
 lastMoveTime: Date.now()
 };
 },

 // Track swipe movement
 handleSwipeMove(event, sessionId) {
 if (this.swipeState.sessionId !== sessionId) {
 return;
 }

 const touch = event.touches[0];
 const deltaX = touch.clientX - this.swipeState.startX;
 const deltaY = touch.clientY - this.swipeState.startY;

 // Determine if this is a scroll or swipe (only once)
 if (this.swipeState.isScrolling === null) {
 this.swipeState.isScrolling = Math.abs(deltaY) > Math.abs(deltaX);
 }

 // If scrolling, don't handle as swipe
 if (this.swipeState.isScrolling) {
 this.resetSwipe();
 return;
 }

 // Prevent scroll while swiping horizontally
 event.preventDefault();

 const now = Date.now();
 const timeDelta = now - this.swipeState.lastMoveTime;
 this.swipeState.velocity = Math.abs(deltaX) / timeDelta;
 this.swipeState.lastMoveTime = now;

 // Cap the deltaX to maxDistance
 const cappedDeltaX = Math.max(
 -this.swipeConfig.maxDistance,
 Math.min(this.swipeConfig.maxDistance, deltaX)
 );

 this.swipeState.currentX = touch.clientX;
 this.swipeState.currentY = touch.clientY;
 this.swipeState.deltaX = cappedDeltaX;
 this.swipeState.deltaY = deltaY;
 this.swipeState.isSwiping = true;
 this.swipeState.direction = deltaX < 0 ? 'left' : 'right';
 },

 // Handle swipe end and trigger actions
 async handleSwipeEnd(event, sessionId) {
 if (this.swipeState.sessionId !== sessionId || !this.swipeState.isSwiping) {
 this.resetSwipe();
 return;
 }

 const { deltaX, velocity, direction } = this.swipeState;
 const session = this.sessions.find(s => s.id === sessionId);

 if (!session) {
 this.resetSwipe();
 return;
 }

 const shouldTrigger = Math.abs(deltaX) >= this.swipeConfig.threshold ||
 velocity >= this.swipeConfig.velocityThreshold;

 if (shouldTrigger) {
 // Trigger haptic feedback
 this.triggerHapticFeedback();
 // Execute action based on direction and session state
 await this.executeSwipeAction(session, direction);
 } else {
 // Snap back with animation
 this.snapBackSwipe(sessionId);
 }

 // Reset after a delay to allow animation
 setTimeout(() => this.resetSwipe(), this.swipeConfig.snapBackDuration);
 },

 // Execute the appropriate action based on swipe direction
 async executeSwipeAction(session, direction) {
 if (direction === 'left') {
 // Swipe left: Archive (if not kept) or Delete (if archived)
 if (session.archived_at) {
 // Delete archived session (swipe is intentional, skip confirmation)
 try {
 const result = await this.sessionAPI.deleteSession(session.id);
 if (result.success) {
 await this.loadSessions();
 // If we deleted current session, start new chat
 if (this.currentSession?.id === session.id) {
 this.startNewChat();
 }
 } else {
 alert('Failed to delete: ' + result.error);
 }
 } catch (error) {
 console.error('Failed to delete session:', error);
 alert('Failed to delete: ' + error.message);
 }
 } else if (!session.is_kept) {
 // Archive non-kept session
 await this.archiveSession(session.id);
 }
 // If kept, do nothing (visual feedback only)
 } else if (direction === 'right') {
 // Swipe right: Unarchive (if archived) or Toggle keep/star (if not archived)
 if (session.archived_at) {
 // Unarchive the session
 await this.unarchiveSession(session.id);
 } else {
 // Toggle keep (star)
 await this.toggleSessionKeep(session.id);
 }
 }
 },

 // Snap back animation
 snapBackSwipe(sessionId) {
 this.swipeState.isSwiping = false;
 this.swipeState.deltaX = 0;
 },

 // Reset swipe state
 resetSwipe() {
 this.swipeState = {
 sessionId: null,
 startX: 0,
 currentX: 0,
 startY: 0,
 currentY: 0,
 deltaX: 0,
 deltaY: 0,
 isScrolling: null,
 isSwiping: false,
 direction: null,
 velocity: 0,
 lastMoveTime: 0
 };
 },

 // Get transform style for swipe animation
 getSwipeTransform(sessionId) {
 if (this.swipeState.sessionId !== sessionId || !this.swipeState.isSwiping) {
 return 'translateX(0)';
 }
 return `translateX(${this.swipeState.deltaX}px)`;
 },

 // Get background color based on swipe state
 getSwipeBackground(sessionId, session) {
 if (this.swipeState.sessionId !== sessionId || !this.swipeState.isSwiping) {
 return '';
 }

 const { direction, deltaX } = this.swipeState;
 const progress = Math.min(Math.abs(deltaX) / this.swipeConfig.threshold, 1);

 if (direction === 'left') {
 if (session.archived_at) {
 // Vibrant red for delete
 return `rgba(220, 38, 38, ${0.2 + progress * 0.5})`;
 } else if (!session.is_kept) {
 // Vibrant orange for archive
 return `rgba(249, 115, 22, ${0.2 + progress * 0.5})`;
 } else {
 // Subtle gray for disabled (kept sessions can't be archived)
 return `rgba(107, 114, 128, ${0.1 + progress * 0.2})`;
 }
 } else {
 if (session.archived_at) {
 // Vibrant blue/teal for unarchive
 return `rgba(20, 184, 166, ${0.2 + progress * 0.5})`;
 } else {
 // Vibrant amber/gold for keep/star
 return `rgba(245, 158, 11, ${0.2 + progress * 0.5})`;
 }
 }
 },

 // Trigger haptic feedback
 triggerHapticFeedback() {
 if (navigator.vibrate) {
 navigator.vibrate([20]);
 }
 },

 // ============================================
 // MESSAGE ACTION HANDLERS
 // ============================================

 /**
 * Retry an interaction by resending the question
 */
 async retryInteraction(interaction) {
 if (!this.isOnline || this.sending) {
 alert('Cannot retry while offline or sending another message');
 return;
 }

 // Set message and send
 this.message = interaction.question;
 await this.sendMessage();
 },

 /**
 * Copy answer to clipboard
 */
 async copyAnswer(interaction) {
 if (!interaction.answer) {
 return;
 }

 try {
 await navigator.clipboard.writeText(interaction.answer);

 // Show success feedback (toast notification)
 this.showToast('Answer copied to clipboard', 'success');
 } catch (error) {
 console.error('Copy failed:', error);
 this.showToast('Failed to copy answer', 'error');
 }
 },

 /**
 * Create artifact from interaction answer
 */
 async createArtifact(interaction) {
 if (!interaction.answer || !this.currentSession?.id) {
 return;
 }

 if (!this.isOnline) {
 alert('Cannot create artifacts while offline');
 return;
 }

 // Set loading state on interaction
 interaction.creatingArtifact = true;

 try {
 const response = await fetch('/api/v1/artifacts', {
 method: 'POST',
 headers: {
 'Content-Type': 'application/json',
 'Authorization': `Bearer ${await this.auth.getToken()}`,
 'Accept': 'application/json'
 },
 body: JSON.stringify({
 chat_interaction_id: interaction.id,
 chat_session_id: this.currentSession.id,
 content: interaction.answer,
 title: `Artifact from ${new Date().toLocaleDateString()}`,
 filetype: 'md',
 privacy_level: 'private'
 })
 });

 const result = await response.json();

 if (response.ok && result.artifact) {
 // Add artifact to interaction's artifacts array so it shows immediately
 if (!interaction.artifacts) {
 interaction.artifacts = [];
 }
 interaction.artifacts.push(result.artifact);

 this.showToast(`Artifact "${result.artifact.title}" created!`, 'success');
 } else {
 throw new Error(result.message || 'Failed to create artifact');
 }
 } catch (error) {
 console.error('Create artifact failed:', error);
 this.showToast('Failed to create artifact: ' + error.message, 'error');
 } finally {
 interaction.creatingArtifact = false;
 }
 },

 /**
 * Export interaction as Markdown file
 */
 async exportInteraction(interaction) {
 if (!interaction.question || !interaction.answer) {
 return;
 }

 try {
 // Build markdown content
 const timestamp = new Date(interaction.created_at).toLocaleString();
 let markdown = `# Chat Interaction\n\n`;
 markdown += `**Date:** ${timestamp}\n\n`;

 if (interaction.agent_name) {
 markdown += `**Agent:** ${interaction.agent_name}\n\n`;
 }

 markdown += `## Question\n\n${interaction.question}\n\n`;
 markdown += `## Answer\n\n${interaction.answer}\n`;

 // Add sources if available
 if (interaction.sources && interaction.sources.length > 0) {
 markdown += `\n## Sources\n\n`;
 interaction.sources.forEach((source, index) => {
 markdown += `${index + 1}. [${source.title || 'Source'}](${source.url || source.citation})\n`;
 });
 }

 // Create and download file
 const blob = new Blob([markdown], { type: 'text/markdown' });
 const url = URL.createObjectURL(blob);
 const a = document.createElement('a');
 a.href = url;
 a.download = `interaction-${interaction.id}-${Date.now()}.md`;
 document.body.appendChild(a);
 a.click();
 document.body.removeChild(a);
 URL.revokeObjectURL(url);

 this.showToast('Interaction exported', 'success');
 } catch (error) {
 console.error('Export failed:', error);
 this.showToast('Failed to export interaction', 'error');
 }
 },

 /**
 * Show toast notification (simple implementation)
 */
 showToast(message, type = 'info') {
 // Create toast element
 const toast = document.createElement('div');
 toast.className = `fixed bottom-4 left-1/2 -translate-x-1/2 px-4 py-2 rounded-lg shadow-lg text-white z-50 transition-opacity duration-300`;

 // Set background color based on type
 if (type === 'success') {
 toast.style.backgroundColor = 'rgb(34, 197, 94)'; // green-500
 } else if (type === 'error') {
 toast.style.backgroundColor = 'rgb(239, 68, 68)'; // red-500
 } else {
 toast.style.backgroundColor = 'rgb(59, 130, 246)'; // blue-500
 }

 toast.textContent = message;
 document.body.appendChild(toast);

 // Fade out and remove after 3 seconds
 setTimeout(() => {
 toast.style.opacity = '0';
 setTimeout(() => {
 document.body.removeChild(toast);
 }, 300);
 }, 3000);
 },

 // ============================================
 // ARTIFACT DRAWER HANDLERS
 // ============================================

 /**
  * Open artifact drawer and fetch content
  */
 async openArtifactDrawer(artifact) {
 console.log('Opening artifact drawer for:', artifact);
 this.selectedArtifact = artifact;
 this.showArtifactDrawer = true;
 this.loadingArtifact = true;
 this.artifactContent = '';
 this.artifactMode = 'preview';  // Reset to preview mode

 try {
 const response = await fetch(`/api/v1/artifacts/${artifact.id}`, {
 headers: {
 'Authorization': `Bearer ${await this.auth.getToken()}`,
 'Accept': 'application/json'
 }
 });

 const result = await response.json();

 if (response.ok && result.artifact) {
 // Store full artifact data
 this.selectedArtifact = result.artifact;
 this.artifactContent = result.artifact.content;

 // Initialize edit form with current values
 this.editFormData = {
 title: result.artifact.title || '',
 description: result.artifact.description || '',
 content: result.artifact.content || '',
 privacy_level: result.artifact.privacy_level || 'private'
 };
 this.originalContent = JSON.stringify(this.editFormData);  // Track original

 // Render markdown if needed
 if (artifact.filetype === 'md' || artifact.filetype === 'markdown') {
 this.artifactContent = window.marked ? window.marked.parse(result.artifact.content) : result.artifact.content;
 }

 // Load conversions
 await this.loadConversions();
 } else {
 throw new Error(result.message || 'Failed to load artifact');
 }
 } catch (error) {
 console.error('Failed to load artifact:', error);
 this.showToast('Failed to load artifact', 'error');
 this.closeArtifactDrawer();
 } finally {
 this.loadingArtifact = false;
 }
 },

 /**
  * Close artifact drawer
  */
 closeArtifactDrawer() {
 this.showArtifactDrawer = false;
 this.selectedArtifact = null;
 this.artifactContent = '';
 },

 /**
  * Download artifact file
  */
 async downloadArtifact(artifact) {
 if (!artifact) {
 return;
 }

 try {
 window.open(`/artifacts/${artifact.id}/download`, '_blank');
 this.showToast('Downloading artifact...', 'success');
 } catch (error) {
 console.error('Download failed:', error);
 this.showToast('Failed to download artifact', 'error');
 }
 },

 /**
  * Load conversions for current artifact
  */
 async loadConversions() {
 if (!this.selectedArtifact) {
 return;
 }

 this.loadingConversions = true;

 try {
 const response = await fetch(`/api/v1/artifacts/${this.selectedArtifact.id}/conversions`, {
 headers: {
 'Authorization': `Bearer ${await this.auth.getToken()}`,
 'Accept': 'application/json'
 }
 });

 if (response.ok) {
 const result = await response.json();
 this.artifactConversions = result.conversions || [];
 } else {
 console.error('Failed to load conversions');
 this.artifactConversions = [];
 }
 } catch (error) {
 console.error('Error loading conversions:', error);
 this.artifactConversions = [];
 } finally {
 this.loadingConversions = false;
 }
 },

 /**
  * Switch between preview and edit modes
  */
 switchArtifactMode(mode) {
 // Check for unsaved changes before switching away from edit
 if (this.artifactMode === 'edit' && this.hasUnsavedArtifactChanges()) {
 if (!confirm('You have unsaved changes. Discard them and switch mode?')) {
 return;
 }
 }

 this.artifactMode = mode;

 // If switching to edit, reset form to current values
 if (mode === 'edit') {
 this.editFormData = {
 title: this.selectedArtifact.title || '',
 description: this.selectedArtifact.description || '',
 content: this.selectedArtifact.content || '',
 privacy_level: this.selectedArtifact.privacy_level || 'private'
 };
 this.originalContent = JSON.stringify(this.editFormData);
 this.artifactErrors = {};
 }
 },

 /**
  * Check if there are unsaved changes
  */
 hasUnsavedArtifactChanges() {
 if (this.artifactMode !== 'edit') return false;
 const currentContent = JSON.stringify(this.editFormData);
 return currentContent !== this.originalContent;
 },

 /**
  * Save artifact changes
  */
 async saveArtifact() {
 if (!this.selectedArtifact || this.savingArtifact) return;

 // Basic validation
 if (!this.editFormData.title?.trim()) {
 this.artifactErrors = { title: 'Title is required' };
 this.showToast('Title is required', 'error');
 return;
 }

 this.savingArtifact = true;
 this.artifactErrors = {};

 try {
 const response = await fetch(`/api/v1/artifacts/${this.selectedArtifact.id}`, {
 method: 'PUT',
 headers: {
 'Content-Type': 'application/json',
 'Authorization': `Bearer ${await this.auth.getToken()}`,
 'Accept': 'application/json'
 },
 body: JSON.stringify(this.editFormData)
 });

 const result = await response.json();

 if (response.ok && result.success) {
 // Update local state
 this.selectedArtifact = result.artifact;
 this.artifactContent = result.artifact.content;
 this.originalContent = JSON.stringify(this.editFormData);

 // Re-render markdown if needed
 if (this.selectedArtifact.filetype === 'md' || this.selectedArtifact.filetype === 'markdown') {
 this.artifactContent = window.marked ? window.marked.parse(result.artifact.content) : result.artifact.content;
 }

 // Update interaction's artifact list if it exists
 this.updateInteractionArtifact(result.artifact);

 this.showToast('Artifact saved successfully', 'success');
 this.switchArtifactMode('preview');
 } else {
 if (result.errors) {
 this.artifactErrors = result.errors;
 }
 throw new Error(result.message || 'Failed to save artifact');
 }
 } catch (error) {
 console.error('Failed to save artifact:', error);
 this.showToast('Failed to save artifact: ' + error.message, 'error');
 } finally {
 this.savingArtifact = false;
 }
 },

 /**
  * Update artifact in interaction's artifacts array (for UI consistency)
  */
 updateInteractionArtifact(updatedArtifact) {
 this.interactions.forEach((interaction, index) => {
 if (interaction.artifacts && interaction.artifacts.length > 0) {
 const artifactIndex = interaction.artifacts.findIndex(a => a.id === updatedArtifact.id);
 if (artifactIndex > -1) {
 this.interactions[index].artifacts[artifactIndex] = updatedArtifact;
 }
 }
 });
 },

 /**
  * Cancel editing and revert changes
  */
 cancelArtifactEdit() {
 if (this.hasUnsavedArtifactChanges()) {
 if (!confirm('You have unsaved changes. Discard them?')) {
 return;
 }
 }
 this.switchArtifactMode('preview');
 },

 /**
  * Queue artifact conversion to different format
  */
 async convertArtifact(format) {
 if (!this.selectedArtifact || this.convertingArtifact) return;

 this.convertingArtifact = true;
 this.showSaveAsMenu = false;

 try {
 const response = await fetch(`/api/v1/artifacts/${this.selectedArtifact.id}/convert`, {
 method: 'POST',
 headers: {
 'Content-Type': 'application/json',
 'Authorization': `Bearer ${await this.auth.getToken()}`,
 'Accept': 'application/json'
 },
 body: JSON.stringify({
 output_format: format,
 template: 'eisvogel'  // Default template
 })
 });

 const result = await response.json();

 if (response.status === 202) {
 // Conversion queued successfully
 this.showToast(`Conversion to ${format.toUpperCase()} queued. Check your downloads soon.`, 'success');

 // Poll for completion (optional - or user can just check later)
 this.pollConversionStatus(result.conversion.id);
 } else {
 throw new Error(result.message || 'Failed to queue conversion');
 }
 } catch (error) {
 console.error('Failed to convert artifact:', error);
 this.showToast('Failed to convert artifact: ' + error.message, 'error');
 } finally {
 this.convertingArtifact = false;
 }
 },

 /**
  * Poll conversion status (optional)
  */
 async pollConversionStatus(conversionId) {
 // Simple polling - check every 5 seconds for up to 1 minute
 let attempts = 0;
 const maxAttempts = 12;

 const checkStatus = async () => {
 if (attempts >= maxAttempts) {
 this.showToast('Conversion is taking longer than expected. Please check later.', 'info');
 return;
 }

 try {
 const response = await fetch(`/api/v1/conversions/${conversionId}`, {
 headers: {
 'Authorization': `Bearer ${await this.auth.getToken()}`,
 'Accept': 'application/json'
 }
 });

 const result = await response.json();

 if (result.conversion.status === 'completed') {
 this.showToast('Conversion completed! Downloading...', 'success');
 // Use signed URL from API response
 if (result.conversion.download_url) {
 window.open(result.conversion.download_url, '_blank');
 } else {
 console.error('No download URL provided');
 this.showToast('Download URL not available', 'error');
 }
 } else if (result.conversion.status === 'failed') {
 this.showToast('Conversion failed: ' + result.conversion.error_message, 'error');
 } else {
 // Still processing, check again
 attempts++;
 setTimeout(checkStatus, 5000);
 }
 } catch (error) {
 console.error('Failed to check conversion status:', error);
 }
 };

 // Start polling after 5 seconds
 setTimeout(checkStatus, 5000);
 },

 /**
  * Format date for display
  */
 formatDate(dateString) {
 try {
 return new Date(dateString).toLocaleDateString('en-US', {
 year: 'numeric',
 month: 'short',
 day: 'numeric'
 });
 } catch (error) {
 return dateString;
 }
 }
 }
 }
 </script>
 @endpush
</x-layouts.pwa>

<x-layouts.pwa>
    <x-slot name="title">Knowledge</x-slot>

    <div class="flex flex-col h-full" x-data="knowledgeSearch()">
        <!-- Search Header -->
        <div class="bg-surface  border-b border-default  px-4 py-3">
            <div class="flex items-center space-x-2">
                <div class="flex-1 relative">
                    <input type="search" x-model="query" @input.debounce.300ms="search()" placeholder="Search knowledge base..." class="w-full pl-10 pr-4 py-2 bg-surface  border border-default  rounded-lg focus:ring-2 focus:ring-accent focus:border-transparent">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>

                <!-- Filter Button -->
                <button @click="showFilters = !showFilters" class="p-2 hover:bg-surface  rounded-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                    </svg>
                </button>
            </div>

            <!-- Filters -->
            <div x-show="showFilters" x-collapse class="mt-3 space-y-3">
                <!-- Search Type -->
                <div>
                    <label class="block text-sm font-medium mb-2">Search Type</label>
                    <div class="flex space-x-2">
                        <button @click="searchType = 'hybrid'; search()" class="flex-1 px-3 py-2 text-sm rounded-lg border" :class="searchType === 'hybrid' ? 'bg-accent text-white border-accent' : 'bg-surface  border-default '">
                            Hybrid
                        </button>
                        <button @click="searchType = 'semantic'; search()" class="flex-1 px-3 py-2 text-sm rounded-lg border" :class="searchType === 'semantic' ? 'bg-accent text-white border-accent' : 'bg-surface  border-default '">
                            Semantic
                        </button>
                        <button @click="searchType = 'full-text'; search()" class="flex-1 px-3 py-2 text-sm rounded-lg border" :class="searchType === 'full-text' ? 'bg-accent text-white border-accent' : 'bg-surface  border-default '">
                            Full-Text
                        </button>
                    </div>
                </div>

                <!-- Tag Filter -->
                <div x-show="availableTags.length > 0">
                    <label class="block text-sm font-medium mb-2">Tags</label>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="tag in availableTags" :key="tag">
                            <button @click="toggleTag(tag)" class="px-3 py-1 text-sm rounded-full border" :class="selectedTags.includes(tag) ? 'bg-accent text-white border-accent' : 'bg-surface  border-default '">
                                <span x-text="tag"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Results Area -->
        <div class="flex-1 overflow-y-auto">
            <!-- Loading State -->
            <div x-show="searching" class="flex items-center justify-center py-12">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent"></div>
            </div>

            <!-- Loading Recent Documents -->
            <div x-show="!searching && results.length === 0 && !query && loadingRecent" class="text-center py-12 px-4">
                <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-accent mx-auto"></div>
                <p class="mt-4 text-tertiary ">Loading recent documents...</p>
            </div>

            <!-- No Results -->
            <div x-show="!searching && results.length === 0 && query" class="text-center py-12 px-4">
                <svg class="w-16 h-16 text-tertiary mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3 class="text-lg font-semibold mb-2">No results found</h3>
                <p class="text-tertiary ">Try adjusting your search terms or filters</p>
            </div>

            <!-- Results List -->
            <div x-show="!searching && results.length > 0" class="p-4 space-y-3">
                <template x-for="result in results" :key="result.id">
                    <div @click="viewDocument(result)" class="bg-surface  rounded-lg border border-default  p-4 hover:border-accent cursor-pointer">
                        <!-- Document Header -->
                        <div class="flex items-start justify-between mb-2">
                            <h3 class="font-semibold flex-1" x-text="result.title"></h3>
                            <div class="flex items-center space-x-2 ml-2">
                                <!-- Offline Badge -->
                                <span x-show="result.cached" class="px-2 py-1 text-xs bg-green-100 dark:bg-green-900/30 text-[var(--palette-success-800)] dark:text-[var(--palette-success-200)] rounded-full">
                                    Offline
                                </span>
                                <!-- Score Badge -->
                                <span x-show="result.score" class="px-2 py-1 text-xs bg-accent/20 text-accent rounded-full" x-text="`${Math.round(result.score * 100)}%`"></span>
                            </div>
                        </div>

                        <!-- Snippet -->
                        <p class="text-sm text-tertiary  line-clamp-2 mb-2" x-text="result.snippet || result.description"></p>

                        <!-- Tags -->
                        <div x-show="result.tags && result.tags.length > 0" class="flex flex-wrap gap-1 mb-2">
                            <template x-for="(tag, index) in result.tags" :key="index">
                                <span class="px-2 py-0.5 text-xs bg-surface-elevated rounded-full" x-text="typeof tag === 'object' ? (tag.name || tag.tag || tag) : tag"></span>
                            </template>
                        </div>

                        <!-- Metadata -->
                        <div class="flex items-center justify-between text-xs text-tertiary">
                            <span x-text="result.type || 'Document'"></span>
                            <span x-text="formatDate(result.created_at)"></span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Document Viewer Modal -->
        <div x-show="viewingDocument" @click.self="closeDocument()" class="fixed inset-0 bg-black bg-opacity-50 z-50 overflow-y-auto">
            <div class="min-h-full flex items-center justify-center p-4">
                <div @click.stop class="bg-surface  rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
                    <!-- Document Header -->
                    <div class="border-b border-default  px-6 py-4 flex items-center justify-between">
                        <h2 class="text-xl font-semibold truncate flex-1" x-text="currentDocument?.title"></h2>
                        <div class="flex items-center space-x-2 ml-4">
                            <!-- Download Button -->
                            <button @click="downloadDocument()" class="p-2 hover:bg-surface  rounded-lg">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                            </button>
                            <!-- Close Button -->
                            <button @click="closeDocument()" class="p-2 hover:bg-surface  rounded-lg">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Document Content -->
                    <div class="flex-1 overflow-y-auto px-6 py-4">
                        <!-- Metadata -->
                        <div class="mb-4 pb-4 border-b border-default ">
                            <div class="flex flex-wrap gap-2 text-sm text-tertiary ">
                                <span x-show="currentDocument?.type" x-text="currentDocument?.type"></span>
                                <span>•</span>
                                <span x-text="formatDate(currentDocument?.created_at)"></span>
                                <span x-show="currentDocument?.external_source_identifier">•</span>
                                <a x-show="currentDocument?.external_source_identifier" :href="currentDocument?.external_source_identifier" target="_blank" class="text-accent hover:underline">View Source</a>
                            </div>

                            <!-- Tags -->
                            <div x-show="currentDocument?.tags && currentDocument.tags.length > 0" class="flex flex-wrap gap-2 mt-2">
                                <template x-for="(tag, index) in currentDocument?.tags" :key="index">
                                    <span class="px-2 py-1 text-xs bg-surface-elevated rounded-full" x-text="typeof tag === 'object' ? (tag.name || tag.tag || tag) : tag"></span>
                                </template>
                            </div>
                        </div>

                        <!-- Content -->
                        <div class="markdown" x-html="renderMarkdown(currentDocument?.content || currentDocument?.description || '')"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function knowledgeSearch() {
            return {
                // State
                query: '',
                results: [],
                searching: false,
                loadingRecent: false,
                searchType: 'hybrid',
                selectedTags: [],
                availableTags: [],
                showFilters: false,
                viewingDocument: false,
                currentDocument: null,
                isOnline: navigator.onLine,

                // Services
                knowledgeAPI: null,
                exporter: null,

                async init() {
                    // Wait for PWA services
                    await this.waitForPWA();

                    // Initialize services
                    this.knowledgeAPI = new window.PWA.KnowledgeAPI();
                    this.exporter = new window.PWA.ChatExporter();

                    // Load available tags
                    await this.loadTags();

                    // Load recent documents on page load
                    await this.loadRecentDocuments();

                    // Listen for online/offline
                    window.addEventListener('online', () => this.isOnline = true);
                    window.addEventListener('offline', () => this.isOnline = false);
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

                async loadTags() {
                    try {
                        // Get unique tags from cached knowledge documents
                        const documents = await window.PWA.db.getAll('knowledge');
                        const tagsSet = new Set();
                        documents.forEach(doc => {
                            if (doc.tags && Array.isArray(doc.tags)) {
                                doc.tags.forEach(tag => {
                                    // Handle both string tags and tag objects
                                    const tagName = typeof tag === 'object' ? (tag.name || tag.tag) : tag;
                                    if (tagName) {
                                        tagsSet.add(tagName);
                                    }
                                });
                            }
                        });
                        this.availableTags = Array.from(tagsSet).sort();
                    } catch (error) {
                        console.error('Failed to load tags:', error);
                    }
                },

                async loadRecentDocuments() {
                    this.loadingRecent = true;

                    try {
                        if (this.isOnline) {
                            // Fetch recent documents from API
                            const response = await this.knowledgeAPI.getRecentDocuments(20);
                            this.results = response.data || response;
                        } else {
                            // Load from IndexedDB cache
                            const documents = await window.PWA.db.getAll('knowledge');
                            this.results = documents
                                .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
                                .slice(0, 20)
                                .map(doc => ({ ...doc, cached: true }));
                        }
                    } catch (error) {
                        console.error('Failed to load recent documents:', error);
                        // Try offline fallback
                        try {
                            const documents = await window.PWA.db.getAll('knowledge');
                            this.results = documents
                                .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
                                .slice(0, 20)
                                .map(doc => ({ ...doc, cached: true }));
                        } catch (offlineError) {
                            console.error('Offline fallback failed:', offlineError);
                            this.results = [];
                        }
                    } finally {
                        this.loadingRecent = false;
                    }
                },

                async search() {
                    if (!this.query.trim()) {
                        // Reload recent documents when search is cleared
                        await this.loadRecentDocuments();
                        return;
                    }

                    this.searching = true;

                    try {
                        // Try online search first
                        if (this.isOnline) {
                            this.results = await this.knowledgeAPI.search(
                                this.query,
                                this.searchType,
                                this.selectedTags
                            );
                        } else {
                            // Offline fallback: search cached documents
                            this.results = await this.searchOffline();
                        }
                    } catch (error) {
                        console.error('Search failed:', error);
                        // Fallback to offline search on error
                        this.results = await this.searchOffline();
                    } finally {
                        this.searching = false;
                    }
                },

                async searchOffline() {
                    const documents = await window.PWA.db.getAll('knowledge');
                    const query = this.query.toLowerCase();

                    return documents
                        .filter(doc => {
                            // Text matching
                            const matchesQuery = (
                                doc.title?.toLowerCase().includes(query) ||
                                doc.description?.toLowerCase().includes(query) ||
                                doc.content?.toLowerCase().includes(query)
                            );

                            // Tag filtering (handle both string and object tags)
                            const matchesTags = this.selectedTags.length === 0 ||
                                (doc.tags && this.selectedTags.some(selectedTag =>
                                    doc.tags.some(docTag => {
                                        const docTagName = typeof docTag === 'object' ? (docTag.name || docTag.tag) : docTag;
                                        return docTagName === selectedTag;
                                    })
                                ));

                            return matchesQuery && matchesTags;
                        })
                        .map(doc => ({
                            ...doc,
                            cached: true,
                            snippet: this.extractSnippet(doc, query)
                        }))
                        .slice(0, 50); // Limit results
                },

                extractSnippet(doc, query) {
                    const content = doc.content || doc.description || '';
                    const index = content.toLowerCase().indexOf(query);

                    if (index === -1) {
                        return content.substring(0, 150);
                    }

                    const start = Math.max(0, index - 50);
                    const end = Math.min(content.length, index + query.length + 100);
                    return '...' + content.substring(start, end) + '...';
                },

                toggleTag(tag) {
                    const index = this.selectedTags.indexOf(tag);
                    if (index > -1) {
                        this.selectedTags.splice(index, 1);
                    } else {
                        this.selectedTags.push(tag);
                    }
                    this.search();
                },

                async viewDocument(result) {
                    try {
                        // Get full document
                        this.currentDocument = await this.knowledgeAPI.getDocument(result.id);
                        this.viewingDocument = true;
                    } catch (error) {
                        console.error('Failed to load document:', error);
                        // Use cached result as fallback
                        this.currentDocument = result;
                        this.viewingDocument = true;
                    }
                },

                closeDocument() {
                    this.viewingDocument = false;
                    this.currentDocument = null;
                },

                async downloadDocument() {
                    if (!this.currentDocument) return;

                    try {
                        const markdown = `# ${this.currentDocument.title}\n\n${this.currentDocument.content || this.currentDocument.description || ''}`;
                        const blob = new Blob([markdown], { type: 'text/markdown' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = `${this.currentDocument.title.replace(/[^a-z0-9]/gi, '-').toLowerCase()}.md`;
                        a.click();
                        URL.revokeObjectURL(url);
                    } catch (error) {
                        console.error('Download failed:', error);
                    }
                },

                renderMarkdown(text) {
                    if (!text) return '';

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

                    return html;
                },

                formatDate(dateString) {
                    if (!dateString) return '';
                    const date = new Date(dateString);
                    return date.toLocaleDateString(undefined, {
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    });
                }
            }
        }
    </script>
    @endpush
</x-layouts.pwa>

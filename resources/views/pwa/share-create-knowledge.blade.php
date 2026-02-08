<x-layouts.pwa>
    <x-slot name="title">Save to Knowledge</x-slot>

    <div class="flex flex-col h-full" x-data="shareKnowledgeCreator(@js($sharedData))">
        <!-- Content Preview -->
        <div class="flex-1 overflow-y-auto p-4 space-y-4">
            <!-- URL Preview -->
            <div x-show="data.url" class="bg-surface  rounded-lg border border-default  p-4">
                <h3 class="text-sm font-medium mb-2">Shared URL</h3>
                <a :href="data.url" target="_blank" class="text-sm text-accent hover:underline break-all" x-text="data.url"></a>

                <!-- Extract Content Button -->
                <button @click="extractUrlContent()" :disabled="extracting" class="mt-3 w-full px-4 py-2 bg-accent hover:bg-accent disabled:bg-accent/50 text-white rounded-lg text-sm font-medium">
                    <span x-show="!extracting">Extract Content from URL</span>
                    <span x-show="extracting">Extracting...</span>
                </button>
            </div>

            <!-- Text Preview -->
            <div x-show="data.text && !data.url" class="bg-surface  rounded-lg border border-default  p-4">
                <h3 class="text-sm font-medium mb-2">Shared Text</h3>
                <p class="text-sm whitespace-pre-wrap" x-text="data.text"></p>
            </div>

            <!-- File Preview -->
            <div x-show="data.file_info" class="bg-surface  rounded-lg border border-default  p-4">
                <h3 class="text-sm font-medium mb-2">Shared File</h3>
                <div class="flex items-center space-x-3">
                    <svg class="w-8 h-8 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate" x-text="data.file_info?.name"></p>
                        <p class="text-xs text-tertiary" x-text="formatBytes(data.file_info?.size)"></p>
                    </div>
                </div>

                <!-- Process File Button -->
                <button @click="processFile()" :disabled="processing" class="mt-3 w-full px-4 py-2 bg-accent hover:bg-accent disabled:bg-accent/50 text-white rounded-lg text-sm font-medium">
                    <span x-show="!processing">Process File</span>
                    <span x-show="processing">Processing...</span>
                </button>
            </div>

            <!-- Extracted Content -->
            <div x-show="extractedContent" class="space-y-4">
                <!-- Title -->
                <div>
                    <label class="block text-sm font-medium mb-2">Title</label>
                    <input type="text" x-model="title" placeholder="Document title" class="w-full px-4 py-2 bg-surface  border border-default  rounded-lg focus:ring-2 focus:ring-accent focus:border-transparent">
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-medium mb-2">Description</label>
                    <textarea x-model="description" rows="3" placeholder="Brief description" class="w-full px-4 py-2 bg-surface  border border-default  rounded-lg focus:ring-2 focus:ring-accent focus:border-transparent resize-none"></textarea>
                </div>

                <!-- Content Preview -->
                <div>
                    <label class="block text-sm font-medium mb-2">Content Preview</label>
                    <div class="bg-surface  rounded-lg border border-default  p-4 max-h-64 overflow-y-auto">
                        <div class="prose prose-sm dark:prose-invert max-w-none" x-html="renderMarkdown(extractedContent)"></div>
                    </div>
                </div>

                <!-- Tags -->
                <div>
                    <label class="block text-sm font-medium mb-2">Tags</label>
                    <div class="flex flex-wrap gap-2 mb-2" x-show="tags.length > 0">
                        <template x-for="(tag, index) in tags" :key="index">
                            <span class="inline-flex items-center px-3 py-1 text-sm bg-accent/20 text-accent rounded-full">
                                <span x-text="tag"></span>
                                <button @click="removeTag(index)" class="ml-2 text-accent hover:text-accent-hover">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </span>
                        </template>
                    </div>
                    <div class="flex space-x-2">
                        <input type="text" x-model="newTag" @keydown.enter.prevent="addTag()" placeholder="Add tag..." class="flex-1 px-4 py-2 bg-surface  border border-default  rounded-lg focus:ring-2 focus:ring-accent focus:border-transparent">
                        <button @click="addTag()" class="px-4 py-2 bg-surface  hover:bg-surface-elevated rounded-lg font-medium">
                            Add
                        </button>
                    </div>
                </div>

                <!-- Privacy Level -->
                <div>
                    <label class="block text-sm font-medium mb-2">Privacy</label>
                    <select x-model="privacy" class="w-full px-4 py-2 bg-surface  border border-default  rounded-lg focus:ring-2 focus:ring-accent focus:border-transparent">
                        <option value="private">Private</option>
                        <option value="shared">Shared</option>
                        <option value="public">Public</option>
                    </select>
                </div>

                <!-- TTL -->
                <div>
                    <label class="block text-sm font-medium mb-2">Time to Live</label>
                    <select x-model="ttl" class="w-full px-4 py-2 bg-surface  border border-default  rounded-lg focus:ring-2 focus:ring-accent focus:border-transparent">
                        <option value="">Permanent</option>
                        <option value="1">1 day</option>
                        <option value="7">7 days</option>
                        <option value="30">30 days</option>
                        <option value="90">90 days</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="border-t border-default  p-4 space-y-2">
            <button @click="saveKnowledge()" :disabled="!canSave || saving" class="w-full px-4 py-3 bg-accent hover:bg-accent disabled:bg-accent/50 disabled:opacity-50 text-white rounded-lg font-medium">
                <span x-show="!saving">Save to Knowledge Base</span>
                <span x-show="saving">Saving...</span>
            </button>

            <button @click="cancel()" :disabled="saving" class="w-full px-4 py-3 bg-surface  hover:bg-surface-elevated disabled:opacity-50 rounded-lg font-medium">
                Cancel
            </button>
        </div>

        <!-- Success/Error Messages -->
        <div x-show="message" class="fixed bottom-20 left-4 right-4 z-50">
            <div class="rounded-lg p-4 shadow-lg" :class="messageType === 'success' ? 'bg-accent text-white' : 'bg-[var(--palette-error-500)] text-white'">
                <p x-text="message"></p>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function shareKnowledgeCreator(initialData) {
            return {
                data: initialData || {},
                extractedContent: '',
                title: initialData?.title || '',
                description: '',
                tags: [],
                newTag: '',
                privacy: 'private',
                ttl: '',
                extracting: false,
                processing: false,
                saving: false,
                message: '',
                messageType: 'success',

                // Services
                knowledgeAPI: null,
                auth: null,

                get canSave() {
                    return this.extractedContent && this.title.trim().length > 0;
                },

                async init() {
                    await this.waitForPWA();
                    this.knowledgeAPI = new window.PWA.KnowledgeAPI();
                    this.auth = new window.PWA.AuthService();

                    // Auto-extract if URL is provided
                    if (this.data.url) {
                        await this.extractUrlContent();
                    } else if (this.data.text) {
                        // Use shared text as content
                        this.extractedContent = this.data.text;
                        this.suggestTitle();
                    }
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

                async extractUrlContent() {
                    if (!this.data.url) return;

                    this.extracting = true;
                    try {
                        const serverUrl = await this.auth.getServerUrl();
                        const headers = await this.auth.getAuthHeaders();

                        const response = await fetch(`${serverUrl}/api/v1/knowledge/extract-url`, {
                            method: 'POST',
                            headers,
                            body: JSON.stringify({ url: this.data.url })
                        });

                        if (!response.ok) {
                            throw new Error('Failed to extract URL content');
                        }

                        const result = await response.json();
                        this.extractedContent = result.content || '';
                        this.title = this.title || result.title || '';
                        this.description = result.description || '';

                        if (result.tags && Array.isArray(result.tags)) {
                            this.tags = result.tags;
                        }

                        this.showMessage('Content extracted successfully', 'success');
                    } catch (error) {
                        console.error('Extract URL error:', error);
                        this.showMessage('Failed to extract content: ' + error.message, 'error');
                    } finally {
                        this.extracting = false;
                    }
                },

                async processFile() {
                    if (!this.data.file_info) return;

                    this.processing = true;
                    try {
                        // File processing would happen server-side
                        // For now, show a placeholder message
                        this.showMessage('File processing not yet implemented', 'error');
                    } catch (error) {
                        console.error('Process file error:', error);
                        this.showMessage('Failed to process file: ' + error.message, 'error');
                    } finally {
                        this.processing = false;
                    }
                },

                suggestTitle() {
                    if (this.title) return;

                    // Generate title from first line of content
                    const firstLine = this.extractedContent.split('\n')[0];
                    this.title = firstLine.substring(0, 100).trim();
                },

                addTag() {
                    const tag = this.newTag.trim().toLowerCase();
                    if (tag && !this.tags.includes(tag)) {
                        this.tags.push(tag);
                        this.newTag = '';
                    }
                },

                removeTag(index) {
                    this.tags.splice(index, 1);
                },

                async saveKnowledge() {
                    if (!this.canSave) return;

                    this.saving = true;
                    try {
                        const serverUrl = await this.auth.getServerUrl();
                        const headers = await this.auth.getAuthHeaders();

                        const payload = {
                            title: this.title,
                            description: this.description,
                            content: this.extractedContent,
                            tags: this.tags,
                            privacy_level: this.privacy,
                            external_source_identifier: this.data.url || null
                        };

                        if (this.ttl) {
                            payload.ttl_days = parseInt(this.ttl);
                        }

                        const response = await fetch(`${serverUrl}/api/v1/knowledge`, {
                            method: 'POST',
                            headers,
                            body: JSON.stringify(payload)
                        });

                        if (!response.ok) {
                            throw new Error('Failed to save knowledge document');
                        }

                        this.showMessage('Saved to knowledge base!', 'success');

                        // Redirect to knowledge page after short delay
                        setTimeout(() => {
                            window.location.href = '/pwa/knowledge';
                        }, 1500);

                    } catch (error) {
                        console.error('Save knowledge error:', error);
                        this.showMessage('Failed to save: ' + error.message, 'error');
                    } finally {
                        this.saving = false;
                    }
                },

                cancel() {
                    window.location.href = '/pwa/';
                },

                showMessage(text, type = 'success') {
                    this.message = text;
                    this.messageType = type;
                    setTimeout(() => {
                        this.message = '';
                    }, 3000);
                },

                renderMarkdown(text) {
                    if (!text) return '';
                    return window.marked.parse(text);
                },

                formatBytes(bytes) {
                    if (!bytes) return '0 Bytes';
                    const k = 1024;
                    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
                }
            }
        }
    </script>
    @endpush
</x-layouts.pwa>

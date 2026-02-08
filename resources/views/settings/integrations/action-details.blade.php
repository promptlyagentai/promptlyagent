<x-layouts.app>
    <section class="w-full pb-20 xl:pb-0">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="'Configure ' . $provider->getActionTypeName() . ' Action'"
            :subheading="$action->name"
            wide>

            {{-- Success/Error Messages --}}
            @if (session('success'))
                <div class="mb-6 rounded-lg border border-[var(--palette-success-200)] bg-[var(--palette-success-100)] p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-[var(--palette-success-800)]">{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-lg border border-[var(--palette-error-200)] bg-[var(--palette-error-100)] p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-[var(--palette-error-700)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-[var(--palette-error-800)]">{{ session('error') }}</span>
                    </div>
                </div>
            @endif

            {{-- Responsive Grid Container --}}
            <div class="space-y-6 xl:grid xl:grid-cols-12 xl:gap-6 xl:space-y-0">

                {{-- LEFT COLUMN - Edit Form (60%) --}}
                <div class="space-y-6 xl:col-span-7">

                    {{-- Edit Form Card --}}
                    <div class="rounded-lg border border-default bg-surface p-6  ">
                        <form id="action-edit-form" action="{{ route('integrations.update-action', $action) }}" method="POST" class="space-y-6">
                            @csrf
                            @method('PATCH')

                            <div class="flex items-center gap-2 mb-6">
                                @if($svg = $provider->getActionIconSvg())
                                    <div class="h-6 w-6 text-secondary ">{!! $svg !!}</div>
                                @else
                                    <span class="text-2xl">{{ $provider->getActionIcon() }}</span>
                                @endif
                                <flux:heading size="lg">Edit Action</flux:heading>
                            </div>

                            {{-- Shared Form Fields --}}
                            <x-output-action.form-fields
                                :action="$action"
                                :provider="$provider"
                                :config-schema="$provider->getActionConfigSchema()" />

                            {{-- Provider-Specific Form Sections --}}
                            @foreach($provider->getEditFormSections() as $section)
                                @include($section, ['action' => $action])
                            @endforeach

                            {{-- Linked Agents --}}
                            <div>
                                <flux:label>Link to Agents (Optional)</flux:label>
                                <select
                                    name="agent_ids[]"
                                    multiple
                                    size="5"
                                    class="w-full rounded-lg border border-default bg-surface px-3 py-2 text-sm  ">
                                    @foreach($agents as $agent)
                                        <option value="{{ $agent->id }}"
                                            {{ in_array($agent->id, old('agent_ids', $action->agents->pluck('id')->toArray())) ? 'selected' : '' }}>
                                            {{ $agent->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <flux:text size="xs" class="mt-1 text-secondary">
                                    Select agents that should trigger this output action when they complete. Hold Ctrl/Cmd to select multiple.
                                </flux:text>
                                @error('agent_ids')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>

                            {{-- Linked Input Triggers --}}
                            <div>
                                <flux:label>Link to Input Triggers (Optional)</flux:label>
                                <select
                                    name="trigger_ids[]"
                                    multiple
                                    size="5"
                                    class="w-full rounded-lg border border-default bg-surface px-3 py-2 text-sm  ">
                                    @foreach($inputTriggers as $trigger)
                                        <option value="{{ $trigger->id }}"
                                            {{ in_array($trigger->id, old('trigger_ids', $action->inputTriggers->pluck('id')->toArray())) ? 'selected' : '' }}>
                                            {{ $trigger->name }}
                                        </option>
                                    @endforeach
                                </select>
                                <flux:text size="xs" class="mt-1 text-secondary">
                                    Select input triggers that should fire this output action when they execute. Hold Ctrl/Cmd to select multiple.
                                </flux:text>
                                @error('trigger_ids')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>

                            {{-- Status --}}
                            <div>
                                <flux:select name="status" label="Status" required>
                                    <option value="active" {{ old('status', $action->status) === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="paused" {{ old('status', $action->status) === 'paused' ? 'selected' : '' }}>Paused</option>
                                    <option value="disabled" {{ old('status', $action->status) === 'disabled' ? 'selected' : '' }}>Disabled</option>
                                </flux:select>
                                @error('status')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>

                        </form>
                    </div>

                    {{-- Execution Statistics Card --}}
                    <div class="rounded-lg border border-default bg-surface p-6  ">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="text-xl">📊</span>
                            <flux:heading size="sm">Execution Statistics</flux:heading>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <div class="text-center">
                                <div class="text-2xl font-bold text-primary">{{ $action->total_executions }}</div>
                                <div class="text-sm text-secondary">Total</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-success">{{ $action->successful_executions }}</div>
                                <div class="text-sm text-secondary">Successful</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-[var(--palette-error-700)]">{{ $action->failed_executions }}</div>
                                <div class="text-sm text-secondary">Failed</div>
                            </div>
                        </div>

                        @if($action->last_executed_at)
                            <div class="mt-4 pt-4 border-t border-default text-sm text-secondary">
                                Last executed: <span class="font-medium text-primary">{{ $action->last_executed_at->diffForHumans() }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Recent Execution Logs --}}
                    @if($action->logs->count() > 0)
                        <div class="rounded-lg border border-default bg-surface  ">
                            <div class="border-b border-default px-6 py-4 ">
                                <div class="flex items-center gap-2">
                                    <span class="text-xl">📝</span>
                                    <flux:heading size="sm">Recent Executions</flux:heading>
                                </div>
                            </div>
                            <div class="divide-y divide-default ">
                                @foreach($action->logs as $log)
                                    <div class="p-4 hover:bg-surface /50" x-data="{ expanded: false }">
                                        <div class="flex items-start justify-between">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-2">
                                                    @if($log->status === 'success')
                                                        <span class="flex h-2 w-2 rounded-full bg-success"></span>
                                                    @elseif($log->status === 'failed')
                                                        <span class="flex h-2 w-2 rounded-full bg-[var(--palette-error-500)]"></span>
                                                    @else
                                                        <span class="flex h-2 w-2 rounded-full bg-amber-500"></span>
                                                    @endif
                                                    <span class="text-sm font-medium text-primary">
                                                        {{ strtoupper($log->method) }} {{ parse_url($log->url, PHP_URL_HOST) }}
                                                    </span>
                                                    @if($log->response_code)
                                                        <span class="text-xs px-2 py-0.5 rounded {{ $log->status === 'success' ? 'bg-[var(--palette-success-100)] text-[var(--palette-success-800)] dark:bg-[var(--palette-success-900/30)] dark:text-[var(--palette-success-300)]' : 'bg-[var(--palette-error-100)] text-[var(--palette-error-800)] dark:bg-[var(--palette-error-900/30)] dark:text-[var(--palette-error-300)]' }}">
                                                            {{ $log->response_code }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="mt-1 text-xs text-secondary">
                                                    {{ $log->executed_at->format('M j, Y \a\t g:i A') }}
                                                    @if($log->duration_ms)
                                                        · {{ $log->duration_ms }}ms
                                                    @endif
                                                </div>
                                                @if($log->error_message)
                                                    <div class="mt-2 text-sm text-[var(--palette-error-700)]">
                                                        {{ $log->error_message }}
                                                    </div>
                                                @endif
                                            </div>
                                            <button @click="expanded = !expanded" class="text-tertiary hover:text-secondary">
                                                <svg class="h-5 w-5 transition-transform" :class="{ 'rotate-180': expanded }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                            </button>
                                        </div>

                                        <div x-show="expanded" x-collapse class="mt-3 pt-3 border-t border-default">
                                            <div class="space-y-3 text-sm">
                                                <div>
                                                    <div class="font-medium text-secondary ">Request URL:</div>
                                                    <div class="mt-1 font-mono text-xs text-secondary break-all">{{ $log->url }}</div>
                                                </div>
                                                @if($log->headers)
                                                    <div>
                                                        <div class="font-medium text-secondary ">Headers:</div>
                                                        <pre class="mt-1 text-xs text-secondary overflow-x-auto">{{ json_encode($log->headers, JSON_PRETTY_PRINT) }}</pre>
                                                    </div>
                                                @endif
                                                @if($log->body)
                                                    <div>
                                                        <div class="font-medium text-secondary ">Request Body:</div>
                                                        <pre class="mt-1 text-xs text-secondary overflow-x-auto">{{ Str::limit($log->body, 500) }}</pre>
                                                    </div>
                                                @endif
                                                @if($log->response_body)
                                                    <div>
                                                        <div class="font-medium text-secondary ">Response Body:</div>
                                                        <pre class="mt-1 text-xs text-secondary overflow-x-auto">{{ Str::limit($log->response_body, 500) }}</pre>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                </div>

                {{-- RIGHT COLUMN - Setup Instructions and Actions (40%) --}}
                <div class="space-y-6 xl:col-span-5">

                    {{-- Test Action Card --}}
                    <div class="rounded-lg border border-default bg-surface  ">
                        <div class="border-b border-default px-6 py-4 ">
                            <div class="flex items-center gap-2">
                                <span class="text-xl">🧪</span>
                                <flux:heading size="sm">Test Action</flux:heading>
                            </div>
                        </div>
                        <div class="p-6">
                            <p class="text-sm text-secondary mb-4">
                                Test this action with example payload data to ensure it's configured correctly.
                            </p>
                            <form action="{{ route('integrations.test-action', $action) }}" method="POST">
                                @csrf
                                <flux:button variant="primary" type="submit" class="w-full">
                                    Run Test
                                </flux:button>
                            </form>
                        </div>
                    </div>

                    {{-- Linked Agents & Triggers Card --}}
                    <div class="rounded-lg border border-default bg-surface  ">
                        <div class="border-b border-default px-6 py-4 ">
                            <div class="flex items-center gap-2">
                                <span class="text-xl">🔗</span>
                                <flux:heading size="sm">Linked To</flux:heading>
                            </div>
                        </div>
                        <div class="p-6 space-y-4">
                            @if($action->agents->count() > 0)
                                <div>
                                    <div class="text-sm font-medium text-secondary  mb-2">Agents ({{ $action->agents->count() }})</div>
                                    <div class="space-y-1">
                                        @foreach($action->agents as $agent)
                                            <div class="text-sm text-secondary">
                                                · {{ $agent->name }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if($action->inputTriggers->count() > 0)
                                <div>
                                    <div class="text-sm font-medium text-secondary  mb-2">Input Triggers ({{ $action->inputTriggers->count() }})</div>
                                    <div class="space-y-1">
                                        @foreach($action->inputTriggers as $trigger)
                                            <div class="text-sm text-secondary">
                                                · {{ $trigger->name }}
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if($action->agents->count() === 0 && $action->inputTriggers->count() === 0)
                                <div class="text-sm text-tertiary  italic">
                                    Not linked to any agents or triggers yet
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Setup Instructions Card --}}
                    <div class="rounded-lg border border-default bg-surface  "
                         x-data="{
                             setupInstructions: @js($setupInstructions),
                             renderedHtml: '',
                             renderMarkdown() {
                                 if (window.marked) {
                                     try {
                                         this.renderedHtml = window.marked.parse(this.setupInstructions);
                                         setTimeout(() => {
                                             if (window.hljs && this.$refs.instructions) {
                                                 this.$refs.instructions.querySelectorAll('pre code').forEach(block => {
                                                     window.hljs.highlightElement(block);
                                                 });
                                             }
                                         }, 10);
                                     } catch (e) {
                                         console.error('Markdown rendering error:', e);
                                     }
                                 }
                             }
                         }"
                         x-init="renderMarkdown()">
                        <div class="border-b border-default px-6 py-4 ">
                            <div class="flex items-center gap-2">
                                @if($svg = $provider->getActionIconSvg())
                                    <div class="h-5 w-5 text-secondary ">{!! $svg !!}</div>
                                @else
                                    <span class="text-xl">{{ $provider->getActionIcon() }}</span>
                                @endif
                                <flux:heading size="sm">Setup Instructions</flux:heading>
                            </div>
                        </div>
                        <div x-ref="instructions" class="markdown-compact">
                            <div class="markdown p-6" x-html="renderedHtml"></div>
                        </div>
                    </div>

                    {{-- Example Payload Card --}}
                    @if($examplePayload)
                        <div class="rounded-lg border border-default bg-surface  ">
                            <div class="border-b border-default px-6 py-4 ">
                                <div class="flex items-center gap-2">
                                    <span class="text-xl">📄</span>
                                    <flux:heading size="sm">Example Payload</flux:heading>
                                </div>
                            </div>
                            <div class="p-6">
                                <pre class="text-xs text-secondary overflow-x-auto">{{ json_encode($examplePayload, JSON_PRETTY_PRINT) }}</pre>
                            </div>
                        </div>
                    @endif

                </div>

            </div>

            {{-- Bottom Action Bar --}}
            <div class="mt-6">
                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('integrations.list-actions') }}" class="inline-flex items-center justify-center rounded-lg border border-default bg-surface px-4 py-2 text-sm font-medium text-secondary hover:bg-surface    ">
                        {{ __('Back to Actions') }}
                    </a>

                    <button type="submit" form="action-edit-form" class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent dark:bg-accent dark:hover:bg-accent">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <span>{{ __('Save Configuration') }}</span>
                    </button>
                </div>
            </div>

        </x-settings.layout>
    </section>

    @push('scripts')
    <script>
        function triggerLoader() {
            return {
                triggers: [],
                selectedTrigger: null,
                async loadTriggers() {
                    try {
                        const response = await fetch('/api/input-triggers?provider=webhook');
                        if (!response.ok) {
                            console.error('Failed to load triggers:', response.status, response.statusText);
                            return;
                        }
                        const data = await response.json();
                        console.log('Loaded triggers:', data);
                        this.triggers = data;
                    } catch (error) {
                        console.error('Failed to load triggers:', error);
                    }
                },
                populateFromTrigger() {
                    if (!this.selectedTrigger) return;
                    const trigger = this.triggers.find(t => t.id === this.selectedTrigger);
                    if (!trigger) return;

                    // Populate form fields
                    document.querySelector('[name="config[url]"]').value = trigger.webhook_url;
                    document.querySelector('[name="config[method]"]').value = 'POST';
                    document.querySelector('[name="webhook_secret"]').value = trigger.config.webhook_secret || '';
                    document.querySelector('[name="config[headers]"]').value = JSON.stringify({
                        'Content-Type': 'application/json',
                        'X-Trigger-Signature': '${hmac(concat(timestamp, nonce, body), secret)}',
                        'X-Trigger-Timestamp': '@{{timestamp}}',
                        'X-Trigger-Nonce': '@{{nonce}}'
                    }, null, 2);
                    document.querySelector('[name="config[body]"]').value = JSON.stringify({
                        'text': '@{{result}}',
                        'metadata': {
                            'source_agent': '@{{agent_name}}',
                            'source_session': '@{{session_id}}',
                            'execution_id': '@{{execution_id}}'
                        }
                    }, null, 2);
                }
            }
        }
    </script>
    @endpush
</x-layouts.app>

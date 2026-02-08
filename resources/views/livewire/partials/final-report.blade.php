<div class="final-report bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-default  p-6">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4 pb-4 border-b border-default ">
        <div class="flex items-center space-x-2">
            <svg class="h-5 w-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <h3 class="text-lg font-medium text-primary ">Research Complete</h3>
        </div>
        <span class="text-sm text-gray-500 dark:text-gray-400">{{ now()->format('H:i:s') }}</span>
    </div>

    <!-- Answer Content -->
    <div class="prose prose-sm max-w-none dark:prose-invert">
        {!! \Illuminate\Support\Str::markdown($answer) !!}
    </div>

    <!-- Sources Section (if provided) -->
    @if(!empty($sources) && count($sources) > 0)
        <div class="mt-6 pt-4 border-t border-default ">
            <h4 class="text-sm font-medium text-primary  mb-3">Sources Referenced</h4>
            <div class="grid gap-2">
                @foreach($sources as $source)
                    <div class="flex items-start space-x-2 text-sm">
                        <svg class="h-4 w-4 text-accent mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                        </svg>
                        <div class="flex-1 min-w-0">
                            @if(is_array($source))
                                <a href="{{ $source['url'] ?? '#' }}" 
                                   class="text-accent hover:underline truncate block"
                                   target="_blank" rel="noopener">
                                    {{ $source['title'] ?? $source['url'] ?? 'Source' }}
                                </a>
                                @if(isset($source['description']))
                                    <p class="text-gray-600 dark:text-gray-300 mt-1">{{ $source['description'] }}</p>
                                @endif
                            @else
                                <a href="{{ $source }}" 
                                   class="text-accent hover:underline truncate block"
                                   target="_blank" rel="noopener">
                                    {{ $source }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Action Buttons -->
    <div class="mt-6 pt-4 border-t border-default  flex justify-between items-center">
        <div class="text-sm text-gray-500 dark:text-gray-400">
            Research completed at {{ now()->format('g:i A') }}
        </div>
        <div class="flex space-x-2">
            <button type="button" 
                    onclick="navigator.clipboard.writeText(document.querySelector('.final-report .prose').innerText)"
                    class="inline-flex items-center px-3 py-1.5 border border-gray-300 dark:border-gray-600 shadow-sm text-sm font-medium rounded text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                <svg class="h-4 w-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                </svg>
                Copy
            </button>
        </div>
    </div>
</div>
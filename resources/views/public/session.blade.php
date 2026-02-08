<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $session->title ?? 'Shared Chat Session' }} - PromptlyAgent</title>
    <meta name="description" content="Publicly shared research session from PromptlyAgent">
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        /* Markdown prose styling */
        .prose {
            max-width: none;
        }
        .prose h1, .prose h2, .prose h3, .prose h4 {
            font-weight: 600;
            margin-top: 1.5em;
            margin-bottom: 0.5em;
        }
        .prose h1 { font-size: 1.5em; }
        .prose h2 { font-size: 1.3em; }
        .prose h3 { font-size: 1.1em; }
        .prose p {
            margin-top: 0.75em;
            margin-bottom: 0.75em;
        }
        .prose ul, .prose ol {
            margin-top: 0.75em;
            margin-bottom: 0.75em;
            padding-left: 1.5em;
        }
        .prose li {
            margin-top: 0.25em;
            margin-bottom: 0.25em;
        }
        .prose code {
            background-color: rgba(var(--color-zinc-800), 0.5);
            padding: 0.15em 0.4em;
            border-radius: 0.25rem;
            font-size: 0.9em;
        }
        .prose pre {
            background-color: rgba(var(--color-zinc-900), 0.8);
            border: 1px solid rgba(var(--color-zinc-700), 0.5);
            padding: 1em;
            border-radius: 0.5rem;
            overflow-x: auto;
            margin-top: 1em;
            margin-bottom: 1em;
        }
        .prose pre code {
            background-color: transparent;
            padding: 0;
        }
        .prose blockquote {
            border-left: 3px solid rgba(var(--color-accent), 0.5);
            padding-left: 1em;
            margin-left: 0;
            font-style: italic;
            color: rgba(var(--color-zinc-400), 1);
        }
        .prose strong {
            font-weight: 600;
        }
        .prose a {
            color: rgba(var(--color-accent), 1);
            text-decoration: none;
        }
        .prose a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body class="bg-zinc-950 text-zinc-100 antialiased">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-zinc-900/50 backdrop-blur-sm border-b border-zinc-800 sticky top-0 z-10">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex-1 min-w-0 mr-4">
                        <h1 class="text-xl sm:text-2xl font-bold text-zinc-100 truncate">{{ $session->title ?? 'Shared Chat Session' }}</h1>
                        <p class="text-xs sm:text-sm text-zinc-400 mt-1">
                            Shared {{ $session->public_shared_at?->diffForHumans() ?? 'recently' }}
                            @if($session->public_expires_at)
                                <span class="hidden sm:inline">· Expires {{ $session->public_expires_at->diffForHumans() }}</span>
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('home') }}" class="text-tropical-teal-400 hover:text-tropical-teal-300 font-medium text-sm sm:text-base whitespace-nowrap transition-colors">
                        Try PromptlyAgent
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="space-y-6">
                @forelse($interactions as $interaction)
                    <div class="bg-zinc-900/50 backdrop-blur-sm rounded-2xl border border-zinc-800 overflow-hidden">
                        <!-- Question -->
                        <div class="p-6 bg-gradient-to-b from-zinc-800/30 to-transparent">
                            <div class="flex items-start gap-4">
                                <div class="flex-shrink-0 w-10 h-10 rounded-xl bg-tropical-teal-500/10 flex items-center justify-center ring-1 ring-tropical-teal-500/20">
                                    <svg class="w-5 h-5 text-tropical-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <h3 class="text-sm font-semibold text-tropical-teal-400 mb-2 uppercase tracking-wide">Question</h3>
                                    <div class="text-zinc-100 leading-relaxed">
                                        {{ $interaction->question }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Answer -->
                        @if($interaction->answer)
                            <div class="p-6 border-t border-zinc-800/50">
                                <div class="flex items-start gap-4">
                                    <div class="flex-shrink-0 w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center ring-1 ring-emerald-500/20">
                                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between mb-3">
                                            <h3 class="text-sm font-semibold text-emerald-400 uppercase tracking-wide">Answer</h3>
                                            @if($interaction->agent)
                                                <span class="text-xs text-zinc-500 font-medium px-2 py-1 bg-zinc-800 rounded-md">{{ $interaction->agent->name }}</span>
                                            @endif
                                        </div>
                                        <div class="prose text-zinc-300 leading-relaxed">
                                            {!! \Illuminate\Support\Str::markdown($interaction->answer) !!}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif

                        <!-- Sources -->
                        @if($interaction->sources && $interaction->sources->count() > 0)
                            <div class="p-6 border-t border-zinc-800/50 bg-zinc-900/30">
                                <h4 class="text-sm font-semibold text-zinc-400 uppercase tracking-wide mb-4">Sources</h4>
                                <div class="grid gap-2">
                                    @foreach($interaction->sources as $chatInteractionSource)
                                        @if($chatInteractionSource->source)
                                            <a href="{{ $chatInteractionSource->source->url }}"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="flex items-center gap-3 p-4 rounded-xl bg-zinc-800/50 hover:bg-zinc-800 border border-zinc-700/50 hover:border-tropical-teal-500/50 transition-all duration-200 group min-w-0"
                                               title="{{ $chatInteractionSource->source->title }}">
                                                <svg class="w-4 h-4 text-zinc-500 group-hover:text-tropical-teal-400 flex-shrink-0 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                                </svg>
                                                <div class="flex-1 min-w-0 overflow-hidden">
                                                    <div class="text-sm font-medium text-zinc-200 group-hover:text-tropical-teal-300 transition-colors truncate">{{ $chatInteractionSource->source->title }}</div>
                                                    <div class="text-xs text-zinc-500 mt-0.5 truncate">{{ $chatInteractionSource->source->domain }}</div>
                                                </div>
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="text-center py-16 bg-zinc-900/30 rounded-2xl border border-zinc-800">
                        <svg class="mx-auto h-16 w-16 text-zinc-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                        </svg>
                        <p class="mt-4 text-sm text-zinc-500">This session has no interactions yet.</p>
                    </div>
                @endforelse
            </div>
        </main>

        <!-- Footer -->
        <footer class="border-t border-zinc-800 mt-16">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <div class="flex items-center justify-center gap-2 text-sm text-zinc-500">
                    <span>Powered by</span>
                    <a href="{{ route('home') }}" class="text-tropical-teal-400 hover:text-tropical-teal-300 font-semibold transition-colors">PromptlyAgent</a>
                    <span>·</span>
                    <span>Shared Research Session</span>
                </div>
            </div>
        </footer>
    </div>
</body>
</html>

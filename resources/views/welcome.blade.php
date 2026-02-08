<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'PromptlyAgent') }} - AI Development Framework for Laravel</title>

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">

    <!-- Import semantic theme system (includes Tailwind) -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- User Custom Color Scheme -->
    @auth
        @if(config('app.custom_color_schemes'))
            @php
                $userPreferences = auth()->user()->preferences ?? [];
                $customScheme = $userPreferences['custom_color_scheme'] ?? null;
                $enabled = $customScheme['enabled'] ?? false;
                $colors = $customScheme['colors'] ?? [];
            @endphp

            @if($enabled && !empty($colors))
                {!! \App\Services\ColorSchemeService::generateStyleTag($colors) !!}
            @endif
        @endif
    @endauth

    <!-- Custom Styles -->
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--color-page-bg);
            color: var(--color-text-primary);
        }
    </style>

    <!-- Dark Mode Script -->
    <script>
        // Initialize dark mode on page load
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
</head>
<body class="antialiased">

    <!-- Header -->
    <header class="fixed top-0 left-0 right-0 z-50 bg-surface-elevated/80 backdrop-blur-md border-b border-default">
        <div class="container mx-auto px-6 py-3">
            <div class="flex items-center justify-between">
                <!-- Logo -->
                <a href="/" class="flex items-center space-x-3">
                    <svg viewBox="0 0 32 32" class="h-10" xmlns="http://www.w3.org/2000/svg">
                        <style>
                            .nav-icon {
                                fill: var(--palette-primary-950, #0b1718);
                            }
                            .dark .nav-icon {
                                fill: var(--palette-primary-50, #eef6f7);
                            }
                        </style>
                        <g>
                            <!-- Left bracket -->
                            <rect x="7.14" y="5.95" class="nav-icon" width="4.36" height="2.57"/>
                            <polygon class="nav-icon" points="7.14,23.48 4.57,23.48 4.57,18.13 2.15,16 4.57,13.87 4.57,8.52 7.14,8.52 7.14,15.03 6.04,16 7.14,16.97"/>
                            <rect x="7.14" y="23.48" class="nav-icon" width="4.36" height="2.57"/>
                            <!-- Right bracket -->
                            <rect x="20.5" y="5.95" class="nav-icon" width="4.36" height="2.57"/>
                            <polygon class="nav-icon" points="24.86,23.48 27.43,23.48 27.43,18.13 29.85,16 27.43,13.87 27.43,8.52 24.86,8.52 24.86,15.03 25.96,16 24.86,16.97"/>
                            <rect x="20.5" y="23.48" class="nav-icon" width="4.36" height="2.57"/>
                            <!-- Center P -->
                            <path class="nav-icon" d="M16.99,11.16c0-0.24-0.19-0.43-0.43-0.43c-0.24,0-0.43,0.19-0.43,0.43s0.19,0.43,0.43,0.43C16.79,11.59,16.99,11.4,16.99,11.16z"/>
                            <circle class="nav-icon" cx="15.7" cy="15.15" r="0.43"/>
                            <path class="nav-icon" d="M17.58,9.58h-4.56c-0.73,0-1.32,0.59-1.32,1.32v2.04h2.51l1.48-1.33c-0.09-0.18-0.13-0.4-0.1-0.62c0.07-0.43,0.43-0.77,0.86-0.82c0.65-0.08,1.19,0.47,1.11,1.12c-0.06,0.44-0.41,0.79-0.84,0.85c-0.19,0.03-0.37,0-0.53-0.07l-1.72,1.54H11.7v1.18h3.06c0.15-0.41,0.55-0.69,1.02-0.65c0.47,0.04,0.86,0.41,0.91,0.88c0.07,0.6-0.4,1.11-0.99,1.11c-0.43,0-0.8-0.27-0.93-0.66H11.7v7.31l2.43-0.98v-2.74c0-0.59,0.48-1.07,1.07-1.07h2.38c0.08,0,0.16,0,0.24-0.01v-3.28c-0.39-0.15-0.65-0.55-0.58-1.01c0.06-0.42,0.4-0.75,0.82-0.79c0.56-0.06,1.04,0.38,1.04,0.93c0,0.4-0.25,0.73-0.59,0.87v3.17c0.61-0.16,1.15-0.49,1.59-0.92c0.64-0.64,1.04-1.53,1.04-2.51v-1.33C21.13,11.17,19.54,9.58,17.58,9.58z"/>
                            <path class="nav-icon" d="M18.17,13.45c-0.22,0-0.4,0.18-0.4,0.4c0,0.22,0.18,0.4,0.4,0.4c0.22,0,0.4-0.18,0.4-0.4C18.57,13.63,18.39,13.45,18.17,13.45z"/>
                        </g>
                    </svg>
                </a>

                <!-- Desktop Navigation -->
                <nav class="hidden md:flex items-center space-x-8">
                    @auth
                        <a href="{{ url('/dashboard') }}" class="text-secondary hover:text-accent transition-colors font-medium">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="text-secondary hover:text-accent transition-colors font-medium">Log in</a>
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="bg-accent hover:bg-accent-hover text-accent-foreground font-semibold py-2 px-5 rounded-lg transition-colors">Get Started</a>
                        @endif
                    @endauth

                    <!-- Dark Mode Toggle -->
                    <button id="theme-toggle" class="p-2 rounded-lg hover:bg-surface transition-colors text-secondary hover:text-accent" aria-label="Toggle dark mode">
                        <svg id="theme-toggle-light-icon" class="w-5 h-5 hidden" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" fill-rule="evenodd" clip-rule="evenodd"></path>
                        </svg>
                        <svg id="theme-toggle-dark-icon" class="w-5 h-5 hidden" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z"></path>
                        </svg>
                    </button>
                </nav>

                <!-- Mobile Menu Button -->
                <button class="md:hidden text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                    </svg>
                </button>
            </div>
        </div>
    </header>

    <main>
        <!-- Hero Section -->
        <section class="pt-32 pb-20 md:pt-40 md:pb-28 relative overflow-hidden bg-page">
            <div class="container mx-auto px-6 text-center relative">

                <svg viewBox="0 0 1290.8 618.64" class="mx-auto h-40 mb-8" xmlns="http://www.w3.org/2000/svg">
                    <style>
                        .hero-logo-text {
                            fill: var(--color-accent, #4a9199);
                            stroke: var(--palette-primary-700, #2d5f63);
                            stroke-width: 3;
                        }
                        .hero-logo-center {
                            fill: var(--color-accent, #4a9199);
                            stroke: var(--palette-primary-950, #0b1718);
                            stroke-width: 3;
                        }
                        .hero-logo-bracket {
                            fill: var(--palette-primary-400, #74b9be);
                            stroke: var(--palette-primary-700, #316468);
                            stroke-width: 3;
                        }
                        @media (prefers-color-scheme: dark) {
                            .hero-logo-bracket {
                                fill: var(--palette-primary-300, #97cace);
                                stroke: var(--palette-primary-600, #41868b);
                            }
                        }
                    </style>
                    <g>
                        <!-- PROMPTLYAGENT text with theme colors -->
                        <path class="hero-logo-text" d="M0,536.14h65.52c4.66,0,8.68,1.66,12.05,4.99c3.37,3.33,5.05,7.33,5.05,11.99v21.34c0,4.67-1.68,8.66-5.05,11.99c-3.37,3.33-7.38,4.99-12.05,4.99l-47.73,0.11c0.23,0,0.35,0.23,0.35,0.69c-0.16,0-0.27-0.04-0.35-0.12v26.51H0V536.14z M17.78,553.93v19.62H64.6v-19.62H17.78z"/>
                        <path class="hero-logo-text" d="M182.43,553.13v21.34c0,4.67-1.68,8.66-5.05,11.99c-3.37,3.33-7.38,4.99-12.05,4.99h-0.8l17.9,21.11v6.08h-18.36l-22.83-27.19l-23.64,0.11c0.23,0,0.35,0.23,0.35,0.69c-0.16,0-0.27-0.04-0.35-0.12v26.51H99.82v-82.5h65.52c4.66,0,8.68,1.66,12.05,4.99C180.75,544.46,182.43,548.46,182.43,553.13z M117.61,553.93v19.62h46.81v-19.62H117.61z"/>
                        <path class="hero-logo-text" d="M220.53,536.03h48.65c4.66,0,8.66,1.66,11.99,4.99c3.33,3.33,4.99,7.33,4.99,11.99v48.65c0,4.67-1.66,8.66-4.99,11.99c-3.33,3.33-7.33,4.99-11.99,4.99h-48.65c-4.74,0-8.76-1.64-12.05-4.93c-3.29-3.29-4.93-7.3-4.93-12.05v-48.65c0-4.74,1.64-8.76,4.93-12.05C211.77,537.68,215.78,536.03,220.53,536.03z M221.33,553.93v46.81h46.81v-46.81H221.33z"/>
                        <path class="hero-logo-text" d="M354.31,569.42l27.88-33.39h18.47v82.61h-17.9v-55.42l-28.46,33.96l-28.57-33.85v55.3h-17.78v-82.61h18.36L354.31,569.42z"/>
                        <path class="hero-logo-text" d="M423.61,536.14h65.52c4.66,0,8.68,1.66,12.05,4.99c3.37,3.33,5.05,7.33,5.05,11.99v21.34c0,4.67-1.68,8.66-5.05,11.99c-3.37,3.33-7.38,4.99-12.05,4.99l-47.73,0.11c0.23,0,0.35,0.23,0.35,0.69c-0.16,0-0.27-0.04-0.35-0.12v26.51h-17.78V536.14z M441.4,553.93v19.62h46.81v-19.62H441.4z"/>
                        <path class="hero-logo-text" d="M519.42,536.03h82.61v17.9h-32.36v64.71h-17.9v-64.71h-32.36V536.03z"/>
                        <path class="hero-logo-text" d="M619.93,618.64v-82.73h17.78v64.83h64.83v17.9H619.93z"/>
                        <path class="hero-logo-text" d="M767.94,536.03h21.45l-38.32,51.86v30.75h-17.9v-30.86l-15.03-20.19l-23.18-31.55h21.23l25.93,32.59L767.94,536.03z"/>
                        <path class="hero-logo-text" d="M818.31,536.03h48.53c4.74,0,8.78,1.66,12.1,4.99c3.33,3.33,4.99,7.33,4.99,11.99v65.63h-18.01v-26.62h-46.81v26.62h-17.78v-65.63c0-4.74,1.64-8.76,4.93-12.05C809.55,537.68,813.56,536.03,818.31,536.03z M819.11,574.12h46.81v-20.19h-46.81V574.12z"/>
                        <path class="hero-logo-text" d="M988.81,553.01v7.8H970.8v-6.88h-46.81v46.81h46.81v-12.39h-17.9v-17.9h35.91v31.21c0,4.67-1.66,8.66-4.99,11.99c-3.33,3.33-7.36,4.99-12.1,4.99h-48.53c-4.74,0-8.76-1.64-12.05-4.93c-3.29-3.29-4.93-7.3-4.93-12.05v-48.65c0-4.74,1.64-8.76,4.93-12.05c3.29-3.29,7.3-4.93,12.05-4.93h48.53c4.74,0,8.78,1.66,12.1,4.99C987.15,544.35,988.81,548.35,988.81,553.01z"/>
                        <path class="hero-logo-text" d="M1087.14,536.03v17.9h-58.29v14.46h46.93v17.9h-46.93v14.46h58.29v17.9h-76.3v-82.61H1087.14z"/>
                        <path class="hero-logo-text" d="M1172.28,591.1v-55.07h18.01v82.61h-18.36l-46.47-55.3v55.3h-17.78v-82.61h18.36L1172.28,591.1z"/>
                        <path class="hero-logo-text" d="M1208.19,536.03h82.61v17.9h-32.36v64.71h-17.9v-64.71h-32.36V536.03z"/>

                        <!-- Logo icon -->
                        <!-- Left bracket (warning to alert gradient) -->
                        <rect x="437.53" y="0" class="hero-logo-bracket" width="102.36" height="60.3"/>
                        <polygon class="hero-logo-bracket" points="437.53,411.32 377.23,411.32 377.23,285.76 320.47,235.81 377.23,185.86 377.23,60.3 437.53,60.3 437.53,213.12 411.74,235.81 437.53,258.5"/>
                        <rect x="437.53" y="411.32" class="hero-logo-bracket" width="102.36" height="60.3"/>

                        <!-- Right bracket (warning to alert gradient) -->
                        <rect x="750.91" y="0" class="hero-logo-bracket" width="102.36" height="60.3"/>
                        <polygon class="hero-logo-bracket" points="853.27,411.32 913.57,411.32 913.57,285.76 970.33,235.81 913.57,185.86 913.57,60.3 853.27,60.3 853.27,213.12 879.06,235.81 853.27,258.5"/>
                        <rect x="750.91" y="411.32" class="hero-logo-bracket" width="102.36" height="60.3"/>

                        <!-- Center P (accent color) -->
                        <path class="hero-logo-center" d="M668.52,122.19c0-5.61-4.57-10.18-10.18-10.18c-5.61,0-10.18,4.57-10.18,10.18c0,5.61,4.57,10.18,10.18,10.18C663.96,132.37,668.52,127.8,668.52,122.19z"/>
                        <circle class="hero-logo-center" cx="638.31" cy="215.96" r="10.2"/>
                        <path class="hero-logo-center" d="M682.54,85.26H575.63c-17.13,0-31.02,13.89-31.02,31v47.93h58.83l34.78-31.16c-2.19-4.26-3.13-9.28-2.23-14.58c1.71-10.08,10.05-18.01,20.2-19.2c15.18-1.78,27.95,11.09,25.97,26.3c-1.34,10.24-9.52,18.54-19.72,19.99c-4.47,0.64-8.75,0-12.52-1.59l-40.38,36.16h-64.91v27.79h71.88c3.48-9.53,12.94-16.19,23.83-15.32c11.01,0.88,20.07,9.61,21.3,20.57c1.59,14.08-9.44,26.05-23.2,26.05c-10.07,0-18.65-6.42-21.93-15.38h-71.88v171.52l57.08-23.03v-64.22c0-13.81,11.2-25.01,25.01-25.01h55.9c1.93,0,3.84-0.06,5.74-0.2v-77.01c-9.08-3.55-15.19-12.99-13.68-23.59c1.38-9.77,9.44-17.52,19.24-18.56c13.18-1.4,24.3,8.87,24.3,21.77c0,9.28-5.77,17.21-13.94,20.38v74.35c14.28-3.85,27.04-11.39,37.2-21.55c15.05-15.07,24.36-35.88,24.36-58.87v-31.25C765.82,122.56,728.53,85.26,682.54,85.26z"/>
                        <path class="hero-logo-center" d="M696.3,176.1c-5.17,0-9.37,4.2-9.37,9.37c0,5.19,4.2,9.39,9.37,9.39c5.17,0,9.37-4.2,9.37-9.39C705.68,180.3,701.48,176.1,696.3,176.1z"/>
                    </g>
                </svg>

                <h1 class="text-4xl md:text-6xl lg:text-7xl font-black tracking-tighter mb-6 text-accent">
                    Build Powerful AI Agents in <span class="gradient-text">Laravel</span>
                </h1>
                <p class="max-w-3xl mx-auto text-lg md:text-xl text-secondary mb-10">
                    PromptlyAgent.ai is the definitive framework for creating, managing, and deploying intelligent agents with the elegance you expect from the Laravel ecosystem.
                </p>
                <div class="flex justify-center items-center gap-4 flex-wrap">
                    @auth
                        <a href="{{ url('/dashboard') }}" class="bg-accent hover:bg-accent-hover text-accent-foreground font-semibold py-3 px-8 rounded-lg transition-all hover:scale-105">
                            Go to Dashboard
                        </a>
                    @else
                        <a href="{{ route('register') }}" class="bg-accent hover:bg-accent-hover text-accent-foreground font-semibold py-3 px-8 rounded-lg transition-all hover:scale-105">
                            Start Building for Free
                        </a>
                        <a href="{{ route('login') }}" class="bg-surface-elevated hover:bg-surface border border-default text-primary font-semibold py-3 px-8 rounded-lg transition-colors">
                            Log In
                        </a>
                    @endauth
                </div>
            </div>
        </section>

        <!-- Framework Features Section -->
        <section id="features" class="py-20 bg-surface-elevated">
            <div class="container mx-auto px-6">
                <div class="text-center mb-16">
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-accent/10 border border-accent/20 text-accent text-sm font-medium mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>
                        Extensible Framework
                    </div>
                    <h2 class="text-3xl md:text-5xl font-bold tracking-tight text-primary mb-4">Build Production-Ready AI Applications</h2>
                    <p class="text-lg text-secondary max-w-2xl mx-auto">Everything you need to create intelligent, scalable AI solutions with Laravel</p>
                </div>

                <!-- Feature Grid -->
                <div class="grid lg:grid-cols-3 gap-8 mb-16">
                    <!-- Multi-Agent AI System -->
                    <div class="bg-surface rounded-2xl border border-default p-8 hover:shadow-xl transition-all group">
                        <div class="mb-6 w-14 h-14 rounded-xl flex items-center justify-center bg-gradient-to-br from-accent to-accent-hover group-hover:scale-110 transition-transform">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                <path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01"/><path d="M16 15.5v.01"/><path d="M12 12v.01"/><path d="M11 17v.01"/><path d="M7 14v.01"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold mb-3 text-accent">Multi-Agent AI System</h3>
                        <p class="text-secondary mb-4">Configurable agents with custom tools and behaviors, multi-provider support, and real-time streaming responses</p>
                        <ul class="space-y-2 text-sm text-secondary">
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>OpenAI, Anthropic, Google, AWS Bedrock</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Workflow orchestration & tool ecosystems</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Knowledge Management -->
                    <div class="bg-surface rounded-2xl border border-default p-8 hover:shadow-xl transition-all group">
                        <div class="mb-6 w-14 h-14 rounded-xl flex items-center justify-center bg-gradient-to-br from-accent to-accent-hover group-hover:scale-110 transition-transform">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/><path d="M8 7h8"/><path d="M8 11h8"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold mb-3 text-accent">Advanced RAG Pipeline</h3>
                        <p class="text-secondary mb-4">Hybrid search combining vector embeddings and keywords for context-aware, intelligent responses</p>
                        <ul class="space-y-2 text-sm text-secondary">
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Meilisearch vector search integration</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Auto-refresh external knowledge sources</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Extensible Packages -->
                    <div class="bg-surface rounded-2xl border border-default p-8 hover:shadow-xl transition-all group">
                        <div class="mb-6 w-14 h-14 rounded-xl flex items-center justify-center bg-gradient-to-br from-accent to-accent-hover group-hover:scale-110 transition-transform">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                <path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold mb-3 text-accent">Package Framework</h3>
                        <p class="text-secondary mb-4">Zero-core-changes architecture with agent tools, input triggers, output actions, and knowledge sources</p>
                        <ul class="space-y-2 text-sm text-secondary">
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Notion, Slack, HTTP webhooks included</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Self-registering with auto-discovery</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Interactive AI Help -->
                    <div class="bg-surface rounded-2xl border border-default p-8 hover:shadow-xl transition-all group">
                        <div class="mb-6 w-14 h-14 rounded-xl flex items-center justify-center bg-gradient-to-br from-accent to-accent-hover group-hover:scale-110 transition-transform">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                <path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 6 0 0 0 12 0v-3.5"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold mb-3 text-accent">Interactive AI Help</h3>
                        <p class="text-secondary mb-4">Context-aware assistance powered by RAG with evergreen content that stays current with your code</p>
                        <ul class="space-y-2 text-sm text-secondary">
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Natural language queries on your project</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Customizable knowledge base</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Modern TALL Stack -->
                    <div class="bg-surface rounded-2xl border border-default p-8 hover:shadow-xl transition-all group">
                        <div class="mb-6 w-14 h-14 rounded-xl flex items-center justify-center bg-gradient-to-br from-accent to-accent-hover group-hover:scale-110 transition-transform">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                <rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold mb-3 text-accent">Modern TALL Stack</h3>
                        <p class="text-secondary mb-4">Beautiful UI with Tailwind 4, Livewire 3, Alpine.js, Flux UI components, and FilamentPHP admin</p>
                        <ul class="space-y-2 text-sm text-secondary">
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Dark mode with custom theme system</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Real-time updates without page refreshes</span>
                            </li>
                        </ul>
                    </div>

                    <!-- Performance & Scale -->
                    <div class="bg-surface rounded-2xl border border-default p-8 hover:shadow-xl transition-all group">
                        <div class="mb-6 w-14 h-14 rounded-xl flex items-center justify-center bg-gradient-to-br from-accent to-accent-hover group-hover:scale-110 transition-transform">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2">
                                <path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>
                            </svg>
                        </div>
                        <h3 class="text-2xl font-bold mb-3 text-accent">Performance & Scale</h3>
                        <p class="text-secondary mb-4">Horizon-powered queues, multi-layer caching with Redis, and Docker-ready with Laravel Sail</p>
                        <ul class="space-y-2 text-sm text-secondary">
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Sub-millisecond search with Meilisearch</span>
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-accent mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                                <span>Consistent development with Docker</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Screenshot Placeholders -->
                <div class="space-y-8">
                    <div class="text-center mb-8">
                        <h3 class="text-2xl md:text-3xl font-bold text-primary mb-2">See It In Action</h3>
                        <p class="text-secondary">Visual tour of PromptlyAgent's key features</p>
                    </div>

                    <div class="grid lg:grid-cols-2 gap-8">
                        <!-- Agent Chat Interface -->
                        <div class="bg-surface rounded-2xl border border-default overflow-hidden hover:shadow-xl transition-all group">
                            <div class="aspect-video bg-gradient-to-br from-accent/10 via-accent/5 to-accent-hover/10 flex items-center justify-center border-b border-default relative overflow-hidden">
                                <div class="absolute inset-0 bg-gradient-to-br from-accent/5 to-transparent"></div>
                                <div class="text-center p-8 relative z-10">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto mb-4 text-accent opacity-40 group-hover:scale-110 transition-transform">
                                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 10h.01"/><path d="M12 10h.01"/><path d="M16 10h.01"/>
                                    </svg>
                                    <p class="text-sm font-medium text-tertiary">[Agent Chat Interface Screenshot]</p>
                                </div>
                            </div>
                            <div class="p-6">
                                <h4 class="text-xl font-bold mb-2 text-primary">Real-time Agent Conversations</h4>
                                <p class="text-secondary text-sm">Stream responses in real-time, watch tool execution, and see knowledge source citations as agents work through complex tasks.</p>
                            </div>
                        </div>

                        <!-- Knowledge Dashboard -->
                        <div class="bg-surface rounded-2xl border border-default overflow-hidden hover:shadow-xl transition-all group">
                            <div class="aspect-video bg-gradient-to-br from-accent/10 via-accent/5 to-accent-hover/10 flex items-center justify-center border-b border-default relative overflow-hidden">
                                <div class="absolute inset-0 bg-gradient-to-br from-accent/5 to-transparent"></div>
                                <div class="text-center p-8 relative z-10">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto mb-4 text-accent opacity-40 group-hover:scale-110 transition-transform">
                                        <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1 0-5H20"/><path d="M8 7h6"/><path d="M8 11h8"/>
                                    </svg>
                                    <p class="text-sm font-medium text-tertiary">[Knowledge Dashboard Screenshot]</p>
                                </div>
                            </div>
                            <div class="p-6">
                                <h4 class="text-xl font-bold mb-2 text-primary">Knowledge Management</h4>
                                <p class="text-secondary text-sm">Upload documents, configure auto-refresh schedules, manage privacy levels, and organize with tags for powerful RAG capabilities.</p>
                            </div>
                        </div>

                        <!-- Package System -->
                        <div class="bg-surface rounded-2xl border border-default overflow-hidden hover:shadow-xl transition-all group">
                            <div class="aspect-video bg-gradient-to-br from-accent/10 via-accent/5 to-accent-hover/10 flex items-center justify-center border-b border-default relative overflow-hidden">
                                <div class="absolute inset-0 bg-gradient-to-br from-accent/5 to-transparent"></div>
                                <div class="text-center p-8 relative z-10">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto mb-4 text-accent opacity-40 group-hover:scale-110 transition-transform">
                                        <path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>
                                    </svg>
                                    <p class="text-sm font-medium text-tertiary">[Integration Packages Screenshot]</p>
                                </div>
                            </div>
                            <div class="p-6">
                                <h4 class="text-xl font-bold mb-2 text-primary">Self-Registering Packages</h4>
                                <p class="text-secondary text-sm">Extend capabilities with zero core changes. Add agent tools, input triggers, output actions, and OAuth providers through packages.</p>
                            </div>
                        </div>

                        <!-- Admin Panel -->
                        <div class="bg-surface rounded-2xl border border-default overflow-hidden hover:shadow-xl transition-all group">
                            <div class="aspect-video bg-gradient-to-br from-accent/10 via-accent/5 to-accent-hover/10 flex items-center justify-center border-b border-default relative overflow-hidden">
                                <div class="absolute inset-0 bg-gradient-to-br from-accent/5 to-transparent"></div>
                                <div class="text-center p-8 relative z-10">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="mx-auto mb-4 text-accent opacity-40 group-hover:scale-110 transition-transform">
                                        <rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>
                                    </svg>
                                    <p class="text-sm font-medium text-tertiary">[FilamentPHP Admin Screenshot]</p>
                                </div>
                            </div>
                            <div class="p-6">
                                <h4 class="text-xl font-bold mb-2 text-primary">Professional Admin Panel</h4>
                                <p class="text-secondary text-sm">Manage agents, users, integrations, and system configuration through a beautiful FilamentPHP admin interface with full CRUD capabilities.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Component States & Theming -->
        <section class="py-20 bg-page">
            <div class="container mx-auto px-6">
                <div class="text-center mb-16">
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-accent/10 border border-accent/20 text-accent text-sm font-medium mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="13.5" cy="6.5" r=".5"/><circle cx="17.5" cy="10.5" r=".5"/><circle cx="8.5" cy="7.5" r=".5"/><circle cx="6.5" cy="12.5" r=".5"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/></svg>
                        Themeability
                    </div>
                    <h2 class="text-3xl md:text-5xl font-bold tracking-tight text-primary mb-4">Design System & Color Framework</h2>
                    <p class="text-lg text-secondary max-w-2xl mx-auto">Comprehensive semantic color system with full dark mode support and custom theme capabilities</p>
                </div>

                <!-- Button States Showcase -->
                <div class="bg-surface-elevated rounded-2xl border border-default shadow-lg p-8 mb-12">
                    <h3 class="text-2xl font-bold mb-8 text-center text-primary">UI Component States</h3>
                    <div class="grid md:grid-cols-4 gap-8">
                        <div>
                            <p class="text-sm font-semibold text-center mb-4 text-secondary">Default</p>
                            <div class="space-y-3">
                                <button class="w-full px-4 py-2 rounded-lg font-medium text-white bg-[var(--palette-primary-600)]">Primary</button>
                                <button class="w-full px-4 py-2 rounded-lg font-medium bg-[var(--palette-primary-100)] text-[var(--palette-primary-700)] border border-[var(--palette-primary-200)]">Secondary</button>
                                <button class="w-full px-4 py-2 rounded-lg font-medium border border-[var(--palette-primary-600)] text-[var(--palette-primary-600)] bg-transparent">Tertiary</button>
                            </div>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-center mb-4 text-secondary">Hover</p>
                            <div class="space-y-3">
                                <button class="w-full px-4 py-2 rounded-lg font-medium bg-[var(--palette-primary-700)] text-white">Primary</button>
                                <button class="w-full px-4 py-2 rounded-lg font-medium bg-[var(--palette-primary-200)] text-[var(--palette-primary-800)] border border-[var(--palette-primary-300)]">Secondary</button>
                                <button class="w-full px-4 py-2 rounded-lg font-medium border-2 border-[var(--palette-primary-600)] text-[var(--palette-primary-700)] bg-[var(--palette-primary-50)]">Tertiary</button>
                            </div>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-center mb-4 text-secondary">Active</p>
                            <div class="space-y-3">
                                <button class="w-full px-4 py-2 rounded-lg font-medium bg-[var(--palette-primary-800)] text-white ring-2 ring-[var(--palette-primary-600)] ring-offset-2">Primary</button>
                                <button class="w-full px-4 py-2 rounded-lg font-medium bg-[var(--palette-primary-200)] text-[var(--palette-primary-900)] border-2 border-[var(--palette-primary-600)]">Secondary</button>
                                <button class="w-full px-4 py-2 rounded-lg font-medium border-2 border-[var(--palette-primary-700)] text-[var(--palette-primary-700)] bg-[var(--palette-primary-100)]">Tertiary</button>
                            </div>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-center mb-4 text-secondary">Disabled</p>
                            <div class="space-y-3">
                                <button class="w-full px-4 py-2 rounded-lg font-medium bg-[var(--palette-primary-300)] text-white opacity-50 cursor-not-allowed">Primary</button>
                                <button class="w-full px-4 py-2 rounded-lg font-medium bg-[var(--palette-primary-50)] text-[var(--palette-primary-400)] border border-[var(--palette-primary-200)] opacity-50 cursor-not-allowed">Secondary</button>
                                <button class="w-full px-4 py-2 rounded-lg font-medium border border-[var(--palette-primary-300)] text-[var(--palette-primary-400)] bg-transparent opacity-50 cursor-not-allowed">Tertiary</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Color Palettes Showcase -->
        <section class="py-20 bg-surface-elevated">
            <div class="container mx-auto px-6">
                <div class="text-center mb-16">
                    <h2 class="text-3xl md:text-4xl font-bold tracking-tight text-accent">Color System</h2>
                    <p class="mt-4 text-lg text-secondary max-w-2xl mx-auto">Comprehensive color palettes defining the visual language of PromptlyAgent</p>
                </div>

                <div class="space-y-12 max-w-7xl mx-auto">
                    <!-- Primary Palette -->
                    <div class="bg-surface rounded-2xl border border-default shadow-lg p-8">
                        <div class="mb-6">
                            <h3 class="text-2xl font-bold text-primary mb-2">Primary Palette</h3>
                            <p class="text-sm font-mono text-secondary">CSS: <code class="bg-code px-2 py-1 rounded">--palette-primary-{shade}</code></p>
                        </div>
                        <div class="grid grid-cols-11 gap-2">
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-50" style="background-color: var(--palette-primary-50);"></div>
                                <span class="text-xs font-semibold text-primary">50</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-100" style="background-color: var(--palette-primary-100);"></div>
                                <span class="text-xs font-semibold text-primary">100</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-200" style="background-color: var(--palette-primary-200);"></div>
                                <span class="text-xs font-semibold text-primary">200</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-300" style="background-color: var(--palette-primary-300);"></div>
                                <span class="text-xs font-semibold text-primary">300</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-400" style="background-color: var(--palette-primary-400);"></div>
                                <span class="text-xs font-semibold text-primary">400</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-500" style="background-color: var(--palette-primary-500);"></div>
                                <span class="text-xs font-semibold text-primary">500</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-600" style="background-color: var(--palette-primary-600);"></div>
                                <span class="text-xs font-semibold text-primary">600</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-700" style="background-color: var(--palette-primary-700);"></div>
                                <span class="text-xs font-semibold text-primary">700</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-800" style="background-color: var(--palette-primary-800);"></div>
                                <span class="text-xs font-semibold text-primary">800</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-900" style="background-color: var(--palette-primary-900);"></div>
                                <span class="text-xs font-semibold text-primary">900</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-primary-950" style="background-color: var(--palette-primary-950);"></div>
                                <span class="text-xs font-semibold text-primary">950</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Neutral Palette -->
                    <div class="bg-surface rounded-2xl border border-default shadow-lg p-8">
                        <div class="mb-6">
                            <h3 class="text-2xl font-bold text-primary mb-2">Neutral Palette</h3>
                            <p class="text-sm font-mono text-secondary">CSS: <code class="bg-code px-2 py-1 rounded">--palette-neutral-{shade}</code></p>
                        </div>
                        <div class="grid grid-cols-11 gap-2">
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2 border border-default" data-color="--palette-neutral-50" style="background-color: var(--palette-neutral-50);"></div>
                                <span class="text-xs font-semibold text-primary">50</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-100" style="background-color: var(--palette-neutral-100);"></div>
                                <span class="text-xs font-semibold text-primary">100</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-200" style="background-color: var(--palette-neutral-200);"></div>
                                <span class="text-xs font-semibold text-primary">200</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-300" style="background-color: var(--palette-neutral-300);"></div>
                                <span class="text-xs font-semibold text-primary">300</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-400" style="background-color: var(--palette-neutral-400);"></div>
                                <span class="text-xs font-semibold text-primary">400</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-500" style="background-color: var(--palette-neutral-500);"></div>
                                <span class="text-xs font-semibold text-primary">500</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-600" style="background-color: var(--palette-neutral-600);"></div>
                                <span class="text-xs font-semibold text-primary">600</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-700" style="background-color: var(--palette-neutral-700);"></div>
                                <span class="text-xs font-semibold text-primary">700</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-800" style="background-color: var(--palette-neutral-800);"></div>
                                <span class="text-xs font-semibold text-primary">800</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-900" style="background-color: var(--palette-neutral-900);"></div>
                                <span class="text-xs font-semibold text-primary">900</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-neutral-950" style="background-color: var(--palette-neutral-950);"></div>
                                <span class="text-xs font-semibold text-primary">950</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Success Palette -->
                    <div class="bg-surface rounded-2xl border border-default shadow-lg p-8">
                        <div class="mb-6">
                            <h3 class="text-2xl font-bold text-primary mb-2">Success Palette</h3>
                            <p class="text-sm font-mono text-secondary">CSS: <code class="bg-code px-2 py-1 rounded">--palette-success-{shade}</code></p>
                        </div>
                        <div class="grid grid-cols-11 gap-2">
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-50" style="background-color: var(--palette-success-50);"></div>
                                <span class="text-xs font-semibold text-primary">50</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-100" style="background-color: var(--palette-success-100);"></div>
                                <span class="text-xs font-semibold text-primary">100</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-200" style="background-color: var(--palette-success-200);"></div>
                                <span class="text-xs font-semibold text-primary">200</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-300" style="background-color: var(--palette-success-300);"></div>
                                <span class="text-xs font-semibold text-primary">300</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-400" style="background-color: var(--palette-success-400);"></div>
                                <span class="text-xs font-semibold text-primary">400</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-500" style="background-color: var(--palette-success-500);"></div>
                                <span class="text-xs font-semibold text-primary">500</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-600" style="background-color: var(--palette-success-600);"></div>
                                <span class="text-xs font-semibold text-primary">600</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-700" style="background-color: var(--palette-success-700);"></div>
                                <span class="text-xs font-semibold text-primary">700</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-800" style="background-color: var(--palette-success-800);"></div>
                                <span class="text-xs font-semibold text-primary">800</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-900" style="background-color: var(--palette-success-900);"></div>
                                <span class="text-xs font-semibold text-primary">900</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-success-950" style="background-color: var(--palette-success-950);"></div>
                                <span class="text-xs font-semibold text-primary">950</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Warning Palette -->
                    <div class="bg-surface rounded-2xl border border-default shadow-lg p-8">
                        <div class="mb-6">
                            <h3 class="text-2xl font-bold text-primary mb-2">Warning Palette</h3>
                            <p class="text-sm font-mono text-secondary">CSS: <code class="bg-code px-2 py-1 rounded">--palette-warning-{shade}</code></p>
                        </div>
                        <div class="grid grid-cols-11 gap-2">
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-50" style="background-color: var(--palette-warning-50);"></div>
                                <span class="text-xs font-semibold text-primary">50</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-100" style="background-color: var(--palette-warning-100);"></div>
                                <span class="text-xs font-semibold text-primary">100</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-200" style="background-color: var(--palette-warning-200);"></div>
                                <span class="text-xs font-semibold text-primary">200</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-300" style="background-color: var(--palette-warning-300);"></div>
                                <span class="text-xs font-semibold text-primary">300</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-400" style="background-color: var(--palette-warning-400);"></div>
                                <span class="text-xs font-semibold text-primary">400</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-500" style="background-color: var(--palette-warning-500);"></div>
                                <span class="text-xs font-semibold text-primary">500</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-600" style="background-color: var(--palette-warning-600);"></div>
                                <span class="text-xs font-semibold text-primary">600</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-700" style="background-color: var(--palette-warning-700);"></div>
                                <span class="text-xs font-semibold text-primary">700</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-800" style="background-color: var(--palette-warning-800);"></div>
                                <span class="text-xs font-semibold text-primary">800</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-900" style="background-color: var(--palette-warning-900);"></div>
                                <span class="text-xs font-semibold text-primary">900</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-warning-950" style="background-color: var(--palette-warning-950);"></div>
                                <span class="text-xs font-semibold text-primary">950</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Error Palette -->
                    <div class="bg-surface rounded-2xl border border-default shadow-lg p-8">
                        <div class="mb-6">
                            <h3 class="text-2xl font-bold text-primary mb-2">Error Palette</h3>
                            <p class="text-sm font-mono text-secondary">CSS: <code class="bg-code px-2 py-1 rounded">--palette-error-{shade}</code></p>
                        </div>
                        <div class="grid grid-cols-11 gap-2">
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-50" style="background-color: var(--palette-error-50);"></div>
                                <span class="text-xs font-semibold text-primary">50</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-100" style="background-color: var(--palette-error-100);"></div>
                                <span class="text-xs font-semibold text-primary">100</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-200" style="background-color: var(--palette-error-200);"></div>
                                <span class="text-xs font-semibold text-primary">200</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-300" style="background-color: var(--palette-error-300);"></div>
                                <span class="text-xs font-semibold text-primary">300</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-400" style="background-color: var(--palette-error-400);"></div>
                                <span class="text-xs font-semibold text-primary">400</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-500" style="background-color: var(--palette-error-500);"></div>
                                <span class="text-xs font-semibold text-primary">500</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-600" style="background-color: var(--palette-error-600);"></div>
                                <span class="text-xs font-semibold text-primary">600</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-700" style="background-color: var(--palette-error-700);"></div>
                                <span class="text-xs font-semibold text-primary">700</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-800" style="background-color: var(--palette-error-800);"></div>
                                <span class="text-xs font-semibold text-primary">800</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-900" style="background-color: var(--palette-error-900);"></div>
                                <span class="text-xs font-semibold text-primary">900</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-error-950" style="background-color: var(--palette-error-950);"></div>
                                <span class="text-xs font-semibold text-primary">950</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Notify Palette -->
                    <div class="bg-surface rounded-2xl border border-default shadow-lg p-8">
                        <div class="mb-6">
                            <h3 class="text-2xl font-bold text-primary mb-2">Notify Palette</h3>
                            <p class="text-sm font-mono text-secondary">CSS: <code class="bg-code px-2 py-1 rounded">--palette-notify-{shade}</code></p>
                        </div>
                        <div class="grid grid-cols-11 gap-2">
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-50" style="background-color: var(--palette-notify-50);"></div>
                                <span class="text-xs font-semibold text-primary">50</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-100" style="background-color: var(--palette-notify-100);"></div>
                                <span class="text-xs font-semibold text-primary">100</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-200" style="background-color: var(--palette-notify-200);"></div>
                                <span class="text-xs font-semibold text-primary">200</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-300" style="background-color: var(--palette-notify-300);"></div>
                                <span class="text-xs font-semibold text-primary">300</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-400" style="background-color: var(--palette-notify-400);"></div>
                                <span class="text-xs font-semibold text-primary">400</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-500" style="background-color: var(--palette-notify-500);"></div>
                                <span class="text-xs font-semibold text-primary">500</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-600" style="background-color: var(--palette-notify-600);"></div>
                                <span class="text-xs font-semibold text-primary">600</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-700" style="background-color: var(--palette-notify-700);"></div>
                                <span class="text-xs font-semibold text-primary">700</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-800" style="background-color: var(--palette-notify-800);"></div>
                                <span class="text-xs font-semibold text-primary">800</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-900" style="background-color: var(--palette-notify-900);"></div>
                                <span class="text-xs font-semibold text-primary">900</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-notify-950" style="background-color: var(--palette-notify-950);"></div>
                                <span class="text-xs font-semibold text-primary">950</span>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Special Colors -->
                    <div class="bg-surface rounded-2xl border border-default shadow-lg p-8">
                        <div class="mb-6">
                            <h3 class="text-2xl font-bold text-primary mb-2">Special Colors</h3>
                            <p class="text-sm font-mono text-secondary">Utility colors for specific use cases</p>
                        </div>
                        <div class="grid grid-cols-5 gap-4">
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-dark-page" style="background-color: var(--palette-dark-page);"></div>
                                <span class="text-xs font-semibold text-primary">Dark Page</span>
                                <code class="text-xs font-mono text-tertiary">--palette-dark-page</code>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-dark-surface" style="background-color: var(--palette-dark-surface);"></div>
                                <span class="text-xs font-semibold text-primary">Dark Surface</span>
                                <code class="text-xs font-mono text-tertiary">--palette-dark-surface</code>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-dark-surface-elevated" style="background-color: var(--palette-dark-surface-elevated);"></div>
                                <span class="text-xs font-semibold text-primary">Elevated</span>
                                <code class="text-xs font-mono text-tertiary">--palette-dark-surface-elevated</code>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2 border border-default" data-color="--palette-white" style="background-color: var(--palette-white);"></div>
                                <span class="text-xs font-semibold text-primary">White</span>
                                <code class="text-xs font-mono text-tertiary">--palette-white</code>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                            <div class="flex flex-col items-center">
                                <div class="w-full aspect-square rounded-lg mb-2" data-color="--palette-black" style="background-color: var(--palette-black);"></div>
                                <span class="text-xs font-semibold text-primary">Black</span>
                                <code class="text-xs font-mono text-tertiary">--palette-black</code>
                                <span class="text-xs font-mono text-tertiary hex-value"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="border-t border-default bg-surface-elevated">
        <div class="container mx-auto px-6 py-8">
            <div class="flex flex-col md:flex-row justify-between items-center space-y-4 md:space-y-0">
                <div class="flex items-center space-x-3">
                    <svg viewBox="0 0 32 32" class="h-8" xmlns="http://www.w3.org/2000/svg">
                        <style>
                            .footer-icon {
                                fill: var(--palette-primary-950, #0b1718);
                            }
                            .dark .footer-icon {
                                fill: var(--palette-primary-50, #eef6f7);
                            }
                        </style>
                        <g>
                            <!-- Left bracket -->
                            <rect x="7.14" y="5.95" class="footer-icon" width="4.36" height="2.57"/>
                            <polygon class="footer-icon" points="7.14,23.48 4.57,23.48 4.57,18.13 2.15,16 4.57,13.87 4.57,8.52 7.14,8.52 7.14,15.03 6.04,16 7.14,16.97"/>
                            <rect x="7.14" y="23.48" class="footer-icon" width="4.36" height="2.57"/>
                            <!-- Right bracket -->
                            <rect x="20.5" y="5.95" class="footer-icon" width="4.36" height="2.57"/>
                            <polygon class="footer-icon" points="24.86,23.48 27.43,23.48 27.43,18.13 29.85,16 27.43,13.87 27.43,8.52 24.86,8.52 24.86,15.03 25.96,16 24.86,16.97"/>
                            <rect x="20.5" y="23.48" class="footer-icon" width="4.36" height="2.57"/>
                            <!-- Center P -->
                            <path class="footer-icon" d="M16.99,11.16c0-0.24-0.19-0.43-0.43-0.43c-0.24,0-0.43,0.19-0.43,0.43s0.19,0.43,0.43,0.43C16.79,11.59,16.99,11.4,16.99,11.16z"/>
                            <circle class="footer-icon" cx="15.7" cy="15.15" r="0.43"/>
                            <path class="footer-icon" d="M17.58,9.58h-4.56c-0.73,0-1.32,0.59-1.32,1.32v2.04h2.51l1.48-1.33c-0.09-0.18-0.13-0.4-0.1-0.62c0.07-0.43,0.43-0.77,0.86-0.82c0.65-0.08,1.19,0.47,1.11,1.12c-0.06,0.44-0.41,0.79-0.84,0.85c-0.19,0.03-0.37,0-0.53-0.07l-1.72,1.54H11.7v1.18h3.06c0.15-0.41,0.55-0.69,1.02-0.65c0.47,0.04,0.86,0.41,0.91,0.88c0.07,0.6-0.4,1.11-0.99,1.11c-0.43,0-0.8-0.27-0.93-0.66H11.7v7.31l2.43-0.98v-2.74c0-0.59,0.48-1.07,1.07-1.07h2.38c0.08,0,0.16,0,0.24-0.01v-3.28c-0.39-0.15-0.65-0.55-0.58-1.01c0.06-0.42,0.4-0.75,0.82-0.79c0.56-0.06,1.04,0.38,1.04,0.93c0,0.4-0.25,0.73-0.59,0.87v3.17c0.61-0.16,1.15-0.49,1.59-0.92c0.64-0.64,1.04-1.53,1.04-2.51v-1.33C21.13,11.17,19.54,9.58,17.58,9.58z"/>
                            <path class="footer-icon" d="M18.17,13.45c-0.22,0-0.4,0.18-0.4,0.4c0,0.22,0.18,0.4,0.4,0.4c0.22,0,0.4-0.18,0.4-0.4C18.57,13.63,18.39,13.45,18.17,13.45z"/>
                        </g>
                    </svg>
                    <span class="text-tertiary">&copy; {{ date('Y') }} PromptlyAgent.ai. All rights reserved.</span>
                </div>
                <div class="flex items-center space-x-6 text-secondary">
                    <a href="#" class="hover:text-accent transition-colors">Twitter</a>
                    <a href="#" class="hover:text-accent transition-colors">GitHub</a>
                    <a href="#" class="hover:text-accent transition-colors">Discord</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Dark Mode Toggle Script -->
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeToggleLightIcon = document.getElementById('theme-toggle-light-icon');
        const themeToggleDarkIcon = document.getElementById('theme-toggle-dark-icon');

        // Show correct icon on page load
        if (localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            themeToggleLightIcon.classList.remove('hidden');
        } else {
            themeToggleDarkIcon.classList.remove('hidden');
        }

        themeToggleBtn.addEventListener('click', function() {
            // Toggle icons
            themeToggleDarkIcon.classList.toggle('hidden');
            themeToggleLightIcon.classList.toggle('hidden');

            // Toggle dark mode
            if (localStorage.theme === 'dark') {
                document.documentElement.classList.remove('dark');
                localStorage.theme = 'light';
            } else {
                document.documentElement.classList.add('dark');
                localStorage.theme = 'dark';
            }
        });
    </script>

    <!-- Color Palette Hex Value Display Script -->
    <script>
        // Function to convert RGB to HEX
        function rgbToHex(rgb) {
            // Handle both rgb() and rgba() formats
            const result = rgb.match(/\d+/g);
            if (!result || result.length < 3) return '';

            const r = parseInt(result[0]);
            const g = parseInt(result[1]);
            const b = parseInt(result[2]);

            return '#' + [r, g, b].map(x => {
                const hex = x.toString(16);
                return hex.length === 1 ? '0' + hex : hex;
            }).join('').toUpperCase();
        }

        // Populate hex values for all color swatches
        document.addEventListener('DOMContentLoaded', function() {
            const colorSwatches = document.querySelectorAll('[data-color]');

            colorSwatches.forEach(swatch => {
                const cssVar = swatch.getAttribute('data-color');
                const computedColor = getComputedStyle(swatch).backgroundColor;
                const hexValue = rgbToHex(computedColor);

                // Find the hex-value span in the same parent
                const hexSpan = swatch.closest('.flex').querySelector('.hex-value');
                if (hexSpan && hexValue) {
                    hexSpan.textContent = hexValue;
                }
            });
        });
    </script>

</body>
</html>

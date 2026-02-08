@php
    use Knuckles\Scribe\Tools\Utils as u;
@endphp
<a href="#" id="nav-button">
    <span>
        MENU
        <img src="{!! $assetPathPrefix !!}images/navbar.png" alt="navbar-image"/>
    </span>
</a>
<div class="tocify-wrapper">
    {{-- PromptlyAgent Logo (matching main application) --}}
    <div class="flex items-center justify-center w-full px-4 py-3">
        <a href="{{ config('app.url', '/') }}" class="block">
            <svg viewBox="0 0 1290.8 618.64" style="width: 133px; height: 64px;" xmlns="http://www.w3.org/2000/svg">
                <style>
                    .logo-text { fill: #FFFFFF; }
                    .logo-center {
                        fill: var(--color-accent, #4a9199);
                        stroke: var(--palette-primary-950, #0b1718);
                        stroke-width: 3;
                    }
                    .logo-bracket {
                        fill: var(--palette-primary-400, #74b9be);
                        stroke: var(--palette-primary-700, #316468);
                        stroke-width: 3;
                    }
                    .dark .logo-bracket {
                        fill: var(--palette-primary-300, #97cace);
                        stroke: var(--palette-primary-600, #41868b);
                    }
                </style>
                <g>
                    <!-- PROMPTLYAGENT text -->
                    <path class="logo-text" d="M0,536.14h65.52c4.66,0,8.68,1.66,12.05,4.99c3.37,3.33,5.05,7.33,5.05,11.99v21.34c0,4.67-1.68,8.66-5.05,11.99c-3.37,3.33-7.38,4.99-12.05,4.99l-47.73,0.11c0.23,0,0.35,0.23,0.35,0.69c-0.16,0-0.27-0.04-0.35-0.12v26.51H0V536.14z M17.78,553.93v19.62H64.6v-19.62H17.78z"/>
                    <path class="logo-text" d="M182.43,553.13v21.34c0,4.67-1.68,8.66-5.05,11.99c-3.37,3.33-7.38,4.99-12.05,4.99h-0.8l17.9,21.11v6.08h-18.36l-22.83-27.19l-23.64,0.11c0.23,0,0.35,0.23,0.35,0.69c-0.16,0-0.27-0.04-0.35-0.12v26.51H99.82v-82.5h65.52c4.66,0,8.68,1.66,12.05,4.99C180.75,544.46,182.43,548.46,182.43,553.13z M117.61,553.93v19.62h46.81v-19.62H117.61z"/>
                    <path class="logo-text" d="M220.53,536.03h48.65c4.66,0,8.66,1.66,11.99,4.99c3.33,3.33,4.99,7.33,4.99,11.99v48.65c0,4.67-1.66,8.66-4.99,11.99c-3.33,3.33-7.33,4.99-11.99,4.99h-48.65c-4.74,0-8.76-1.64-12.05-4.93c-3.29-3.29-4.93-7.3-4.93-12.05v-48.65c0-4.74,1.64-8.76,4.93-12.05C211.77,537.68,215.78,536.03,220.53,536.03z M221.33,553.93v46.81h46.81v-46.81H221.33z"/>
                    <path class="logo-text" d="M354.31,569.42l27.88-33.39h18.47v82.61h-17.9v-55.42l-28.46,33.96l-28.57-33.85v55.3h-17.78v-82.61h18.36L354.31,569.42z"/>
                    <path class="logo-text" d="M423.61,536.14h65.52c4.66,0,8.68,1.66,12.05,4.99c3.37,3.33,5.05,7.33,5.05,11.99v21.34c0,4.67-1.68,8.66-5.05,11.99c-3.37,3.33-7.38,4.99-12.05,4.99l-47.73,0.11c0.23,0,0.35,0.23,0.35,0.69c-0.16,0-0.27-0.04-0.35-0.12v26.51h-17.78V536.14z M441.4,553.93v19.62h46.81v-19.62H441.4z"/>
                    <path class="logo-text" d="M519.42,536.03h82.61v17.9h-32.36v64.71h-17.9v-64.71h-32.36V536.03z"/>
                    <path class="logo-text" d="M619.93,618.64v-82.73h17.78v64.83h64.83v17.9H619.93z"/>
                    <path class="logo-text" d="M767.94,536.03h21.45l-38.32,51.86v30.75h-17.9v-30.86l-15.03-20.19l-23.18-31.55h21.23l25.93,32.59L767.94,536.03z"/>
                    <path class="logo-text" d="M818.31,536.03h48.53c4.74,0,8.78,1.66,12.1,4.99c3.33,3.33,4.99,7.33,4.99,11.99v65.63h-18.01v-26.62h-46.81v26.62h-17.78v-65.63c0-4.74,1.64-8.76,4.93-12.05C809.55,537.68,813.56,536.03,818.31,536.03z M819.11,574.12h46.81v-20.19h-46.81V574.12z"/>
                    <path class="logo-text" d="M988.81,553.01v7.8H970.8v-6.88h-46.81v46.81h46.81v-12.39h-17.9v-17.9h35.91v31.21c0,4.67-1.66,8.66-4.99,11.99c-3.33,3.33-7.36,4.99-12.1,4.99h-48.53c-4.74,0-8.76-1.64-12.05-4.93c-3.29-3.29-4.93-7.3-4.93-12.05v-48.65c0-4.74,1.64-8.76,4.93-12.05c3.29-3.29,7.3-4.93,12.05-4.93h48.53c4.74,0,8.78,1.66,12.1,4.99C987.15,544.35,988.81,548.35,988.81,553.01z"/>
                    <path class="logo-text" d="M1087.14,536.03v17.9h-58.29v14.46h46.93v17.9h-46.93v14.46h58.29v17.9h-76.3v-82.61H1087.14z"/>
                    <path class="logo-text" d="M1172.28,591.1v-55.07h18.01v82.61h-18.36l-46.47-55.3v55.3h-17.78v-82.61h18.36L1172.28,591.1z"/>
                    <path class="logo-text" d="M1208.19,536.03h82.61v17.9h-32.36v64.71h-17.9v-64.71h-32.36V536.03z"/>

                    <!-- Logo icon with theme colors -->
                    <!-- Left bracket (warning to alert gradient) -->
                    <rect x="437.53" y="0" class="logo-bracket" width="102.36" height="60.3"/>
                    <polygon class="logo-bracket" points="437.53,411.32 377.23,411.32 377.23,285.76 320.47,235.81 377.23,185.86 377.23,60.3 437.53,60.3 437.53,213.12 411.74,235.81 437.53,258.5"/>
                    <rect x="437.53" y="411.32" class="logo-bracket" width="102.36" height="60.3"/>

                    <!-- Right bracket (warning to alert gradient) -->
                    <rect x="750.91" y="0" class="logo-bracket" width="102.36" height="60.3"/>
                    <polygon class="logo-bracket" points="853.27,411.32 913.57,411.32 913.57,285.76 970.33,235.81 913.57,185.86 913.57,60.3 853.27,60.3 853.27,213.12 879.06,235.81 853.27,258.5"/>
                    <rect x="750.91" y="411.32" class="logo-bracket" width="102.36" height="60.3"/>

                    <!-- Center P (accent color) -->
                    <path class="logo-center" d="M668.52,122.19c0-5.61-4.57-10.18-10.18-10.18c-5.61,0-10.18,4.57-10.18,10.18c0,5.61,4.57,10.18,10.18,10.18C663.96,132.37,668.52,127.8,668.52,122.19z"/>
                    <circle class="logo-center" cx="638.31" cy="215.96" r="10.2"/>
                    <path class="logo-center" d="M682.54,85.26H575.63c-17.13,0-31.02,13.89-31.02,31v47.93h58.83l34.78-31.16c-2.19-4.26-3.13-9.28-2.23-14.58c1.71-10.08,10.05-18.01,20.2-19.2c15.18-1.78,27.95,11.09,25.97,26.3c-1.34,10.24-9.52,18.54-19.72,19.99c-4.47,0.64-8.75,0-12.52-1.59l-40.38,36.16h-64.91v27.79h71.88c3.48-9.53,12.94-16.19,23.83-15.32c11.01,0.88,20.07,9.61,21.3,20.57c1.59,14.08-9.44,26.05-23.2,26.05c-10.07,0-18.65-6.42-21.93-15.38h-71.88v171.52l57.08-23.03v-64.22c0-13.81,11.2-25.01,25.01-25.01h55.9c1.93,0,3.84-0.06,5.74-0.2v-77.01c-9.08-3.55-15.19-12.99-13.68-23.59c1.38-9.77,9.44-17.52,19.24-18.56c13.18-1.4,24.3,8.87,24.3,21.77c0,9.28-5.77,17.21-13.94,20.38v74.35c14.28-3.85,27.04-11.39,37.2-21.55c15.05-15.07,24.36-35.88,24.36-58.87v-31.25C765.82,122.56,728.53,85.26,682.54,85.26z"/>
                    <path class="logo-center" d="M696.3,176.1c-5.17,0-9.37,4.2-9.37,9.37c0,5.19,4.2,9.39,9.37,9.39c5.17,0,9.37-4.2,9.37-9.39C705.68,180.3,701.48,176.1,696.3,176.1z"/>
                </g>
            </svg>
        </a>
    </div>

    <div class="search">
        <input type="text" class="search" id="input-search" placeholder="{{ u::trans("scribe::labels.search") }}">
    </div>

    <div id="toc">
        @foreach($headings as $h1)
            <ul id="tocify-header-{{ $h1['slug'] }}" class="tocify-header">
                <li class="tocify-item level-1" data-unique="{!! $h1['slug'] !!}">
                    <a href="#{!! $h1['slug'] !!}">{!! $h1['name'] !!}</a>
                </li>
                @if(count($h1['subheadings']) > 0)
                    <ul id="tocify-subheader-{!! $h1['slug'] !!}" class="tocify-subheader">
                        @foreach($h1['subheadings'] as $h2)
                            <li class="tocify-item level-2" data-unique="{!! $h2['slug'] !!}">
                                <a href="#{!! $h2['slug'] !!}">{!! $h2['name'] !!}</a>
                            </li>
                            @if(count($h2['subheadings']) > 0)
                                <ul id="tocify-subheader-{!! $h2['slug'] !!}" class="tocify-subheader">
                                    @foreach($h2['subheadings'] as $h3)
                                        <li class="tocify-item level-3" data-unique="{!! $h3['slug'] !!}">
                                            <a href="#{!! $h3['slug'] !!}">{!! $h3['name'] !!}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </ul>
        @endforeach
    </div>

    <ul class="toc-footer" id="toc-footer">
        @if($metadata['postman_collection_url'])
            <li style="padding-bottom: 5px;"><a href="{!! $metadata['postman_collection_url'] !!}">{!! u::trans("scribe::links.postman") !!}</a></li>
        @endif
        @if($metadata['openapi_spec_url'])
            <li style="padding-bottom: 5px;"><a href="{!! $metadata['openapi_spec_url'] !!}">{!! u::trans("scribe::links.openapi") !!}</a></li>
        @endif
        <li><a href="http://github.com/knuckleswtf/scribe">Scribe ✍</a></li>
    </ul>

    <ul class="toc-footer" id="last-updated">
        <li>{{ $metadata['last_updated'] }}</li>
    </ul>
</div>

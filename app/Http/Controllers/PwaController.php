<?php

namespace App\Http\Controllers;

use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Http\Request;

class PwaController extends Controller
{
    public function __construct(
        protected KnowledgeManager $knowledgeManager
    ) {}

    /**
     * PWA home / index page
     */
    public function index()
    {
        return view('pwa.index');
    }

    /**
     * PWA chat interface
     */
    public function chat(?int $sessionId = null)
    {
        return view('pwa.chat', [
            'sessionId' => $sessionId,
        ]);
    }

    /**
     * PWA knowledge search interface
     */
    public function knowledge()
    {
        return view('pwa.knowledge');
    }

    /**
     * PWA settings page
     */
    public function settings()
    {
        return view('pwa.settings');
    }

    /**
     * Generate dynamic PWA manifest with user's color scheme
     */
    public function manifest(Request $request)
    {
        $accentColor = '#6366f1';
        $backgroundColor = '#18181b';

        if ($request->user()) {
            $preferences = $request->user()->preferences ?? [];
            $customScheme = $preferences['custom_color_scheme'] ?? null;
            $enabled = $customScheme['enabled'] ?? false;
            $colors = $customScheme['colors'] ?? [];

            if ($enabled && ! empty($colors)) {
                $accentColor = $colors['accent'] ?? $accentColor;
            }
        }

        $manifest = [
            'name' => config('app.name', 'PromptlyAgent'),
            'short_name' => config('app.name', 'PromptlyAgent'),
            'description' => 'AI-powered research and knowledge management',
            'start_url' => '/pwa/',
            'display' => 'standalone',
            'background_color' => $backgroundColor,
            'theme_color' => $accentColor,
            'lang' => 'en',
            'scope' => '/pwa/',
            'orientation' => 'portrait-primary',
            'icons' => [
                [
                    'src' => '/pwa-64x64.png',
                    'sizes' => '64x64',
                    'type' => 'image/png',
                ],
                [
                    'src' => '/pwa-192x192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ],
                [
                    'src' => '/pwa-512x512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => '/maskable-icon-512x512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
            'share_target' => [
                'action' => '/pwa/share-target',
                'method' => 'POST',
                'enctype' => 'multipart/form-data',
                'params' => [
                    'title' => 'title',
                    'text' => 'text',
                    'url' => 'url',
                    'files' => [
                        [
                            'name' => 'file',
                            'accept' => [
                                'image/png',
                                'image/jpeg',
                                'image/gif',
                                'image/webp',
                                'application/pdf',
                                'text/plain',
                                'text/markdown',
                                'text/csv',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return response()->json($manifest)
            ->header('Content-Type', 'application/manifest+json')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Handle PWA Share Target submissions with secure file validation
     *
     * Security measures:
     * - Requires authentication (route middleware)
     * - File size limited to 10MB
     * - Strict MIME type whitelist
     * - Laravel's built-in file validation
     */
    public function shareTarget(Request $request)
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:500',
            'text' => 'nullable|string|max:50000',
            'url' => 'nullable|url|max:2048',
            'file' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:png,jpg,jpeg,gif,webp,pdf,txt,md,csv',
            ],
        ]);

        $sharedData = [
            'title' => $validated['title'] ?? null,
            'text' => $validated['text'] ?? null,
            'url' => $validated['url'] ?? null,
            'file' => null,
            'file_info' => null,
        ];

        if ($request->hasFile('file')) {
            $file = $request->file('file');

            if ($file->isValid()) {
                $sharedData['file'] = $file;
                $sharedData['file_info'] = [
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime' => $file->getMimeType(),
                ];
            }
        }

        return view('pwa.share-create-knowledge', [
            'sharedData' => $sharedData,
        ]);
    }
}

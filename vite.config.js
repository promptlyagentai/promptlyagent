import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    server: {
        host: '0.0.0.0',
        hmr: {
            host: 'localhost',
        },
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Add image files to be processed by Vite
            publicDirectory: 'public',
        }),
        tailwindcss(),
        VitePWA({
            registerType: 'autoUpdate',
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.js',
            includeAssets: [
                'favicon.ico',
                'favicon.svg',
                'apple-touch-icon-180x180.png',
                'pwa-64x64.png',
                'pwa-192x192.png',
                'pwa-512x512.png',
                'maskable-icon-512x512.png'
            ],
            manifest: {
                name: 'PromptlyAgent',
                short_name: 'PromptlyAgent',
                description: 'AI-powered research and knowledge management',
                theme_color: '#6366f1',
                background_color: '#18181b',
                display: 'standalone',
                scope: '/',
                start_url: '/pwa/',
                orientation: 'portrait-primary',
                icons: [
                    {
                        src: '/pwa-64x64.png?v=20241227',
                        sizes: '64x64',
                        type: 'image/png'
                    },
                    {
                        src: '/pwa-192x192.png?v=20241227',
                        sizes: '192x192',
                        type: 'image/png'
                    },
                    {
                        src: '/pwa-512x512.png?v=20241227',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'any'
                    },
                    {
                        src: '/maskable-icon-512x512.png?v=20241227',
                        sizes: '512x512',
                        type: 'image/png',
                        purpose: 'maskable'
                    }
                ],
                share_target: {
                    action: '/pwa/share-target',
                    method: 'POST',
                    enctype: 'multipart/form-data',
                    params: {
                        title: 'title',
                        text: 'text',
                        url: 'url',
                        files: [{
                            name: 'file',
                            accept: ['image/*', 'text/*', 'application/pdf']
                        }]
                    }
                }
            },
            workbox: {
                globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
                cleanupOutdatedCaches: true,
                skipWaiting: true,
                clientsClaim: true,
                // Handle notification click events
                navigateFallback: null,
                runtimeCaching: [
                    {
                        urlPattern: /^https:\/\/fonts\.bunny\.net\/.*/i,
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'google-fonts-cache',
                            expiration: {
                                maxEntries: 10,
                                maxAgeSeconds: 60 * 60 * 24 * 365
                            }
                        }
                    },
                    {
                        urlPattern: /^\/api\/v1\/chat\/sessions$/,
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'chat-sessions-cache',
                            networkTimeoutSeconds: 10
                        }
                    },
                    {
                        urlPattern: /^\/api\/v1\/knowledge\/search$/,
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'knowledge-search-cache',
                            networkTimeoutSeconds: 10
                        }
                    }
                ]
            },
            devOptions: {
                enabled: true
            }
        })
    ],
    // Configure assets that should be copied to public directory
    build: {
        assetsDir: 'assets',
    },
    // Ensure images are properly processed
    publicDir: 'resources/images',
});

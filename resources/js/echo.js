/**
 * Laravel Echo WebSocket Configuration
 *
 * Configures Laravel Echo with Laravel Reverb broadcaster for real-time
 * bidirectional communication between client and server.
 *
 * Key Features:
 * - Public channels: No authentication required
 * - Private channels: Authenticated via Laravel broadcasting routes
 * - Presence channels: Track active users in channels
 * - Auto-reconnection: Built-in reconnection logic
 * - CSRF protection: Automatic token injection
 *
 * Configuration:
 * - Broadcaster: Laravel Reverb (self-hosted WebSocket server)
 * - Transport: WebSocket (ws/wss) with fallback support
 * - Authentication: Laravel Sanctum via /broadcasting/auth endpoint
 * - TLS: Configurable via VITE_REVERB_SCHEME environment variable
 *
 * Environment Variables Required:
 * - VITE_REVERB_APP_KEY: Application key for Reverb
 * - VITE_REVERB_APP_ID: Application ID for Reverb
 * - VITE_REVERB_HOST: WebSocket server host
 * - VITE_REVERB_PORT: WebSocket server port
 * - VITE_REVERB_SCHEME: Connection scheme (http/https)
 *
 * @module echo
 * @see {@link https://laravel.com/docs/broadcasting Laravel Broadcasting Documentation}
 * @see {@link https://reverb.laravel.com/ Laravel Reverb Documentation}
 */

import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

/**
 * Global Echo instance for WebSocket communication
 * @global
 * @type {Echo}
 */
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    wsPath: '/ws',
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
    // App ID is required for Reverb authentication
    appId: import.meta.env.VITE_REVERB_APP_ID ?? 'app-id',
    // Reverb requires empty cluster (unlike Pusher cloud)
    cluster: '',

    // Authorization endpoint for private/presence channels
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
    },
});

// Log connection status for debugging
if (window.Echo?.connector?.pusher) {
    window.Echo.connector.pusher.connection.bind('connected', () => {
        console.log('WebSocket connected successfully');
    });

    window.Echo.connector.pusher.connection.bind('error', (error) => {
        console.error('WebSocket connection error:', error);
    });
}

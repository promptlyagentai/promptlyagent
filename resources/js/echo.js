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
 * - TLS: Configurable via runtime Reverb config or VITE_REVERB_SCHEME
 *
 * Runtime Configuration:
 * - window.promptlyagent.reverb is rendered by Laravel from runtime env
 * - VITE_REVERB_* values are only fallbacks for local/dev builds
 *
 * @module echo
 * @see {@link https://laravel.com/docs/broadcasting Laravel Broadcasting Documentation}
 * @see {@link https://reverb.laravel.com/ Laravel Reverb Documentation}
 */

import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const runtimeReverbConfig = window.promptlyagent?.reverb ?? {};

const getConfig = (key, fallback = undefined) => {
    const value = runtimeReverbConfig[key] ?? fallback;

    return value === '' ? fallback : value;
};

const parsePort = (value, fallback) => {
    const port = Number(value);

    return Number.isFinite(port) && port > 0 ? port : fallback;
};

const reverbScheme = getConfig('scheme', import.meta.env.VITE_REVERB_SCHEME ?? 'https');
const reverbKey = getConfig('key', import.meta.env.VITE_REVERB_APP_KEY);
const reverbHost = getConfig('host', import.meta.env.VITE_REVERB_HOST ?? window.location.hostname);
const reverbPort = parsePort(
    getConfig('port', import.meta.env.VITE_REVERB_PORT),
    reverbScheme === 'https' ? 443 : 80,
);

/**
 * Global Echo instance for WebSocket communication
 * @global
 * @type {Echo}
 */
if (reverbKey) {
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: reverbHost,
        wsPort: reverbPort,
        wssPort: reverbPort,
        wsPath: getConfig('path', '/ws'),
        forceTLS: reverbScheme === 'https',
        enabledTransports: ['ws', 'wss'],
        appId: getConfig('appId', import.meta.env.VITE_REVERB_APP_ID ?? 'app-id'),
        cluster: '',

        // Authorization endpoint for private/presence channels
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        },
    });
} else {
    console.error('Reverb app key is not configured; real-time updates are disabled.');
    window.Echo = null;
}

// Log connection status for debugging
if (window.Echo?.connector?.pusher) {
    window.Echo.connector.pusher.connection.bind('connected', () => {
        console.log('WebSocket connected successfully');
    });

    window.Echo.connector.pusher.connection.bind('error', (error) => {
        console.error('WebSocket connection error:', error);
    });
}

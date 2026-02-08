/**
 * PromptlyAgent Service Worker
 * Handles offline caching, background sync, and push notifications
 */

import { clientsClaim } from 'workbox-core';
import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst } from 'workbox-strategies';
import { ExpirationPlugin } from 'workbox-expiration';

// Take control of all clients immediately
clientsClaim();
self.skipWaiting();

// Precache assets
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();

// Cache strategies
registerRoute(
    /^https:\/\/fonts\.bunny\.net\/.*/i,
    new CacheFirst({
        cacheName: 'google-fonts-cache',
        plugins: [
            new ExpirationPlugin({
                maxEntries: 10,
                maxAgeSeconds: 60 * 60 * 24 * 365
            })
        ]
    }),
    'GET'
);

registerRoute(
    /^\/api\/v1\/chat\/sessions$/,
    new NetworkFirst({
        cacheName: 'chat-sessions-cache',
        networkTimeoutSeconds: 10
    }),
    'GET'
);

registerRoute(
    /^\/api\/v1\/knowledge\/search$/,
    new NetworkFirst({
        cacheName: 'knowledge-search-cache',
        networkTimeoutSeconds: 10
    }),
    'GET'
);

// ============================================================================
// Push Notifications & Background Messaging
// ============================================================================

/**
 * Handle notification display requests from the client
 * Receives messages via postMessage and shows native notifications
 */
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SHOW_NOTIFICATION') {
        const { title, options } = event.data;

        self.registration.showNotification(title, {
            ...options,
            badge: '/pwa-64x64.png',
            icon: options.icon || '/pwa-192x192.png',
            // Mobile-specific enhancements
            vibrate: [200, 100, 200], // Vibration pattern for mobile
            requireInteraction: false, // Auto-dismiss after timeout
            silent: false, // Play notification sound
        }).catch(err => {
            console.error('Failed to show notification:', err);
        });
    }
});

/**
 * Handle notification clicks (both body clicks and action button clicks)
 * Opens the app and navigates to the relevant content or triggers downloads
 */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const data = event.notification.data || {};

    // Handle action button clicks
    if (event.action === 'download' && data.downloadUrl) {
        // Open download URL in new window/tab
        event.waitUntil(clients.openWindow(data.downloadUrl));
        return;
    } else if (event.action === 'view' && data.viewUrl) {
        // Open view URL in new window/tab
        event.waitUntil(clients.openWindow(data.viewUrl));
        return;
    }

    // Handle notification body click (no action)
    const targetUrl = data.url || '/pwa/chat';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            // Check if there's already a PWA window/tab open
            for (const client of clientList) {
                if (client.url.includes('/pwa/') && 'focus' in client) {
                    // Focus existing window and navigate to the target URL
                    return client.focus().then(() => {
                        if ('navigate' in client) {
                            return client.navigate(targetUrl);
                        }
                    });
                }
            }

            // No existing window, open a new one
            if (clients.openWindow) {
                return clients.openWindow(targetUrl);
            }
        })
    );
});

/**
 * Handle notification close events
 * Track which notifications were dismissed without interaction
 */
self.addEventListener('notificationclose', (event) => {
    // Optional: Track notification dismissals for analytics
    console.log('Notification closed:', event.notification.tag);
});

// ============================================================================
// Background Sync (for future use)
// ============================================================================

/**
 * Handle background sync events
 * Processes queued requests when connection is restored
 */
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-chat-messages') {
        event.waitUntil(syncChatMessages());
    }
});

async function syncChatMessages() {
    // Placeholder for background sync logic
    // This can be used to sync messages when the device comes back online
    console.log('Background sync: chat messages');
}

// ============================================================================
// Lifecycle Events
// ============================================================================

self.addEventListener('install', (event) => {
    console.log('Service Worker installing...');
});

self.addEventListener('activate', (event) => {
    console.log('Service Worker activated');
    event.waitUntil(clients.claim());
});

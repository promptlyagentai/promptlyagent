/**
 * Service Worker Registration - PWA offline support
 *
 * Manages service worker lifecycle including registration, updates, and activation.
 * Provides automatic update checks and seamless PWA experience with offline capabilities.
 *
 * IMPORTANT: Only registers service worker on PWA routes (/pwa/*) to prevent
 * cross-contamination with regular dashboard/research interfaces.
 *
 * @module pwa/sw-register
 */

import { Workbox } from 'workbox-window'

// Only register service worker on PWA routes
const isPwaRoute = window.location.pathname.startsWith('/pwa/');

if ('serviceWorker' in navigator && isPwaRoute) {
    const wb = new Workbox('/build/sw.js')

    /**
     * Handle new service worker waiting to activate
     * Automatically skips waiting and reloads to apply updates
     */
    wb.addEventListener('waiting', (event) => {
        console.log('PWA: New content available', {
            isUpdate: event.isUpdate,
            isExternal: event.isExternal
        })
        // Skip waiting and reload immediately
        wb.messageSkipWaiting()
    })

    /**
     * Handle service worker taking control
     * Reloads page to ensure new code is active
     */
    wb.addEventListener('controlling', (event) => {
        console.log('PWA: Service Worker taking control')
        // Reload the page to load new content
        window.location.reload()
    })

    /**
     * Handle service worker activation
     * Shows offline ready notification for first install
     */
    wb.addEventListener('activated', (event) => {
        console.log('PWA: Service Worker activated', {
            isUpdate: event.isUpdate,
            isExternal: event.isExternal
        })
        if (!event.isUpdate) {
            showOfflineReady()
        }
    })

    // Register the service worker
    wb.register()
        .then(registration => {
            console.log('PWA: Service Worker registered successfully', {
                scope: registration.scope,
                updateViaCache: registration.updateViaCache
            })

            // Check for updates periodically (every 60 seconds)
            setInterval(() => {
                registration.update()
            }, 60000)
        })
        .catch(error => {
            console.error('PWA: Service Worker registration failed', {
                error: error.message,
                stack: error.stack
            })
        })

    /**
     * Show offline ready notification
     *
     * @returns {void}
     */
    function showOfflineReady() {
        if (window.Livewire) {
            window.Livewire.dispatch('pwa-offline-ready')
        }
    }

    /**
     * Show update prompt to user
     *
     * @param {Workbox} workbox - Workbox instance
     * @returns {void}
     */
    function showUpdatePrompt(workbox) {
        if (window.Livewire) {
            window.Livewire.dispatch('pwa-update-available', { workbox })
        }
    }
} else if (!isPwaRoute) {
    console.log('PWA: Service Worker registration skipped - not on PWA route')
} else {
    console.warn('PWA: Service Workers not supported in this browser')
}

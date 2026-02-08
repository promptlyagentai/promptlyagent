/**
 * Bug Report Capture Utilities
 * Screenshot capture via Screen Capture API
 * Console log buffering and sanitization
 */

(function() {
    'use strict';

    /**
     * Console log buffer with sanitization
     */
    class ConsoleLogBuffer {
        constructor(maxEntries = 100, maxSize = 50 * 1024) {
            this.buffer = [];
            this.maxEntries = maxEntries;
            this.maxSize = maxSize;
            this.interceptConsole();
        }

        interceptConsole() {
            ['log', 'warn', 'error', 'info'].forEach(method => {
                const original = console[method];
                console[method] = (...args) => {
                    this.addEntry(method, args);
                    original.apply(console, args);
                };
            });
        }

        addEntry(level, args) {
            const entry = {
                timestamp: new Date().toISOString(),
                level,
                message: args.map(arg => {
                    try {
                        return typeof arg === 'object' ? JSON.stringify(arg) : String(arg);
                    } catch (e) {
                        return '[Unable to serialize]';
                    }
                }).join(' ')
            };

            this.buffer.push(entry);

            // Keep buffer size manageable
            if (this.buffer.length > this.maxEntries) {
                this.buffer.shift();
            }
        }

        getLogs() {
            return this.buffer.map(entry =>
                `[${entry.timestamp}] ${entry.level.toUpperCase()}: ${entry.message}`
            ).join('\n');
        }

        sanitize() {
            // Remove sensitive patterns
            const sensitivePatterns = [
                /Bearer\s+[A-Za-z0-9\-._~+\/]+=*/gi,  // Bearer tokens
                /api[_-]?key[s]?\s*[:=]\s*['"]?[A-Za-z0-9]{20,}['"]?/gi,  // API keys
                /password\s*[:=]\s*['"]?[^'"]+['"]?/gi,  // Passwords
                /token\s*[:=]\s*['"]?[A-Za-z0-9]{20,}['"]?/gi,  // Generic tokens
                /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/gi,  // Emails
            ];

            let logs = this.getLogs();

            sensitivePatterns.forEach(pattern => {
                logs = logs.replace(pattern, '[REDACTED]');
            });

            // Limit total size
            if (logs.length > this.maxSize) {
                logs = logs.substring(logs.length - this.maxSize);
                logs = '... [truncated]\n' + logs;
            }

            return logs;
        }

        clear() {
            this.buffer = [];
        }
    }

    /**
     * Screenshot capture utilities
     */
    const ScreenshotCapture = {
        /**
         * Capture screenshot of the current page using canvas
         * Returns a Blob or null if failed
         */
        async capture() {
            try {
                // Try automatic DOM capture first (no permission needed)
                return await this.captureDOMScreenshot();
            } catch (error) {
                console.error('DOM screenshot failed, falling back to screen capture:', error);

                // Fallback to screen capture API with current tab preference
                return await this.captureWithDisplayMedia();
            }
        },

        /**
         * Capture the current page DOM as a screenshot (automatic, no permission)
         */
        async captureDOMScreenshot() {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');

            // Set canvas size to viewport
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;

            // Draw white background
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Capture the visible page using foreignObject SVG trick
            const data = `
                <svg xmlns="http://www.w3.org/2000/svg" width="${canvas.width}" height="${canvas.height}">
                    <foreignObject width="100%" height="100%">
                        <div xmlns="http://www.w3.org/1999/xhtml" style="font-size: 14px">
                            ${document.documentElement.outerHTML}
                        </div>
                    </foreignObject>
                </svg>
            `;

            const img = new Image();
            const blob = new Blob([data], { type: 'image/svg+xml' });
            const url = URL.createObjectURL(blob);

            return new Promise((resolve, reject) => {
                img.onload = () => {
                    ctx.drawImage(img, 0, 0);
                    URL.revokeObjectURL(url);

                    canvas.toBlob((blob) => {
                        if (blob) {
                            console.log('DOM screenshot captured:', blob.size, 'bytes');
                            resolve(blob);
                        } else {
                            reject(new Error('Failed to create blob from canvas'));
                        }
                    }, 'image/png', 0.9);
                };

                img.onerror = () => {
                    URL.revokeObjectURL(url);
                    reject(new Error('Failed to load SVG image'));
                };

                img.src = url;
            });
        },

        /**
         * Fallback: Capture screenshot using Screen Capture API with current tab preference
         */
        async captureWithDisplayMedia() {
            try {
                // Check browser support
                if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
                    console.warn('Screen Capture API not supported');
                    return null;
                }

                // Request screen capture with preference for current tab
                const stream = await navigator.mediaDevices.getDisplayMedia({
                    video: {
                        displaySurface: 'browser', // Prefer browser tab
                        width: { ideal: 1920 },
                        height: { ideal: 1080 },
                    },
                    preferCurrentTab: true, // Chrome 109+ - pre-select current tab
                    selfBrowserSurface: 'include', // Allow current tab
                    surfaceSwitching: 'include', // Allow switching if needed
                });

                // Create video element
                const video = document.createElement('video');
                video.srcObject = stream;
                video.play();

                // Wait for video to be ready
                await new Promise(resolve => {
                    video.onloadedmetadata = resolve;
                });

                // Create canvas and capture frame
                const canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;

                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                // Stop all tracks
                stream.getTracks().forEach(track => track.stop());

                // Convert to blob
                const blob = await new Promise(resolve => {
                    canvas.toBlob(resolve, 'image/png', 0.9);
                });

                console.log('Screen capture screenshot captured:', blob.size, 'bytes');
                return blob;

            } catch (error) {
                if (error.name === 'NotAllowedError') {
                    console.log('User cancelled screenshot capture');
                } else {
                    console.error('Screen capture failed:', error);
                }
                return null;
            }
        },
    };

    // Initialize console logger on page load
    window.consoleLogger = new ConsoleLogBuffer();

    // Export screenshot capture
    window.ScreenshotCapture = ScreenshotCapture;

    // Add to PromptlySupport if available
    if (window.PromptlySupport) {
        window.PromptlySupport.capture.captureScreenshot = ScreenshotCapture.capture;
    }

})();

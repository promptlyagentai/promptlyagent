/**
 * Custom marked.js renderer extensions for asset:// and attachment:// URLs
 *
 * Converts internal URL schemes to authenticated Laravel routes for browser display:
 * - asset://123 → /assets/123/download (preferred: numeric ID)
 * - asset://filename.png → /assets/filename.png/download (fallback: filename lookup)
 * - attachment://456 → /chat/attachment/456/download (preferred: numeric ID)
 * - attachment://diagram.png → /chat/attachment/diagram.png/download (fallback: filename lookup)
 *
 * The backend controllers automatically resolve both numeric IDs and filenames,
 * with numeric IDs being the preferred format for performance and uniqueness.
 *
 * This enables client-side markdown rendering without server-side URL resolution,
 * improving performance and leveraging browser session authentication.
 */

export const customRendererExtension = {
    renderer: {
        // Override image renderer to transform internal URLs
        image({ href, title, text }) {
            let transformedHref = href;

            // Transform asset:// URLs (supports both numeric IDs and filenames)
            if (href && href.startsWith('asset://')) {
                const identifier = href.replace('asset://', '');
                transformedHref = `/assets/${identifier}/download`;
            }
            // Transform attachment:// URLs (supports both numeric IDs and filenames)
            else if (href && href.startsWith('attachment://')) {
                const identifier = href.replace('attachment://', '');
                transformedHref = `/chat/attachment/${identifier}/download`;
            }

            // Build the image tag
            let out = `<img src="${transformedHref}" alt="${text}"`;
            if (title) {
                out += ` title="${title}"`;
            }
            out += '>';
            return out;
        },

        // Override link renderer to transform internal URLs and open in new tabs (Issue #188)
        link({ href, title, text }) {
            let transformedHref = href;

            // Transform asset:// URLs (supports both numeric IDs and filenames)
            if (href && href.startsWith('asset://')) {
                const identifier = href.replace('asset://', '');
                transformedHref = `/assets/${identifier}/download`;
            }
            // Transform attachment:// URLs (supports both numeric IDs and filenames)
            else if (href && href.startsWith('attachment://')) {
                const identifier = href.replace('attachment://', '');
                transformedHref = `/chat/attachment/${identifier}/download`;
            }

            // Build the link tag with target="_blank" and security attributes
            let out = `<a href="${transformedHref}"`;
            if (title) {
                out += ` title="${title}"`;
            }
            // Open all links in new tabs with security attributes (Issue #188)
            out += ` target="_blank" rel="noopener noreferrer"`;
            out += `>${text}</a>`;
            return out;
        }
    }
};

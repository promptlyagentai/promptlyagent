<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Support\Facades\Storage;

class AssetController extends Controller
{
    /**
     * Download an asset file
     *
     * Provides session-authenticated access to asset files for browser display.
     * Supports two identifier formats:
     * - Numeric ID (preferred): /assets/123/download
     * - Filename (fallback): /assets/document.pdf/download
     *
     * Assets don't have direct user ownership - access is controlled through
     * parent resources (knowledge documents, etc.). For now, any authenticated
     * user can download assets.
     *
     * Future Enhancement: Granular Permissions Based on Parent Resources
     *
     * Current behavior: Any authenticated user can download any asset
     * Future behavior: Respect parent resource permissions
     *
     * Use cases requiring enhanced permissions:
     * 1. Private knowledge documents - Only document owner can access assets
     * 2. Team/organization knowledge - Only team members can access
     * 3. Agent-specific assets - Only users with agent access can download
     * 4. Shared collaboration - Multiple users can access based on sharing rules
     * 5. Public knowledge documents - Anyone can access (current behavior OK)
     *
     * Implementation approach:
     * - Check Asset::knowledgeDocuments() relationship
     * - Verify current user has access to at least one parent document
     * - Consider caching permission checks for performance
     * - Add audit logging for sensitive asset access
     *
     * @see \App\Models\KnowledgeDocument for privacy/ownership logic
     */
    public function download(string $asset)
    {
        // Try to resolve by ID first (preferred)
        if (is_numeric($asset)) {
            $assetModel = Asset::find((int) $asset);
        } else {
            // Fallback: resolve by filename
            $assetModel = Asset::where('original_filename', $asset)->first();
        }

        if (! $assetModel) {
            abort(404, 'Asset not found');
        }

        if (! $assetModel->exists()) {
            abort(404, 'File not found in storage');
        }

        return Storage::download(
            $assetModel->storage_key,
            $assetModel->original_filename,
            [
                'Content-Type' => $assetModel->mime_type,
            ]
        );
    }
}

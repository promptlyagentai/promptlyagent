<?php

namespace App\Policies;

use App\Models\KnowledgeDocument;
use App\Models\User;

/**
 * Authorization policy for KnowledgeDocument
 *
 * Centralizes permission logic for knowledge documents:
 * - Admin users can perform all actions
 * - Document owners can perform all actions on their documents
 * - Public documents can be viewed by any authenticated user
 * - Private documents can only be viewed by owner or admin
 */
class KnowledgeDocumentPolicy
{
    /**
     * Determine if user can view the document
     */
    public function view(User $user, KnowledgeDocument $document): bool
    {
        // Admin users can view any document
        if ($user->is_admin ?? false) {
            return true;
        }

        // Owner can always view
        if ($document->created_by === $user->id) {
            return true;
        }

        // Public documents can be viewed by anyone
        if ($document->privacy_level === 'public') {
            return true;
        }

        return false;
    }

    /**
     * Determine if user can update the document
     */
    public function update(User $user, KnowledgeDocument $document): bool
    {
        // Admin users can edit any document
        if ($user->is_admin ?? false) {
            return true;
        }

        // Owner can always edit
        if ($document->created_by === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine if user can delete the document
     */
    public function delete(User $user, KnowledgeDocument $document): bool
    {
        // Same logic as update - only owner or admin can delete
        return $this->update($user, $document);
    }

    /**
     * Determine if user can reprocess/refresh the document
     */
    public function refresh(User $user, KnowledgeDocument $document): bool
    {
        // Same logic as update - only owner or admin can trigger refresh
        return $this->update($user, $document);
    }

    /**
     * Determine if user can download the document file
     */
    public function download(User $user, KnowledgeDocument $document): bool
    {
        // Same logic as view - must be able to view to download
        return $this->view($user, $document);
    }
}

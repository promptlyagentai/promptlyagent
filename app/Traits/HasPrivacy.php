<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * HasPrivacy Trait - Two-Level Privacy Control with Authorization.
 *
 * Provides two-level privacy control (private/public) with user-based authorization.
 * Implements access control for model visibility, editing, and deletion with
 * automatic admin overrides.
 *
 * Requirements:
 * - Model must have `privacy_level` column (string: 'private' or 'public')
 * - Model must have `created_by` column (foreign key to users table)
 * - User model must have `isAdmin()` method
 *
 * Privacy Levels:
 * - `private`: Only visible to creator and admins
 * - `public`: Visible to all users, editable only by creator and admins
 *
 * Authorization Logic:
 * - Admins bypass all restrictions
 * - Creators always have full access to their own items
 * - Public items are readable by everyone but editable only by creator/admin
 * - Private items are completely isolated to creator/admin
 *
 * Scopes:
 * - `public()`: Only public items
 * - `private()`: Only private items
 * - `accessibleBy(User)`: Items user can read (public + user's private)
 * - `forUser(User)`: Alias for accessibleBy
 *
 * Boot Behavior:
 * - Automatically sets privacy_level to 'private' on model creation
 *
 * Usage Example:
 * ```php
 * // Query accessible items
 * $items = KnowledgeDocument::accessibleBy($user)->get();
 *
 * // Authorization checks
 * if ($document->canEdit($user)) {
 *     $document->makePublic();
 * }
 *
 * // Fluent privacy control
 * $document->makePublic()->save();
 * ```
 *
 * @see \App\Models\User::isAdmin()
 */
trait HasPrivacy
{
    /**
     * Available privacy levels
     */
    public static function getPrivacyLevels(): array
    {
        return [
            'private' => 'Private - Only visible to the creator',
            'public' => 'Public - Visible to everyone',
        ];
    }

    /**
     * Boot the trait
     */
    protected static function bootHasPrivacy(): void
    {
        // Set default privacy level on creation
        static::creating(function ($model) {
            if (! $model->privacy_level) {
                $model->privacy_level = 'private';
            }
        });
    }

    /**
     * Scope to filter by privacy level
     */
    public function scopeWithPrivacyLevel(Builder $query, string $level): Builder
    {
        return $query->where('privacy_level', $level);
    }

    /**
     * Scope to get public items
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('privacy_level', 'public');
    }

    /**
     * Scope to get private items
     */
    public function scopePrivate(Builder $query): Builder
    {
        return $query->where('privacy_level', 'private');
    }

    /**
     * Scope to get items accessible by a specific user
     *
     * Returns all items the user can read based on privacy rules:
     * - Admin users: All items (no filtering)
     * - Regular users: Public items + items they created
     *
     * This is the primary authorization scope for listing views.
     *
     * @param  Builder  $query  Query builder instance
     * @param  User  $user  User to check access for
     * @return Builder Modified query with access filters applied
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        // Admin users can access everything
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('privacy_level', 'public')
                ->orWhere('created_by', $user->id);
        });
    }

    /**
     * Scope to get items for a specific user (created by them or accessible to them)
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $this->scopeAccessibleBy($query, $user);
    }

    /**
     * Check if the item is public
     */
    public function isPublic(): bool
    {
        return $this->privacy_level === 'public';
    }

    /**
     * Check if the item is private
     */
    public function isPrivate(): bool
    {
        return $this->privacy_level === 'private';
    }

    /**
     * Check if a user can access this item
     */
    public function canAccess(User $user): bool
    {
        // Admin users can access everything
        if ($user->isAdmin()) {
            return true;
        }

        // Public items are accessible to everyone
        if ($this->isPublic()) {
            return true;
        }

        // Private items are only accessible to the creator
        if ($this->isPrivate()) {
            return $this->created_by === $user->id;
        }

        return false;
    }

    /**
     * Check if a user can edit this item
     */
    public function canEdit(User $user): bool
    {
        // Admin users can edit everything
        if ($user->isAdmin()) {
            return true;
        }

        // Only the creator can edit (for now)
        return $this->created_by === $user->id;
    }

    /**
     * Check if a user can delete this item
     */
    public function canDelete(User $user): bool
    {
        return $this->canEdit($user);
    }

    /**
     * Set privacy level to public
     */
    public function makePublic(): self
    {
        return $this->setPrivacyLevel('public');
    }

    /**
     * Set privacy level to private
     */
    public function makePrivate(): self
    {
        return $this->setPrivacyLevel('private');
    }

    /**
     * Set privacy level with validation
     *
     * @param  string  $level  Privacy level ('private' or 'public')
     * @return self For method chaining
     *
     * @throws \InvalidArgumentException If level is not 'private' or 'public'
     */
    public function setPrivacyLevel(string $level): self
    {
        if (! in_array($level, array_keys(static::getPrivacyLevels()))) {
            throw new \InvalidArgumentException("Invalid privacy level: {$level}");
        }

        $this->update(['privacy_level' => $level]);

        return $this;
    }

    /**
     * Get privacy level label
     */
    public function getPrivacyLevelLabelAttribute(): string
    {
        $levels = static::getPrivacyLevels();

        return $levels[$this->privacy_level] ?? 'Unknown';
    }

    /**
     * Get privacy level badge class for UI
     */
    public function getPrivacyBadgeClassAttribute(): string
    {
        return match ($this->privacy_level) {
            'public' => 'bg-green-100 text-green-800',
            'private' => 'bg-gray-100 text-gray-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }
}

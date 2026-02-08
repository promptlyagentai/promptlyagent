<?php

namespace App\Enums;

/**
 * Integration lifecycle status values.
 *
 * - ACTIVE: Fully operational and available for use
 * - PAUSED: Temporarily disabled, preserves configuration
 * - ARCHIVED: Permanently disabled, hidden from active lists
 */
enum IntegrationStatus: string
{
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case ARCHIVED = 'archived';

    /**
     * Get human-readable label for status.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::PAUSED => 'Paused',
            self::ARCHIVED => 'Archived',
        };
    }

    /**
     * Get color theme for UI display.
     *
     * @return string Color identifier for Flux/Tailwind components
     */
    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::PAUSED => 'warning',
            self::ARCHIVED => 'gray',
        };
    }
}

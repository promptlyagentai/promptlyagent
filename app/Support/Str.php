<?php

namespace App\Support;

/**
 * Application String Helpers - PromptlyAgent-Specific String Utilities.
 *
 * Extends Laravel's Illuminate\Support\Str with application-specific utilities.
 * Use via static calls: \App\Support\Str::shortHash($uuid)
 *
 * Why Not Use Laravel's Str?
 * These helpers are specific to PromptlyAgent's domain (UUID hashing for UI)
 * and don't belong in Laravel's general-purpose string utilities.
 *
 * @see \Illuminate\Support\Str
 */
class Str
{
    /**
     * Generate a short hash from a UUID for human-readable identifiers
     *
     * Removes hyphens and combines prefix/suffix characters to create
     * compact identifiers suitable for URLs, UI labels, or logs.
     *
     * Collision Risk:
     * Default 5-character output has ~1M possible values. Suitable for
     * small-medium datasets (<10K items). Increase lengths for larger scales.
     *
     * Use Cases:
     * - Short URLs: /agents/019c1
     * - Log identifiers: "Execution 019c1 failed"
     * - UI display: "Integration #019c1"
     *
     * @param  string  $uuid  Full UUID string (with or without hyphens)
     * @param  int  $prefixLength  Number of characters from start (default: 3)
     * @param  int  $suffixLength  Number of characters from end (default: 2)
     * @return string Short hash (e.g., "019c1" from "019...c2d2c1")
     *
     * @example
     * Str::shortHash("019b6aeb-be25-7356-b619-a86f06c2d2c1"); // "019c1"
     * Str::shortHash($uuid, 4, 4); // "019bc2c1" (8 chars, lower collision)
     */
    public static function shortHash(string $uuid, int $prefixLength = 3, int $suffixLength = 2): string
    {
        // Remove hyphens from UUID
        $clean = str_replace('-', '', $uuid);

        // Get prefix and suffix
        $prefix = substr($clean, 0, $prefixLength);
        $suffix = substr($clean, -$suffixLength);

        return $prefix.$suffix;
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Auto-Archive Configuration
    |--------------------------------------------------------------------------
    |
    | Control automatic archiving of old chat sessions. When enabled, sessions
    | older than the specified threshold will be archived (unless marked as kept).
    |
    */

    'auto_archive_enabled' => (bool) env('CHAT_AUTO_ARCHIVE_ENABLED', true),

    'auto_archive_days' => (int) env('CHAT_AUTO_ARCHIVE_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Search Configuration
    |--------------------------------------------------------------------------
    |
    | Enable or disable full-text search across session titles and interaction
    | content. When disabled, only title-based filtering will be available.
    |
    */

    'search_enabled' => (bool) env('CHAT_SEARCH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Duration (in minutes) to cache computed attributes like attachment,
    | artifact, and source counts for chat sessions.
    |
    */

    'counts_cache_minutes' => (int) env('CHAT_COUNTS_CACHE_MINUTES', 5),

];

<?php

namespace App\Services\Chat;

/**
 * Source Type Registry
 *
 * Allows integration packages to self-register their session source types
 * with display names and icons. Provides a centralized registry for all
 * source types that can create chat sessions.
 */
class SourceTypeRegistry
{
    protected array $sources = [];

    public function __construct()
    {
        // Register core source types
        $this->registerCoreTypes();
    }

    /**
     * Register a new source type
     *
     * @param  string  $key  Unique source type identifier (used in database)
     * @param  string  $label  Display label for UI
     * @param  string  $icon  Icon/emoji for UI display
     * @param  int  $priority  Display priority (lower numbers first)
     */
    public function register(string $key, string $label, string $icon, int $priority = 100): void
    {
        $this->sources[$key] = [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'priority' => $priority,
        ];
    }

    /**
     * Get all registered source types sorted by priority
     */
    public function all(): array
    {
        $sources = $this->sources;

        // Sort by priority
        uasort($sources, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        return $sources;
    }

    /**
     * Get source type by key
     */
    public function get(string $key): ?array
    {
        return $this->sources[$key] ?? null;
    }

    /**
     * Get icon for a source type
     */
    public function getIcon(string $key): string
    {
        return $this->sources[$key]['icon'] ?? '💬';
    }

    /**
     * Get label for a source type
     */
    public function getLabel(string $key): string
    {
        return $this->sources[$key]['label'] ?? ucfirst($key);
    }

    /**
     * Check if a source type is registered
     */
    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    /**
     * Register core application source types
     */
    protected function registerCoreTypes(): void
    {
        $this->register('web', 'Web', '🌐', 10);
        $this->register('api', 'API', '🔌', 20);
        $this->register('trigger', 'Trigger', '⚡', 30);
        $this->register('webhook', 'Webhook', '🔗', 40);
    }
}

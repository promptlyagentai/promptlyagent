<?php

namespace App\Livewire\Components;

use Livewire\Component;

class TimelineStep extends Component
{
    public array $step;

    public bool $showSourceTitle = false;

    public bool $isExpandable = false;

    public bool $isExpanded = false;

    public string $context = 'thinking'; // 'thinking' or 'steps'

    public bool $template = false; // Whether this is a template that will be cloned by JS

    public function mount(array $step = [], bool $showSourceTitle = false, bool $isExpandable = false, string $context = 'thinking', bool $template = false)
    {
        $this->step = $step;
        $this->showSourceTitle = $showSourceTitle;
        $this->isExpandable = $isExpandable;
        $this->context = $context;
        $this->template = $template;
    }

    public function toggleExpanded()
    {
        if ($this->isExpandable) {
            $this->isExpanded = ! $this->isExpanded;
            $this->dispatch('stepToggled');
        }
    }

    /**
     * Determine if this step is significant (should get a blue dot)
     */
    public function isSignificantStep(): bool
    {
        // Check for explicit significance flag
        if (isset($this->step['is_significant']) && $this->step['is_significant']) {
            return true;
        }

        // Auto-detect significant steps based on patterns
        $source = strtolower($this->step['source'] ?? '');
        $message = strtolower($this->step['message'] ?? '');
        $type = strtolower($this->step['type'] ?? '');

        // Phase transitions are significant
        if ($type === 'phase') {
            return true;
        }

        // Agent lifecycle events
        if (str_contains($message, 'agent execution') ||
            str_contains($message, 'started') ||
            str_contains($message, 'completed') ||
            str_contains($message, 'initializing')) {
            return true;
        }

        // Tool call initiations
        if ($source === 'tool_call' || str_contains($message, 'executing')) {
            return true;
        }

        // Major search operations
        if (str_contains($message, 'searching knowledge base') ||
            str_contains($message, 'found') && str_contains($message, 'results')) {
            return true;
        }

        return false;
    }

    /**
     * Get the timeline marker class based on step significance
     */
    public function getTimelineMarkerClass(): string
    {
        if ($this->isSignificantStep()) {
            return 'timeline-marker-significant'; // Blue dot
        }

        // Check if this is a regular step that should get a dot
        if ($this->step['create_event'] ?? false) {
            return 'timeline-marker-dot'; // Grey dot
        }

        return 'timeline-marker-regular'; // Dash or pipe
    }

    /**
     * Get icon name for the step
     */
    public function getStepIcon(): string
    {
        $source = strtolower($this->step['source'] ?? '');
        $type = strtolower($this->step['type'] ?? '');

        if ($type === 'phase') {
            return match ($this->step['phase'] ?? '') {
                'initializing' => 'play',
                'planning' => 'light-bulb',
                'searching' => 'magnifying-glass',
                'reading' => 'document-text',
                'processing' => 'cog-6-tooth',
                'synthesizing' => 'beaker',
                'streaming' => 'wifi',
                'completed' => 'check-circle',
                default => 'clock'
            };
        }

        return match ($source) {
            'system' => 'cog-6-tooth',
            'agent' => 'cpu-chip',
            'tool' => 'wrench-screwdriver',
            'search', 'knowledge_search', 'searxng_search' => 'magnifying-glass',
            'read', 'markitdown' => 'document-text',
            'bulk_link_validator', 'link_validator' => 'globe-alt',
            'knowledge_rag' => 'archive-box',
            default => 'information-circle'
        };
    }

    /**
     * Format message content with URLs and search terms
     */
    public function getFormattedMessage(): string
    {
        $message = $this->step['message'] ?? '';

        // Don't format if we're showing source titles (admin view)
        if ($this->showSourceTitle) {
            return $message;
        }

        // Make URLs clickable
        $message = preg_replace(
            '/(https?:\/\/[^\s]+)/',
            '<a href="$1" target="_blank" class="text-tropical-teal-600 dark:text-tropical-teal-400 underline hover:text-tropical-teal-800 dark:hover:text-tropical-teal-300">$1</a>',
            $message
        );

        // Bold search terms in quotes
        $message = preg_replace('/[""]([^""]+)[""]/', '<strong class="font-semibold text-gray-900 dark:text-gray-100">"$1"</strong>', $message);
        $message = preg_replace('/\'([^\']+)\'/', '<strong class="font-semibold text-gray-900 dark:text-gray-100">"$1"</strong>', $message);

        // Bold key result indicators
        $message = preg_replace(
            '/(found \d+ results?|completed in \d+[.\d]*\w+|validated \d+ URLs?)/i',
            '<strong class="font-semibold text-green-600 dark:text-green-400">$1</strong>',
            $message
        );

        // Bold domain names
        $message = preg_replace(
            '/([a-zA-Z0-9.-]+\.(com|org|net|edu|gov|io|co|ai|dev)[^\s]*)/i',
            '<strong class="font-semibold text-tropical-teal-600 dark:text-tropical-teal-400">$1</strong>',
            $message
        );

        return $message;
    }

    /**
     * Get color class for the step type
     */
    public function getStepColorClass(): string
    {
        $type = $this->step['type'] ?? 'status';

        return match ($type) {
            'phase' => 'text-tropical-teal-600 dark:text-tropical-teal-300 bg-tropical-teal-50 dark:bg-tropical-teal-900/20',
            'error' => 'text-red-600 dark:text-red-300 bg-red-50 dark:bg-red-900/20',
            'complete' => 'text-green-600 dark:text-green-300 bg-green-50 dark:bg-green-900/20',
            default => 'text-gray-600 dark:text-gray-300 bg-gray-50 dark:bg-gray-800/50'
        };
    }

    public function shouldRender(): bool
    {
        // Don't render steps where create_event is false
        if (isset($this->step['create_event']) && $this->step['create_event'] === false) {
            return false;
        }

        return true;
    }

    public function render()
    {
        // Skip rendering if create_event is false
        if (! $this->shouldRender()) {
            return '';
        }

        return view('livewire.components.timeline-step');
    }
}

<?php

namespace App\Services\Agents;

/**
 * Action Configuration
 *
 * Defines an action that executes side effects (input or output) for an agent node.
 * Actions are executed in priority order (lower priority values execute first).
 *
 * Examples:
 * - logOutput: Structured logging
 * - sendWebhook: HTTP POST to external URL
 * - storeInKnowledge: Save to knowledge database
 * - notifySlack: Send Slack notification
 * - auditTrail: Record execution event
 */
class ActionConfig
{
    /**
     * @param  string  $method  Action method name (must be registered in ActionRegistry)
     * @param  array  $params  Action-specific parameters
     * @param  int  $priority  Execution priority (lower = executes first, default: 100)
     */
    public function __construct(
        public string $method,
        public array $params = [],
        public int $priority = 100
    ) {}

    /**
     * Create from array (for JSON deserialization)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            method: $data['method'],
            params: $data['params'] ?? [],
            priority: $data['priority'] ?? 100
        );
    }

    /**
     * Convert to array (for JSON serialization)
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'params' => $this->params,
            'priority' => $this->priority,
        ];
    }

    /**
     * Get a summary for logging
     */
    public function getSummary(): string
    {
        $paramCount = count($this->params);
        $paramStr = $paramCount > 0 ? " ({$paramCount} params)" : '';

        return "{$this->method}{$paramStr} [priority: {$this->priority}]";
    }
}

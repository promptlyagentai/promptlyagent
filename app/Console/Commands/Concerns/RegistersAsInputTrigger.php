<?php

namespace App\Console\Commands\Concerns;

/**
 * Registers As Input Trigger Trait
 *
 * Allows Artisan commands to register themselves as triggerable via webhooks.
 * Commands using this trait must implement getTriggerDefinition() to provide
 * parameter schemas for dynamic form generation and webhook payload mapping.
 *
 * Usage:
 * ```php
 * class DailyDigestCommand extends Command
 * {
 *     use RegistersAsInputTrigger;
 *
 *     public function getTriggerDefinition(): array
 *     {
 *         return [
 *             'name' => 'Daily News Digest',
 *             'description' => 'Create a daily news digest workflow',
 *             'parameters' => [
 *                 'topics' => [
 *                     'type' => 'array',
 *                     'required' => true,
 *                     'description' => 'Topics to research (1-4 topics)',
 *                     'min' => 1,
 *                     'max' => 4,
 *                 ],
 *                 'webhook-url' => [
 *                     'type' => 'string',
 *                     'required' => false,
 *                     'description' => 'Webhook URL for final digest delivery',
 *                     'placeholder' => 'https://example.com/webhook',
 *                 ],
 *             ],
 *         ];
 *     }
 * }
 * ```
 */
trait RegistersAsInputTrigger
{
    /**
     * Get trigger definition for this command
     *
     * Returns command metadata and parameter schema for:
     * - Displaying in trigger selection dropdown
     * - Generating dynamic configuration forms
     * - Validating webhook payloads
     * - Mapping webhook fields to command arguments/options
     *
     * @return array{name: string, description: string, parameters: array<string, array>}
     */
    abstract public function getTriggerDefinition(): array;

    /**
     * Check if this command is registered as a trigger
     */
    public function isTriggerableCommand(): bool
    {
        return true;
    }
}

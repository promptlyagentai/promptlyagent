<?php

namespace App\Services\InputTrigger;

use App\Console\Commands\Concerns\RegistersAsInputTrigger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Triggerable Command Registry
 *
 * Discovers and manages Artisan commands that can be triggered via webhooks.
 * Commands must use the RegistersAsInputTrigger trait and implement
 * getTriggerDefinition() to be included in the registry.
 *
 * Features:
 * - Auto-discovery of triggerable commands
 * - Cached command list (1 hour) for performance
 * - Parameter schema generation for forms
 * - Webhook payload validation
 */
class TriggerableCommandRegistry
{
    /**
     * Get all registered triggerable commands
     *
     * Returns cached list of commands that use RegistersAsInputTrigger trait.
     * Cache is cleared when commands are updated or artisan cache:clear is run.
     *
     * @return array<string, array{name: string, class: string, command: string, description: string, parameters: array}>
     */
    public function getAll(): array
    {
        return Cache::remember('triggerable_commands', 3600, function () {
            $commands = [];

            // Get all registered Artisan commands
            $allCommands = Artisan::all();

            foreach ($allCommands as $command) {
                // Only include commands with RegistersAsInputTrigger trait
                if (! $this->usesTriggerTrait($command)) {
                    continue;
                }

                try {
                    $definition = $command->getTriggerDefinition();
                    $commandName = $command->getName();

                    $commands[$commandName] = [
                        'name' => $definition['name'] ?? $commandName,
                        'class' => get_class($command),
                        'command' => $commandName,
                        'description' => $definition['description'] ?? $command->getDescription(),
                        'parameters' => $definition['parameters'] ?? [],
                    ];

                    Log::debug('TriggerableCommandRegistry: Registered command', [
                        'command' => $commandName,
                        'class' => get_class($command),
                    ]);
                } catch (\Exception $e) {
                    Log::error('TriggerableCommandRegistry: Failed to register command', [
                        'command' => $command->getName(),
                        'class' => get_class($command),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('TriggerableCommandRegistry: Discovery complete', [
                'total_commands' => count($commands),
            ]);

            return $commands;
        });
    }

    /**
     * Get a specific command by class name
     *
     * @param  string  $commandClass  Fully qualified command class name
     * @return array|null Command definition or null if not found/not triggerable
     */
    public function getByClass(string $commandClass): ?array
    {
        if (! class_exists($commandClass)) {
            return null;
        }

        $command = app($commandClass);

        if (! $command instanceof Command || ! $this->usesTriggerTrait($command)) {
            return null;
        }

        try {
            $definition = $command->getTriggerDefinition();
            $commandName = $command->getName();

            return [
                'name' => $definition['name'] ?? $commandName,
                'class' => get_class($command),
                'command' => $commandName,
                'description' => $definition['description'] ?? $command->getDescription(),
                'parameters' => $definition['parameters'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::error('TriggerableCommandRegistry: Failed to get command definition', [
                'class' => $commandClass,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get a specific command by command name
     *
     * @param  string  $commandName  Command name (e.g., 'research:daily-digest')
     * @return array|null Command definition or null if not found
     */
    public function getByName(string $commandName): ?array
    {
        $all = $this->getAll();

        return $all[$commandName] ?? null;
    }

    /**
     * Get parameter schema for a command (for form generation)
     *
     * @param  string  $commandClass  Fully qualified command class name
     * @return array<string, array> Parameter definitions
     */
    public function getParameters(string $commandClass): array
    {
        $definition = $this->getByClass($commandClass);

        return $definition['parameters'] ?? [];
    }

    /**
     * Validate webhook payload against command parameters
     *
     * @param  string  $commandClass  Command class name
     * @param  array  $payload  Webhook payload data
     * @return array{valid: bool, errors: array<string>}
     */
    public function validatePayload(string $commandClass, array $payload): array
    {
        $parameters = $this->getParameters($commandClass);
        $errors = [];

        foreach ($parameters as $name => $definition) {
            $required = $definition['required'] ?? false;
            $type = $definition['type'] ?? 'string';

            // Check required parameters
            if ($required && ! isset($payload[$name])) {
                $errors[] = "Required parameter '{$name}' is missing";

                continue;
            }

            // Skip validation if parameter not provided and not required
            if (! isset($payload[$name])) {
                continue;
            }

            $value = $payload[$name];

            // Type validation
            switch ($type) {
                case 'array':
                    if (! is_array($value)) {
                        $errors[] = "Parameter '{$name}' must be an array";
                    } else {
                        // Array length validation (only if value is actually an array)
                        if (isset($definition['min']) && count($value) < $definition['min']) {
                            $errors[] = "Parameter '{$name}' must have at least {$definition['min']} items";
                        }

                        if (isset($definition['max']) && count($value) > $definition['max']) {
                            $errors[] = "Parameter '{$name}' must have at most {$definition['max']} items";
                        }
                    }
                    break;

                case 'string':
                    if (! is_string($value)) {
                        $errors[] = "Parameter '{$name}' must be a string";
                    }
                    break;

                case 'integer':
                    if (! is_int($value)) {
                        $errors[] = "Parameter '{$name}' must be an integer";
                    }
                    break;

                case 'boolean':
                    if (! is_bool($value)) {
                        $errors[] = "Parameter '{$name}' must be a boolean";
                    }
                    break;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Clear the command cache
     */
    public function clearCache(): void
    {
        Cache::forget('triggerable_commands');
    }

    /**
     * Check if a command uses the RegistersAsInputTrigger trait
     *
     * @param  mixed  $command  Command instance (could be Laravel or Symfony command)
     */
    protected function usesTriggerTrait($command): bool
    {
        // Only Laravel commands can use our trait
        if (! $command instanceof Command) {
            return false;
        }

        return in_array(RegistersAsInputTrigger::class, class_uses_recursive($command));
    }
}

<?php

namespace App\Services\Agents\Actions;

use Illuminate\Support\Facades\Log;

/**
 * Action Registry - Central registry for workflow side-effect actions.
 *
 * Provides whitelist-based security for action execution, preventing arbitrary
 * code execution while enabling flexible workflow automation (webhooks, logging,
 * storage, notifications, etc.).
 *
 * Architecture:
 * - Whitelist of allowed action methods (prevents code injection)
 * - Lazy instantiation of action classes
 * - Parameter validation before execution
 * - Support for async/queued actions
 * - Comprehensive error handling and logging
 * - Discovery methods for UI/documentation
 *
 * Usage:
 * ```php
 * ActionRegistry::execute('logOutput', $context, ['level' => 'info']);
 * ```
 */
class ActionRegistry
{
    /**
     * Registered actions (method => class)
     *
     * Only actions in this whitelist can be executed.
     * Add new actions here after implementing ActionInterface.
     */
    private static array $actions = [
        // Data transformation actions
        'normalizeText' => \App\Services\Agents\Actions\NormalizeTextAction::class,
        'truncateText' => \App\Services\Agents\Actions\TruncateTextAction::class,

        // Side effect actions
        'logOutput' => \App\Services\Agents\Actions\LogOutputAction::class,

        // Daily digest workflow actions
        'formatAsJson' => \App\Services\Agents\Actions\FormatAsJsonAction::class,
        'consolidateResearch' => \App\Services\Agents\Actions\ConsolidateResearchAction::class,
        'sendWebhook' => \App\Services\Agents\Actions\SendWebhookAction::class,
        'slackMarkdown' => \App\Services\Agents\Actions\SlackMarkdownAction::class,

        // Future actions (implement as needed):
        // 'storeInKnowledge' => \App\Services\Agents\Actions\StoreInKnowledgeAction::class,
        // 'notifySlack' => \App\Services\Agents\Actions\NotifySlackAction::class,
        // 'validateOutput' => \App\Services\Agents\Actions\ValidateOutputAction::class,
        // 'auditTrail' => \App\Services\Agents\Actions\AuditTrailAction::class,
    ];

    /**
     * Execute an action by method name and return transformed data
     *
     * @param  string  $method  Action method name
     * @param  string  $data  Input/output data to process
     * @param  array  $context  Execution context
     * @param  array  $params  Action parameters
     * @return string Transformed data (unchanged if no transformation or on non-critical failure)
     *
     * @throws \InvalidArgumentException if method is not registered or params invalid
     * @throws \Exception if action execution fails (critical actions only)
     */
    public static function execute(string $method, string $data, array $context, array $params = []): string
    {
        if (! self::isValidMethod($method)) {
            throw new \InvalidArgumentException("Action method '{$method}' is not registered");
        }

        try {
            $actionClass = self::$actions[$method];
            $action = app($actionClass);

            // Validate action implements interface
            if (! $action instanceof ActionInterface) {
                throw new \RuntimeException("Action '{$method}' must implement ActionInterface");
            }

            // Validate parameters
            if (! $action->validate($params)) {
                throw new \InvalidArgumentException("Invalid parameters for action '{$method}'");
            }

            Log::debug('ActionRegistry: Executing action', [
                'method' => $method,
                'data_length' => strlen($data),
                'context_keys' => array_keys($context),
                'params' => $params,
                'should_queue' => $action->shouldQueue(),
            ]);

            /**
             * Future Enhancement: Queued Action Support
             *
             * Currently, all workflow actions execute synchronously during synthesis.
             * For long-running actions (email sending, external API calls, file processing),
             * queue support would improve user experience.
             *
             * Implementation plan:
             * 1. Create ExecuteWorkflowActionJob to handle queued execution
             * 2. Add job tracking to AgentExecution metadata
             * 3. Implement completion callbacks for async result handling
             * 4. Add UI feedback for queued vs immediate actions
             *
             * Use cases:
             * - Email notifications (don't block synthesis on SMTP)
             * - Webhook dispatching (external service may be slow)
             * - Large file processing (PDFs, videos)
             * - Batch operations (multiple database writes)
             *
             * @see \App\Services\Agents\Actions\OutputAction::shouldQueue()
             * @link https://github.com/yourorg/promptlyagent/issues/XXX (create issue if needed)
             */
            // if ($action->shouldQueue()) {
            //     dispatch(new ExecuteWorkflowActionJob($method, $data, $context, $params));
            //     return $data; // Return unchanged for queued actions
            // }

            $result = $action->execute($data, $context, $params);

            // SAFETY: Validate action returned non-empty string
            // Empty strings could break execution chains
            if ($result === '') {
                Log::warning('ActionRegistry: Action returned empty string, using original data', [
                    'method' => $method,
                    'input_length' => strlen($data),
                ]);
                $result = $data;
            }

            Log::debug('ActionRegistry: Action executed successfully', [
                'method' => $method,
                'input_length' => strlen($data),
                'output_length' => strlen($result),
                'data_modified' => $data !== $result,
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('ActionRegistry: Action execution failed', [
                'method' => $method,
                'error' => $e->getMessage(),
                'data_length' => strlen($data),
                'context_keys' => array_keys($context),
                'params' => $params,
            ]);

            // Don't throw for non-critical actions - log and continue with unchanged data
            // This prevents workflow failures from action side effects
            if (! self::isCriticalAction($method)) {
                Log::warning("ActionRegistry: Non-critical action '{$method}' failed, continuing workflow with unchanged data");

                return $data;
            }

            throw $e;
        }
    }

    /**
     * Check if method is registered
     */
    public static function isValidMethod(string $method): bool
    {
        return array_key_exists($method, self::$actions);
    }

    /**
     * Get all registered action methods
     */
    public static function getAvailableMethods(): array
    {
        return array_keys(self::$actions);
    }

    /**
     * Get action information for documentation/UI
     *
     * @return array Array of action metadata
     */
    public static function getActionsInfo(): array
    {
        $info = [];

        foreach (self::$actions as $method => $class) {
            try {
                $action = app($class);

                if ($action instanceof ActionInterface) {
                    $info[$method] = [
                        'method' => $method,
                        'class' => $class,
                        'description' => $action->getDescription(),
                        'parameters' => $action->getParameterSchema(),
                        'should_queue' => $action->shouldQueue(),
                    ];
                }
            } catch (\Exception $e) {
                Log::warning("ActionRegistry: Could not load action info for {$method}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $info;
    }

    /**
     * Register a new action dynamically (for plugins/packages)
     *
     * @param  string  $method  Action method name
     * @param  string  $class  Fully qualified class name
     */
    public static function register(string $method, string $class): void
    {
        if (self::isValidMethod($method)) {
            Log::warning("ActionRegistry: Method '{$method}' already registered, overwriting");
        }

        if (! class_exists($class)) {
            throw new \InvalidArgumentException("Action class '{$class}' does not exist");
        }

        self::$actions[$method] = $class;

        Log::info("ActionRegistry: Registered action '{$method}' => '{$class}'");
    }

    /**
     * Check if action is critical (should stop workflow on failure)
     *
     * Most actions are non-critical (logging, webhooks, notifications).
     * Critical actions should stop workflow execution on failure.
     */
    protected static function isCriticalAction(string $method): bool
    {
        $criticalActions = [
            'validateOutput', // Validation failures should stop workflow
        ];

        return in_array($method, $criticalActions);
    }
}

<?php

namespace App\Http\Controllers\Concerns;

use App\Models\InputTrigger;
use Illuminate\Support\Facades\Auth;

/**
 * Validates Trigger Access Trait
 *
 * Provides reusable authorization methods for API controllers that work with triggers.
 * Reduces code duplication across InputTriggerController, AgentController, etc.
 */
trait ValidatesTriggerAccess
{
    /**
     * Require specific Sanctum token ability
     *
     * @param  string  $ability  Required ability (e.g., 'trigger:invoke')
     * @param  string|null  $context  Optional context for error message
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function requireAbility(string $ability, ?string $context = null): void
    {
        if (! request()->user()->tokenCan($ability)) {
            $messages = [
                'trigger:invoke' => 'Your API token does not have the trigger:invoke ability',
                'trigger:attach' => 'Your API token does not have the trigger:attach ability required for file uploads',
                'trigger:tools' => 'Your API token does not have the trigger:tools ability required for tool override',
                'trigger:status' => 'Your API token does not have the trigger:status ability',
                'agent:view' => 'Your API token does not have the agent:view ability',
                'tools:view' => 'Your API token does not have the tools:view ability',
                'chat:view' => 'Your API token does not have the chat:view ability',
            ];

            $message = $context ?? ($messages[$ability] ?? "Missing required ability: {$ability}");

            abort(response()->json([
                'success' => false,
                'error' => 'Unauthorized',
                'message' => $message,
            ], 403));
        }
    }

    /**
     * Require trigger ownership
     *
     * @param  InputTrigger  $trigger  The trigger to validate
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function requireTriggerOwnership(InputTrigger $trigger): void
    {
        if ($trigger->user_id !== Auth::id()) {
            abort(response()->json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'You do not have permission to access this trigger',
            ], 403));
        }
    }

    /**
     * Require trigger to be active
     *
     * @param  InputTrigger  $trigger  The trigger to validate
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function requireActiveTrigger(InputTrigger $trigger): void
    {
        if (! $trigger->is_active) {
            abort(response()->json([
                'success' => false,
                'error' => 'Trigger Disabled',
                'message' => 'This trigger is currently disabled',
            ], 403));
        }
    }

    /**
     * Find trigger by ID or fail with standardized error
     *
     * @param  string  $id  Trigger ID
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function findTriggerOrFail(string $id): InputTrigger
    {
        $trigger = InputTrigger::where('id', $id)->first();

        if (! $trigger) {
            abort(response()->json([
                'success' => false,
                'error' => 'Not Found',
                'message' => 'Trigger not found',
            ], 404));
        }

        return $trigger;
    }

    /**
     * Validate full trigger access (ownership + active)
     *
     * Convenience method that combines ownership and active status checks.
     *
     * @param  InputTrigger  $trigger  The trigger to validate
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    protected function validateTriggerAccess(InputTrigger $trigger): void
    {
        $this->requireTriggerOwnership($trigger);
        $this->requireActiveTrigger($trigger);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OutputAction;
use App\Models\OutputActionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * @group Output Actions
 *
 * View execution logs and audit trails for output actions.
 * Output actions are automated webhooks triggered by agent executions.
 *
 * ## Use Cases
 * - Compliance reporting and audit trails
 * - Debugging failed webhook deliveries
 * - Monitoring action success rates
 * - Performance analysis
 *
 * ## Authentication
 * Required token abilities:
 * - `output-action:view` - View action logs and statistics
 *
 * ## Log Retention
 * Logs are retained for up to 365 days and can be filtered by date range.
 */
class OutputActionController extends Controller
{
    /**
     * List execution logs for an output action
     *
     * Retrieve paginated execution logs with filtering and statistics for a specific output action.
     * Useful for debugging, compliance reporting, and monitoring webhook delivery success rates.
     *
     * @authenticated
     *
     * @urlParam action integer required The output action ID. Example: 1
     *
     * @queryParam status string Optional filter by execution status. Options: success, failed, timeout. Example: success
     * @queryParam days integer Optional filter by recent days (1-365). Defaults to 30. Example: 7
     * @queryParam per_page integer Optional results per page (1-100). Defaults to 50. Example: 20
     *
     * @response 200 scenario="Success with logs" {"success": true, "action": {"id": 1, "name": "Slack Notification", "type": "webhook", "status": "active"}, "stats": {"total_executions": 100, "successes": 95, "failures": 4, "timeouts": 1, "success_rate": 95.0, "avg_duration_ms": 245.3, "last_executed_at": "2024-01-01T12:00:00Z", "period_days": 30}, "logs": {"data": [{"id": 123, "status": "success", "response_code": 200, "duration_ms": 234, "executed_at": "2024-01-01T12:00:00Z"}], "current_page": 1, "per_page": 50, "total": 100}}
     * @response 403 scenario="Not Action Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to view this action"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField action object Output action details
     * @responseField action.id integer Action ID
     * @responseField action.name string Action name
     * @responseField action.type string Provider type (webhook, slack, email)
     * @responseField action.status string Action status (active, inactive)
     * @responseField stats object Execution statistics for the specified period
     * @responseField stats.total_executions integer Total executions in period
     * @responseField stats.successes integer Number of successful executions
     * @responseField stats.failures integer Number of failed executions
     * @responseField stats.timeouts integer Number of timeout executions
     * @responseField stats.success_rate number Success percentage (0-100)
     * @responseField stats.avg_duration_ms number Average execution duration in milliseconds
     * @responseField stats.last_executed_at string Last execution timestamp (ISO 8601) or null
     * @responseField stats.period_days integer Number of days included in statistics
     * @responseField logs object Paginated execution logs
     * @responseField logs.data array Array of log entries
     * @responseField logs.current_page integer Current page number
     * @responseField logs.per_page integer Results per page
     * @responseField logs.total integer Total number of logs
     */
    public function logs(Request $request, OutputAction $action): JsonResponse
    {
        // Authorization: User must own the output action
        if ($action->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'You do not have permission to view this action',
            ], 403);
        }

        // Build query with filters
        $query = $action->logs()->with(['user', 'triggerable']);

        // Filter by status
        if ($request->filled('status')) {
            $status = $request->input('status');
            if (in_array($status, ['success', 'failed', 'timeout'])) {
                $query->where('status', $status);
            }
        }

        // Filter by date range
        $days = min($request->integer('days', 30), 365); // Max 1 year
        $query->where('executed_at', '>=', now()->subDays($days));

        // SECURITY: Validate and cap per_page to prevent resource exhaustion
        $perPage = min(
            $request->integer('per_page', 50),
            100
        );

        // Order by most recent first
        $logs = $query->orderBy('executed_at', 'desc')->paginate($perPage);

        // Calculate statistics
        $stats = $this->calculateLogStatistics($action, $days);

        return response()->json([
            'success' => true,
            'action' => [
                'id' => $action->id,
                'name' => $action->name,
                'type' => $action->provider_id,
                'status' => $action->status,
            ],
            'stats' => $stats,
            'logs' => $logs,
        ], 200);
    }

    /**
     * View a specific execution log
     *
     * Retrieve complete details for a single output action execution log including request/response data,
     * headers, timing information, and error details.
     *
     * @authenticated
     *
     * @urlParam log integer required The log entry ID. Example: 123
     *
     * @response 200 scenario="Success" {"success": true, "log": {"id": 123, "output_action": {"id": 1, "name": "Slack Notification", "type": "webhook"}, "user": {"id": 10, "name": "John Doe"}, "triggerable": {"type": "App\\Models\\AgentExecution", "id": 456}, "url": "https://hooks.slack.com/services/...", "method": "POST", "headers": {"Content-Type": "application/json"}, "body": {"text": "Agent completed successfully"}, "status": "success", "response_code": 200, "response_body": {"ok": true}, "error_message": null, "duration_ms": 234, "executed_at": "2024-01-01T12:00:00Z", "created_at": "2024-01-01T12:00:00Z"}}
     * @response 403 scenario="Not Log Owner" {"success": false, "error": "Forbidden", "message": "You do not have permission to view this log"}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField log object Complete log entry details
     * @responseField log.id integer Log entry ID
     * @responseField log.output_action object Output action that was executed
     * @responseField log.output_action.id integer Action ID
     * @responseField log.output_action.name string Action name
     * @responseField log.output_action.type string Provider type
     * @responseField log.user object User who triggered the action (null for system triggers)
     * @responseField log.triggerable object The model that triggered this action (agent execution, chat interaction, etc.)
     * @responseField log.triggerable.type string Model class name
     * @responseField log.triggerable.id integer Model ID
     * @responseField log.url string Target URL that was called
     * @responseField log.method string HTTP method used (POST, GET, etc.)
     * @responseField log.headers object Request headers sent
     * @responseField log.body object Request body sent
     * @responseField log.status string Execution status (success, failed, timeout)
     * @responseField log.response_code integer HTTP response code received (null on timeout)
     * @responseField log.response_body object Response body received (null on failure/timeout)
     * @responseField log.error_message string Error description (null on success)
     * @responseField log.duration_ms integer Execution duration in milliseconds
     * @responseField log.executed_at string Execution timestamp (ISO 8601)
     * @responseField log.created_at string Log creation timestamp (ISO 8601)
     */
    public function show(Request $request, OutputActionLog $log): JsonResponse
    {
        // Authorization: User must own the output action
        if ($log->outputAction->user_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'You do not have permission to view this log',
            ], 403);
        }

        // Load relationships
        $log->load(['outputAction', 'user', 'triggerable']);

        return response()->json([
            'success' => true,
            'log' => [
                'id' => $log->id,
                'output_action' => [
                    'id' => $log->outputAction->id,
                    'name' => $log->outputAction->name,
                    'type' => $log->outputAction->provider_id,
                ],
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                ] : null,
                'triggerable' => $log->triggerable ? [
                    'type' => $log->triggerable_type,
                    'id' => $log->triggerable_id,
                ] : null,
                'url' => $log->url,
                'method' => $log->method,
                'headers' => $log->headers,
                'body' => $log->body,
                'status' => $log->status,
                'response_code' => $log->response_code,
                'response_body' => $log->response_body,
                'error_message' => $log->error_message,
                'duration_ms' => $log->duration_ms,
                'executed_at' => $log->executed_at,
                'created_at' => $log->created_at,
            ],
        ], 200);
    }

    /**
     * List all execution logs across all actions
     *
     * Retrieve execution logs across all output actions owned by the authenticated user.
     * Supports filtering by action, status, and date range.
     *
     * @authenticated
     *
     * @queryParam action_id integer Optional filter by specific output action ID. Example: 1
     * @queryParam status string Optional filter by execution status. Options: success, failed, timeout. Example: failed
     * @queryParam days integer Optional filter by recent days (1-365). Defaults to 7. Example: 30
     * @queryParam per_page integer Optional results per page (1-100). Defaults to 50. Example: 20
     *
     * @response 200 scenario="Success" {"success": true, "logs": {"data": [{"id": 123, "output_action": {"id": 1, "name": "Slack Notification"}, "status": "success", "response_code": 200, "duration_ms": 234, "executed_at": "2024-01-01T12:00:00Z"}], "current_page": 1, "per_page": 50, "total": 250}}
     *
     * @responseField success boolean Indicates if the request was successful
     * @responseField logs object Paginated execution logs across all actions
     * @responseField logs.data array Array of log entries with nested output_action, user, and triggerable details
     * @responseField logs.current_page integer Current page number
     * @responseField logs.per_page integer Results per page
     * @responseField logs.total integer Total number of logs matching filters
     */
    public function index(Request $request): JsonResponse
    {
        // Build query for user's actions only
        $query = OutputActionLog::whereHas('outputAction', function ($q) {
            $q->where('user_id', Auth::id());
        })->with(['outputAction', 'user', 'triggerable']);

        // Filter by action
        if ($request->filled('action_id')) {
            $query->where('output_action_id', $request->input('action_id'));
        }

        // Filter by status
        if ($request->filled('status')) {
            $status = $request->input('status');
            if (in_array($status, ['success', 'failed', 'timeout'])) {
                $query->where('status', $status);
            }
        }

        // Filter by date range
        $days = min($request->integer('days', 7), 365); // Max 1 year
        $query->where('executed_at', '>=', now()->subDays($days));

        // SECURITY: Validate and cap per_page to prevent resource exhaustion
        $perPage = min(
            $request->integer('per_page', 50),
            100
        );

        // Order by most recent first
        $logs = $query->orderBy('executed_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'logs' => $logs,
        ], 200);
    }

    /**
     * Calculate statistics for output action logs
     */
    protected function calculateLogStatistics(OutputAction $action, int $days): array
    {
        $logsInPeriod = $action->logs()
            ->where('executed_at', '>=', now()->subDays($days))
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) as successes,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failures,
                SUM(CASE WHEN status = "timeout" THEN 1 ELSE 0 END) as timeouts,
                AVG(duration_ms) as avg_duration_ms,
                MAX(executed_at) as last_executed_at
            ')
            ->first();

        $successRate = $logsInPeriod->total > 0
            ? round(($logsInPeriod->successes / $logsInPeriod->total) * 100, 2)
            : 0;

        return [
            'total_executions' => $logsInPeriod->total ?? 0,
            'successes' => $logsInPeriod->successes ?? 0,
            'failures' => $logsInPeriod->failures ?? 0,
            'timeouts' => $logsInPeriod->timeouts ?? 0,
            'success_rate' => $successRate,
            'avg_duration_ms' => $logsInPeriod->avg_duration_ms ? round($logsInPeriod->avg_duration_ms, 2) : null,
            'last_executed_at' => $logsInPeriod->last_executed_at,
            'period_days' => $days,
        ];
    }
}

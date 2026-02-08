<?php

namespace App\Console\Commands;

use App\Models\OutputActionLog;
use Illuminate\Console\Command;

/**
 * Generate Output Action Audit Report
 *
 * Generates comprehensive compliance and audit reports for output action executions.
 * Provides statistics on success rates, execution times, and failure patterns grouped
 * by action type.
 *
 * Usage:
 *   ./vendor/bin/sail artisan output-actions:audit-report
 *   ./vendor/bin/sail artisan output-actions:audit-report --days=7
 *   ./vendor/bin/sail artisan output-actions:audit-report --days=90
 *
 * Features:
 * - Execution counts per action type
 * - Success/failure breakdown
 * - Average execution time metrics
 * - Last execution timestamps
 * - User-friendly table display
 *
 * Compliance: Suitable for SOC2, HIPAA, GDPR audit requirements
 */
class GenerateOutputActionAuditReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'output-actions:audit-report
                            {--days=30 : Number of days to include in the report (max: 365)}
                            {--format=table : Output format: table, json, csv}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate audit report for output action executions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = min((int) $this->option('days'), 365); // Max 1 year
        $format = $this->option('format');
        $startDate = now()->subDays($days);

        $this->info('Generating Output Action Audit Report');
        $this->info("Period: Last {$days} days (from {$startDate->toDateString()})");
        $this->newLine();

        // Fetch statistics grouped by action type
        $stats = OutputActionLog::where('executed_at', '>=', $startDate)
            ->join('output_actions', 'output_action_logs.output_action_id', '=', 'output_actions.id')
            ->selectRaw('
                output_actions.name as action_name,
                output_actions.provider_id as action_type,
                COUNT(*) as total_executions,
                SUM(CASE WHEN output_action_logs.status = "success" THEN 1 ELSE 0 END) as successes,
                SUM(CASE WHEN output_action_logs.status = "failed" THEN 1 ELSE 0 END) as failures,
                SUM(CASE WHEN output_action_logs.status = "timeout" THEN 1 ELSE 0 END) as timeouts,
                AVG(output_action_logs.duration_ms) as avg_time_ms,
                MAX(output_action_logs.executed_at) as last_execution
            ')
            ->groupBy('output_actions.id', 'output_actions.name', 'output_actions.provider_id')
            ->orderBy('total_executions', 'desc')
            ->get();

        if ($stats->isEmpty()) {
            $this->warn("No output action executions found in the last {$days} days.");

            return self::SUCCESS;
        }

        // Calculate overall statistics
        $totalExecutions = $stats->sum('total_executions');
        $totalSuccesses = $stats->sum('successes');
        $totalFailures = $stats->sum('failures');
        $totalTimeouts = $stats->sum('timeouts');
        $overallSuccessRate = $totalExecutions > 0
            ? round(($totalSuccesses / $totalExecutions) * 100, 2)
            : 0;

        // Display summary
        $this->info('📊 SUMMARY STATISTICS');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Executions', number_format($totalExecutions)],
                ['Successful', number_format($totalSuccesses)." ({$overallSuccessRate}%)"],
                ['Failed', number_format($totalFailures)],
                ['Timeouts', number_format($totalTimeouts)],
                ['Unique Actions', $stats->count()],
            ]
        );

        $this->newLine();

        // Format data based on output format
        switch ($format) {
            case 'json':
                $this->handleJsonOutput($stats, $days, $overallSuccessRate);
                break;

            case 'csv':
                $this->handleCsvOutput($stats, $days);
                break;

            case 'table':
            default:
                $this->handleTableOutput($stats);
                break;
        }

        $this->newLine();
        $this->info('✅ Audit report generated successfully');

        return self::SUCCESS;
    }

    /**
     * Display detailed table output
     */
    protected function handleTableOutput($stats): void
    {
        $this->info('📋 DETAILED ACTION BREAKDOWN');

        $tableData = $stats->map(function ($stat) {
            $successRate = $stat->total_executions > 0
                ? round(($stat->successes / $stat->total_executions) * 100, 1)
                : 0;

            return [
                $stat->action_name,
                $stat->action_type,
                number_format($stat->total_executions),
                number_format($stat->successes),
                number_format($stat->failures),
                number_format($stat->timeouts),
                $successRate.'%',
                $stat->avg_time_ms ? round($stat->avg_time_ms, 2).' ms' : 'N/A',
                $stat->last_execution?->diffForHumans() ?? 'Never',
            ];
        })->toArray();

        $this->table(
            [
                'Action Name',
                'Type',
                'Total',
                'Success',
                'Failed',
                'Timeout',
                'Success Rate',
                'Avg Time',
                'Last Run',
            ],
            $tableData
        );
    }

    /**
     * Output as JSON
     */
    protected function handleJsonOutput($stats, int $days, float $overallSuccessRate): void
    {
        $output = [
            'report_generated_at' => now()->toIso8601String(),
            'period_days' => $days,
            'overall_success_rate' => $overallSuccessRate,
            'actions' => $stats->map(function ($stat) {
                return [
                    'action_name' => $stat->action_name,
                    'action_type' => $stat->action_type,
                    'total_executions' => $stat->total_executions,
                    'successes' => $stat->successes,
                    'failures' => $stat->failures,
                    'timeouts' => $stat->timeouts,
                    'success_rate' => $stat->total_executions > 0
                        ? round(($stat->successes / $stat->total_executions) * 100, 2)
                        : 0,
                    'avg_duration_ms' => $stat->avg_time_ms ? round($stat->avg_time_ms, 2) : null,
                    'last_executed_at' => $stat->last_execution?->toIso8601String(),
                ];
            })->toArray(),
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT));
    }

    /**
     * Output as CSV
     */
    protected function handleCsvOutput($stats, int $days): void
    {
        $filename = storage_path('logs/output-action-audit-'.now()->format('Y-m-d-His').'.csv');

        $fp = fopen($filename, 'w');

        // CSV Headers
        fputcsv($fp, [
            'Action Name',
            'Action Type',
            'Total Executions',
            'Successes',
            'Failures',
            'Timeouts',
            'Success Rate (%)',
            'Avg Duration (ms)',
            'Last Executed At',
        ]);

        // CSV Data
        foreach ($stats as $stat) {
            $successRate = $stat->total_executions > 0
                ? round(($stat->successes / $stat->total_executions) * 100, 2)
                : 0;

            fputcsv($fp, [
                $stat->action_name,
                $stat->action_type,
                $stat->total_executions,
                $stat->successes,
                $stat->failures,
                $stat->timeouts,
                $successRate,
                $stat->avg_time_ms ? round($stat->avg_time_ms, 2) : null,
                $stat->last_execution?->toIso8601String() ?? 'Never',
            ]);
        }

        fclose($fp);

        $this->info("CSV report saved to: {$filename}");
    }
}

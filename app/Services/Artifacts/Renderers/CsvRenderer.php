<?php

namespace App\Services\Artifacts\Renderers;

use App\Models\Artifact;

/**
 * CSV Artifact Renderer - Tabular Data Display and Export.
 *
 * Renders CSV (Comma-Separated Values) artifacts as HTML tables for
 * display, with proper formatting for downloads. Automatically parses
 * CSV structure and applies table styling.
 *
 * Table Rendering Features:
 * - First row treated as table header
 * - Responsive table with horizontal scrolling
 * - Dark mode support via Tailwind classes
 * - Hover effects on rows
 * - Truncation indicator when previewing large datasets
 *
 * Preview Mode:
 * - Limits display to first 10 rows by default
 * - Shows count of remaining rows
 * - Prevents performance issues with large CSV files
 *
 * CSV Parsing:
 * - Uses str_getcsv() for RFC 4180 compliant parsing
 * - Handles quoted fields and embedded commas
 * - Sanitizes output to prevent XSS
 * - Graceful handling of malformed CSV
 *
 * Download Format:
 * - Returns raw CSV content unchanged
 * - Proper text/csv MIME type
 * - .csv file extension
 *
 * @see \App\Services\Artifacts\Renderers\AbstractArtifactRenderer
 */
class CsvRenderer extends AbstractArtifactRenderer
{
    /**
     * Render CSV content as an HTML table
     */
    public function render(Artifact $artifact): string
    {
        $content = $this->raw($artifact);

        return $this->renderCsvTable($content);
    }

    /**
     * Render CSV preview (truncated to max rows)
     */
    public function renderPreview(Artifact $artifact, int $maxLength = 500): string
    {
        $content = $this->raw($artifact);

        // For preview, limit to first ~10 rows
        return $this->renderCsvTable($content, 10);
    }

    /**
     * Parse CSV content and render as an HTML table
     *
     * @param  string  $content  CSV content to parse
     * @param  int|null  $maxRows  Maximum number of rows to display (null for all)
     * @return string HTML table representation
     */
    protected function renderCsvTable(string $content, ?int $maxRows = null): string
    {
        if (empty(trim($content))) {
            return '<div class="text-sm text-zinc-500 dark:text-zinc-400 p-4">Empty CSV file</div>';
        }

        // Parse CSV content
        $lines = str_getcsv($content, "\n");
        if (empty($lines)) {
            return '<div class="text-sm text-zinc-500 dark:text-zinc-400 p-4">No data available</div>';
        }

        $rows = array_map(function ($line) {
            return str_getcsv($line);
        }, $lines);

        if (empty($rows)) {
            return '<div class="text-sm text-zinc-500 dark:text-zinc-400 p-4">No data available</div>';
        }

        // Extract header and data rows
        $header = array_shift($rows);

        // Limit rows if specified
        $displayRows = $rows;
        $hasMore = false;
        if ($maxRows !== null && count($rows) > $maxRows) {
            $displayRows = array_slice($rows, 0, $maxRows);
            $hasMore = true;
        }

        // Build HTML table
        $html = '<div class="overflow-x-auto">';
        $html .= '<table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700 text-sm">';

        // Header
        $html .= '<thead class="bg-zinc-50 dark:bg-zinc-800">';
        $html .= '<tr>';
        foreach ($header as $cell) {
            $html .= sprintf(
                '<th class="px-4 py-3 text-left text-xs font-medium text-zinc-700 dark:text-zinc-300 uppercase tracking-wider">%s</th>',
                htmlspecialchars($cell, ENT_QUOTES, 'UTF-8')
            );
        }
        $html .= '</tr>';
        $html .= '</thead>';

        // Body
        $html .= '<tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">';
        foreach ($displayRows as $row) {
            $html .= '<tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800">';
            foreach ($row as $cell) {
                $html .= sprintf(
                    '<td class="px-4 py-3 text-zinc-900 dark:text-zinc-100 whitespace-nowrap">%s</td>',
                    htmlspecialchars($cell, ENT_QUOTES, 'UTF-8')
                );
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';

        $html .= '</table>';

        // Show indicator if there are more rows
        if ($hasMore) {
            $totalRows = count($rows);
            $remaining = $totalRows - $maxRows;
            $html .= sprintf(
                '<div class="text-xs text-zinc-500 dark:text-zinc-400 p-3 bg-zinc-50 dark:bg-zinc-800 border-t border-zinc-200 dark:border-zinc-700">Showing %d of %d rows (%d more...)</div>',
                $maxRows,
                $totalRows,
                $remaining
            );
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Get MIME type for CSV files
     */
    public function getMimeType(Artifact $artifact): string
    {
        return 'text/csv; charset=utf-8';
    }

    /**
     * Get file extension for CSV files
     */
    public function getFileExtension(Artifact $artifact): string
    {
        return 'csv';
    }
}

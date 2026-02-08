<?php

namespace App\Tools;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Tool;

class PromptlyAgentPrismTool
{
    public static function create()
    {
        return Tool::as('promptlyagent')
            ->for('Retrieve text from Ernest Hemingway\'s The Old Man and the Sea')
            ->withNumberParameter('length', 'Length of the text to retrieve')
            ->using(function (int $length = 1024) {
                // Get the StatusReporter from the execution context
                $statusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

                if (! $statusReporter) {
                    Log::warning('PromptlyAgentPrismTool: No status reporter available for status reporting');
                }

                try {
                    $start = microtime(true);

                    if ($statusReporter) {
                        $statusReporter->report('promptlyagent', "Extracting passage: {$length} characters", true, false);
                    }

                    $filePath = resource_path('data/The-Old-Man-and-the-Sea-Ernest-Hemingway.txt');
                    if (! file_exists($filePath)) {
                        if ($statusReporter) {
                            $statusReporter->report('promptlyagent', "Error: Book corpus not found at $filePath", true, false);
                        }

                        return json_encode([
                            'success' => false,
                            'error' => 'file_not_found',
                            'message' => "Book corpus not found at $filePath",
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ]);
                    }

                    $book = file_get_contents($filePath);
                    if (! $book) {
                        if ($statusReporter) {
                            $statusReporter->report('promptlyagent', 'Error: Failed to read book corpus', true, false);
                        }

                        return json_encode([
                            'success' => false,
                            'error' => 'file_read_error',
                            'message' => 'Failed to read book corpus.',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ]);
                    }

                    // Normalize whitespace and split into words
                    $book = preg_replace('/\s+/', ' ', $book);
                    $words = preg_split('/\s+/', trim($book));
                    $totalWords = count($words);

                    // Find all possible start indices where a chunk of words (not exceeding $length chars) can be taken
                    $candidates = [];
                    for ($i = 0; $i < $totalWords; $i++) {
                        $text = '';
                        $j = $i;
                        while ($j < $totalWords && strlen($text.' '.$words[$j]) <= $length) {
                            $text = $text === '' ? $words[$j] : $text.' '.$words[$j];
                            $j++;
                        }
                        // Only add if the chunk is at least 80% of requested length (to avoid too-short results)
                        if (strlen($text) >= (int) ($length * 0.8) && strlen($text) <= $length) {
                            $candidates[] = $text;
                        }
                    }

                    if (empty($candidates)) {
                        if ($statusReporter) {
                            $statusReporter->report('promptlyagent', 'Error: Could not find a suitable text chunk of the requested length', true, false);
                        }

                        return json_encode([
                            'success' => false,
                            'error' => 'text_processing_error',
                            'message' => 'Could not find a suitable text chunk of the requested length.',
                            'metadata' => [
                                'executed_at' => now()->toISOString(),
                                'tool_version' => '1.0.0',
                                'error_occurred' => true,
                            ],
                        ]);
                    }

                    // Pick a random candidate
                    $randomText = $candidates[array_rand($candidates)];
                    $duration = microtime(true) - $start;

                    $result = json_encode([
                        'success' => true,
                        'data' => [
                            'length' => $length,
                            'message' => $randomText,
                        ],
                        'metadata' => [
                            'executed_at' => now()->toISOString(),
                            'tool_version' => '1.0.0',
                            'execution_time_ms' => (int) ((microtime(true) - $start) * 1000),
                        ],
                    ]);

                    if ($statusReporter) {
                        $statusReporter->report('promptlyagent', 'Extracted: '.strlen($randomText).' characters, '.str_word_count($randomText).' words', true, false);
                    }

                    return $result;

                } catch (\Exception $e) {
                    if ($statusReporter) {
                        $statusReporter->report('promptlyagent', 'Error: '.$e->getMessage(), false, false);
                    }

                    Log::error('PromptlyAgent tool execution failed', [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    throw $e;
                }
            });
    }
}

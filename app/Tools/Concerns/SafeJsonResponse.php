<?php

namespace App\Tools\Concerns;

use Illuminate\Support\Facades\Log;

trait SafeJsonResponse
{
    /**
     * Safely encode data to JSON, handling potential issues
     */
    protected static function safeJsonEncode(array $data, string $toolName = 'unknown'): string
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            // Validate that we can decode it back
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return $json;

        } catch (\JsonException $e) {
            try {
                $cleanedData = self::cleanDataForJson($data);
                $json = json_encode($cleanedData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                return $json;
            } catch (\JsonException $cleanException) {
                Log::error("JSON encoding error in tool: {$toolName} even after cleaning", [
                    'error' => $cleanException->getMessage(),
                ]);

                return json_encode([
                    'success' => false,
                    'error' => 'JSON encoding error',
                    'message' => "Tool {$toolName} encountered a data formatting issue",
                    'metadata' => [
                        'executed_at' => now()->toISOString(),
                        'error_type' => 'json_encoding_error',
                    ],
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Unexpected error in safeJsonEncode for tool: {$toolName}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return json_encode([
                'success' => false,
                'error' => 'Unexpected error',
                'message' => "Tool {$toolName} encountered an unexpected error",
                'metadata' => [
                    'executed_at' => now()->toISOString(),
                    'error_type' => 'unexpected_error',
                ],
            ]);
        }
    }

    /**
     * Clean data recursively to ensure JSON safety
     */
    protected static function cleanDataForJson($data)
    {
        if (is_string($data)) {
            // Ensure string is valid UTF-8
            if (! mb_check_encoding($data, 'UTF-8')) {
                $data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
            }

            // Remove any control characters that might break JSON
            $data = preg_replace('/[\x00-\x1F\x7F]/', '', $data);

            return $data;
        }

        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $key => $value) {
                $cleanKey = is_string($key) ? self::cleanDataForJson($key) : $key;
                $cleaned[$cleanKey] = self::cleanDataForJson($value);
            }

            return $cleaned;
        }

        if (is_object($data)) {
            // Convert objects to arrays for JSON safety
            return self::cleanDataForJson((array) $data);
        }

        // For other types (numbers, booleans, null), return as-is
        return $data;
    }

    /**
     * Validate that a string contains valid JSON
     */
    protected static function isValidJson(string $json): bool
    {
        try {
            json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return true;
        } catch (\JsonException $e) {
            return false;
        }
    }

    /**
     * Safely truncate content that might be too large for JSON
     */
    protected static function safeTruncate(string $content, int $maxLength = 50000): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        $truncated = substr($content, 0, $maxLength);
        $lastSpace = strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > $maxLength * 0.8) {
            $truncated = substr($truncated, 0, $lastSpace);
        }

        return $truncated."\n\n[Content truncated for safety - original length: ".strlen($content).' characters]';
    }
}

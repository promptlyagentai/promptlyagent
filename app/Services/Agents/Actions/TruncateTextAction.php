<?php

namespace App\Services\Agents\Actions;

/**
 * Truncate Text Action
 *
 * Limits text length with optional word boundary preservation.
 * Useful for ensuring agent inputs don't exceed token limits.
 */
class TruncateTextAction implements ActionInterface
{
    public function execute(string $data, array $context, array $params): string
    {
        try {
            $maxLength = $params['maxLength'] ?? 5000;
            $preserveWords = $params['preserveWords'] ?? true;
            $ellipsis = $params['ellipsis'] ?? '...';

            // No truncation needed
            if (strlen($data) <= $maxLength) {
                return $data;
            }

            // Calculate truncation point
            $truncateAt = $maxLength - strlen($ellipsis);

            if ($truncateAt <= 0) {
                return $ellipsis;
            }

            $truncated = substr($data, 0, $truncateAt);

            // Preserve word boundaries if requested
            if ($preserveWords) {
                // Find last complete word
                $lastSpace = strrpos($truncated, ' ');
                if ($lastSpace !== false && $lastSpace > ($truncateAt * 0.8)) {
                    // Only break at word boundary if we're not losing >20% of text
                    $truncated = substr($truncated, 0, $lastSpace);
                }
            }

            return $truncated.$ellipsis;
        } catch (\Throwable $e) {
            // SAFETY: Return original data on any error
            return $data;
        }
    }

    public function validate(array $params): bool
    {
        // maxLength is required
        if (! isset($params['maxLength'])) {
            return false;
        }

        // maxLength must be positive integer
        if (! is_int($params['maxLength']) || $params['maxLength'] <= 0) {
            return false;
        }

        // preserveWords must be boolean if provided
        if (isset($params['preserveWords']) && ! is_bool($params['preserveWords'])) {
            return false;
        }

        // ellipsis must be string if provided
        if (isset($params['ellipsis']) && ! is_string($params['ellipsis'])) {
            return false;
        }

        return true;
    }

    public function getDescription(): string
    {
        return 'Truncate text to maximum length with optional word boundary preservation';
    }

    public function getParameterSchema(): array
    {
        return [
            'maxLength' => [
                'type' => 'int',
                'required' => true,
                'description' => 'Maximum text length in characters',
                'min' => 1,
            ],
            'preserveWords' => [
                'type' => 'bool',
                'required' => false,
                'default' => true,
                'description' => 'Break at word boundaries when possible',
            ],
            'ellipsis' => [
                'type' => 'string',
                'required' => false,
                'default' => '...',
                'description' => 'Text to append when truncating',
            ],
        ];
    }

    public function shouldQueue(): bool
    {
        // Text truncation is fast, run synchronously
        return false;
    }
}

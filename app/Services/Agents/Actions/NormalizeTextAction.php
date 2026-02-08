<?php

namespace App\Services\Agents\Actions;

/**
 * Normalize Text Action
 *
 * Cleans and normalizes text by:
 * - Removing excessive whitespace
 * - Normalizing line endings
 * - Normalizing unicode characters
 * - Optionally trimming to single line
 */
class NormalizeTextAction implements ActionInterface
{
    public function execute(string $data, array $context, array $params): string
    {
        try {
            $text = $data;

            // Normalize unicode (fix encoding issues)
            if ($params['normalizeUnicode'] ?? true) {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }

            // Normalize line endings to \n
            $text = str_replace(["\r\n", "\r"], "\n", $text);

            // Remove excessive whitespace
            if ($params['collapseSpaces'] ?? true) {
                // Replace multiple spaces with single space
                $text = preg_replace('/[ \t]+/', ' ', $text);
            }

            // Remove excessive newlines
            if ($params['collapseNewlines'] ?? true) {
                // Replace 3+ newlines with 2 newlines (preserve paragraph breaks)
                $text = preg_replace('/\n{3,}/', "\n\n", $text);
            }

            // Convert to single line
            if ($params['singleLine'] ?? false) {
                $text = preg_replace('/\s+/', ' ', $text);
            }

            // Trim leading/trailing whitespace
            if ($params['trim'] ?? true) {
                $text = trim($text);
            }

            return $text;
        } catch (\Throwable $e) {
            // SAFETY: Return original data on any error
            return $data;
        }
    }

    public function validate(array $params): bool
    {
        $validParams = ['normalizeUnicode', 'collapseSpaces', 'collapseNewlines', 'singleLine', 'trim'];

        foreach ($params as $key => $value) {
            // Check if parameter is valid
            if (! in_array($key, $validParams)) {
                return false;
            }

            // Check if value is boolean (all params are boolean flags)
            if (! is_bool($value)) {
                return false;
            }
        }

        return true;
    }

    public function getDescription(): string
    {
        return 'Normalize text by cleaning whitespace, line endings, and unicode characters';
    }

    public function getParameterSchema(): array
    {
        return [
            'normalizeUnicode' => [
                'type' => 'bool',
                'required' => false,
                'default' => true,
                'description' => 'Fix unicode encoding issues',
            ],
            'collapseSpaces' => [
                'type' => 'bool',
                'required' => false,
                'default' => true,
                'description' => 'Replace multiple spaces with single space',
            ],
            'collapseNewlines' => [
                'type' => 'bool',
                'required' => false,
                'default' => true,
                'description' => 'Replace 3+ newlines with 2 newlines',
            ],
            'singleLine' => [
                'type' => 'bool',
                'required' => false,
                'default' => false,
                'description' => 'Convert entire text to single line',
            ],
            'trim' => [
                'type' => 'bool',
                'required' => false,
                'default' => true,
                'description' => 'Trim leading/trailing whitespace',
            ],
        ];
    }

    public function shouldQueue(): bool
    {
        // Text normalization is fast, run synchronously
        return false;
    }
}

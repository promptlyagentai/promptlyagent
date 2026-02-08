<?php

namespace App\Services\Agents\Actions;

use Illuminate\Support\Facades\Log;

/**
 * Slack Markdown Action
 *
 * Converts standard markdown to Slack-compatible markdown (mrkdwn) format.
 *
 * Key transformations:
 * - Bold: **text** → *text*
 * - Italic: *text* OR _text_ → _text_
 * - Links: [text](url) → <url|text>
 * - Headers: # Header → *Header* (bold)
 * - Code blocks: ```lang\ncode\n``` → ```code```
 * - Lists: Preserve bullet points and numbering
 * - Horizontal rules: --- → ─────────────
 *
 * Slack limitations:
 * - No nested formatting (e.g., no bold within italic)
 * - No HTML tags
 * - Limited header support (use bold instead)
 * - No image embedding (links only)
 */
class SlackMarkdownAction implements ActionInterface
{
    public function execute(string $data, array $context, array $params): string
    {
        Log::info('SlackMarkdownAction: Converting to Slack-compatible markdown', [
            'input_length' => strlen($data),
            'execution_id' => $context['execution']->id ?? null,
        ]);

        $converted = $data;

        // 1. Convert headers to bold text (Slack doesn't support # headers)
        // Match: ## Header or ### Header etc.
        $converted = preg_replace('/^(#{1,6})\s+(.+)$/m', '*$2*', $converted);

        // 2. Convert bold: **text** → *text* (Slack uses single asterisk for bold)
        // Must do this before italic conversion
        $converted = preg_replace('/\*\*([^\*]+)\*\*/', '*$1*', $converted);

        // 3. Convert italic: *text* or _text_ → _text_ (Slack uses underscore for italic)
        // Only convert remaining single asterisks (after bold conversion above)
        $converted = preg_replace('/(?<!\*)\*(?!\*)([^\*]+)\*(?!\*)/', '_$1_', $converted);

        // 4. Convert links: [text](url) → <url|text>
        $converted = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<$2|$1>', $converted);

        // 5. Convert code blocks: Remove language identifier, keep fences
        // Slack supports ``` but ignores language specifiers
        $converted = preg_replace('/```[a-zA-Z]*\n/', "```\n", $converted);

        // 6. Convert horizontal rules: --- or *** or ___ → Unicode line
        $converted = preg_replace('/^([-*_]){3,}$/m', '─────────────────────', $converted);

        // 7. Handle strikethrough: ~~text~~ → ~text~ (Slack uses single tilde)
        $converted = preg_replace('/~~([^~]+)~~/', '~$1~', $converted);

        // 8. Remove blockquotes prefix (Slack doesn't support > blockquotes well)
        // Convert them to plain text with "Quote:" prefix
        $converted = preg_replace('/^>\s+(.+)$/m', '💬 _$1_', $converted);

        // 9. Ensure lists have proper spacing (Slack is sensitive to this)
        // Add blank line before lists if not present
        $converted = preg_replace('/([^\n])\n([-*•]\s)/', "$1\n\n$2", $converted);

        Log::info('SlackMarkdownAction: Conversion complete', [
            'input_length' => strlen($data),
            'output_length' => strlen($converted),
            'execution_id' => $context['execution']->id ?? null,
        ]);

        return $converted;
    }

    public function validate(array $params): bool
    {
        // No parameters required
        return true;
    }

    public function shouldQueue(): bool
    {
        return false; // Fast operation, execute inline
    }

    public function getDescription(): string
    {
        return 'Convert standard markdown to Slack-compatible mrkdwn format';
    }

    public function getParameterSchema(): array
    {
        return []; // No parameters needed
    }
}

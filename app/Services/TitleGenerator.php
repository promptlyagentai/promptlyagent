<?php

namespace App\Services;

use App\Traits\UsesAIModels;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class TitleGenerator
{
    use UsesAIModels;

    /**
     * Generate a concise title from the provided content
     */
    public function generateFromContent(string $question, string $answer): string
    {
        try {
            // Create content context for title generation
            $conversationContext = $this->buildConversationContext($question, $answer);

            Log::debug('TitleGenerator: Generating title', [
                'question_length' => strlen($question),
                'answer_length' => strlen($answer),
                'context_length' => strlen($conversationContext),
            ]);

            // Use Prism to generate title with low-cost model (fast, efficient for titles)
            // Use withSystemPrompt() for provider interoperability (per Prism best practices)
            $response = $this->useLowCostModel()
                ->withSystemPrompt('Create a concise 3-5 word title that captures the essence of this conversation. Return only the title text.')
                ->withMessages([
                    new UserMessage($conversationContext),
                ])
                ->asText();

            $title = trim($response->text);

            // Validate and clean the title
            $title = $this->cleanTitle($title);

            Log::info('TitleGenerator: Successfully generated title', [
                'title' => $title,
                'title_length' => strlen($title),
            ]);

            return $title;

        } catch (PrismException $e) {
            Log::error('TitleGenerator: Prism exception occurred', [
                'error' => $e->getMessage(),
                'question_length' => strlen($question),
                'answer_length' => strlen($answer),
            ]);

            // Return a fallback title based on question
            return $this->generateFallbackTitle($question);

        } catch (\Throwable $e) {
            Log::error('TitleGenerator: Unexpected error occurred', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return a fallback title based on question
            return $this->generateFallbackTitle($question);
        }
    }

    /**
     * Build conversation context for title generation
     */
    protected function buildConversationContext(string $question, string $answer): string
    {
        // Limit context length to avoid token limits
        $maxQuestionLength = 200;
        $maxAnswerLength = 300;

        $truncatedQuestion = strlen($question) > $maxQuestionLength
            ? substr($question, 0, $maxQuestionLength).'...'
            : $question;

        $truncatedAnswer = strlen($answer) > $maxAnswerLength
            ? substr($answer, 0, $maxAnswerLength).'...'
            : $answer;

        return "Question: {$truncatedQuestion}\n\nAnswer: {$truncatedAnswer}";
    }

    /**
     * Clean and validate the generated title
     */
    protected function cleanTitle(string $title): string
    {
        // Remove quotes if present
        $title = trim($title, '"\'');

        // Remove extra whitespace
        $title = preg_replace('/\s+/', ' ', $title);

        // Ensure title is not too long (max 60 characters)
        if (strlen($title) > 60) {
            $title = substr($title, 0, 57).'...';
        }

        // Ensure title is not empty
        if (empty($title)) {
            return 'Research Discussion';
        }

        return $title;
    }

    /**
     * Generate a simple fallback title from the question
     */
    protected function generateFallbackTitle(string $question): string
    {
        // Extract key words from the question
        $words = str_word_count($question, 1);
        $importantWords = array_filter($words, function ($word) {
            $word = strtolower($word);
            $stopWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'how', 'what', 'when', 'where', 'why', 'who', 'is', 'are', 'was', 'were', 'will', 'would', 'could', 'should', 'can', 'do', 'does', 'did'];

            return strlen($word) > 2 && ! in_array($word, $stopWords);
        });

        // Take first 3-4 important words
        $titleWords = array_slice($importantWords, 0, 4);

        if (count($titleWords) >= 2) {
            return ucwords(implode(' ', $titleWords));
        }

        // Ultimate fallback
        return 'Research Question';
    }
}

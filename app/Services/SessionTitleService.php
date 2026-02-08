<?php

namespace App\Services;

use App\Models\ChatInteraction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SessionTitleService
{
    /**
     * Generate title for session if needed (first interaction with answer)
     */
    public static function generateTitleIfNeeded(ChatInteraction $interaction): void
    {
        $session = $interaction->session;
        if (! $session) {
            return; // No session found
        }

        // Only generate a title if no title exists OR if it's a default datetime title
        if ($session->title && ! static::isDefaultDatetimeTitle($session->title)) {
            return; // Session already has a non-default title
        }

        // Check if this is the first interaction with an answer
        $firstInteraction = $session->interactions()->orderBy('created_at')->first();
        if ($firstInteraction && $firstInteraction->id === $interaction->id && $interaction->answer) {
            try {
                // Use the centralized TitleGenerator service
                $titleGenerator = new TitleGenerator;
                $title = $titleGenerator->generateFromContent($interaction->question, $interaction->answer);

                if ($title) {
                    $session->update(['title' => $title]);

                    Log::info('SessionTitleService: Generated title using TitleGenerator', [
                        'session_id' => $session->id,
                        'interaction_id' => $interaction->id,
                        'title' => $title,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::error('SessionTitleService: Failed to generate title using TitleGenerator', [
                    'session_id' => $session->id,
                    'interaction_id' => $interaction->id,
                    'error' => $e->getMessage(),
                ]);

                // Fallback: use first question
                $title = Str::words($interaction->question, 5, '');
                if ($title) {
                    $session->update(['title' => trim($title)]);
                }
            }
        }
    }

    /**
     * Check if a title is one of our default datetime titles that can be overwritten
     */
    public static function isDefaultDatetimeTitle(?string $title): bool
    {
        if (empty($title)) {
            return false;
        }

        // Check if title matches "Chat MM-DD-YYYY HH:MM" pattern
        return preg_match('/^Chat \d{2}-\d{2}-\d{4} \d{2}:\d{2}$/', $title) === 1;
    }
}

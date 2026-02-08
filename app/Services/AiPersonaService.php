<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * AI Persona Service - User Context Injection for Personalized Responses.
 *
 * Provides user-specific context data that gets injected into agent system
 * prompts to enable personalized AI responses. Includes user demographics,
 * preferences, skills, and local time/timezone information.
 *
 * Persona Context Fields:
 * - **user_name**: Full name for personalized greetings
 * - **local_time**: Formatted local time with timezone
 * - **age**: User age (if provided in profile)
 * - **job_description**: Occupation/role for context-aware responses
 * - **location**: City/region for localized information
 * - **skills**: User skills with proficiency levels for relevant suggestions
 * - **timezone**: Timezone identifier for time-sensitive operations
 *
 * Personalization Benefits:
 * - Time-aware responses (morning/evening greetings)
 * - Skill-level appropriate explanations
 * - Localized information and recommendations
 * - Role-specific suggestions and workflows
 * - Age-appropriate communication style
 *
 * Usage Pattern:
 * - Called by AgentService during system prompt assembly
 * - Injected as contextual information section
 * - Available to all agents automatically
 * - Optional fields gracefully omitted if not set
 *
 * Privacy:
 * - User explicitly provides demographic data
 * - Data stored in user profile, not shared externally
 * - Context limited to current session
 *
 * @see \App\Services\Agents\AgentService
 * @see \App\Models\User
 */
class AiPersonaService
{
    /**
     * Get the AI Persona context for the current user
     *
     * @return array{user_name: string, local_time: string, age?: int, job_description?: string, location?: string, skills?: array, timezone?: string}
     */
    public static function getPersonaContext(?User $user = null): array
    {
        if (! $user) {
            $user = Auth::user();
        }

        if (! $user) {
            return [];
        }

        $context = [
            'user_name' => $user->name,
            'local_time' => Carbon::now($user->timezone ?? config('app.timezone'))->format('Y-m-d H:i:s T'),
        ];

        // Add age if available
        if ($user->age) {
            $context['age'] = $user->age;
        }

        // Add job description if available
        if ($user->job_description) {
            $context['job_description'] = $user->job_description;
        }

        // Add location if available
        if ($user->location) {
            $context['location'] = $user->location;
        }

        // Add skills if available
        if ($user->skills && is_array($user->skills)) {
            $context['skills'] = $user->skills;
        }

        // Add timezone if available
        if ($user->timezone) {
            $context['timezone'] = $user->timezone;
        }

        return $context;
    }

    /**
     * Format the AI Persona context as a string for injection into prompts
     */
    public static function formatPersonaContext(?User $user = null): string
    {
        $context = self::getPersonaContext($user);

        if (empty($context)) {
            return '';
        }

        $parts = [];

        // Basic information
        $parts[] = '**User Information:**';
        $parts[] = "- Name: {$context['user_name']}";
        $parts[] = "- Local Time: {$context['local_time']}";

        // Age
        if (isset($context['age'])) {
            $parts[] = "- Age: {$context['age']} years old";
        }

        // Location
        if (isset($context['location'])) {
            $parts[] = "- Location: {$context['location']}";
        }

        // Job description
        if (isset($context['job_description'])) {
            $parts[] = "- Job/Role: {$context['job_description']}";
        }

        // Skills
        if (isset($context['skills']) && ! empty($context['skills'])) {
            $parts[] = '- Skills & Competencies:';
            foreach ($context['skills'] as $skill) {
                $level = $skill['level'] ?? 5;
                $levelText = self::getSkillLevelText($level);
                $parts[] = "  • {$skill['name']} (Level {$level} - {$levelText})";
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Get skill level text description
     */
    private static function getSkillLevelText(int $level): string
    {
        return match (true) {
            $level <= 3 => 'Beginner',
            $level <= 6 => 'Intermediate',
            $level <= 8 => 'Advanced',
            default => 'Expert',
        };
    }

    /**
     * Inject AI Persona context into a system prompt
     */
    public static function injectIntoSystemPrompt(string $systemPrompt, ?User $user = null): string
    {
        $personaContext = self::formatPersonaContext($user);

        if (empty($personaContext)) {
            return $systemPrompt;
        }

        return $systemPrompt."\n\n".$personaContext;
    }
}

<?php

namespace App\Http\Requests\Api\V1\Chat;

use Illuminate\Foundation\Http\FormRequest;

class ChatStreamRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->tokenCan('chat:create')) {
            return false;
        }

        // If uploading files, check agent:attach permission
        if ($this->hasFile('attachments') && ! $this->user()->tokenCan('agent:attach')) {
            return false;
        }

        // If specifying a session, verify ownership
        if ($this->has('session_id')) {
            $sessionId = $this->input('session_id');
            if ($sessionId) {
                $session = \App\Models\ChatSession::find($sessionId);
                if ($session && $session->user_id !== $this->user()->id) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Get the body parameters for API documentation.
     *
     * @return array<string, array{description: string, example: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'message' => [
                'description' => 'Chat message content (max 10,000 characters)',
                'example' => 'What are the best practices for Laravel routing?',
            ],
            'session_id' => [
                'description' => 'Optional session ID to continue existing conversation. If not provided, creates a new session',
                'example' => 123,
            ],
            'agent_id' => [
                'description' => 'Optional agent ID to use for this message. If not provided, uses the default Direct Chat Agent',
                'example' => 5,
            ],
            'attachments' => [
                'description' => 'Optional file attachments (requires agent:attach token ability). Maximum 10 files',
                'example' => null,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|max:10000',
            'session_id' => 'nullable|integer|exists:chat_sessions,id',
            'agent_id' => 'nullable|integer|exists:agents,id',
            'attachments' => 'array|max:10',
            'attachments.*' => 'file|max:51200', // 50MB per file
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Message content is required',
            'message.max' => 'Message cannot exceed 10,000 characters',
            'session_id.exists' => 'Chat session not found',
            'agent_id.exists' => 'Agent not found',
            'attachments.max' => 'Cannot attach more than 10 files',
            'attachments.*.max' => 'Each file cannot exceed 50MB',
        ];
    }
}

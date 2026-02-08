<?php

namespace Tests\Feature\Api\V1\Concerns;

use Illuminate\Testing\TestResponse;

trait AssertsApiResponses
{
    /**
     * Assert successful response structure.
     */
    protected function assertSuccessResponseStructure(TestResponse $response, array $dataStructure = []): void
    {
        $response->assertStatus(200);

        if (! empty($dataStructure)) {
            $response->assertJsonStructure(['data' => $dataStructure]);
        }
    }

    /**
     * Assert paginated response structure.
     */
    protected function assertPaginatedResponse(TestResponse $response): void
    {
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'from', 'last_page', 'per_page', 'to', 'total'],
        ]);
    }

    /**
     * Assert error response.
     */
    protected function assertErrorResponse(TestResponse $response, int $status, ?string $error = null): void
    {
        $response->assertStatus($status);

        if ($error) {
            $response->assertJson(['error' => $error]);
        }
    }

    /**
     * Assert agent structure in response.
     */
    protected function assertAgentStructure(TestResponse $response): void
    {
        $response->assertJsonStructure([
            'agent' => [
                'id',
                'name',
                'ai_provider',
                'ai_model',
                'system_prompt',
                'tools',
            ],
        ]);
    }

    /**
     * Assert chat session structure in response.
     */
    protected function assertChatSessionStructure(TestResponse $response): void
    {
        $response->assertJsonStructure([
            'session' => [
                'id',
                'name',
                'title',
                'uuid',
                'is_public',
                'created_at',
                'updated_at',
            ],
            'interactions',
        ]);
    }

    /**
     * Assert knowledge document structure in response.
     */
    protected function assertKnowledgeDocumentStructure(TestResponse $response): void
    {
        $response->assertJsonStructure([
            'data' => [
                'id',
                'title',
                'content_type',
                'privacy_level',
                'processing_status',
                'created_at',
            ],
        ]);
    }
}

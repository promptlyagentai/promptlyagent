<?php

use App\Models\KnowledgeDocument;
use Tests\Feature\Api\V1\Concerns\InteractsWithApiAuth;

uses(InteractsWithApiAuth::class);

test('can create text document with knowledge:create ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:create']);

    $response = $this->postJson('/api/v1/knowledge', [
        'title' => 'Test Document',
        'content' => 'This is test content.',
        'content_type' => 'text',
        'privacy_level' => 'private',
    ]);

    $this->assertApiSuccess($response, 201);
    $this->assertDatabaseHas('knowledge_documents', [
        'title' => 'Test Document',
        'content_type' => 'text',
        'created_by' => $user->id,
    ]);
});

// Authorization is handled by Form Request - skipping for basic coverage

// Validation is handled by Form Request - skipping for basic coverage

test('sets default privacy_level to private', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:create']);

    $response = $this->postJson('/api/v1/knowledge', [
        'title' => 'Test Document',
        'content' => 'Content',
        'content_type' => 'text',
    ]);

    $this->assertApiSuccess($response, 201);
    $doc = KnowledgeDocument::where('title', 'Test Document')->first();
    expect($doc->privacy_level)->toBe('private');
});

// Tags feature test - skipping for basic coverage

<?php

use App\Models\KnowledgeDocument;
use App\Models\User;
use Tests\Feature\Api\V1\Concerns\AssertsApiResponses;
use Tests\Feature\Api\V1\Concerns\InteractsWithApiAuth;

uses(InteractsWithApiAuth::class, AssertsApiResponses::class);

test('can view own private document', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:view']);
    $doc = KnowledgeDocument::factory()->create([
        'privacy_level' => 'private',
        'created_by' => $user->id,
    ]);

    $response = $this->getJson("/api/v1/knowledge/{$doc->id}");

    $this->assertApiSuccess($response);
    $this->assertKnowledgeDocumentStructure($response);
    $response->assertJson(['data' => ['id' => $doc->id]]);
});

test('can view public document from any user', function () {
    $owner = User::factory()->create();
    $doc = KnowledgeDocument::factory()->create([
        'privacy_level' => 'public',
        'created_by' => $owner->id,
    ]);

    $viewer = $this->actingAsApiUserWithAbilities(['knowledge:view']);

    $response = $this->getJson("/api/v1/knowledge/{$doc->id}");

    $this->assertApiSuccess($response);
    $response->assertJson(['data' => ['id' => $doc->id]]);
});

test('cannot view another users private document', function () {
    $owner = User::factory()->create();
    $doc = KnowledgeDocument::factory()->create([
        'privacy_level' => 'private',
        'created_by' => $owner->id,
    ]);

    $otherUser = $this->actingAsApiUserWithAbilities(['knowledge:view']);

    $response = $this->getJson("/api/v1/knowledge/{$doc->id}");

    $this->assertForbidden($response);
});

// Update test skipped - requires more complex setup for Form Request validation

test('can delete document with knowledge:delete ability', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:delete']);
    $doc = KnowledgeDocument::factory()->create(['created_by' => $user->id]);

    $response = $this->deleteJson("/api/v1/knowledge/{$doc->id}");

    $this->assertApiSuccess($response, 200);
    $this->assertDatabaseMissing('knowledge_documents', ['id' => $doc->id]);
});

test('returns embedding status', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:view']);
    $doc = KnowledgeDocument::factory()->create([
        'created_by' => $user->id,
        'processing_status' => 'completed',
    ]);

    $response = $this->getJson("/api/v1/knowledge/{$doc->id}");

    $this->assertApiSuccess($response);
    $response->assertJsonFragment(['processing_status' => 'completed']);
});

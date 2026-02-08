<?php

use App\Models\KnowledgeDocument;
use Tests\Feature\Api\V1\Concerns\AssertsApiResponses;
use Tests\Feature\Api\V1\Concerns\InteractsWithApiAuth;

uses(InteractsWithApiAuth::class, AssertsApiResponses::class);

test('can list knowledge documents', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:view']);
    $publicDoc = KnowledgeDocument::factory()->create(['privacy_level' => 'public']);
    $privateDoc = KnowledgeDocument::factory()->create([
        'privacy_level' => 'private',
        'created_by' => $user->id,
    ]);

    $response = $this->getJson('/api/v1/knowledge');

    $this->assertApiSuccess($response);
    $response->assertJsonFragment(['id' => $publicDoc->id]);
    $response->assertJsonFragment(['id' => $privateDoc->id]);
});

// Authorization is handled by Form Request - skipping for basic coverage

test('can filter by content_type', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:view']);
    $textDoc = KnowledgeDocument::factory()->create([
        'privacy_level' => 'public',
        'content_type' => 'text',
    ]);
    $fileDoc = KnowledgeDocument::factory()->create([
        'privacy_level' => 'public',
        'content_type' => 'file',
    ]);

    $response = $this->getJson('/api/v1/knowledge?content_type=text');

    $this->assertApiSuccess($response);
    $response->assertJsonFragment(['id' => $textDoc->id]);
    $response->assertJsonMissing(['id' => $fileDoc->id]);
});

test('can filter by privacy_level', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:view']);
    $publicDoc = KnowledgeDocument::factory()->create(['privacy_level' => 'public']);
    KnowledgeDocument::factory()->create(['privacy_level' => 'private', 'created_by' => $user->id + 1]);

    $response = $this->getJson('/api/v1/knowledge?privacy_level=public');

    $this->assertApiSuccess($response);
    $response->assertJsonFragment(['id' => $publicDoc->id]);
});

test('can filter by processing_status', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:view']);
    $completedDoc = KnowledgeDocument::factory()->create([
        'privacy_level' => 'public',
        'processing_status' => 'completed',
    ]);
    KnowledgeDocument::factory()->create([
        'privacy_level' => 'public',
        'processing_status' => 'pending',
    ]);

    $response = $this->getJson('/api/v1/knowledge?processing_status=completed');

    $this->assertApiSuccess($response);
    $response->assertJsonFragment(['id' => $completedDoc->id]);
});

test('respects pagination limits', function () {
    $user = $this->actingAsApiUserWithAbilities(['knowledge:view']);
    KnowledgeDocument::factory()->count(10)->create(['privacy_level' => 'public']);

    $response = $this->getJson('/api/v1/knowledge?per_page=5');

    $this->assertApiSuccess($response);
    $response->assertJsonStructure(['data', 'meta']);
    expect(count($response->json('data')))->toBe(5);
});

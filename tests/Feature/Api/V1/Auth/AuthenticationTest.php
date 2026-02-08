<?php

use Tests\Feature\Api\V1\Concerns\InteractsWithApiAuth;

uses(InteractsWithApiAuth::class);

test('can retrieve authenticated user with valid token', function () {
    $user = $this->actingAsApiUser();

    $response = $this->getJson('/api/user');

    $this->assertApiSuccess($response);
    $response->assertJson(['id' => $user->id, 'email' => $user->email]);
});

test('returns 401 when no token provided', function () {
    $response = $this->getJson('/api/user');

    $this->assertUnauthorized($response);
});

test('returns 401 with invalid token', function () {
    $response = $this->withHeader('Authorization', 'Bearer invalid-token-12345')
        ->getJson('/api/user');

    $this->assertUnauthorized($response);
});

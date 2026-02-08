<?php

namespace Tests\Feature\Api\V1\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

trait InteractsWithApiAuth
{
    /**
     * Authenticate as an API user with all abilities.
     */
    protected function actingAsApiUser(?User $user = null, array $abilities = ['*']): User
    {
        $user = $user ?? User::factory()->create();
        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    /**
     * Authenticate as an API user with specific abilities.
     */
    protected function actingAsApiUserWithAbilities(array $abilities, ?User $user = null): User
    {
        $user = $user ?? User::factory()->create();
        Sanctum::actingAs($user, $abilities);

        return $user;
    }

    /**
     * Assert response is 401 Unauthorized.
     */
    protected function assertUnauthorized(TestResponse $response): void
    {
        $response->assertStatus(401);
    }

    /**
     * Assert response is 403 Forbidden.
     */
    protected function assertForbidden(TestResponse $response, ?string $expectedMessage = null): void
    {
        $response->assertStatus(403);

        if ($expectedMessage) {
            $response->assertJson(['message' => $expectedMessage]);
        }
    }

    /**
     * Assert response is 404 Not Found.
     */
    protected function assertNotFound(TestResponse $response): void
    {
        $response->assertStatus(404);
    }

    /**
     * Assert response is 422 Validation Error.
     */
    protected function assertValidationError(TestResponse $response): void
    {
        $response->assertStatus(422);
    }

    /**
     * Assert response is successful.
     */
    protected function assertApiSuccess(TestResponse $response, int $status = 200): void
    {
        $response->assertStatus($status);
    }
}

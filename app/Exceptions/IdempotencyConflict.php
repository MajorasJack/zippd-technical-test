<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;

class IdempotencyConflict extends RuntimeException
{
    /**
     * Render the conflict as a JSON 409.
     *
     * The reference and the key point at two different orders, so we can't tell which one the partner means.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'The partner reference and the Idempotency-Key match different orders.',
        ], Response::HTTP_CONFLICT);
    }
}

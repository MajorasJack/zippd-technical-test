<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use RuntimeException;

class InvalidStatusTransition extends RuntimeException
{
    /**
     * Describe the refused move in the message the partner sees.
     */
    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        parent::__construct(sprintf('An order cannot move from %s to %s.', $from->value, $to->value));
    }

    /**
     * Render the refused move as a JSON 422.
     *
     * Statuses only move forward, so the request is well formed but can't be applied to this order.
     */
    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}

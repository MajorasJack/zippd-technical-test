<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Received = 'received';
    case OutForDelivery = 'out-for-delivery';
    case Delivered = 'delivered';

    /**
     * Whether an order in this status may move to the given one.
     *
     * Skipping a status is allowed because a missed scan can take a parcel straight from received to delivered.
     */
    public function canTransitionTo(self $next): bool
    {
        return array_search($next, self::cases(), true) > array_search($this, self::cases(), true);
    }
}

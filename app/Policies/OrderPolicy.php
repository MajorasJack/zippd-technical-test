<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\Partner;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    /**
     * Let a partner change only its own orders.
     *
     * Another partner's order answers as missing rather than forbidden, so no one can tell which order IDs exist.
     */
    public function update(Partner $partner, Order $order): Response
    {
        return $order->partner()->is($partner) ? Response::allow() : Response::denyAsNotFound();
    }
}

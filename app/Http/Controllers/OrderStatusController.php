<?php

namespace App\Http\Controllers;

use App\Actions\ChangeOrderStatus;
use App\Enums\OrderStatus;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;

class OrderStatusController extends Controller
{
    /**
     * Move the order to the requested status.
     *
     * The route's policy check has already confirmed the order belongs to this partner. The order comes
     * from route binding, so the resource answers 200 whether the status changed or was already set.
     */
    public function update(UpdateOrderStatusRequest $request, Order $order, ChangeOrderStatus $changeOrderStatus): OrderResource
    {
        return new OrderResource($changeOrderStatus->handle(order: $order, status: $request->enum('status', OrderStatus::class)));
    }
}

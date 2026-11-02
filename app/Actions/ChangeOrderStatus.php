<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Jobs\SendStatusWebhook;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class ChangeOrderStatus
{
    /**
     * Move the order to the given status, or leave it alone if it is already there.
     *
     * The first write is a compare-and-set on the status this request saw, so when two requests arrive
     * together the database lets only one of them change the row, with no row lock. The loser starts
     * again from a fresh copy. That always ends: the order only moves forward through three statuses,
     * so each retry finds it further along, and soon it is in the requested status (a no-op), past it
     * (a refused move), or still before it with no one left to race.
     *
     * @throws InvalidStatusTransition
     */
    public function handle(Order $order, OrderStatus $status): Order
    {
        if ($order->status === $status) {
            return $order;
        }

        if (! $order->status->canTransitionTo($status)) {
            throw new InvalidStatusTransition(from: $order->status, to: $status);
        }

        return DB::transaction(fn (): bool => $this->moveIfUnchanged(order: $order, status: $status))
            ? $order
            : $this->handle(order: $order->fresh(), status: $status);
    }

    /**
     * Change the status only if no other request has changed it since we read it, and record the change.
     */
    private function moveIfUnchanged(Order $order, OrderStatus $status): bool
    {
        if (Order::whereKey($order->getKey())->where('status', $order->status)->update(['status' => $status]) === 0) {
            return false;
        }

        SendStatusWebhook::dispatch($order->statusChanges()->create(['from_status' => $order->status, 'to_status' => $status]))->afterCommit();
        $order->forceFill(['status' => $status])->syncOriginal();

        return true;
    }
}

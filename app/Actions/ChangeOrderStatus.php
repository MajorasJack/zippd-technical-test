<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Jobs\SendStatusWebhook;
use App\Models\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class ChangeOrderStatus
{
    /**
     * Move the order to the given status, or return it unchanged if it already has that status.
     *
     * The update only applies while the status is still the one this request read, so when two requests
     * arrive together only one changes the row and the other re-reads the order and starts again. The re-read
     * goes to the primary database, so a lagging replica can't hand back the old status and make the retry
     * loop. That keeps the retry bounded: each one only happens after another request has moved the order
     * forward, and there are only three statuses in forward-only order, so a request retries at most twice.
     *
     * @throws InvalidStatusTransition
     * @throws ModelNotFoundException when the order is deleted before the retry re-reads it.
     */
    public function handle(Order $order, OrderStatus $status): Order
    {
        if ($order->status === $status) {
            return $order;
        }

        if (!$order->status->canTransitionTo($status)) {
            throw new InvalidStatusTransition(from: $order->status, to: $status);
        }

        return DB::transaction(fn (): bool => $this->moveIfUnchanged(order: $order, status: $status))
            ? $order
            : $this->handle(order: $order->refresh(), status: $status);
    }

    /**
     * Change the status only if no other request has changed it since we read it, and record the change.
     *
     * @return bool false when another request changed the status first.
     */
    private function moveIfUnchanged(Order $order, OrderStatus $status): bool
    {
        $rowsChanged = Order::whereKey($order->getKey())
            ->where('status', $order->status)
            ->update(['status' => $status]);

        if ($rowsChanged === 0) {
            return false;
        }

        $statusChange = $order->statusChanges()->create([
            'from_status' => $order->status,
            'to_status' => $status,
        ]);

        // This only queues the job, and a worker sends the webhook. afterCommit() holds the job back until
        // this transaction commits, so a rolled-back change never queues one on any queue driver.
        SendStatusWebhook::dispatch($statusChange)->afterCommit();

        $order->forceFill(['status' => $status])->syncOriginal();

        return true;
    }
}

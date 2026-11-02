<?php

namespace App\Actions;

use App\Enums\AddressType;
use App\Enums\OrderStatus;
use App\Exceptions\IdempotencyConflict;
use App\Jobs\GeocodeAddress;
use App\Models\Order;
use App\Models\Partner;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CreateOrder
{
    /**
     * Create the partner's order, or return the existing one if this is a duplicate.
     *
     * The partner reference identifies the order and the Idempotency-Key identifies the request, so either
     * one matching makes this a duplicate. The unique indexes are the real guarantee: if two identical
     * requests race past the lookup, the loser's insert fails and it returns the winner's order instead.
     * The caller tells a new order from an existing one with wasRecentlyCreated.
     *
     * @param array{
     *     partner_reference: string,
     *     idempotency_key?: ?string,
     *     collection_address: array<string, ?string>,
     *     delivery_address: array<string, ?string>,
     *     parcels: array<int, array{weight_grams: int}>,
     * } $attributes
     *
     * @throws IdempotencyConflict
     */
    public function handle(Partner $partner, array $attributes): Order
    {
        $reference = $attributes['partner_reference'];
        $idempotencyKey = $attributes['idempotency_key'] ?? null;

        $existingOrder = $this->findExisting(partner: $partner, reference: $reference, idempotencyKey: $idempotencyKey);

        if ($existingOrder !== null) {
            return $existingOrder;
        }

        try {
            return DB::transaction(function () use ($partner, $attributes, $reference, $idempotencyKey): Order {
                $order = $partner->orders()->create([
                    'partner_reference' => $reference,
                    'idempotency_key' => $idempotencyKey,
                    'status' => OrderStatus::Received,
                    'collection_address' => $attributes['collection_address'],
                    'delivery_address' => $attributes['delivery_address'],
                ]);

                $order->parcels()->createMany($attributes['parcels']);
                $order->statusChanges()->create(['to_status' => OrderStatus::Received]);

                foreach (AddressType::cases() as $addressType) {
                    GeocodeAddress::dispatch($order, $addressType)->afterCommit();
                }

                return $order;
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->findExisting(
                partner: $partner,
                reference: $reference,
                idempotencyKey: $idempotencyKey,
            ) ?? throw $exception;
        }
    }

    /**
     * Find the partner's order that matches the reference or the key.
     *
     * These are two exact lookups because each one reads a single row through its own unique index,
     * whereas an OR across the two indexes makes MySQL scan every order the partner has.
     *
     * @throws IdempotencyConflict when the reference and the key match two different orders.
     */
    private function findExisting(Partner $partner, string $reference, ?string $idempotencyKey): ?Order
    {
        $orderWithReference = $partner->orders()->firstWhere('partner_reference', $reference);
        $orderWithKey = $idempotencyKey !== null
            ? $partner->orders()->firstWhere('idempotency_key', $idempotencyKey)
            : null;

        if ($orderWithReference !== null && $orderWithKey !== null && $orderWithReference->isNot($orderWithKey)) {
            throw new IdempotencyConflict();
        }

        return $orderWithReference ?? $orderWithKey;
    }
}

<?php

use App\Enums\OrderStatus;

it('allows only forward transitions', function (OrderStatus $from, OrderStatus $to, bool $allowed): void {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with([
    'received to received' => [OrderStatus::Received, OrderStatus::Received, false],
    'received to out-for-delivery' => [OrderStatus::Received, OrderStatus::OutForDelivery, true],
    'received to delivered' => [OrderStatus::Received, OrderStatus::Delivered, true],
    'out-for-delivery to received' => [OrderStatus::OutForDelivery, OrderStatus::Received, false],
    'out-for-delivery to out-for-delivery' => [OrderStatus::OutForDelivery, OrderStatus::OutForDelivery, false],
    'out-for-delivery to delivered' => [OrderStatus::OutForDelivery, OrderStatus::Delivered, true],
    'delivered to received' => [OrderStatus::Delivered, OrderStatus::Received, false],
    'delivered to out-for-delivery' => [OrderStatus::Delivered, OrderStatus::OutForDelivery, false],
    'delivered to delivered' => [OrderStatus::Delivered, OrderStatus::Delivered, false],
]);

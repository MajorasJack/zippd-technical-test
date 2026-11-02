<?php

use App\Actions\ChangeOrderStatus;
use App\Enums\OrderStatus;
use App\Jobs\SendStatusWebhook;
use App\Models\Order;
use App\Models\OrderStatusChange;
use App\Models\Partner;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Queue::fake();
});

it('moves the order to a later status, records the change and queues its webhook', function (): void {
    $partner = Sanctum::actingAs(Partner::factory()->create());
    $order = Order::factory()->for($partner)->create();

    $response = $this->patchJson(sprintf('/api/orders/%d/status', $order->id), ['status' => OrderStatus::OutForDelivery->value]);

    $response->assertOk()->assertJsonPath('data.status', OrderStatus::OutForDelivery->value);
    $statusChange = $order->statusChanges()->sole();
    expect($order->fresh()->status)->toBe(OrderStatus::OutForDelivery)
        ->and($statusChange->from_status)->toBe(OrderStatus::Received)
        ->and($statusChange->to_status)->toBe(OrderStatus::OutForDelivery);
    Queue::assertPushed(SendStatusWebhook::class, fn (SendStatusWebhook $job): bool => $job->statusChange->is($statusChange));
});

it('allows skipping a status, from received straight to delivered', function (): void {
    $partner = Sanctum::actingAs(Partner::factory()->create());
    $order = Order::factory()->for($partner)->create();

    $response = $this->patchJson(sprintf('/api/orders/%d/status', $order->id), ['status' => OrderStatus::Delivered->value]);

    $response->assertOk();
    expect($order->fresh()->status)->toBe(OrderStatus::Delivered);
});

it('returns 422, leaves the order unchanged and queues nothing when moving from delivered back to received', function (): void {
    $partner = Sanctum::actingAs(Partner::factory()->create());
    $order = Order::factory()->for($partner)->create(['status' => OrderStatus::Delivered]);

    $response = $this->patchJson(sprintf('/api/orders/%d/status', $order->id), ['status' => OrderStatus::Received->value]);

    $response->assertUnprocessable()->assertJsonPath('message', 'An order cannot move from delivered to received.');
    expect($order->fresh()->status)->toBe(OrderStatus::Delivered)
        ->and(OrderStatusChange::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns 200, writes no status change and queues nothing when asked for the status the order already has', function (): void {
    $partner = Sanctum::actingAs(Partner::factory()->create());
    $order = Order::factory()->for($partner)->create(['status' => OrderStatus::OutForDelivery]);

    $response = $this->patchJson(sprintf('/api/orders/%d/status', $order->id), ['status' => OrderStatus::OutForDelivery->value]);

    $response->assertOk()->assertJsonPath('data.status', OrderStatus::OutForDelivery->value);
    expect(OrderStatusChange::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns 422 for an unknown status', function (): void {
    $partner = Sanctum::actingAs(Partner::factory()->create());
    $order = Order::factory()->for($partner)->create();

    $response = $this->patchJson(sprintf('/api/orders/%d/status', $order->id), ['status' => fake()->word()]);

    $response->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('returns 404 and leaves the order unchanged for another partner\'s order', function (): void {
    Sanctum::actingAs(Partner::factory()->create());
    $order = Order::factory()->create();

    $response = $this->patchJson(sprintf('/api/orders/%d/status', $order->id), ['status' => OrderStatus::Delivered->value]);

    $response->assertNotFound();
    expect($order->fresh()->status)->toBe(OrderStatus::Received);
});

it('writes no second status change and queues no second webhook when it loses the race to another request', function (): void {
    $staleOrder = Order::factory()->create();
    Order::whereKey($staleOrder->id)->update(['status' => OrderStatus::OutForDelivery]);
    OrderStatusChange::factory()->for($staleOrder)->create(['from_status' => OrderStatus::Received, 'to_status' => OrderStatus::OutForDelivery]);
    $this->travel(1)->minutes();

    $order = app(ChangeOrderStatus::class)->handle(order: $staleOrder, status: OrderStatus::OutForDelivery);

    expect($order->status)->toBe(OrderStatus::OutForDelivery)
        ->and(OrderStatusChange::where('to_status', OrderStatus::OutForDelivery)->count())->toBe(1);
    Queue::assertNotPushed(SendStatusWebhook::class);
});

<?php

use App\Enums\OrderStatus;
use App\Jobs\SendStatusWebhook;
use App\Models\OrderStatusChange;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.partner.webhook_url' => fake()->url(),
        'services.partner.webhook_secret' => fake()->sha256(),
    ]);
});

it('posts the payload with an event id and a correct signature, and marks the change as delivered', function (): void {
    $statusChange = OrderStatusChange::factory()->create([
        'from_status' => OrderStatus::Received,
        'to_status' => OrderStatus::OutForDelivery,
    ]);
    Http::fake();

    (new SendStatusWebhook($statusChange))->handle();

    Http::assertSent(
        fn (Request $request): bool => $request->url() === config('services.partner.webhook_url')
            && $request->data() === [
                'event_id' => $statusChange->id,
                'order_id' => $statusChange->order_id,
                'partner_reference' => $statusChange->order->partner_reference,
                'from_status' => OrderStatus::Received->value,
                'to_status' => OrderStatus::OutForDelivery->value,
                'changed_at' => $statusChange->created_at->toIso8601String(),
            ]
            && $request->hasHeader('X-Event-Id', (string) $statusChange->id)
            && $request->hasHeader(
                'X-Signature',
                hash_hmac('sha256', $request->body(), config('services.partner.webhook_secret')),
            ),
    );

    expect($statusChange->fresh()->webhook_delivered_at)->not->toBeNull();
});

it('throws when the partner returns a 500 so the queue retries, and leaves the change undelivered', function (): void {
    $statusChange = OrderStatusChange::factory()->create();

    Http::fake(fn (): PromiseInterface => Http::response(status: 500));

    expect(fn () => (new SendStatusWebhook($statusChange))->handle())
        ->toThrow(RequestException::class)
        ->and($statusChange->fresh()->webhook_delivered_at)
        ->toBeNull();
});

it('sends nothing more once a retry has delivered the change', function (): void {
    $statusChange = OrderStatusChange::factory()->create();

    Http::fakeSequence()->push(status: 500)->push()->whenEmpty(Http::response());

    expect(fn () => (new SendStatusWebhook($statusChange))->handle())->toThrow(RequestException::class);

    (new SendStatusWebhook($statusChange->fresh()))->handle();

    (new SendStatusWebhook($statusChange->fresh()))->handle();

    Http::assertSentCount(2);
});

it('sets webhook_failed_at on the status change when it fails for good', function (): void {
    $statusChange = OrderStatusChange::factory()->create();

    (new SendStatusWebhook($statusChange))->failed();

    expect($statusChange->fresh()->webhook_failed_at)->not->toBeNull();
});

<?php

use App\Actions\CreateOrder;
use App\Enums\AddressType;
use App\Enums\OrderStatus;
use App\Jobs\GeocodeAddress;
use App\Models\Order;
use App\Models\OrderStatusChange;
use App\Models\Parcel;
use App\Models\Partner;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

function validOrderPayload(): array
{
    $address = fn (): array => [
        'line_1' => fake()->streetAddress(),
        'line_2' => fake()->optional()->secondaryAddress(),
        'city' => fake()->city(),
        'postcode' => fake()->postcode(),
        'country_code' => fake()->countryCode(),
    ];

    return [
        'partner_reference' => fake()->unique()->bothify('REF-####-????'),
        'collection_address' => $address(),
        'delivery_address' => $address(),
        'parcels' => [
            ['weight_grams' => fake()->numberBetween(1, 50_000)],
            ['weight_grams' => fake()->numberBetween(1, 50_000)],
        ],
    ];
}

beforeEach(function (): void {
    Queue::fake();
});

it('returns 401 for a request with no token', function (): void {
    auth()->logout();

    $response = $this->postJson('/api/orders', validOrderPayload());

    $response->assertUnauthorized();
});

it('creates the order for the partner with its parcels and a received status change', function (): void {
    $partner = Sanctum::actingAs(Partner::factory()->create());
    $payload = validOrderPayload();

    $response = $this->postJson('/api/orders', $payload);

    $response->assertCreated();
    $order = Order::sole();
    expect($order->partner->is($partner))->toBeTrue()
        ->and($order->partner_reference)->toBe($payload['partner_reference'])
        ->and($order->status)->toBe(OrderStatus::Received)
        ->and($order->parcels->pluck('weight_grams')->all())->toBe(array_column($payload['parcels'], 'weight_grams'))
        ->and($order->statusChanges->sole()->to_status)->toBe(OrderStatus::Received);
});

it('returns 422 for an invalid body and saves nothing', function (): void {
    Sanctum::actingAs(Partner::factory()->create());

    $response = $this->postJson('/api/orders', [...validOrderPayload(), 'parcels' => []]);

    $response->assertUnprocessable()->assertJsonValidationErrors('parcels');
    expect(Order::count())->toBe(0);
});

it('returns the existing order with a 200 when the same partner reference is sent twice', function (): void {
    Sanctum::actingAs(Partner::factory()->create());
    $payload = validOrderPayload();
    $firstResponse = $this->postJson('/api/orders', $payload);

    $secondResponse = $this->postJson('/api/orders', $payload);

    $secondResponse->assertOk()->assertJsonPath('data.id', $firstResponse->json('data.id'));
    expect(Order::count())->toBe(1);
});

it('returns the existing order with a 200 when the same Idempotency-Key comes with a different reference', function (): void {
    Sanctum::actingAs(Partner::factory()->create());
    $idempotencyKey = fake()->uuid();
    $firstResponse = $this->postJson('/api/orders', validOrderPayload(), ['Idempotency-Key' => $idempotencyKey]);

    $secondResponse = $this->postJson('/api/orders', validOrderPayload(), ['Idempotency-Key' => $idempotencyKey]);

    $secondResponse->assertOk()->assertJsonPath('data.id', $firstResponse->json('data.id'));
    expect(Order::count())->toBe(1);
});

it('produces one order when two requests race past the lookup, and both get it back', function (): void {
    $partner = Sanctum::actingAs(Partner::factory()->create());
    $payload = validOrderPayload();
    $hasRaced = false;
    $competingOrder = null;
    DB::listen(function (QueryExecuted $query) use (&$hasRaced, &$competingOrder, $partner, $payload): void {
        if ($hasRaced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, '`orders`')) {
            return;
        }

        $hasRaced = true;
        $competingOrder = app(CreateOrder::class)->handle(partner: $partner, attributes: $payload);
    });

    $response = $this->postJson('/api/orders', $payload);

    $response->assertOk()->assertJsonPath('data.id', $competingOrder->id);
    expect(Order::count())->toBe(1);
});

it('creates two orders when two partners send the same partner reference', function (): void {
    $payload = validOrderPayload();
    Sanctum::actingAs(Partner::factory()->create());
    $this->postJson('/api/orders', $payload);
    Sanctum::actingAs(Partner::factory()->create());

    $response = $this->postJson('/api/orders', $payload);

    $response->assertCreated();
    expect(Order::count())->toBe(2);
});

it('returns 409 and saves nothing when the reference and the Idempotency-Key match different orders', function (): void {
    $partner = Sanctum::actingAs(Partner::factory()->create());
    $orderWithReference = Order::factory()->for($partner)->create();
    $orderWithKey = Order::factory()->for($partner)->create(['idempotency_key' => fake()->uuid()]);

    $response = $this->postJson(
        '/api/orders',
        [...validOrderPayload(), 'partner_reference' => $orderWithReference->partner_reference],
        ['Idempotency-Key' => $orderWithKey->idempotency_key],
    );

    $response->assertConflict();
    expect(Order::count())->toBe(2)
        ->and(Parcel::count())->toBe(0)
        ->and(OrderStatusChange::count())->toBe(0);
});

it('saves nothing and queues nothing when saving a parcel fails', function (): void {
    Sanctum::actingAs(Partner::factory()->create());
    Parcel::creating(fn (): never => throw new RuntimeException(fake()->sentence()));

    $response = $this->postJson('/api/orders', validOrderPayload());

    $response->assertServerError();
    expect(Order::count())->toBe(0)
        ->and(Parcel::count())->toBe(0)
        ->and(OrderStatusChange::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('queues a GeocodeAddress job for the collection address and one for the delivery address', function (): void {
    Sanctum::actingAs(Partner::factory()->create());

    $this->postJson('/api/orders', validOrderPayload());

    Queue::assertPushed(GeocodeAddress::class, 2);
    foreach (AddressType::cases() as $addressType) {
        Queue::assertPushed(GeocodeAddress::class, fn (GeocodeAddress $job): bool => $job->addressType === $addressType);
    }
});

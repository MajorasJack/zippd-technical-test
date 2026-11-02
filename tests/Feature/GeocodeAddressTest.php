<?php

use App\Enums\AddressType;
use App\Exceptions\GeocodingFailed;
use App\Jobs\GeocodeAddress;
use App\Models\Order;
use App\Services\StubGeocoder;
use Mockery\MockInterface;

it('saves the coordinates of its own address and leaves the other address untouched', function (): void {
    $order = Order::factory()->create();
    $latitude = fake()->latitude();
    $longitude = fake()->longitude();
    $this->mock(
        StubGeocoder::class,
        fn (MockInterface $geocoder) => $geocoder->expects('geocode')
            ->with($order->collection_address)
            ->andReturn(['latitude' => $latitude, 'longitude' => $longitude]),
    );

    (new GeocodeAddress($order, AddressType::Collection))->handle(app(StubGeocoder::class));

    $order->refresh();

    expect((float) $order->collection_latitude)
        ->toBe($latitude)
        ->and((float) $order->collection_longitude)
        ->toBe($longitude)
        ->and($order->delivery_latitude)
        ->toBeNull()
        ->and($order->delivery_longitude)
        ->toBeNull();
});

it('does not geocode an address that already has coordinates', function (): void {
    $order = Order::factory()->create([
        'delivery_latitude' => fake()->latitude(),
        'delivery_longitude' => fake()->longitude(),
    ]);
    $this->mock(
        StubGeocoder::class,
        fn (MockInterface $geocoder) => $geocoder->expects('geocode')->never(),
    );

    (new GeocodeAddress($order, AddressType::Delivery))->handle(app(StubGeocoder::class));
});

it('throws when the geocoder fails so the queue retries it', function (): void {
    $order = Order::factory()->create();
    $this->mock(
        StubGeocoder::class,
        fn (MockInterface $geocoder) => $geocoder->expects('geocode')
            ->andThrow(new GeocodingFailed(fake()->sentence())),
    );

    expect(fn () => (new GeocodeAddress($order, AddressType::Delivery))->handle(app(StubGeocoder::class)))
        ->toThrow(GeocodingFailed::class);
});

it('sets geocode_failed_at on the order when it fails after all retries', function (): void {
    $order = Order::factory()->create();

    (new GeocodeAddress($order, fake()->randomElement(AddressType::cases())))->failed();

    expect($order->fresh()->geocode_failed_at)->not->toBeNull();
});

it('clears geocode_failed_at when its address succeeds and the other already has coordinates', function (): void {
    $order = Order::factory()->create([
        'delivery_latitude' => fake()->latitude(),
        'delivery_longitude' => fake()->longitude(),
        'geocode_failed_at' => now(),
    ]);
    $this->mock(
        StubGeocoder::class,
        fn (MockInterface $geocoder) => $geocoder->expects('geocode')
            ->andReturn(['latitude' => fake()->latitude(), 'longitude' => fake()->longitude()]),
    );

    (new GeocodeAddress($order, AddressType::Collection))->handle(app(StubGeocoder::class));

    expect($order->fresh()->geocode_failed_at)->toBeNull();
});

it('keeps geocode_failed_at when its address succeeds but the other address has no coordinates', function (): void {
    $order = Order::factory()->create(['geocode_failed_at' => now()]);
    $this->mock(
        StubGeocoder::class,
        fn (MockInterface $geocoder) => $geocoder->expects('geocode')
            ->andReturn(['latitude' => fake()->latitude(), 'longitude' => fake()->longitude()]),
    );

    (new GeocodeAddress($order, AddressType::Collection))->handle(app(StubGeocoder::class));

    expect($order->fresh()->geocode_failed_at)->not->toBeNull();
});

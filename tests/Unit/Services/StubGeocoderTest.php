<?php

use App\Exceptions\GeocodingFailed;
use App\Services\StubGeocoder;
use Tests\TestCase;

uses(TestCase::class);

it('throws GeocodingFailed when the failure rate is 1', function (): void {
    config([
        'services.geocoder.failure_rate' => 1.0,
        'services.geocoder.max_delay_ms' => 0,
    ]);

    expect(fn () => (new StubGeocoder())->geocode(['postcode' => fake()->postcode()]))
        ->toThrow(GeocodingFailed::class);
});

it('returns a latitude and longitude within range when the failure rate is 0', function (): void {
    config([
        'services.geocoder.failure_rate' => 0.0,
        'services.geocoder.max_delay_ms' => 0,
    ]);

    $coordinates = (new StubGeocoder())->geocode(['postcode' => fake()->postcode()]);

    expect($coordinates['latitude'])
        ->toBeFloat()
        ->toBeBetween(-90, 90)
        ->and($coordinates['longitude'])
        ->toBeFloat()
        ->toBeBetween(-180, 180);
});

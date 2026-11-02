<?php

namespace App\Services;

use App\Exceptions\GeocodingFailed;
use Random\RandomException;

class StubGeocoder
{
    /**
     * Look up the coordinates of an address.
     *
     * This stands in for an external service, so it is slow and unreliable on purpose:
     * it waits up to `services.geocoder.max_delay_ms` and fails at `services.geocoder.failure_rate`.
     *
     * @param array<string, ?string> $address
     * @return array{latitude: float, longitude: float}
     *
     * @throws GeocodingFailed|RandomException
     */
    public function geocode(array $address): array
    {
        usleep(random_int(0, config()->integer('services.geocoder.max_delay_ms')) * 1000);

        if (mt_rand() / mt_getrandmax() < config()->float('services.geocoder.failure_rate')) {
            throw new GeocodingFailed('The geocoding service did not return a result.');
        }

        return [
            'latitude' => random_int(-90_000_000, 90_000_000) / 1_000_000,
            'longitude' => random_int(-180_000_000, 180_000_000) / 1_000_000,
        ];
    }
}

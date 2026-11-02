<?php

use App\Enums\AddressType;
use App\Exceptions\GeocodingFailed;
use App\Jobs\GeocodeAddress;
use App\Models\Order;
use App\Services\StubGeocoder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;

it('writes a critical alert when a job fails for good', function (): void {
    $order = Order::factory()->create();

    $message = fake()->sentence();

    $this->mock(StubGeocoder::class, fn (MockInterface $geocoder) => $geocoder->expects('geocode')
        ->andThrow(new GeocodingFailed($message)));

    Log::shouldReceive('channel')->once()->with('alerts')->andReturnSelf();

    Log::shouldReceive('critical')->once()->with(
        sprintf('Job %s failed for good', GeocodeAddress::class),
        Mockery::on(
            fn (array $context): bool => Str::isUuid($context['job_uuid']) && $context['exception'] === $message,
        ),
    );

    expect(
        fn () => dispatch_sync(
            new GeocodeAddress($order, AddressType::Collection),
        ),
    )
        ->toThrow(GeocodingFailed::class);
});

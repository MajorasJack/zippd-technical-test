<?php

namespace App\Jobs;

use App\Enums\AddressType;
use App\Models\Order;
use App\Services\StubGeocoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(5)]
#[Backoff(10, 30, 60, 120)]
class GeocodeAddress implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Order $order,
        public AddressType $addressType,
    ) {
    }

    /**
     * Geocode one of the order's addresses and save its coordinates.
     *
     * An address with a latitude is already geocoded, so a job that runs twice does no harm.
     * A geocoder failure is left to throw, so the queue retries it with the backoff above.
     */
    public function handle(StubGeocoder $geocoder): void
    {
        $type = $this->addressType->value;

        if ($this->order->{sprintf('%s_latitude', $type)} !== null) {
            return;
        }

        $coordinates = $geocoder->geocode($this->order->{sprintf('%s_address', $type)});

        $this->order->update([
            sprintf('%s_latitude', $type) => $coordinates['latitude'],
            sprintf('%s_longitude', $type) => $coordinates['longitude'],
        ]);
    }

    /**
     * Mark the order as failed to geocode once every retry is used up.
     *
     * Alerting happens in the central Queue::failing() listener, not here.
     */
    public function failed(): void
    {
        $this->order->update(['geocode_failed_at' => now()]);
    }
}

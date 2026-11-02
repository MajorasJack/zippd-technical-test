<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'partner_reference' => fake()->unique()->bothify('REF-####-????'),
            'status' => OrderStatus::Received,
            'collection_address' => $this->address(),
            'delivery_address' => $this->address(),
        ];
    }

    /**
     * An address in the shape the create-order endpoint accepts.
     *
     * @return array{line_1: string, line_2: ?string, city: string, postcode: string, country_code: string}
     */
    private function address(): array
    {
        return [
            'line_1' => fake()->streetAddress(),
            'line_2' => fake()->optional()->secondaryAddress(),
            'city' => fake()->city(),
            'postcode' => fake()->postcode(),
            'country_code' => fake()->countryCode(),
        ];
    }
}

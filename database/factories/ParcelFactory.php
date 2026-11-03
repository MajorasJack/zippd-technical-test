<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Parcel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Parcel>
 */
class ParcelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'weight_grams' => fake()->numberBetween(1, 50_000),
        ];
    }
}

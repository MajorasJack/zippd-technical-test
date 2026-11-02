<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\Parcel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * Transform the order into the shape partners receive.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner_reference' => $this->partner_reference,
            'status' => $this->status,
            'collection_address' => $this->collection_address,
            'delivery_address' => $this->delivery_address,
            'parcels' => $this->parcels->map(fn (Parcel $parcel): array => ['weight_grams' => $parcel->weight_grams]),
            'created_at' => $this->created_at,
        ];
    }
}

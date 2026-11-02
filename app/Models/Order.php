<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'partner_reference',
    'idempotency_key',
    'status',
    'collection_address',
    'delivery_address',
    'collection_latitude',
    'collection_longitude',
    'delivery_latitude',
    'delivery_longitude',
    'geocode_failed_at',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * The partner that sent this order.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * The parcels in this order.
     *
     * @return HasMany<Parcel, $this>
     */
    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class);
    }

    /**
     * Every status this order has entered, which doubles as the webhook record.
     *
     * @return HasMany<OrderStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'collection_address' => 'array',
            'delivery_address' => 'array',
            'collection_latitude' => 'decimal:7',
            'collection_longitude' => 'decimal:7',
            'delivery_latitude' => 'decimal:7',
            'delivery_longitude' => 'decimal:7',
            'geocode_failed_at' => 'datetime',
        ];
    }
}

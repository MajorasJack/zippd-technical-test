<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderStatusChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['from_status', 'to_status', 'webhook_delivered_at', 'webhook_failed_at'])]
class OrderStatusChange extends Model
{
    /** @use HasFactory<OrderStatusChangeFactory> */
    use HasFactory;

    /**
     * The row records the moment a status was entered, so the table has no updated_at.
     */
    public const UPDATED_AT = null;

    /**
     * The order whose status changed.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'webhook_delivered_at' => 'datetime',
            'webhook_failed_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Database\Factories\ParcelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['weight_grams'])]
class Parcel extends Model
{
    /** @use HasFactory<ParcelFactory> */
    use HasFactory;

    /**
     * The order this parcel belongs to.
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
            'weight_grams' => 'integer',
        ];
    }
}

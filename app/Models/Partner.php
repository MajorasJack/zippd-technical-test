<?php

namespace App\Models;

use Database\Factories\PartnerFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name'])]
class Partner extends Model implements AuthenticatableContract
{
    /** @use HasFactory<PartnerFactory> */
    use Authenticatable, HasApiTokens, HasFactory;

    /**
     * The orders this partner has sent.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}

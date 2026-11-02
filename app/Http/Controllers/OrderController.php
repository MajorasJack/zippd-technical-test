<?php

namespace App\Http\Controllers;

use App\Actions\CreateOrder;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Partner;
use Illuminate\Container\Attributes\CurrentUser;

class OrderController extends Controller
{
    /**
     * Create an order for the partner who owns the token.
     *
     * A resource answers 201 for a model that was just created and 200 otherwise, so a duplicate
     * request gets the existing order back with a 200 without any status handling here.
     */
    public function store(StoreOrderRequest $request, CreateOrder $createOrder, #[CurrentUser] Partner $partner): OrderResource
    {
        return new OrderResource($createOrder->handle(partner: $partner, attributes: $request->validated()));
    }
}

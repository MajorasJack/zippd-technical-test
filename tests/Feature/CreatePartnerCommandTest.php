<?php

use App\Models\Partner;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

it('creates a partner and prints a token that authenticates as that partner', function (): void {
    $name = fake()->company();

    Artisan::call('partner:create', ['name' => $name]);

    $partner = Partner::sole();
    expect($partner->name)->toBe($name)
        ->and(PersonalAccessToken::findToken(trim(Artisan::output()))->tokenable->is($partner))
        ->toBeTrue();
});

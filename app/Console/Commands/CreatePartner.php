<?php

namespace App\Console\Commands;

use App\Models\Partner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('partner:create {name : The partner\'s name}')]
#[Description('Create a partner and print its API token')]
class CreatePartner extends Command
{
    /**
     * Create the partner and print a Sanctum token for it.
     *
     * This is the only way to issue a token, so the plain-text value is shown once here and never stored.
     */
    public function handle(): int
    {
        $partner = Partner::create(['name' => $this->argument('name')]);

        $this->line($partner->createToken('api')->plainTextToken);

        return self::SUCCESS;
    }
}

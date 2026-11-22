<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Raffles\RaffleResource;
use App\Filament\App\Resources\RaffleTickets\RaffleTicketResource;
use Tests\TestCase;

class RaffleNavigationTest extends TestCase
{
    public function test_raffle_resources_are_grouped_with_customer_relationships(): void
    {
        $this->assertSame('Pelanggan & CRM', RaffleResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', RaffleTicketResource::getNavigationGroup());
    }
}

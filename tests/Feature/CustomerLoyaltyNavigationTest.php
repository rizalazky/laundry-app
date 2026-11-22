<?php

namespace Tests\Feature;

use App\Filament\App\Resources\PointLogs\PointLogResource;
use App\Filament\App\Resources\PointRewards\PointRewardResource;
use Tests\TestCase;

class CustomerLoyaltyNavigationTest extends TestCase
{
    public function test_loyalty_resources_are_grouped_with_customer_relationships(): void
    {
        $this->assertSame('Pelanggan & CRM', PointLogResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', PointRewardResource::getNavigationGroup());
    }
}

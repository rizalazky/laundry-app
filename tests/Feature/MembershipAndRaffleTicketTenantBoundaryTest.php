<?php

namespace Tests\Feature;

use App\Filament\App\Resources\CustomerMemberships\CustomerMembershipResource;
use App\Filament\App\Resources\RaffleTickets\RaffleTicketResource;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\MemberTier;
use App\Models\Raffle;
use App\Models\RaffleTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipAndRaffleTicketTenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_membership_and_raffle_ticket_resources_exclude_foreign_tenant_records(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $ownCustomer = Customer::factory()->for($tenant)->create();
        $foreignCustomer = Customer::factory()->for($foreignTenant)->create();

        $ownTier = MemberTier::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Gold',
            'price' => 100000,
            'duration_days' => 30,
            'is_active' => true,
        ]);
        $foreignTier = MemberTier::query()->create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Foreign Gold',
            'price' => 100000,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        $ownMembership = CustomerMembership::query()->create([
            'tenant_id' => $tenant->id,
            'customer_id' => $ownCustomer->id,
            'member_tier_id' => $ownTier->id,
            'started_at' => now()->subDay(),
            'expired_at' => now()->addDays(29),
            'status' => CustomerMembership::STATUS_ACTIVE,
        ]);
        CustomerMembership::query()->create([
            'tenant_id' => $foreignTenant->id,
            'customer_id' => $foreignCustomer->id,
            'member_tier_id' => $foreignTier->id,
            'started_at' => now()->subDay(),
            'expired_at' => now()->addDays(29),
            'status' => CustomerMembership::STATUS_ACTIVE,
        ]);

        $ownRaffle = Raffle::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Undian Lokal',
            'status' => Raffle::STATUS_ACTIVE,
        ]);
        $foreignRaffle = Raffle::query()->create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Undian Asing',
            'status' => Raffle::STATUS_ACTIVE,
        ]);

        $ownTicket = RaffleTicket::query()->create([
            'tenant_id' => $tenant->id,
            'raffle_id' => $ownRaffle->id,
            'customer_id' => $ownCustomer->id,
            'ticket_number' => 'LOCAL-000001',
            'issued_at' => now(),
        ]);
        RaffleTicket::query()->create([
            'tenant_id' => $foreignTenant->id,
            'raffle_id' => $foreignRaffle->id,
            'customer_id' => $foreignCustomer->id,
            'ticket_number' => 'FOREIGN-000001',
            'issued_at' => now(),
        ]);

        $this->actingAs($owner);

        $this->assertSame(
            [$ownMembership->id],
            CustomerMembershipResource::getEloquentQuery()->orderBy('id')->pluck('id')->all(),
        );

        $this->assertSame(
            [$ownTicket->id],
            RaffleTicketResource::getEloquentQuery()->orderBy('id')->pluck('id')->all(),
        );
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Raffles\IssueRaffleTickets;
use App\Models\Customer;
use App\Models\Raffle;
use App\Models\RaffleTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RaffleTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_issue_sequential_tickets_for_a_customer_during_an_active_raffle(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $customer = $tenant->customers()->create(['name' => 'Pelanggan Undian']);
        $raffle = Raffle::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Undian Akhir Tahun',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(),
            'status' => Raffle::STATUS_ACTIVE,
            'prizes' => ['1' => 'Mesin Cuci', '2' => 'Setrika'],
        ]);

        $this->actingAs($owner);

        $tickets = app(IssueRaffleTickets::class)->handle($owner, $raffle->id, $customer->id, 2);

        $this->assertCount(2, $tickets);
        $this->assertSame('RFL-'.$raffle->id.'-000001', $tickets[0]->ticket_number);
        $this->assertSame('RFL-'.$raffle->id.'-000002', $tickets[1]->ticket_number);
        $this->assertDatabaseHas('raffle_tickets', [
            'tenant_id' => $tenant->id,
            'raffle_id' => $raffle->id,
            'customer_id' => $customer->id,
            'ticket_number' => 'RFL-'.$raffle->id.'-000002',
            'is_winner' => false,
        ]);
    }

    public function test_ticket_issuance_rejects_inactive_raffles_and_cross_tenant_records(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $customer = $tenant->customers()->create(['name' => 'Pelanggan']);
        $foreignCustomer = $otherTenant->customers()->create(['name' => 'Pelanggan Asing']);
        $draft = Raffle::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Undian Draft',
            'status' => Raffle::STATUS_DRAFT,
        ]);
        $foreignRaffle = Raffle::query()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Undian Tenant Lain',
            'status' => Raffle::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner);

        try {
            app(IssueRaffleTickets::class)->handle($owner, $draft->id, $customer->id);
            $this->fail('Expected inactive raffle validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('raffle_id', $exception->errors());
        }

        try {
            app(IssueRaffleTickets::class)->handle($owner, $foreignRaffle->id, $customer->id);
            $this->fail('Expected foreign raffle validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('raffle_id', $exception->errors());
        }

        $active = Raffle::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Undian Aktif',
            'status' => Raffle::STATUS_ACTIVE,
        ]);

        try {
            app(IssueRaffleTickets::class)->handle($owner, $active->id, $foreignCustomer->id);
            $this->fail('Expected foreign customer validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        $this->assertDatabaseCount('raffle_tickets', 0);
    }

    public function test_ticket_issuance_rejects_raffles_outside_their_validity_window(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $customer = $tenant->customers()->create(['name' => 'Pelanggan']);
        $raffle = Raffle::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Undian Sudah Berakhir',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'status' => Raffle::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner);

        try {
            app(IssueRaffleTickets::class)->handle($owner, $raffle->id, $customer->id);
            $this->fail('Expected raffle validity validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('raffle_id', $exception->errors());
        }

        $this->assertDatabaseCount('raffle_tickets', 0);
    }

    public function test_ticket_issuance_requires_permission_and_limits_batch_size(): void
    {
        $tenant = Tenant::factory()->create();
        $cashier = User::factory()->for($tenant)->withRole('cashier')->create();
        $customer = $tenant->customers()->create(['name' => 'Pelanggan']);
        $raffle = Raffle::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Undian Aktif',
            'status' => Raffle::STATUS_ACTIVE,
        ]);

        $this->expectException(AuthorizationException::class);
        app(IssueRaffleTickets::class)->handle($cashier, $raffle->id, $customer->id);
    }
}

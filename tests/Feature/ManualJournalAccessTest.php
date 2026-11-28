<?php

namespace Tests\\Feature;

use App\\Filament\\App\\Pages\\ManualJournal;
use App\\Models\\Branch;
use App\\Models\\Tenant;
use App\\Models\\User;
use Filament\\Facades\\Filament;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Livewire\\Livewire;
use Tests\\TestCase;

class ManualJournalAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_journal_rejects_a_stale_owner_deactivated_in_the_database(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($branch);

        $component = Livewire::test(ManualJournal::class);

        User::query()->whereKey($owner->getKey())->update(['is_active' => false]);

        $component->call('getBranchOptions')->assertForbidden();
        $this->assertFalse(ManualJournal::canAccess());
    }
}

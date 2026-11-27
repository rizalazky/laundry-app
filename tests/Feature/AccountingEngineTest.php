<?php

namespace Tests\Feature;

use App\Actions\Accounting\CreateJournal;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Journal;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_tenant_receives_default_chart_of_accounts(): void
    {
        $tenant = Tenant::factory()->create();

        $accounts = ChartOfAccount::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->get();

        $this->assertCount(21, $accounts);
        $this->assertDatabaseHas('chart_of_accounts', [
            'tenant_id' => $tenant->id,
            'code' => '1000',
            'name' => 'Kas',
            'account_type' => 'asset',
            'is_system' => true,
        ]);
        $this->assertDatabaseHas('chart_of_accounts', [
            'tenant_id' => $tenant->id,
            'code' => '4000',
            'name' => 'Pendapatan Jasa Laundry',
            'account_type' => 'revenue',
            'is_system' => true,
        ]);
    }

    public function test_create_journal_persists_balanced_entries(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();

        $journal = app(CreateJournal::class)->handle(
            tenantId: $tenant->id,
            branchId: $branch->id,
            journalDate: now(),
            referenceType: 'payment',
            referenceId: 501,
            description: 'Penerimaan pembayaran pelanggan',
            entries: [
                ['account_code' => '1000', 'debit' => '100000.00', 'credit' => '0.00'],
                ['account_code' => '1100', 'debit' => '0.00', 'credit' => '100000.00'],
            ],
        );

        $this->assertModelExists($journal);
        $this->assertCount(2, $journal->entries);
        $this->assertSame('100000.00', $journal->entries->firstWhere('debit', '100000.00')->debit);
        $this->assertDatabaseHas('journal_entries', [
            'tenant_id' => $tenant->id,
            'journal_id' => $journal->id,
            'debit' => '100000.00',
            'credit' => '0.00',
        ]);
    }

    public function test_same_source_and_same_payload_returns_existing_journal(): void
    {
        $tenant = Tenant::factory()->create();
        $payload = [
            'tenantId' => $tenant->id,
            'branchId' => null,
            'journalDate' => now(),
            'referenceType' => 'payment',
            'referenceId' => 502,
            'description' => 'Penerimaan pembayaran',
            'entries' => [
                ['account_code' => '1000', 'debit' => '50000', 'credit' => '0'],
                ['account_code' => '1100', 'debit' => '0', 'credit' => '50000'],
            ],
        ];

        $first = app(CreateJournal::class)->handle(...$payload);
        $second = app(CreateJournal::class)->handle(...$payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Journal::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
    }

    public function test_existing_source_reference_cannot_be_reused_with_different_amounts(): void
    {
        $tenant = Tenant::factory()->create();
        $action = app(CreateJournal::class);

        $action->handle(
            tenantId: $tenant->id,
            branchId: null,
            journalDate: now(),
            referenceType: 'payment',
            referenceId: 503,
            description: 'Penerimaan pembayaran',
            entries: [
                ['account_code' => '1000', 'debit' => '50000', 'credit' => '0'],
                ['account_code' => '1100', 'debit' => '0', 'credit' => '50000'],
            ],
        );

        $this->expectException(DomainException::class);

        $action->handle(
            tenantId: $tenant->id,
            branchId: null,
            journalDate: now(),
            referenceType: 'payment',
            referenceId: 503,
            description: 'Penerimaan pembayaran',
            entries: [
                ['account_code' => '1000', 'debit' => '60000', 'credit' => '0'],
                ['account_code' => '1100', 'debit' => '0', 'credit' => '60000'],
            ],
        );
    }

    public function test_unbalanced_journal_is_rejected_without_writing_records(): void
    {
        $tenant = Tenant::factory()->create();

        try {
            app(CreateJournal::class)->handle(
                tenantId: $tenant->id,
                branchId: null,
                journalDate: now(),
                referenceType: null,
                referenceId: null,
                description: 'Jurnal tidak seimbang',
                entries: [
                    ['account_code' => '1000', 'debit' => '100000', 'credit' => '0'],
                    ['account_code' => '1100', 'debit' => '0', 'credit' => '90000'],
                ],
            );
            $this->fail('Unbalanced journals must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Total debit dan kredit harus sama dan lebih besar dari nol.', $exception->getMessage());
        }

        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_journal_cannot_use_an_account_from_another_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        ChartOfAccount::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantB->id,
            'code' => '9999',
            'name' => 'Akun Tenant B',
            'account_type' => 'asset',
            'is_system' => false,
            'is_active' => true,
        ]);

        $this->expectException(DomainException::class);

        app(CreateJournal::class)->handle(
            tenantId: $tenantA->id,
            branchId: null,
            journalDate: now(),
            referenceType: null,
            referenceId: null,
            description: 'Percobaan lintas tenant',
            entries: [
                ['account_code' => '9999', 'debit' => '10000', 'credit' => '0'],
                ['account_code' => '1000', 'debit' => '0', 'credit' => '10000'],
            ],
        );
    }

    public function test_journal_line_must_have_exactly_one_positive_side(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(DomainException::class);

        app(CreateJournal::class)->handle(
            tenantId: $tenant->id,
            branchId: null,
            journalDate: now(),
            referenceType: null,
            referenceId: null,
            description: 'Baris debit dan kredit bersamaan',
            entries: [
                ['account_code' => '1000', 'debit' => '100', 'credit' => '100'],
                ['account_code' => '1100', 'debit' => '0', 'credit' => '0'],
            ],
        );
    }

    public function test_journal_rejects_a_branch_from_another_tenant_when_called_directly(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $foreignBranch = Branch::factory()->for($foreignTenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        try {
            app(CreateJournal::class)->handle(
                tenantId: $tenant->id,
                branchId: $foreignBranch->id,
                journalDate: now(),
                referenceType: null,
                referenceId: null,
                description: 'Cross-tenant branch journal',
                entries: [
                    ['account_code' => '1000', 'debit' => '10000', 'credit' => '0'],
                    ['account_code' => '3000', 'debit' => '0', 'credit' => '10000'],
                ],
            );

            $this->fail('A journal must not reference a branch owned by another tenant.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Cabang jurnal tidak ditemukan atau bukan milik tenant ini.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_authenticated_journal_caller_cannot_forge_a_foreign_creator_id(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $owner = User::factory()->for($tenant)->withRole('tenant_owner')->create();
        $foreignUser = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();

        $this->actingAs($owner);

        try {
            app(CreateJournal::class)->handle(
                tenantId: $tenant->id,
                branchId: $branch->id,
                journalDate: now(),
                referenceType: null,
                referenceId: null,
                description: 'Forged journal creator',
                entries: [
                    ['account_code' => '1000', 'debit' => '10000', 'credit' => '0'],
                    ['account_code' => '3000', 'debit' => '0', 'credit' => '10000'],
                ],
                createdBy: $foreignUser->id,
            );

            $this->fail('An authenticated caller must not attribute a journal to another user.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Pengguna pencatat jurnal harus sesuai dengan akun yang sedang login.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_unauthenticated_internal_journal_can_only_reference_a_creator_from_its_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignTenant = Tenant::factory()->create();
        $branch = Branch::factory()->for($tenant)->create();
        $foreignUser = User::factory()->for($foreignTenant)->withRole('tenant_owner')->create();

        try {
            app(CreateJournal::class)->handle(
                tenantId: $tenant->id,
                branchId: $branch->id,
                journalDate: now(),
                referenceType: null,
                referenceId: null,
                description: 'Foreign internal creator',
                entries: [
                    ['account_code' => '1000', 'debit' => '10000', 'credit' => '0'],
                    ['account_code' => '3000', 'debit' => '0', 'credit' => '10000'],
                ],
                createdBy: $foreignUser->id,
            );

            $this->fail('An internal journal must not reference a creator from another tenant.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'Pengguna pencatat jurnal tidak ditemukan atau bukan milik tenant ini.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('journals', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }
}

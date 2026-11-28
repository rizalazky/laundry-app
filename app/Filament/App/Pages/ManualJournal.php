<?php

namespace App\Filament\App\Pages;

use App\Actions\Accounting\CreateJournal;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use UnitEnum;
use BackedEnum;

class ManualJournal extends Page
{
    protected string $view = 'filament.app.pages.manual-journal';
    protected static ?string $slug = 'jurnal-manual';
    protected static ?string $title = 'Entry Jurnal Manual';
    protected static ?string $navigationLabel = 'Jurnal Manual';
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-plus';
    protected static string|UnitEnum|null $navigationGroup = 'Keuangan & Laporan';
    protected static ?int $navigationSort = 14;

    public string $journalDate = '';
    public string $branchId = '';
    public string $description = '';
    public string $referenceType = '';
    public ?string $referenceId = null;
    public array $entries = [];

    public static function canAccess(): bool
    {
        $user = static::authenticatedActor();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && $user->hasPermissionTo('finance.manage');
    }

    private static function authenticatedActor(): ?User
    {
        $authenticatedUserId = Auth::id();

        return $authenticatedUserId === null
            ? null
            : User::query()->find($authenticatedUserId);
    }

    /**
     * Re-check page access for every subsequent Livewire request.
     */
    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->journalDate = now()->format('Y-m-d\TH:i');
        $this->branchId = (string) (Filament::getTenant()?->getKey() ?? '');
        $this->entries = [
            ['account_code' => '', 'debit' => '0.00', 'credit' => '0.00'],
            ['account_code' => '', 'debit' => '0.00', 'credit' => '0.00'],
        ];
    }

    public function getAccountOptions(): array
    {
        abort_unless(static::canAccess(), 403);

        return ChartOfAccount::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (ChartOfAccount $account): array => [
                $account->code => $account->code.' — '.$account->name,
            ])
            ->all();
    }

    public function getBranchOptions(): array
    {
        abort_unless(static::canAccess(), 403);

        $user = static::authenticatedActor();

        abort_unless($user !== null, 403);

        $query = Branch::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('is_active', true)
            ->orderBy('name');

        if ($user->role !== 'tenant_owner') {
            $query->whereKey(Filament::getTenant()?->getKey());
        }

        $branches = $query->pluck('name', 'id')->all();

        return $user->role === 'tenant_owner'
            ? ['' => 'Perusahaan (tanpa cabang)'] + $branches
            : $branches;
    }

    public function addEntry(): void
    {
        $this->entries[] = ['account_code' => '', 'debit' => '0.00', 'credit' => '0.00'];
    }

    public function removeEntry(int $index): void
    {
        if (count($this->entries) <= 2) {
            return;
        }

        unset($this->entries[$index]);
        $this->entries = array_values($this->entries);
    }

    public function totalDebit(): string
    {
        return $this->sumEntries('debit');
    }

    public function totalCredit(): string
    {
        return $this->sumEntries('credit');
    }

    public function createJournal(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->referenceId = $this->referenceId === '' ? null : $this->referenceId;

        $data = $this->validate([
            'journalDate' => ['required', 'date'],
            'branchId' => ['nullable', 'string'],
            'description' => ['required', 'string', 'max:2000'],
            'referenceType' => [
                'nullable',
                'string',
                'max:100',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (in_array(strtolower(trim((string) $value)), CreateJournal::SYSTEM_REFERENCE_TYPES, true)) {
                        $fail('Tipe referensi ini dikhususkan untuk jurnal otomatis.');
                    }
                },
            ],
            'referenceId' => ['nullable', 'integer', 'min:1'],
            'entries' => ['required', 'array', 'min:2'],
            'entries.*.account_code' => ['required', 'string', 'max:30'],
            'entries.*.debit' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'entries.*.credit' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        $user = static::authenticatedActor();

        abort_unless(
            $user !== null
                && $user->is_active
                && $user->tenant_id !== null
                && $user->hasPermissionTo('finance.manage'),
            403,
        );

        $branchId = $data['branchId'] === '' ? null : (int) $data['branchId'];

        if ($branchId !== null) {
            $branch = Branch::query()
                ->where('tenant_id', $user?->tenant_id)
                ->find($branchId);

            if ($branch === null) {
                throw ValidationException::withMessages(['branchId' => 'Cabang tidak valid untuk tenant ini.']);
            }

            if ($user?->role !== 'tenant_owner' && $branchId !== (int) Filament::getTenant()?->getKey()) {
                throw ValidationException::withMessages(['branchId' => 'Anda tidak dapat mencatat jurnal untuk cabang lain.']);
            }
        } elseif ($user?->role !== 'tenant_owner') {
            throw ValidationException::withMessages(['branchId' => 'Hanya owner yang dapat membuat jurnal tingkat perusahaan.']);
        }

        try {
            app(CreateJournal::class)->handle(
                tenantId: (int) $user->tenant_id,
                branchId: $branchId,
                journalDate: CarbonImmutable::parse($data['journalDate']),
                referenceType: filled($data['referenceType']) ? $data['referenceType'] : null,
                referenceId: filled($data['referenceId']) ? (int) $data['referenceId'] : null,
                description: $data['description'],
                entries: $data['entries'],
                createdBy: (int) $user->getKey(),
            );
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['entries' => $exception->getMessage()]);
        }

        Notification::make()
            ->title('Jurnal manual berhasil disimpan')
            ->success()
            ->send();

        $this->reset('description', 'referenceType', 'referenceId');
        $this->journalDate = now()->format('Y-m-d\TH:i');
        $this->entries = [
            ['account_code' => '', 'debit' => '0.00', 'credit' => '0.00'],
            ['account_code' => '', 'debit' => '0.00', 'credit' => '0.00'],
        ];
    }

    public function formatMoney(string $amount): string
    {
        return 'Rp '.number_format((float) $amount, 2, ',', '.');
    }

    private function sumEntries(string $side): string
    {
        return (string) collect($this->entries)->reduce(
            fn (BigDecimal $sum, array $entry): BigDecimal => $sum->plus(
                is_numeric($entry[$side] ?? null) ? (string) $entry[$side] : '0',
            ),
            BigDecimal::zero(),
        )->toScale(2);
    }
}

<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use DateTimeZone;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class GeneralSettings extends Page
{
    protected string $view = 'filament.app.pages.general-settings';

    protected static ?string $slug = 'pengaturan-umum';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 10;

    public string $timezone = 'Asia/Jakarta';

    public string $dateFormat = 'd/m/Y';

    public string $invoicePrefix = '';

    public int $defaultEstimationHours = 24;

    public bool $allowPartialPayment = true;

    /** @var array<string, string> */
    public array $timezoneOptions = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->is_active
            && $user->role === 'tenant_owner'
            && $user->tenant_id !== null
            && $user->tenant !== null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $tenant = Auth::user()->tenant;
        $settings = $tenant->generalSettings();

        $this->timezone = $settings['timezone'];
        $this->dateFormat = $settings['date_format'];
        $this->invoicePrefix = $settings['invoice_prefix'] ?? '';
        $this->defaultEstimationHours = (int) $settings['default_estimation_hours'];
        $this->allowPartialPayment = (bool) $settings['allow_partial_payment'];

        $this->timezoneOptions = collect(DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $timezone): array => [$timezone => $timezone])
            ->all();
    }

    public function save(): void
    {
        $user = Auth::user();

        abort_unless(
            $user instanceof User
                && $user->is_active
                && $user->role === 'tenant_owner'
                && $user->tenant_id !== null,
            403,
        );

        $validated = $this->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'dateFormat' => ['required', 'string', Rule::in(['d/m/Y', 'd-m-Y', 'm/d/Y', 'Y-m-d'])],
            'invoicePrefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]*$/'],
            'defaultEstimationHours' => ['required', 'integer', 'min:1', 'max:720'],
            'allowPartialPayment' => ['required', 'boolean'],
        ]);

        $user->tenant()->firstOrFail()->updateGeneralSettings([
            'timezone' => $validated['timezone'],
            'date_format' => $validated['dateFormat'],
            'invoice_prefix' => filled($validated['invoicePrefix']) ? strtoupper($validated['invoicePrefix']) : null,
            'default_estimation_hours' => (int) $validated['defaultEstimationHours'],
            'allow_partial_payment' => (bool) $validated['allowPartialPayment'],
        ]);

        Notification::make()
            ->title('Pengaturan umum berhasil disimpan')
            ->success()
            ->send();
    }
}

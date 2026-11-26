<?php

namespace App\Filament\Pages;

use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use UnitEnum;

class SubscriptionSettings extends Page
{
    protected string $view = 'filament.pages.subscription-settings';

    protected static ?string $slug = 'subscription-settings';

    protected static ?string $navigationLabel = 'Subscription Settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 70;

    public bool $registrationEnabled = true;

    public bool $trialEnabled = true;

    public int $trialDays = 30;

    public int $gracePeriodDays = 3;

    public string $defaultPlanCode = 'starter';

    /** @var array<string, string> */
    public array $planOptions = [];

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->is_active
            && $user->role === 'super_admin'
            && $user->tenant_id === null;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->planOptions = Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'code')
            ->all();

        $this->registrationEnabled = (bool) $this->setting('registration_enabled', true);
        $this->trialEnabled = (bool) $this->setting('trial_enabled', true);
        $this->trialDays = max(0, (int) $this->setting('trial_days', 30));
        $this->gracePeriodDays = max(0, (int) $this->setting('grace_period_days', 3));
        $this->defaultPlanCode = (string) $this->setting('default_plan_code', 'starter');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $validated = $this->validate([
            'registrationEnabled' => ['required', 'boolean'],
            'trialEnabled' => ['required', 'boolean'],
            'trialDays' => ['required', 'integer', 'min:0', 'max:3650'],
            'gracePeriodDays' => ['required', 'integer', 'min:0', 'max:3650'],
            'defaultPlanCode' => [
                'required',
                'string',
                Rule::exists('plans', 'code')->where('is_active', true),
            ],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ([
                'registration_enabled' => (bool) $validated['registrationEnabled'],
                'trial_enabled' => (bool) $validated['trialEnabled'],
                'trial_days' => (int) $validated['trialDays'],
                'grace_period_days' => (int) $validated['gracePeriodDays'],
                'default_plan_code' => $validated['defaultPlanCode'],
            ] as $key => $value) {
                PlatformSetting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $value],
                );
            }
        });

        Notification::make()
            ->title('Pengaturan subscription berhasil disimpan')
            ->success()
            ->send();
    }

    private function setting(string $key, mixed $default): mixed
    {
        return PlatformSetting::query()
            ->where('key', $key)
            ->value('value') ?? $default;
    }
}

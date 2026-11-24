<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Defaults are centralized here so existing tenants remain compatible
     * when a new setting is introduced.
     *
     * @var array<string, mixed>
     */
    private const GENERAL_SETTINGS_DEFAULTS = [
        'timezone' => 'Asia/Jakarta',
        'date_format' => 'd/m/Y',
        'invoice_prefix' => null,
        'default_estimation_hours' => 24,
        'allow_partial_payment' => true,
    ];

    protected $fillable = [
        'name',
        'slug',
        'owner_email',
        'phone',
        'plan',
        'max_branches',
        'subscription_ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'subscription_ends_at' => 'datetime',
            'is_active' => 'boolean',
            'general_settings' => 'array',
        ];
    }

    /**
     * Return all settings merged with defaults for legacy tenants.
     *
     * @return array<string, mixed>
     */
    public function generalSettings(): array
    {
        return array_replace(
            self::GENERAL_SETTINGS_DEFAULTS,
            $this->general_settings ?? [],
        );
    }

    public function getGeneralSetting(string $key, mixed $default = null): mixed
    {
        return $this->generalSettings()[$key] ?? $default;
    }

    /**
     * Merge settings instead of replacing the JSON document, preserving
     * keys introduced by newer application versions.
     *
     * @param  array<string, mixed>  $settings
     */
    public function updateGeneralSettings(array $settings): void
    {
        $this->forceFill([
            'general_settings' => array_replace(
                $this->general_settings ?? [],
                $settings,
            ),
        ])->save();
    }

    public function getTimezone(): string
    {
        return (string) $this->getGeneralSetting('timezone', 'Asia/Jakarta');
    }

    public function getPreferredDateFormat(): string
    {
        return (string) $this->getGeneralSetting('date_format', 'd/m/Y');
    }

    public function getInvoicePrefix(): ?string
    {
        $prefix = $this->getGeneralSetting('invoice_prefix');

        return filled($prefix) ? (string) $prefix : null;
    }

    public function getDefaultEstimationHours(): int
    {
        return (int) $this->getGeneralSetting('default_estimation_hours', 24);
    }

    public function allowsPartialPayment(): bool
    {
        return (bool) $this->getGeneralSetting('allow_partial_payment', true);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function hasActiveSubscription(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->subscription_ends_at === null || $this->subscription_ends_at->isFuture();
    }
}

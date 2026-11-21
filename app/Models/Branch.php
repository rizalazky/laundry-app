<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model implements HasCurrentTenantLabel, HasName
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'phone',
        'address',
        'is_workshop',
        'receipt_footer',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_workshop' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getCurrentTenantLabel(): string
    {
        return $this->is_workshop ? 'Central Workshop' : 'Cabang Aktif';
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function workshops(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'outlet_workshops', 'outlet_id', 'workshop_id')
            ->withPivot('tenant_id')
            ->wherePivot('tenant_id', $this->tenant_id)
            ->where('branches.is_workshop', true);
    }

    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'outlet_workshops', 'workshop_id', 'outlet_id')
            ->withPivot('tenant_id')
            ->wherePivot('tenant_id', $this->tenant_id)
            ->where('branches.is_workshop', false);
    }

    /**
     * Replace the workshops assigned to this outlet.
     *
     * Only active workshops from the same tenant may be assigned. The
     * composite foreign keys on the pivot table provide a second tenant
     * integrity boundary at the database level.
     *
     * @param  array<int, int|string>  $workshopIds
     */
    public function syncWorkshops(array $workshopIds): void
    {
        $workshopIds = array_values(array_unique(array_map('intval', $workshopIds)));

        if ($this->is_workshop && $workshopIds !== []) {
            throw ValidationException::withMessages([
                'data.workshop_ids' => 'Lokasi workshop tidak dapat ditugaskan ke workshop lain.',
            ]);
        }

        $validWorkshopIds = self::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('is_workshop', true)
            ->where('is_active', true)
            ->whereKey($workshopIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if (count($validWorkshopIds) !== count($workshopIds)) {
            throw ValidationException::withMessages([
                'data.workshop_ids' => 'Pilih workshop aktif dari tenant yang sama.',
            ]);
        }

        DB::transaction(function () use ($validWorkshopIds): void {
            DB::table('outlet_workshops')
                ->where('tenant_id', $this->tenant_id)
                ->where('outlet_id', $this->getKey())
                ->delete();

            if ($validWorkshopIds === []) {
                return;
            }

            $now = now();

            DB::table('outlet_workshops')->insert(array_map(
                fn (int $workshopId): array => [
                    'tenant_id' => $this->tenant_id,
                    'outlet_id' => $this->getKey(),
                    'workshop_id' => $workshopId,
                    'created_at' => $now,
                ],
                $validWorkshopIds,
            ));
        });
    }

}

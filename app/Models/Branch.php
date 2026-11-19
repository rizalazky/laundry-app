<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
}

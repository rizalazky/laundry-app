<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'email',
        'address',
        'deposit_balance',
        'loyalty_points',
    ];

    protected function casts(): array
    {
        return [
            'deposit_balance' => 'decimal:2',
            'loyalty_points' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $phone = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($phone, '08')) {
            $phone = '62'.substr($phone, 1);
        }

        return $phone === '' ? null : $phone;
    }

    public function setPhoneAttribute(?string $phone): void
    {
        $this->attributes['phone'] = self::normalizePhone($phone);
    }
}

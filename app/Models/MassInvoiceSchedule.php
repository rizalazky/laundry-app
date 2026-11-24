<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class MassInvoiceSchedule extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'frequency',
        'run_day',
        'next_run_at',
        'last_run_at',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'run_day' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $schedule): void {
            $maximum = $schedule->frequency === 'weekly' ? 7 : 28;

            if ($schedule->created_by === null && Auth::id() !== null) {
                $schedule->created_by = Auth::id();
            }

            if (! in_array($schedule->frequency, ['weekly', 'monthly'], true)
                || $schedule->run_day < 1
                || $schedule->run_day > $maximum) {
                throw ValidationException::withMessages([
                    'run_day' => 'Aturan jadwal faktur tidak valid.',
                ]);
            }

            if ($schedule->next_run_at === null) {
                $schedule->next_run_at = $schedule->calculateNextRunAt(now());
            }
        });
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'mass_invoice_schedule_customer')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(MassInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function calculateNextRunAt(\DateTimeInterface|string $from): Carbon
    {
        $date = Carbon::parse($from);

        if ($this->frequency === 'weekly') {
            $candidate = $date->copy()->startOfWeek()->addDays($this->run_day - 1)->setTime(1, 0);

            return $candidate->greaterThan($date) ? $candidate : $candidate->addWeek();
        }

        $candidate = $date->copy()->startOfMonth()->day($this->run_day)->setTime(1, 0);

        return $candidate->greaterThan($date) ? $candidate : $candidate->addMonthNoOverflow();
    }
}

<?php

namespace App\Actions\Raffles;

use App\Models\Customer;
use App\Models\Raffle;
use App\Models\RaffleTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IssueRaffleTickets
{
    /**
     * @return list<RaffleTicket>
     */
    public function handle(User $actor, int $raffleId, int $customerId, int $quantity = 1): array
    {
        if (Auth::hasUser() && (int) Auth::id() !== (int) $actor->getKey()) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk menerbitkan tiket undian.');
        }

        $actor = User::query()->find($actor->getKey());

        if (
            $actor === null || ! $actor->is_active || $actor->tenant_id === null
            || ! in_array($actor->role, ['tenant_owner', 'branch_manager'], true)
            || ! $actor->hasPermissionTo('raffles.manage')
        ) {
            throw new AuthorizationException('Anda tidak memiliki izin untuk menerbitkan tiket undian.');
        }

        $tenant = Tenant::query()->find($actor->tenant_id);

        if ($tenant === null) {
            throw new AuthorizationException('Tenant undian tidak ditemukan.');
        }

        app(EntitlementService::class)->assertCanWrite($tenant);

        if ($quantity < 1 || $quantity > 100) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah tiket harus antara 1 sampai 100.']);
        }

        return DB::transaction(function () use ($actor, $raffleId, $customerId, $quantity): array {
            $raffle = Raffle::query()
                ->where('tenant_id', $actor->tenant_id)
                ->lockForUpdate()
                ->find($raffleId);

            if (! $raffle) {
                throw ValidationException::withMessages(['raffle_id' => 'Undian tidak ditemukan pada tenant ini.']);
            }

            $customer = Customer::query()
                ->where('tenant_id', $actor->tenant_id)
                ->lockForUpdate()
                ->find($customerId);

            if (! $customer) {
                throw ValidationException::withMessages(['customer_id' => 'Pelanggan tidak ditemukan pada tenant ini.']);
            }

            $now = now();
            if (
                $raffle->status !== Raffle::STATUS_ACTIVE
                || ($raffle->starts_at !== null && $raffle->starts_at->isAfter($now))
                || ($raffle->ends_at !== null && $raffle->ends_at->isBefore($now))
            ) {
                throw ValidationException::withMessages(['raffle_id' => 'Undian tidak sedang aktif untuk penerbitan tiket.']);
            }

            $sequence = (int) $raffle->tickets()->count();
            $tickets = [];

            for ($index = 1; $index <= $quantity; $index++) {
                $sequence++;
                $tickets[] = $raffle->tickets()->create([
                    'tenant_id' => $actor->tenant_id,
                    'customer_id' => $customer->id,
                    'ticket_number' => sprintf('RFL-%d-%06d', $raffle->id, $sequence),
                    'issued_at' => Carbon::now(),
                    'is_winner' => false,
                ]);
            }

            return $tickets;
        });
    }
}

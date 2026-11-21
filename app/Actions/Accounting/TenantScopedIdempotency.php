<?php

namespace App\Actions\Accounting;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Closure;

/**
 * Makes accounting writes safe to retry, including concurrent requests.
 *
 * The tenant-scoped unique indexes remain the final concurrency guard; this
 * resolver turns a duplicate-key race into an idempotent read of the winner.
 */
class TenantScopedIdempotency
{
    /**
     * @param class-string<Model> $modelClass
     */
    public function execute(
        string $modelClass,
        int $tenantId,
        ?string $referenceNumber,
        ?string $idempotencyKey,
        string $payloadHash,
        Closure $callback,
    ): Model {
        if ($referenceNumber === null && $idempotencyKey === null) {
            throw new DomainException('Reference number atau idempotency key wajib diisi untuk mencegah pencatatan ganda.');
        }

        try {
            return DB::transaction(function () use (
                $modelClass,
                $tenantId,
                $referenceNumber,
                $idempotencyKey,
                $payloadHash,
                $callback,
            ): Model {
                $existing = $this->findExisting(
                    $modelClass,
                    $tenantId,
                    $referenceNumber,
                    $idempotencyKey,
                    $payloadHash,
                    lock: true,
                );

                return $existing ?? $callback();
            });
        } catch (QueryException $exception) {
            // A concurrent request may have inserted the same tenant-scoped
            // key after our initial read. Resolve only if it is our duplicate.
            $existing = $this->findExisting(
                $modelClass,
                $tenantId,
                $referenceNumber,
                $idempotencyKey,
                $payloadHash,
            );

            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param class-string<Model> $modelClass
     */
    private function findExisting(
        string $modelClass,
        int $tenantId,
        ?string $referenceNumber,
        ?string $idempotencyKey,
        string $payloadHash,
        bool $lock = false,
    ): ?Model {
        $query = $modelClass::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($referenceNumber, $idempotencyKey): void {
                if ($referenceNumber !== null) {
                    $query->where('reference_number', $referenceNumber);
                }

                if ($idempotencyKey !== null) {
                    $method = $referenceNumber === null ? 'where' : 'orWhere';
                    $query->{$method}('idempotency_key', $idempotencyKey);
                }
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            return null;
        }

        if ($records->count() !== 1) {
            throw new DomainException('Reference number dan idempotency key mengarah ke transaksi yang berbeda.');
        }

        $record = $records->first();

        if (! is_string($record->payload_hash) || ! hash_equals($record->payload_hash, $payloadHash)) {
            throw new DomainException('Reference number atau idempotency key sudah digunakan dengan payload berbeda.');
        }

        return $record;
    }
}

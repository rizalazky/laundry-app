<?php

namespace App\Filament\App\Resources\Orders\Pages;

use App\Actions\Orders\CreateOrder as CreateOrderAction;
use App\Enums\PaymentMethod;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Models\Branch;
use Carbon\Carbon;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $branch = Filament::getTenant();

        abort_unless($branch instanceof Branch, 404);

        $initialPayment = null;

        if (filled($data['down_payment'] ?? null) && (float) $data['down_payment'] > 0) {
            $initialPayment = [
                'amount' => $data['down_payment'],
                'method' => PaymentMethod::from($data['payment_method']),
                'reference' => $data['payment_reference'] ?? null,
            ];
        }

        try {
            return app(CreateOrderAction::class)->handle(
                branch: $branch,
                user: Auth::user(),
                customerId: (int) $data['customer_id'],
                items: array_values($data['items']),
                discount: $data['discount'] ?? 0,
                notes: $data['notes'] ?? null,
                dueAt: filled($data['due_at'] ?? null) ? Carbon::parse($data['due_at']) : null,
                initialPayment: $initialPayment,
                voucherCode: $data['voucher_code'] ?? null,
                workshopId: filled($data['workshop_id'] ?? null) ? (int) $data['workshop_id'] : null,
            );
        } catch (DomainException $exception) {
            Notification::make()
                ->danger()
                ->title('Order gagal dibuat')
                ->body($exception->getMessage())
                ->send();

            $this->halt();
        }
    }
}

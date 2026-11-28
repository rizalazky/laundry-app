<?php

namespace App\Filament\App\Pages;

use App\Actions\Orders\ChangeOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ProductionLog;
use App\Models\User;
use DomainException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use UnitEnum;
use BackedEnum;

class WorkshopOperator extends Page
{
    protected string $view = 'filament.app.pages.workshop-operator';

    protected static ?string $slug = 'workshop-operator';

    protected static ?string $title = 'Antrean Workshop';

    protected static ?string $navigationLabel = 'Antrean Workshop';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 15;

    public string $barcode = '';

    public string $selectedStatus = 'all';

    public ?int $selectedOrderId = null;

    public ?int $workerId = null;

    public string $machineCode = '';

    public string $note = '';

    public static function canAccess(): bool
    {
        $authenticatedUserId = Auth::id();
        $user = $authenticatedUserId === null
            ? null
            : User::query()->find($authenticatedUserId);

        return $user instanceof User
            && $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager', 'operator'], true)
            && $user->hasPermissionTo('orders.view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (Auth::user()->role === 'operator') {
            $this->workerId = (int) Auth::id();
        }
    }

    /**
     * Re-check page access on every subsequent Livewire request.
     *
     * The initial Filament page check does not protect a component that
     * remains mounted after the user's role, active state, or permissions
     * have changed.
     */
    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function scanOrder(): void
    {
        $this->validate([
            'barcode' => ['required', 'string', 'max:100'],
        ]);

        $invoiceNumber = trim($this->barcode);

        $order = $this->ordersForCurrentLocation()
            ->where('invoice_no', $invoiceNumber)
            ->first();

        if ($order === null) {
            Notification::make()
                ->title('Nota tidak ditemukan')
                ->body('Pastikan order memang ditugaskan ke workshop ini.')
                ->danger()
                ->send();

            return;
        }

        $this->selectOrder((int) $order->getKey());
        $this->barcode = '';

        Notification::make()
            ->title('Order ditemukan')
            ->body($order->invoice_no)
            ->success()
            ->send();
    }

    public function selectOrder(int $orderId): void
    {
        $order = $this->ordersForCurrentLocation()->findOrFail($orderId);

        $this->selectedOrderId = (int) $order->getKey();

        if (Auth::user()->role === 'operator') {
            $this->workerId = (int) Auth::id();
        }
    }

    public function changeStage(string $status): void
    {
        $order = $this->selectedOrder();

        abort_if($order === null, 404);

        $targetStatus = OrderStatus::tryFrom($status);

        if ($targetStatus === null || ! in_array($targetStatus, $this->availableTransitions($order), true)) {
            throw ValidationException::withMessages([
                'status' => 'Perubahan tahap produksi tidak valid.',
            ]);
        }

        $this->validate([
            'workerId' => ['nullable', 'integer'],
            'machineCode' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $actor = Auth::user();
        $workerId = $actor->role === 'operator'
            ? (int) $actor->getKey()
            : $this->workerId;

        try {
            app(ChangeOrderStatus::class)->handle(
                order: $order,
                to: $targetStatus,
                user: $actor,
                note: filled($this->note) ? $this->note : null,
                workerId: $workerId,
                machineCode: filled($this->machineCode) ? $this->machineCode : null,
            );
        } catch (DomainException $exception) {
            Notification::make()
                ->title('Tahap tidak dapat diperbarui')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->note = '';
        $this->machineCode = '';

        Notification::make()
            ->title('Tahap produksi diperbarui')
            ->body('Order '.$order->invoice_no.' sekarang berstatus '.$targetStatus->label().'.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    public function queueStatusOptions(): array
    {
        return [
            'all' => 'Semua',
            OrderStatus::Received->value => OrderStatus::Received->label(),
            OrderStatus::Washing->value => OrderStatus::Washing->label(),
            OrderStatus::Drying->value => OrderStatus::Drying->label(),
            OrderStatus::Ironing->value => OrderStatus::Ironing->label(),
            OrderStatus::Packing->value => OrderStatus::Packing->label(),
            OrderStatus::Ready->value => OrderStatus::Ready->label(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function queueCounts(): array
    {
        $counts = $this->ordersForCurrentLocation()
            ->whereIn('status', array_keys($this->queueStatusOptions()))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = ['all' => (int) $counts->sum()];

        foreach (array_keys($this->queueStatusOptions()) as $status) {
            if ($status !== 'all') {
                $result[$status] = (int) ($counts[$status] ?? 0);
            }
        }

        return $result;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Order>
     */
    public function queueOrders(): \Illuminate\Support\Collection
    {
        $query = $this->ordersForCurrentLocation()
            ->with(['customer', 'items', 'productionLogs'])
            ->whereIn('status', array_keys(array_filter(
                $this->queueStatusOptions(),
                fn (string $key): bool => $key !== 'all',
                ARRAY_FILTER_USE_KEY,
            )));

        if ($this->selectedStatus !== 'all' && array_key_exists($this->selectedStatus, $this->queueStatusOptions())) {
            $query->where('status', $this->selectedStatus);
        }

        return $query
            ->orderBy('due_at')
            ->orderBy('order_date')
            ->limit(100)
            ->get();
    }

    public function selectedOrder(): ?Order
    {
        if ($this->selectedOrderId === null) {
            return null;
        }

        return $this->ordersForCurrentLocation()
            ->with(['customer', 'items', 'productionLogs.worker'])
            ->find($this->selectedOrderId);
    }

    /**
     * @return array<int, User>
     */
    public function workerOptions(): array
    {
        $branch = $this->currentBranch();

        return User::query()
            ->where('tenant_id', $branch->tenant_id)
            ->where('role', 'operator')
            ->where('is_active', true)
            ->whereHas('branches', fn (Builder $query): Builder => $query->whereKey($branch->getKey()))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * @return list<OrderStatus>
     */
    public function availableTransitions(Order $order): array
    {
        $role = Auth::user()->role;

        return array_values(array_filter(
            OrderStatus::cases(),
            fn (OrderStatus $status): bool => $status !== OrderStatus::Cancelled
                && $order->status->canMoveTo($status)
                && $status->canBeSetByRole($role),
        ));
    }

    public function activeProductionLog(Order $order): ?ProductionLog
    {
        return $order->productionLogs
            ->whereNull('finished_at')
            ->sortByDesc('started_at')
            ->first();
    }

    public function formatElapsedTime(ProductionLog $log): string
    {
        $seconds = max(0, (int) $log->started_at->diffInSeconds(now()));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    public function currentBranchName(): string
    {
        return $this->currentBranch()->name;
    }

    private function currentBranch(): Branch
    {
        $branch = Filament::getTenant();

        abort_unless($branch instanceof Branch, 404);

        return $branch;
    }

    private function ordersForCurrentLocation(): Builder
    {
        $branch = $this->currentBranch();

        $query = Order::query()
            ->where('tenant_id', $branch->tenant_id);

        if ($branch->is_workshop) {
            return $query->where('workshop_id', $branch->getKey());
        }

        return $query->where('branch_id', $branch->getKey());
    }
}

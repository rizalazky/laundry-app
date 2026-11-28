<?php

namespace App\Filament\App\Widgets;

use App\Enums\OrderStatus;
use App\Models\OrderStatusLog;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RecentOrderActivity extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Aktivitas Pesanan Terkini';

    public static function canView(): bool
    {
        $user = static::authenticatedActor();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && $user->hasPermissionTo('dashboard.view');
    }

    public function hydrate(): void
    {
        abort_unless(static::canView(), 403);
    }

    private static function authenticatedActor(): ?User
    {
        $authenticatedUserId = Auth::id();

        return $authenticatedUserId === null
            ? null
            : User::query()->find($authenticatedUserId);
    }

    public function table(Table $table): Table
    {
        $user = static::authenticatedActor();

        abort_unless(
            $user !== null
                && $user->is_active
                && $user->tenant_id !== null
                && $user->hasPermissionTo('dashboard.view'),
            403,
        );

        $query = OrderStatusLog::query()
            ->with(['order.customer', 'user'])
            ->whereHas('order', function (Builder $orders) use ($user): void {
                $orders->where('tenant_id', $user?->tenant_id);

                if ($user !== null && $user->role !== 'tenant_owner') {
                    $branchIds = $user->branches()
                        ->where('branches.tenant_id', $user->tenant_id)
                        ->where('branches.is_active', true)
                        ->select('branches.id');

                    $orders->whereIn('branch_id', $branchIds);
                }
            });

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('order.invoice_no')
                    ->label('No. Nota')
                    ->searchable(),

                TextColumn::make('order.customer.name')
                    ->label('Pelanggan')
                    ->placeholder('—'),

                TextColumn::make('to_status')
                    ->label('Status Terbaru')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus|string|null $state): string => $state instanceof OrderStatus
                        ? $state->label()
                        : (string) ($state ?? '—'))
                    ->color(fn (OrderStatus|string|null $state): string => $state instanceof OrderStatus
                        ? $state->color()
                        : 'gray'),

                TextColumn::make('user.name')
                    ->label('Diperbarui Oleh')
                    ->placeholder('Sistem'),

                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Belum ada aktivitas pesanan')
            ->emptyStateDescription('Perubahan status pesanan akan muncul di sini.');
    }
}

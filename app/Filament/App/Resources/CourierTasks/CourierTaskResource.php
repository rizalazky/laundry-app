<?php

namespace App\Filament\App\Resources\CourierTasks;

use App\Enums\CourierTaskStatus;
use App\Filament\App\Resources\CourierTasks\Pages\ListCourierTasks;
use App\Models\CourierTask;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class CourierTaskResource extends Resource
{
    protected static ?string $model = CourierTask::class;
    protected static bool $isScopedToTenant = false;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';
    protected static string|UnitEnum|null $navigationGroup = 'Operasional';
    protected static ?int $navigationSort = 30;
    protected static ?string $navigationLabel = 'Tugas Kurir';
    protected static ?string $modelLabel = 'Tugas Kurir';
    protected static ?string $pluralModelLabel = 'Tugas Kurir';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null
            && $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager', 'courier'], true)
            && $user->hasPermissionTo('courier_tasks.view');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Auth::user();
        $query = parent::getEloquentQuery()
            ->where('tenant_id', $user?->tenant_id)
            ->with(['order.customer', 'order.branch', 'courier']);

        if ($user?->role === 'tenant_owner') {
            return $query;
        }

        if ($user?->role === 'branch_manager') {
            $branchIds = $user->branches()
                ->where('branches.tenant_id', $user->tenant_id)
                ->where('branches.is_active', true)
                ->pluck('branches.id');

            return $query->whereHas('order', fn (Builder $orders) => $orders->whereIn('branch_id', $branchIds));
        }

        return $query->where('courier_id', $user?->getKey());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order.invoice_no')
                    ->label('No. Nota')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('task_type')
                    ->label('Jenis Tugas')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? '—'),

                TextColumn::make('order.customer.name')
                    ->label('Pelanggan')
                    ->searchable(),

                TextColumn::make('courier.name')
                    ->label('Kurir')
                    ->placeholder('—'),

                TextColumn::make('address')
                    ->label('Alamat')
                    ->limit(45)
                    ->tooltip(fn (CourierTask $record): string => $record->address),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (CourierTaskStatus $state): string => $state->color())
                    ->formatStateUsing(fn (CourierTaskStatus $state): string => $state->label()),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('completed_at')
                    ->label('Selesai')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('task_type')
                    ->label('Jenis Tugas')
                    ->options([
                        'pickup' => 'Pickup',
                        'delivery' => 'Delivery',
                    ]),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(CourierTaskStatus::cases())
                        ->mapWithKeys(fn (CourierTaskStatus $status): array => [$status->value => $status->label()])
                        ->all()),
            ])
            ->recordActions([
                Action::make('start')
                    ->label('Mulai')
                    ->icon('heroicon-o-play')
                    ->color('info')
                    ->visible(fn (CourierTask $record): bool => $record->status === CourierTaskStatus::Assigned
                        && (int) $record->courier_id === (int) Auth::id()
                        && Auth::user()?->hasPermissionTo('courier_tasks.update') === true)
                    ->action(fn (CourierTask $record) => self::updateStatus($record, CourierTaskStatus::InProgress)),

                Action::make('complete')
                    ->label('Selesaikan')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CourierTask $record): bool => $record->status === CourierTaskStatus::InProgress
                        && (int) $record->courier_id === (int) Auth::id()
                        && Auth::user()?->hasPermissionTo('courier_tasks.update') === true)
                    ->action(fn (CourierTask $record) => self::updateStatus($record, CourierTaskStatus::Completed)),

                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (CourierTask $record): bool => in_array(Auth::user()?->role, ['tenant_owner', 'branch_manager'], true)
                        && Auth::user()?->hasPermissionTo('courier_tasks.manage') === true
                        && ! in_array($record->status, [CourierTaskStatus::Completed, CourierTaskStatus::Cancelled], true))
                    ->action(fn (CourierTask $record) => self::updateStatus($record, CourierTaskStatus::Cancelled)),
            ]);
    }

    private static function updateStatus(CourierTask $record, CourierTaskStatus $status): void
    {
        try {
            app(\App\Actions\CourierTasks\UpdateCourierTaskStatus::class)
                ->handle($record, $status, Auth::user());
            Notification::make()->title('Status tugas kurir diperbarui.')->success()->send();
        } catch (\DomainException $exception) {
            Notification::make()->title('Gagal memperbarui tugas.')->body($exception->getMessage())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourierTasks::route('/'),
        ];
    }
}

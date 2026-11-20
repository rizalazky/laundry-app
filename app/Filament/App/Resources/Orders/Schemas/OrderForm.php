<?php

namespace App\Filament\App\Resources\Orders\Schemas;

use App\Enums\PaymentMethod;
use App\Enums\ServiceUnit;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        $tenantId = Auth::user()?->tenant_id;

        return $schema
            ->components([
                Section::make('Pelanggan')
                    ->schema([
                        Select::make('customer_id')
                            ->label('Pelanggan')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => Customer::query()
                                ->where('tenant_id', $tenantId)
                                ->where(fn ($query) => $query
                                    ->where('name', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%"))
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Customer $customer): array => [$customer->getKey() => "{$customer->name} ({$customer->phone})"])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Customer::query()
                                ->where('tenant_id', $tenantId)
                                ->find($value)
                                ?->name),
                    ]),

                Section::make('Layanan')
                    ->schema([
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Tambah layanan')
                            ->columns(2)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshTotals($get, $set))
                            ->schema([
                                Select::make('service_id')
                                    ->label('Layanan')
                                    ->options(fn (): array => Service::query()
                                        ->where('tenant_id', $tenantId)
                                        ->where('is_active', true)
                                        ->orderBy('name')
                                        ->get()
                                        ->mapWithKeys(fn (Service $service): array => [$service->getKey() => "{$service->name} — {$service->unit->label()}"])
                                        ->all())
                                    ->required()
                                    ->searchable()
                                    ->native(false)
                                    ->live(),

                                TextInput::make('quantity')
                                    ->label('Berat / Jumlah')
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->maxValue(99999)
                                    ->step(fn (Get $get): int|float => self::isPieceService($get('service_id'), $tenantId) ? 1 : 0.01)
                                    ->rules(fn (Get $get): array => self::isPieceService($get('service_id'), $tenantId) ? ['integer'] : [])
                                    ->required()
                                    ->live(onBlur: true),
                            ]),
                    ]),

                Section::make('Tagihan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('estimated_subtotal')
                            ->label('Subtotal')
                            ->prefix('Rp')
                            ->disabled()
                            ->dehydrated(false)
                            ->default('0'),

                        TextInput::make('discount')
                            ->label('Diskon')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->prefix('Rp')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshTotals($get, $set)),

                        TextInput::make('estimated_total')
                            ->label('Total')
                            ->prefix('Rp')
                            ->disabled()
                            ->dehydrated(false)
                            ->default('0'),

                        DateTimePicker::make('due_at')
                            ->label('Estimasi Selesai')
                            ->helperText('Kosongkan untuk memakai estimasi layanan terlama.'),
                    ]),

                Section::make('Pembayaran Awal (DP)')
                    ->description('Opsional. Sisa tagihan bisa dibayar bertahap setelah order dibuat.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('down_payment')
                            ->label('Nominal DP')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('Rp')
                            ->live(onBlur: true),

                        Select::make('payment_method')
                            ->label('Metode')
                            ->options(PaymentMethod::options())
                            ->native(false)
                            ->requiredWith('down_payment'),

                        TextInput::make('payment_reference')
                            ->label('No. Referensi')
                            ->maxLength(255),
                    ]),

                Section::make('Catatan')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Catatan Order')
                            ->rows(3)
                            ->maxLength(1000),
                    ]),
            ]);
    }

    private static function isPieceService(mixed $serviceId, ?int $tenantId): bool
    {
        if (blank($serviceId) || $tenantId === null) {
            return false;
        }

        return Service::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($serviceId)
            ->value('unit') === ServiceUnit::Piece->value;
    }

    /**
     * Preview the order total from the current branch prices. The server
     * recalculates everything again when the order is created.
     */
    private static function refreshTotals(Get $get, Set $set): void
    {
        $branch = Filament::getTenant();
        $subtotal = BigDecimal::zero();

        foreach ($get('items') ?? [] as $item) {
            if (blank($item['service_id'] ?? null) || ! is_numeric($item['quantity'] ?? null)) {
                continue;
            }

            $service = Service::query()
                ->where('tenant_id', Auth::user()?->tenant_id)
                ->where('is_active', true)
                ->with('branchPrices')
                ->find($item['service_id']);

            if ($service === null || ! $branch instanceof Branch) {
                continue;
            }

            $normalizedQuantity = BigDecimal::of((string) $item['quantity'])
                ->toScale(2, RoundingMode::HalfUp);

            $subtotal = $subtotal->plus(
                BigDecimal::of($service->priceForBranch($branch))
                    ->multipliedBy($normalizedQuantity)
                    ->toScale(2, RoundingMode::HalfUp)
            );
        }

        $discount = is_numeric($get('discount')) ? BigDecimal::of((string) $get('discount')) : BigDecimal::zero();

        $set('estimated_subtotal', number_format((float) (string) $subtotal, 0, ',', '.'));
        $set('estimated_total', number_format(max(0, (float) (string) $subtotal->minus($discount)), 0, ',', '.'));
    }
}

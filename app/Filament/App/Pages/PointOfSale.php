<?php

namespace App\Filament\App\Pages;

use App\Actions\Orders\CreateOrder as CreateOrderAction;
use App\Actions\Orders\RecordPayment;
use Closure;
use App\Enums\PaymentMethod;
use App\Enums\ServiceUnit;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMembership;
use App\Models\Order;
use App\Models\Service;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;
use BackedEnum;

class PointOfSale extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.app.pages.point-of-sale';

    protected static ?string $slug = 'pos';

    protected static ?string $title = 'Kasir POS';

    protected static ?string $navigationLabel = 'Kasir POS';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?int $navigationSort = 9;

    public array $customerForm = [];

    public ?int $customerId = null;

    public array $cart = [];

    public string $discount = '0';

    public string $voucherCode = '';

    public bool $paymentModalOpen = false;

    public array $paymentLines = [];

    public ?int $lastCreatedOrderId = null;

    public ?int $selectedWorkshopId = null;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->is_active
            && $user->tenant_id !== null
            && in_array($user->role, ['tenant_owner', 'branch_manager', 'cashier'], true)
            && $user->hasPermissionTo('orders.create');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cart')
                ->label('Keranjang')
                ->icon('heroicon-o-shopping-cart')
                ->badge(fn (): int => count($this->cartLines()))
                ->badgeColor('primary')
                ->visible(fn (): bool => $this->selectedCustomer() !== null)
                ->slideOver()
                ->modalHeading('Keranjang Belanja')
                ->modalDescription('Periksa layanan dan total transaksi sebelum melanjutkan pembayaran.')
                ->modalContent(fn (Action $action) => view('filament.app.pages.partials.pos-cart', [
                    'livewire' => $this,
                    'modalId' => "fi-{$this->getId()}-action-{$action->getNestingIndex()}",
                ]))
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->extraAttributes(['class' => 'xl:hidden']),
        ];
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill(['customerId' => null]);
    }

    /**
     * Re-check POS access on every subsequent Livewire request so an open
     * register cannot keep exposing tenant data after access is revoked.
     */
    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customerId')
                    ->label('Pelanggan')
                    ->placeholder('Cari nama atau nomor HP pelanggan...')
                    ->searchable()
                    ->allowHtml()
                    ->required()
                    ->live()
                    ->getSearchResultsUsing(function (string $search): array {
                        $branch = $this->currentBranch();

                        return Customer::query()
                            ->with(['memberships' => $this->activeMembershipsConstraint()])
                            ->where('tenant_id', $branch->tenant_id)
                            ->where(function ($query) use ($search): void {
                                $query->where('name', 'like', "%{$search}%")
                                    ->orWhere('phone', 'like', "%{$search}%");
                            })
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (Customer $customer): array => [
                                $customer->getKey() => $this->customerOptionLabel($customer),
                            ])
                            ->all();
                    })
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (blank($value)) {
                            return null;
                        }

                        $customer = Customer::query()
                            ->with(['memberships' => $this->activeMembershipsConstraint()])
                            ->where('tenant_id', $this->currentBranch()->tenant_id)
                            ->find($value);

                        return $customer ? $this->customerOptionLabel($customer) : null;
                    })
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('Nama Pelanggan')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Nomor HP / WhatsApp')
                            ->tel()
                            ->maxLength(25),
                    ])
                    ->createOptionAction(fn ($action) => $action
                        ->label('Tambah pelanggan baru')
                        ->modalHeading('Pelanggan Baru')
                        ->modalSubmitActionLabel('Simpan Pelanggan'))
                    ->createOptionUsing(fn (array $data): int => $this->createCustomerOption($data))
                    ->afterStateUpdated(fn ($state) => $this->customerId = filled($state) ? (int) $state : null),
            ])
            ->statePath('customerForm');
    }

    /**
     * @return array<int, string>
     */
    public function workshopOptions(): array
    {
        $branch = $this->currentBranch();

        if ($branch->is_workshop) {
            return [];
        }

        return $branch->workshops()
            ->where('branches.is_active', true)
            ->orderBy('branches.name')
            ->pluck('branches.name', 'branches.id')
            ->all();
    }

    public function currentBranchName(): string
    {
        return $this->currentBranch()->name;
    }

    public function serviceCatalog(): \Illuminate\Support\Collection
    {
        $branch = $this->currentBranch();

        return Service::query()
            ->where('tenant_id', $branch->tenant_id)
            ->where('is_active', true)
            ->with(['branchPrices' => fn ($query) => $query->where('branch_id', $branch->getKey())])
            ->orderBy('name')
            ->get();
    }

    public function selectedCustomer(): ?Customer
    {
        if ($this->customerId === null) {
            return null;
        }

        return Customer::query()
            ->with(['memberships' => $this->activeMembershipsConstraint()])
            ->where('tenant_id', $this->currentBranch()->tenant_id)
            ->find($this->customerId);
    }

    private function activeMembershipsConstraint(): Closure
    {
        return fn ($query) => $query
            ->where('status', CustomerMembership::STATUS_ACTIVE)
            ->where('started_at', '<=', now())
            ->where('expired_at', '>', now())
            ->with('memberTier')
            ->orderByDesc('started_at');
    }

    private function customerOptionLabel(Customer $customer): string
    {
        $membership = $customer->memberships->first();
        $memberTierName = $membership?->memberTier?->name;
        $membershipBadge = filled($memberTierName)
            ? '<span class="inline-flex w-fit rounded-full bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-400/10 dark:text-primary-300">'.e($memberTierName).' Member</span>'
            : '';

        $name = e($customer->name);
        $phone = e($customer->phone ?: 'Nomor HP belum diisi');

        return <<<HTML
            <div class="flex flex-col gap-1">
                <span class="font-medium">{$name}</span>
                <span class="text-xs text-gray-500">{$phone}</span>
                {$membershipBadge}
            </div>
            HTML;
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::query()
            ->where('tenant_id', $this->currentBranch()->tenant_id)
            ->findOrFail($customerId);

        $this->customerId = (int) $customer->getKey();
        $this->customerForm['customerId'] = $this->customerId;
    }

    public function createCustomerOption(array $data): int
    {
        abort_unless(static::canAccess(), 403);

        $data = validator($data, [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:25'],
        ])->validate();

        $customer = Customer::query()->create([
            'tenant_id' => $this->currentBranch()->tenant_id,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
        ]);

        $this->customerId = (int) $customer->getKey();
        $this->customerForm['customerId'] = $this->customerId;

        return (int) $customer->getKey();
    }

    private function ensureCustomerSelected(): void
    {
        abort_if($this->selectedCustomer() === null, 403, 'Pilih pelanggan terlebih dahulu.');
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
        $this->customerForm['customerId'] = null;
    }

    public function addToCart(int $serviceId): void
    {
        $this->ensureCustomerSelected();

        $service = $this->availableService($serviceId);
        $key = (string) $service->getKey();

        if (! isset($this->cart[$key])) {
            $this->cart[$key] = '1';
        } else {
            $this->cart[$key] = (string) BigDecimal::of((string) $this->cart[$key])
                ->plus($service->unit === ServiceUnit::Piece ? '1' : '0.5')
                ->toScale(2);
        }
    }

    public function updateQuantity(int $serviceId, mixed $quantity): void
    {
        $this->ensureCustomerSelected();

        $service = $this->availableService($serviceId);
        $value = is_scalar($quantity) ? trim((string) $quantity) : '';

        if ($value === '' || ! is_numeric($value) || (float) $value <= 0) {
            unset($this->cart[(string) $serviceId]);

            return;
        }

        if ($service->unit === ServiceUnit::Piece && (float) $value !== (float) (int) $value) {
            throw ValidationException::withMessages([
                'cart.'.$serviceId => 'Layanan PCS harus menggunakan jumlah bulat.',
            ]);
        }

        $this->cart[(string) $serviceId] = $value;
    }

    public function removeFromCart(int $serviceId): void
    {
        $this->ensureCustomerSelected();

        unset($this->cart[(string) $serviceId]);
    }

    public function cartLines(): array
    {
        $branch = $this->currentBranch();

        return Service::query()
            ->where('tenant_id', $branch->tenant_id)
            ->where('is_active', true)
            ->whereIn('id', array_map('intval', array_keys($this->cart)))
            ->with(['branchPrices' => fn ($query) => $query->where('branch_id', $branch->getKey())])
            ->get()
            ->map(function (Service $service): array {
                $quantity = BigDecimal::of((string) ($this->cart[(string) $service->getKey()] ?? '0'))
                    ->toScale(2, RoundingMode::HalfUp);
                $price = BigDecimal::of($service->priceForBranch($this->currentBranch()))->toScale(2);
                $subtotal = $price->multipliedBy($quantity)->toScale(2);

                return [
                    'id' => (int) $service->getKey(),
                    'name' => $service->name,
                    'unit' => $service->unit->value,
                    'unit_label' => $service->unit->label(),
                    'quantity' => (string) $quantity,
                    'price' => (string) $price,
                    'subtotal' => (string) $subtotal,
                ];
            })
            ->all();
    }

    public function subtotal(): string
    {
        return (string) collect($this->cartLines())
            ->reduce(fn (BigDecimal $total, array $line): BigDecimal => $total->plus($line['subtotal']), BigDecimal::zero())
            ->toScale(2);
    }

    public function discountAmount(): string
    {
        if (! is_numeric($this->discount)) {
            return '0.00';
        }

        return (string) BigDecimal::of($this->discount)->toScale(2, RoundingMode::HalfUp);
    }

    public function membershipDiscountDetails(): ?array
    {
        $customer = $this->selectedCustomer();
        if ($customer === null) {
            return null;
        }

        $membership = $customer->memberships->first();
        $tier = $membership?->memberTier;

        if ($membership === null || $tier === null) {
            return null;
        }

        return [
            'membership_id' => (int) $membership->getKey(),
            'tier_id' => (int) $tier->getKey(),
            'tier_name' => $tier->name,
            'discount_percent' => (string) $tier->discount_percent,
        ];
    }

    public function membershipDiscountAmount(): string
    {
        $details = $this->membershipDiscountDetails();
        if ($details === null) {
            return '0.00';
        }

        $percent = BigDecimal::of($details['discount_percent']);
        return (string) BigDecimal::of($this->subtotal())
            ->multipliedBy($percent)
            ->dividedBy(100, 2, RoundingMode::HalfUp);
    }

    public function voucherPreview(): ?array
    {
        if (blank($this->voucherCode) || BigDecimal::of($this->subtotal())->isZero()) {
            return null;
        }

        try {
            $promotion = app(\App\Actions\Orders\ResolveOrderPromotion::class)->handle(
                tenantId: (int) $this->currentBranch()->tenant_id,
                voucherCode: $this->voucherCode,
                subtotal: BigDecimal::of($this->subtotal()),
            );

            return [
                'promo_name' => $promotion['promo']->name,
                'voucher_code' => $promotion['voucher']->code,
                'discount' => (string) $promotion['discount'],
            ];
        } catch (DomainException) {
            return null;
        }
    }

    public function voucherPreviewError(): ?string
    {
        if (blank($this->voucherCode) || BigDecimal::of($this->subtotal())->isZero()) {
            return null;
        }

        try {
            app(\App\Actions\Orders\ResolveOrderPromotion::class)->handle(
                tenantId: (int) $this->currentBranch()->tenant_id,
                voucherCode: $this->voucherCode,
                subtotal: BigDecimal::of($this->subtotal()),
            );

            return null;
        } catch (DomainException $exception) {
            return $exception->getMessage();
        }
    }

    public function promoDiscountAmount(): string
    {
        return $this->voucherPreview()['discount'] ?? '0.00';
    }

    public function totalDiscountAmount(): string
    {
        return (string) BigDecimal::of($this->discountAmount())
            ->plus($this->membershipDiscountAmount())
            ->plus($this->promoDiscountAmount())
            ->toScale(2, RoundingMode::HalfUp);
    }

    public function grandTotal(): string
    {
        $subtotal = BigDecimal::of($this->subtotal());
        $discount = BigDecimal::of($this->totalDiscountAmount());

        return (string) ($discount->isGreaterThan($subtotal) || $discount->isLessThan(0)
            ? BigDecimal::zero()
            : $subtotal->minus($discount))->toScale(2);
    }

    public function paymentTotal(): string
    {
        return (string) collect($this->paymentLines)
            ->reduce(function (BigDecimal $total, array $line): BigDecimal {
                $amount = $line['amount'] ?? 0;

                return is_numeric($amount) ? $total->plus((string) $amount) : $total;
            }, BigDecimal::zero())
            ->toScale(2, RoundingMode::HalfUp);
    }

    public function paymentRemaining(): string
    {
        return (string) BigDecimal::of($this->grandTotal())
            ->minus($this->paymentTotal())
            ->toScale(2);
    }

    public function openPaymentModal(?string $cartModalId = null): void
    {
        $this->ensureCustomerSelected();

        if ($this->cart === []) {
            Notification::make()->title('Keranjang masih kosong')->warning()->send();

            return;
        }

        $this->paymentLines = BigDecimal::of($this->grandTotal())->isZero()
            ? []
            : [[
                'amount' => $this->grandTotal(),
                'method' => PaymentMethod::Cash->value,
                'reference' => '',
            ]];

        $this->paymentModalOpen = true;

        if (filled($cartModalId)) {
            $this->dispatch('close-modal', id: $cartModalId);
        }
    }

    public function addPaymentLine(): void
    {
        $this->paymentLines[] = [
            'amount' => max(0, (float) $this->paymentRemaining()),
            'method' => PaymentMethod::Cash->value,
            'reference' => '',
        ];
    }

    public function removePaymentLine(int $index): void
    {
        unset($this->paymentLines[$index]);
        $this->paymentLines = array_values($this->paymentLines);
    }

    public function closePaymentModal(): void
    {
        $this->paymentModalOpen = false;
    }

    public function createOrder(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->validate([
            'customerId' => ['required', 'integer'],
            'cart' => ['required', 'array', 'min:1'],
            'cart.*' => ['required', 'numeric', 'gt:0'],
            'discount' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'voucherCode' => ['nullable', 'string', 'max:100'],
            'selectedWorkshopId' => ['nullable', 'integer'],
            'paymentLines' => ['array'],
            'paymentLines.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'paymentLines.*.method' => ['required', 'in:'.implode(',', array_keys(PaymentMethod::options()))],
            'paymentLines.*.reference' => ['nullable', 'string', 'max:255'],
        ]);

        if (count($this->workshopOptions()) > 1 && $this->selectedWorkshopId === null) {
            throw ValidationException::withMessages([
                'selectedWorkshopId' => 'Pilih workshop tujuan untuk order ini.',
            ]);
        }

        $branch = $this->currentBranch();
        $customer = $this->selectedCustomer();

        if ($customer === null) {
            throw ValidationException::withMessages(['customerId' => 'Pilih pelanggan terlebih dahulu.']);
        }

        $lines = $this->cartLines();

        if ($lines === []) {
            throw ValidationException::withMessages(['cart' => 'Tambahkan minimal satu layanan aktif ke keranjang.']);
        }

        foreach ($lines as $line) {
            if ($line['unit'] === ServiceUnit::Piece->value && (float) $line['quantity'] !== (float) (int) $line['quantity']) {
                throw ValidationException::withMessages([
                    'cart.'.$line['id'] => 'Layanan PCS harus menggunakan jumlah bulat.',
                ]);
            }
        }

        $subtotal = BigDecimal::of($this->subtotal());
        $discount = BigDecimal::of($this->discountAmount());
        $total = BigDecimal::of($this->grandTotal());
        $paymentTotal = BigDecimal::of($this->paymentTotal());

        if ($discount->isGreaterThan($subtotal)) {
            throw ValidationException::withMessages(['discount' => 'Diskon tidak boleh melebihi subtotal.']);
        }

        if ($paymentTotal->isGreaterThan($total)) {
            throw ValidationException::withMessages(['paymentLines' => 'Total pembayaran melebihi total tagihan.']);
        }

        if (
            $paymentTotal->isGreaterThan(0)
            && $paymentTotal->isLessThan($total)
            && ! $branch->tenant->allowsPartialPayment()
        ) {
            throw ValidationException::withMessages(['paymentLines' => 'Pembayaran sebagian dinonaktifkan.']);
        }

        try {
            $order = DB::transaction(function () use ($branch, $customer, $lines, $discount): Order {
                $order = app(CreateOrderAction::class)->handle(
                    branch: $branch,
                    user: Auth::user(),
                    customerId: (int) $customer->getKey(),
                    items: array_map(
                        fn (array $line): array => [
                            'service_id' => $line['id'],
                            'quantity' => $line['quantity'],
                        ],
                        $lines,
                    ),
                    discount: (string) $discount,
                    voucherCode: filled($this->voucherCode) ? $this->voucherCode : null,
                    workshopId: $this->selectedWorkshopId,
                );

                foreach ($this->paymentLines as $line) {
                    if (BigDecimal::of((string) $line['amount'])->isGreaterThan(0)) {
                        app(RecordPayment::class)->handle(
                            order: $order,
                            amount: $line['amount'],
                            method: PaymentMethod::from($line['method']),
                            user: Auth::user(),
                            reference: filled($line['reference'] ?? null) ? $line['reference'] : null,
                            notes: 'Pembayaran melalui Kasir POS',
                            idempotencyKey: (string) Str::uuid(),
                        );
                    }
                }

                return $order->fresh();
            });
        } catch (DomainException $exception) {
            Notification::make()
                ->title('Order gagal dibuat')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->lastCreatedOrderId = (int) $order->getKey();
        $this->cart = [];
        $this->discount = '0';
        $this->voucherCode = '';
        $this->customerId = null;
        $this->customerForm = ['customerId' => null];
        $this->paymentLines = [];
        $this->paymentModalOpen = false;

        Notification::make()
            ->title('Order berhasil dibuat')
            ->body($order->invoice_no.' · Total Rp '.number_format((float) $order->total, 0, ',', '.'))
            ->success()
            ->send();
    }

    public function lastCreatedOrder(): ?Order
    {
        if ($this->lastCreatedOrderId === null) {
            return null;
        }

        $branch = $this->currentBranch();

        return Order::query()
            ->where('tenant_id', $branch->tenant_id)
            ->where('branch_id', $branch->getKey())
            ->find($this->lastCreatedOrderId);
    }

    public function receiptUrl(Order $order): string
    {
        abort_unless(
            (int) $order->tenant_id === (int) $this->currentBranch()->tenant_id
            && (int) $order->branch_id === (int) $this->currentBranch()->getKey(),
            404,
        );

        return route('orders.receipt', $order);
    }

    public function paymentMethods(): array
    {
        return PaymentMethod::options();
    }

    private function availableService(int $serviceId): Service
    {
        return Service::query()
            ->where('tenant_id', $this->currentBranch()->tenant_id)
            ->where('is_active', true)
            ->findOrFail($serviceId);
    }

    private function currentBranch(): Branch
    {
        $branch = Filament::getTenant();

        abort_unless($branch instanceof Branch, 404);

        return $branch;
    }
}

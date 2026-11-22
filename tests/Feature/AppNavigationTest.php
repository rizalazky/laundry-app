<?php

namespace Tests\Feature;

use App\Filament\App\Pages\AccountingReports;
use App\Filament\App\Pages\ManualJournal;
use App\Filament\App\Resources\BankAccounts\BankAccountResource;
use App\Filament\App\Resources\CashSettlements\CashSettlementResource;
use App\Filament\App\Resources\ChartOfAccounts\ChartOfAccountResource;
use App\Filament\App\Resources\EdcMachines\EdcMachineResource;
use App\Filament\App\Pages\RolePermissions;
use App\Filament\App\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\App\Resources\Attendances\AttendanceResource;
use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\CustomerMemberships\CustomerMembershipResource;
use App\Filament\App\Resources\MemberTiers\MemberTierResource;
use App\Filament\App\Resources\Promos\PromoResource;
use App\Filament\App\Resources\Vouchers\VoucherResource;
use App\Filament\App\Resources\PointLogs\PointLogResource;
use App\Filament\App\Resources\PointRewards\PointRewardResource;
use App\Filament\App\Resources\Raffles\RaffleResource;
use App\Filament\App\Resources\RaffleTickets\RaffleTicketResource;
use App\Filament\App\Resources\EmployeeLoans\EmployeeLoanResource;
use App\Filament\App\Resources\InventoryItems\InventoryItemResource;
use App\Filament\App\Resources\InventoryUnits\InventoryUnitResource;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Filament\App\Resources\Services\ServiceResource;
use App\Filament\App\Resources\StockMovements\StockMovementResource;
use App\Filament\App\Resources\StockOpnames\StockOpnameResource;
use App\Filament\App\Resources\StockTransfers\StockTransferResource;
use App\Filament\App\Resources\Suppliers\SupplierResource;
use App\Filament\App\Resources\Users\UserResource;
use Tests\TestCase;

class AppNavigationTest extends TestCase
{
    public function test_implemented_modules_are_assigned_to_their_owner_dashboard_navigation_groups(): void
    {
        $this->assertSame('Operasional', OrderResource::getNavigationGroup());
        $this->assertSame('Operasional', ServiceResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', CustomerResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', MemberTierResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', CustomerMembershipResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', PromoResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', VoucherResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', PointLogResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', PointRewardResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', RaffleResource::getNavigationGroup());
        $this->assertSame('Pelanggan & CRM', RaffleTicketResource::getNavigationGroup());

        $this->assertSame('Karyawan & SDM', UserResource::getNavigationGroup());
        $this->assertSame('Karyawan & SDM', AttendanceResource::getNavigationGroup());
        $this->assertSame('Karyawan & SDM', EmployeeLoanResource::getNavigationGroup());
        $this->assertSame('Karyawan & SDM', ActivityLogResource::getNavigationGroup());
        $this->assertSame('Karyawan & SDM', RolePermissions::getNavigationGroup());

        $this->assertSame('Inventori & Distribusi', SupplierResource::getNavigationGroup());
        $this->assertSame('Inventori & Distribusi', InventoryItemResource::getNavigationGroup());
        $this->assertSame('Inventori & Distribusi', InventoryUnitResource::getNavigationGroup());
        $this->assertSame('Inventori & Distribusi', StockMovementResource::getNavigationGroup());
        $this->assertSame('Inventori & Distribusi', StockTransferResource::getNavigationGroup());
        $this->assertSame('Inventori & Distribusi', StockOpnameResource::getNavigationGroup());

        $this->assertSame('Keuangan & Laporan', AccountingReports::getNavigationGroup());
        $this->assertSame('Keuangan & Laporan', ChartOfAccountResource::getNavigationGroup());
        $this->assertSame('Keuangan & Laporan', BankAccountResource::getNavigationGroup());
        $this->assertSame('Keuangan & Laporan', EdcMachineResource::getNavigationGroup());
        $this->assertSame('Keuangan & Laporan', CashSettlementResource::getNavigationGroup());
        $this->assertSame('Keuangan & Laporan', ManualJournal::getNavigationGroup());
    }
}

<?php

namespace Tests\Feature;

use App\Filament\App\Pages\AccountingReports;
use App\Filament\App\Pages\RolePermissions;
use App\Filament\App\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\App\Resources\Attendances\AttendanceResource;
use App\Filament\App\Resources\Customers\CustomerResource;
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
    }
}

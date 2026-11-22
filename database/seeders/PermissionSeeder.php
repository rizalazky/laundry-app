<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['name' => 'Lihat Dashboard', 'slug' => 'dashboard.view', 'group' => 'Dashboard'],
            ['name' => 'Lihat Pesanan', 'slug' => 'orders.view', 'group' => 'Pesanan'],
            ['name' => 'Buat Pesanan', 'slug' => 'orders.create', 'group' => 'Pesanan'],
            ['name' => 'Ubah Status Pesanan', 'slug' => 'orders.update', 'group' => 'Pesanan'],
            ['name' => 'Catat Pembayaran Pesanan', 'slug' => 'orders.payment', 'group' => 'Pesanan'],
            ['name' => 'Refund Pesanan', 'slug' => 'orders.refund', 'group' => 'Pesanan'],
            ['name' => 'Batalkan Pesanan', 'slug' => 'orders.cancel', 'group' => 'Pesanan'],
            ['name' => 'Lihat Pelanggan', 'slug' => 'customers.view', 'group' => 'Pelanggan'],
            ['name' => 'Kelola Pelanggan', 'slug' => 'customers.manage', 'group' => 'Pelanggan'],
            ['name' => 'Lihat Membership Pelanggan', 'slug' => 'memberships.view', 'group' => 'Pelanggan'],
            ['name' => 'Kelola Membership Pelanggan', 'slug' => 'memberships.manage', 'group' => 'Pelanggan'],
            ['name' => 'Lihat Promo dan Voucher', 'slug' => 'promotions.view', 'group' => 'Promosi'],
            ['name' => 'Kelola Promo dan Voucher', 'slug' => 'promotions.manage', 'group' => 'Promosi'],
            ['name' => 'Lihat Poin dan Hadiah', 'slug' => 'loyalty.view', 'group' => 'Promosi'],
            ['name' => 'Kelola Poin dan Hadiah', 'slug' => 'loyalty.manage', 'group' => 'Promosi'],
            ['name' => 'Lihat Undian dan Tiket', 'slug' => 'raffles.view', 'group' => 'Promosi'],
            ['name' => 'Kelola Undian dan Tiket', 'slug' => 'raffles.manage', 'group' => 'Promosi'],
            ['name' => 'Lihat Layanan', 'slug' => 'services.view', 'group' => 'Layanan'],
            ['name' => 'Kelola Layanan', 'slug' => 'services.manage', 'group' => 'Layanan'],
            ['name' => 'Lihat Karyawan', 'slug' => 'staff.view', 'group' => 'Karyawan'],
            ['name' => 'Kelola Karyawan', 'slug' => 'staff.manage', 'group' => 'Karyawan'],
            ['name' => 'Lihat Cabang', 'slug' => 'branches.view', 'group' => 'Cabang'],
            ['name' => 'Kelola Cabang', 'slug' => 'branches.manage', 'group' => 'Cabang'],
            ['name' => 'Lihat Inventori', 'slug' => 'inventory.view', 'group' => 'Inventori'],
            ['name' => 'Kelola Inventori', 'slug' => 'inventory.manage', 'group' => 'Inventori'],
            ['name' => 'Lihat Mutasi dan Operasi Stok', 'slug' => 'stock.view', 'group' => 'Inventori'],
            ['name' => 'Kelola Mutasi dan Operasi Stok', 'slug' => 'stock.manage', 'group' => 'Inventori'],
            ['name' => 'Lihat Antrean Workshop', 'slug' => 'workshop.queue.view', 'group' => 'Workshop'],
            ['name' => 'Ubah Status Pengerjaan', 'slug' => 'workshop.queue.update', 'group' => 'Workshop'],
            ['name' => 'Lihat Laporan', 'slug' => 'reports.view', 'group' => 'Laporan'],
            ['name' => 'Lihat Keuangan', 'slug' => 'finance.view', 'group' => 'Keuangan'],
            ['name' => 'Kelola Keuangan', 'slug' => 'finance.manage', 'group' => 'Keuangan'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission['slug']],
                $permission,
            );
        }
    }
}

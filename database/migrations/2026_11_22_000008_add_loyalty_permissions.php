<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        ['name' => 'Lihat Poin dan Hadiah', 'slug' => 'loyalty.view', 'group' => 'Promosi'],
        ['name' => 'Kelola Poin dan Hadiah', 'slug' => 'loyalty.manage', 'group' => 'Promosi'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('slug', array_column(self::PERMISSIONS, 'slug'))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};

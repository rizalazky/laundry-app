<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        DB::table('plans')->insertOrIgnore([
            'code' => 'starter',
            'name' => 'Starter',
            'description' => 'Default plan used for the initial trial. Pricing and entitlements can be configured by the platform administrator.',
            'monthly_price' => 0,
            'yearly_price' => null,
            'currency' => 'IDR',
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            'trial_enabled' => true,
            'trial_days' => 30,
            'grace_period_days' => 3,
            'default_plan_code' => 'starter',
            'registration_enabled' => true,
        ] as $key => $value) {
            DB::table('platform_settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach ([
            'trial_enabled' => true,
            'trial_days' => 30,
            'grace_period_days' => 3,
            'default_plan_code' => 'starter',
            'registration_enabled' => true,
        ] as $key => $value) {
            DB::table('platform_settings')
                ->where('key', $key)
                ->where('value', json_encode($value, JSON_THROW_ON_ERROR))
                ->delete();
        }

        DB::table('plans')
            ->where('code', 'starter')
            ->where('name', 'Starter')
            ->where('monthly_price', 0)
            ->where('description', 'Default plan used for the initial trial. Pricing and entitlements can be configured by the platform administrator.')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('subscriptions')
                    ->whereColumn('subscriptions.plan_id', 'plans.id');
            })
            ->delete();
    }
};

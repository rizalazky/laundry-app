<?php

namespace Database\\Seeders;

use App\\Models\\Plan;
use App\\Models\\PlatformSetting;
use Illuminate\\Database\\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Seed the initial subscription catalog without overwriting platform edits.
     */
    public function run(): void
    {
        $basic = Plan::query()->where('code', 'basic')->first();

        if ($basic === null) {
            $basic = Plan::query()->where('code', 'starter')->first();

            if ($basic !== null) {
                // Preserve the existing default-trial plan and its foreign keys.
                $basic->forceFill([
                    'code' => 'basic',
                    'name' => 'Basic',
                    'description' => 'Paket awal untuk bisnis laundry yang baru mulai.',
                    'sort_order' => 1,
                ])->save();
            } else {
                $basic = Plan::query()->firstOrCreate(
                    ['code' => 'basic'],
                    [
                        'name' => 'Basic',
                        'description' => 'Paket awal untuk bisnis laundry yang baru mulai.',
                        'monthly_price' => 0,
                        'yearly_price' => null,
                        'currency' => 'IDR',
                        'is_active' => true,
                        'sort_order' => 1,
                    ],
                );
            }
        }

        Plan::query()->firstOrCreate(
            ['code' => 'pro'],
            [
                'name' => 'Pro',
                'description' => 'Paket untuk bisnis laundry yang ingin mengembangkan operasional.',
                'monthly_price' => 0,
                'yearly_price' => null,
                'currency' => 'IDR',
                'is_active' => true,
                'sort_order' => 2,
            ],
        );

        Plan::query()->firstOrCreate(
            ['code' => 'enterprise'],
            [
                'name' => 'Enterprise',
                'description' => 'Paket fleksibel untuk bisnis laundry dengan kebutuhan skala besar.',
                'monthly_price' => 0,
                'yearly_price' => null,
                'currency' => 'IDR',
                'is_active' => true,
                'sort_order' => 3,
            ],
        );

        PlatformSetting::query()->updateOrCreate(
            ['key' => 'default_plan_code'],
            ['value' => 'basic'],
        );
    }
}

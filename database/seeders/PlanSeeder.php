<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Listen to everything. Download a few sounds every day.',
                'price_cents' => 0,
                'currency' => 'USD',
                'interval' => 'month',
                'daily_download_limit' => 5,
                'allows_premium' => false,
                'features' => [
                    'Unlimited previews',
                    '5 downloads per day',
                    'Standard license (credit required)',
                ],
                'sort_order' => 0,
            ],
            [
                'name' => 'Pro Monthly',
                'slug' => 'pro-monthly',
                'description' => 'Unlimited downloads, billed monthly.',
                'price_cents' => 900,
                'currency' => 'USD',
                'interval' => 'month',
                'daily_download_limit' => null,   // null = unlimited
                'allows_premium' => true,
                'features' => [
                    'Unlimited downloads',
                    'Access to premium sounds',
                    'Pro license (no credit required)',
                    'WAV master files',
                ],
                'sort_order' => 1,
            ],
            [
                'name' => 'Pro Yearly',
                'slug' => 'pro-yearly',
                'description' => 'Unlimited downloads, billed yearly. Two months free.',
                'price_cents' => 9000,
                'currency' => 'USD',
                'interval' => 'year',
                'daily_download_limit' => null,
                'allows_premium' => true,
                'features' => [
                    'Everything in Pro Monthly',
                    'Two months free',
                ],
                'sort_order' => 2,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}

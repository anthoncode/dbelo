<?php

namespace Database\Seeders;

use App\Models\License;
use Illuminate\Database\Seeder;

class LicenseSeeder extends Seeder
{
    public function run(): void
    {
        $licenses = [
            [
                'name' => 'dbelo Standard License',
                'slug' => 'dbelo-standard',
                'version' => '1.0',
                'summary' => 'Free to use in personal and commercial projects. Credit to dbelo is required. You may not resell or redistribute the file on its own.',
                'requires_attribution' => true,
                'allows_commercial' => true,
                'allows_derivatives' => true,
            ],
            [
                'name' => 'dbelo Pro License',
                'slug' => 'dbelo-pro',
                'version' => '1.0',
                'summary' => 'Included with an active subscription. Unlimited use in personal and commercial projects, no credit required. You may not resell or redistribute the file on its own.',
                'requires_attribution' => false,
                'allows_commercial' => true,
                'allows_derivatives' => true,
            ],
            [
                'name' => 'CC0 1.0 Public Domain',
                'slug' => 'cc0-1-0',
                'version' => '1.0',
                'summary' => 'No rights reserved. Use it any way you want, with no credit required.',
                'requires_attribution' => false,
                'allows_commercial' => true,
                'allows_derivatives' => true,
                'url' => 'https://creativecommons.org/publicdomain/zero/1.0/',
            ],
            [
                'name' => 'CC BY 4.0',
                'slug' => 'cc-by-4-0',
                'version' => '4.0',
                'summary' => 'Free to use, including commercially, as long as you credit the original author.',
                'requires_attribution' => true,
                'allows_commercial' => true,
                'allows_derivatives' => true,
                'url' => 'https://creativecommons.org/licenses/by/4.0/',
            ],
        ];

        foreach ($licenses as $license) {
            License::updateOrCreate(['slug' => $license['slug']], $license);
        }
    }
}

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
                            'full_text' => 'You may use this sound in personal and commercial productions: videos, films, games, podcasts, applications, advertising and similar works.

You must credit dbelo where credits are shown. A line such as "Sound effects by dbelo.com" is enough.

You may modify, edit, layer and process the sound freely.

You may not resell or redistribute the audio file on its own, modified or not. You may not include it in another sound library, pack or marketplace. You may not register it, or a work consisting mainly of it, with any content identification system. You may not use it to train machine learning or generative audio models.

This licence is granted for the file you downloaded, is perpetual, and survives the cancellation of your account.',
],
            [
                'name' => 'dbelo Pro License',
                'slug' => 'dbelo-pro',
                'version' => '1.0',
                'summary' => 'Included with an active subscription. Unlimited use in personal and commercial projects, no credit required. You may not resell or redistribute the file on its own.',
                'requires_attribution' => false,
                'allows_commercial' => true,
                'allows_derivatives' => true,
                            'full_text' => 'Included with an active dbelo subscription.

You may use this sound in personal and commercial productions without limit and without crediting dbelo.

You may modify, edit, layer and process the sound freely.

You may not resell or redistribute the audio file on its own, modified or not. You may not include it in another sound library, pack or marketplace. You may not register it, or a work consisting mainly of it, with any content identification system. You may not use it to train machine learning or generative audio models.

Files downloaded while your subscription was active remain licensed for the projects you had already used them in, even after the subscription ends. Ending a subscription does not revoke a licence already granted.',
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
                            'full_text' => 'The author has waived all copyright and related rights in this work worldwide, to the extent allowed by law.

You may copy, modify, distribute and use the work, including for commercial purposes, without asking permission and without giving credit.

The work is provided as is, with no warranties of any kind.',
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
                            'full_text' => 'You are free to share and adapt this work, including commercially.

You must give appropriate credit to the original author, provide a link to the licence, and indicate if you made changes. You may do so in any reasonable manner, but not in any way that suggests the author endorses you or your use.

You may not apply legal terms or technological measures that legally restrict others from doing anything the licence permits.

The work is provided as is, with no warranties of any kind.',
],
        ];

        foreach ($licenses as $license) {
            License::updateOrCreate(['slug' => $license['slug']], $license);
        }
    }
}

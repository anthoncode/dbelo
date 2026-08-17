<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            LicenseSeeder::class,
            CategorySeeder::class,
            PlanSeeder::class,
        ]);

        $admin = User::factory()->create([
            'name' => 'Marco',
            'username' => 'marco',
            'email' => 'admin@dbelo.com',
        ]);

        // 'role' is not mass assignable on purpose: nobody should be able to
        // make themselves an admin by posting a form field.
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();
    }
}

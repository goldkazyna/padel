<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'first_name' => 'Admin',
            'last_name' => 'Super',
            'name' => 'Super Admin',
            'email' => 'admin@padel.kz',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'rating' => 1000,
            'level' => 1.00,
        ]);
    }
}
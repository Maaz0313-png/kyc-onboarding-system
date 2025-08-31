<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class TestUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Create test users
        $admin = User::firstOrCreate(
            ['email' => 'admin@kyc-system.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('admin123'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'is_active' => true,
            ]
        );
        $admin->assignRole('admin');

        $compliance = User::firstOrCreate(
            ['email' => 'compliance@kyc-system.com'],
            [
                'name' => 'Compliance Officer',
                'password' => Hash::make('compliance123'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'is_active' => true,
            ]
        );
        $compliance->assignRole('compliance_officer');

        $kyc = User::firstOrCreate(
            ['email' => 'kyc@kyc-system.com'],
            [
                'name' => 'KYC Officer',
                'password' => Hash::make('kyc123'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'is_active' => true,
            ]
        );
        $kyc->assignRole('kyc_officer');

        $customer = User::firstOrCreate(
            ['email' => 'customer@example.com'],
            [
                'name' => 'Test Customer',
                'password' => Hash::make('customer123'),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'is_active' => true,
            ]
        );
    }
}
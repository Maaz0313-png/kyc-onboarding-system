<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // KYC Application permissions
            'create-kyc',
            'view-kyc',
            'update-kyc',
            'delete-kyc',
            'submit-kyc',
            'review-kyc',
            'approve-kyc',
            'reject-kyc',

            // Document permissions
            'upload-documents',
            'view-documents',
            'download-documents',
            'delete-documents',
            'verify-documents',

            // Verification permissions
            'perform-nadra-verification',
            'perform-biometric-verification',
            'perform-sanctions-screening',

            // Compliance permissions
            'view-audit-trails',
            'generate-reports',
            'manage-sanctions-lists',
            'fmu-reporting',

            // User management permissions
            'manage-users',
            'assign-roles',
            'view-user-profiles',

            // System permissions
            'view-system-logs',
            'manage-system-settings',
            'backup-data',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // Create roles and assign permissions

        // Customer role - basic KYC operations
        $customerRole = Role::create(['name' => 'customer']);
        $customerRole->givePermissionTo([
            'create-kyc',
            'view-kyc',
            'update-kyc',
            'submit-kyc',
            'upload-documents',
            'view-documents',
            'download-documents',
            'delete-documents',
        ]);

        // KYC Officer role - document verification and basic review
        $kycOfficerRole = Role::create(['name' => 'kyc_officer']);
        $kycOfficerRole->givePermissionTo([
            'view-kyc',
            'review-kyc',
            'view-documents',
            'download-documents',
            'verify-documents',
            'perform-nadra-verification',
            'perform-biometric-verification',
            'view-audit-trails',
        ]);

        // Compliance Officer role - full KYC management and compliance
        $complianceOfficerRole = Role::create(['name' => 'compliance_officer']);
        $complianceOfficerRole->givePermissionTo([
            'view-kyc',
            'review-kyc',
            'approve-kyc',
            'reject-kyc',
            'view-documents',
            'download-documents',
            'verify-documents',
            'perform-nadra-verification',
            'perform-biometric-verification',
            'perform-sanctions-screening',
            'view-audit-trails',
            'generate-reports',
            'manage-sanctions-lists',
            'fmu-reporting',
        ]);

        // Admin role - full system access
        $adminRole = Role::create(['name' => 'admin']);
        $adminRole->givePermissionTo(Permission::all());

        // Create test users
        $this->createTestUsers($customerRole, $kycOfficerRole, $complianceOfficerRole, $adminRole);
    }

    /**
     * Create test users for different roles
     */
    private function createTestUsers($customerRole, $kycOfficerRole, $complianceOfficerRole, $adminRole): void
    {
        // Create admin user
        $admin = User::create([
            'name' => 'System Administrator',
            'email' => 'admin@kyc-system.com',
            'password' => Hash::make('admin123'),
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'is_active' => true,
        ]);
        $admin->assignRole($adminRole);

        // Create compliance officer
        $complianceOfficer = User::create([
            'name' => 'Compliance Officer',
            'email' => 'compliance@kyc-system.com',
            'password' => Hash::make('compliance123'),
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'is_active' => true,
        ]);
        $complianceOfficer->assignRole($complianceOfficerRole);

        // Create KYC officer
        $kycOfficer = User::create([
            'name' => 'KYC Officer',
            'email' => 'kyc@kyc-system.com',
            'password' => Hash::make('kyc123'),
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'is_active' => true,
        ]);
        $kycOfficer->assignRole($kycOfficerRole);

        // Create test customer
        $customer = User::create([
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'password' => Hash::make('customer123'),
            'phone_number' => '03001234567',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'is_active' => true,
        ]);
        $customer->assignRole($customerRole);

        // Create another test customer for comprehensive testing
        $customer2 = User::create([
            'name' => 'Ahmad Ali Khan',
            'email' => 'ahmad.ali@example.com',
            'password' => Hash::make('customer123'),
            'phone_number' => '03009876543',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'is_active' => true,
        ]);
        $customer2->assignRole($customerRole);
    }
}
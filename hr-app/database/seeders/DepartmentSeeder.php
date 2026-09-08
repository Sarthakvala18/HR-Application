<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Departments plus the offboarding data-migration matrix from the HR SOP.
 * Destination addresses are placeholders until HR confirms the real mailboxes
 * behind "admin", "product" and "services".
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['key' => 'general', 'name' => 'General', 'dest' => 'admin@example.com', 'manager_first' => false],
            ['key' => 'sales', 'name' => 'Sales', 'dest' => 'admin@example.com', 'manager_first' => false],
            ['key' => 'finance', 'name' => 'Finance', 'dest' => 'admin@example.com', 'manager_first' => false],
            ['key' => 'product', 'name' => 'Product', 'dest' => 'product@example.com', 'manager_first' => false],
            ['key' => 'tech', 'name' => 'Tech', 'dest' => 'services@example.com', 'manager_first' => false],
            // Marketing routes to the manager unless the leaver *is* the manager.
            ['key' => 'marketing', 'name' => 'Marketing', 'dest' => 'admin@example.com', 'manager_first' => true],
            ['key' => 'customer_support', 'name' => 'Customer Support', 'dest' => 'admin@example.com', 'manager_first' => false],
            ['key' => 'operations', 'name' => 'Operations', 'dest' => 'admin@example.com', 'manager_first' => false],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(
                ['key' => $department['key']],
                [
                    'name' => $department['name'],
                    'offboard_destination_email' => $department['dest'],
                    'offboard_to_manager_first' => $department['manager_first'],
                    'active' => true,
                ],
            );
        }
    }
}

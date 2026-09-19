<?php

namespace Database\Seeders;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalDevelopmentSeeder extends Seeder
{
    /**
     * Seed opt-in local QA accounts. Never include this in DatabaseSeeder.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Development accounts may only be seeded locally or in tests.');
        }

        DB::transaction(function (): void {
            $this->call(RbacSeeder::class);

            $main = Branch::query()->firstOrCreate(['code' => 'MAIN'], [
                'name' => 'Main Branch',
                'status' => BranchStatus::Active,
            ]);
            $quezon = Branch::query()->firstOrCreate(['code' => 'QAVE'], [
                'name' => 'Quezon Avenue',
                'status' => BranchStatus::Active,
            ]);

            foreach ([$main, $quezon] as $branch) {
                foreach (range(1, $branch->code === 'MAIN' ? 3 : 2) as $number) {
                    $branch->tables()->firstOrCreate(['name' => 'Table '.$number], [
                        'sort_order' => $number, 'is_active' => true,
                    ]);
                }
            }

            $accounts = [
                ['Super Admin Tester', 'superadmin@gmail.com', 'super_admin', []],
                ['Owner Tester', 'owner@gmail.com', 'owner', []],
                ['Cashier Tester', 'cashier@gmail.com', 'cashier', [$main->id]],
                ['Kitchen Tester', 'kitchen@gmail.com', 'kitchen_staff', [$main->id]],
                ['Multi Branch Cashier', 'branch@gmail.com', 'cashier', [$main->id, $quezon->id]],
            ];

            foreach ($accounts as [$name, $email, $roleName, $branchIds]) {
                $user = User::query()->firstOrCreate(['email' => $email], [
                    'name' => $name,
                    'password' => 'password',
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]);

                $role = Role::query()->where('name', $roleName)->sole();
                $user->roles()->sync([$role->id]);
                $user->branches()->syncWithPivotValues($branchIds, ['is_active' => true]);
            }

            $this->call(LocalMenuCatalogSeeder::class);
        });
    }
}

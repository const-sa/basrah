<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Support\SystemRegistry;
use Illuminate\Console\Command;

class SyncAdminPermissionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:sync-admin {role=super-admin : The slug of the role to sync}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or update a role with all available system permissions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $roleSlug = $this->argument('role');
        
        $role = Role::firstOrCreate(
            ['slug' => $roleSlug],
            [
                'name' => 'المالك',
                'description' => 'صلاحية كاملة على كل الأقسام دون استثناء',
                'permissions' => []
            ]
        );

        $allKeys = SystemRegistry::permissionKeys();
        
        $role->update(['permissions' => $allKeys]);

        $this->info("Successfully synced " . count($allKeys) . " permissions to the [{$roleSlug}] role.");
        
        return self::SUCCESS;
    }
}

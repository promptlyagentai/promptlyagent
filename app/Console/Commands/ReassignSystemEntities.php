<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Console\Command;

class ReassignSystemEntities extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reassign-system-entities';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reassign entities from system user to first admin (migration helper)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Reassigning System User Entities');
        $this->newLine();

        // Find system user
        $system = User::where('email', 'system@promptlyagent.local')->first();

        if (! $system) {
            $this->info('✅ No system user found. Nothing to reassign.');

            return self::SUCCESS;
        }

        $this->info("Found system user: {$system->email} (ID: {$system->id})");

        // Find admin user (excluding system user)
        $admin = User::where('is_admin', true)
            ->where('id', '!=', $system->id)
            ->first();

        if (! $admin) {
            $this->error('❌ No admin user found. Create one first:');
            $this->info('   php artisan make:admin');
            $this->newLine();

            return self::FAILURE;
        }

        $this->info("Target admin user: {$admin->email} (ID: {$admin->id})");
        $this->newLine();

        // Reassign agents
        $agentCount = Agent::where('created_by', $system->id)->count();
        if ($agentCount > 0) {
            Agent::where('created_by', $system->id)->update(['created_by' => $admin->id]);
            $this->info("✅ Reassigned {$agentCount} agents to {$admin->email}");
        } else {
            $this->info('   No agents to reassign');
        }

        // Reassign knowledge documents
        $docCount = KnowledgeDocument::where('created_by', $system->id)->count();
        if ($docCount > 0) {
            KnowledgeDocument::where('created_by', $system->id)->update(['created_by' => $admin->id]);
            $this->info("✅ Reassigned {$docCount} knowledge documents to {$admin->email}");
        } else {
            $this->info('   No knowledge documents to reassign');
        }

        // Delete system user
        $this->newLine();
        if ($this->confirm('Delete system user now?', true)) {
            $system->delete();
            $this->info('✅ Deleted system user: system@promptlyagent.local');
        } else {
            $this->warn('⚠️  System user not deleted. Run this command again to delete it.');
        }

        $this->newLine();
        $this->info('🎉 Migration completed!');

        return self::SUCCESS;
    }
}

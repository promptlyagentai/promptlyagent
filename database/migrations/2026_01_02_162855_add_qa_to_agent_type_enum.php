<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'qa' to agent_type enum
        // Current: enum('individual','workflow','direct','promptly','synthesizer')
        // New: enum('individual','workflow','direct','promptly','synthesizer','qa')
        DB::statement("ALTER TABLE agents MODIFY COLUMN agent_type ENUM('individual','workflow','direct','promptly','synthesizer','qa') NOT NULL DEFAULT 'individual'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'qa' from agent_type enum
        // First, update any 'qa' agents back to 'individual'
        DB::statement("UPDATE agents SET agent_type = 'individual' WHERE agent_type = 'qa'");

        // Then remove 'qa' from enum
        DB::statement("ALTER TABLE agents MODIFY COLUMN agent_type ENUM('individual','workflow','direct','promptly','synthesizer') NOT NULL DEFAULT 'individual'");
    }
};

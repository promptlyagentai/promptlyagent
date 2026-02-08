<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RedisCleanupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder cleans up Redis cache when running fresh migrations
     * to ensure a clean state for the application.
     */
    public function run(): void
    {
        $this->command->info('🔧 Clearing Redis cache...');

        try {
            // Get all Redis connections
            $connections = config('database.redis');
            $clearedConnections = [];

            // Clean default connection if not in specific list
            if (isset($connections['default'])) {
                $this->clearRedisConnection('default');
                $clearedConnections[] = 'default';
            }

            // Clean cache connection
            if (isset($connections['cache'])) {
                $this->clearRedisConnection('cache');
                $clearedConnections[] = 'cache';
            }

            // Clean queue connection if different from default and cache
            if (isset($connections['queue']) &&
                ! in_array('queue', $clearedConnections)) {
                $this->clearRedisConnection('queue');
                $clearedConnections[] = 'queue';
            }

            $this->command->info('✅ Redis cache cleared successfully for connections: '.implode(', ', $clearedConnections));

        } catch (\Exception $e) {
            $this->command->error('❌ Redis cache clearing failed: '.$e->getMessage());
            Log::error('Redis cleanup seeder failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't fail the entire seeding process - just log the error
            $this->command->warn('⚠️  Continuing with seeding despite Redis cleanup failure...');
        }
    }

    /**
     * Clear a specific Redis connection
     */
    protected function clearRedisConnection(string $connection): void
    {
        $this->command->line("   - Clearing Redis {$connection} connection");

        try {
            $redis = Redis::connection($connection);

            // Get all keys
            $keys = $redis->keys('*');

            if (count($keys) > 0) {
                // Delete all keys in batches to prevent timeout on large datasets
                foreach (array_chunk($keys, 1000) as $chunk) {
                    $redis->del(...$chunk);
                }
                $this->command->line('     ✓ Deleted '.count($keys).' keys');
            } else {
                $this->command->line('     ✓ No keys found to delete');
            }
        } catch (\Exception $e) {
            $this->command->error("     ✗ Failed to clear Redis {$connection} connection: {$e->getMessage()}");
            throw $e;
        }
    }
}

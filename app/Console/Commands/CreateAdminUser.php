<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:admin
                            {--name= : The name of the admin user}
                            {--email= : The email of the admin user}
                            {--password= : The password for the admin user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new admin user';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔐 Create Admin User');
        $this->newLine();

        // Get name
        $name = $this->option('name') ?: $this->ask('Admin name', 'Admin User');

        // Get email with validation
        $email = $this->option('email');
        while (! $email || ! $this->isValidEmail($email) || $this->emailExists($email)) {
            if ($email && ! $this->isValidEmail($email)) {
                $this->error('Invalid email format.');
            }
            if ($email && $this->emailExists($email)) {
                $this->error('Email already exists.');
            }
            $email = $this->ask('Admin email');
        }

        // Get password with confirmation
        $password = $this->option('password');
        if (! $password) {
            $password = $this->secret('Admin password (min 8 characters)');
            $passwordConfirmation = $this->secret('Confirm password');

            while ($password !== $passwordConfirmation || strlen($password) < 8) {
                if ($password !== $passwordConfirmation) {
                    $this->error('Passwords do not match.');
                } elseif (strlen($password) < 8) {
                    $this->error('Password must be at least 8 characters.');
                }

                $password = $this->secret('Admin password (min 8 characters)');
                $passwordConfirmation = $this->secret('Confirm password');
            }
        }

        // Create the admin user
        try {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'is_admin' => true,
                'account_status' => 'active',
            ]);

            $this->newLine();
            $this->info('✅ Admin user created successfully!');
            $this->newLine();
            $this->table(
                ['Field', 'Value'],
                [
                    ['Name', $user->name],
                    ['Email', $user->email],
                    ['Admin', 'Yes'],
                    ['Status', 'Active'],
                ]
            );

            $this->newLine();
            $this->comment('Next steps:');
            $this->info('  1. Run seeders to create agents and default data:');
            $this->line('     php artisan db:seed');
            $this->newLine();
            $this->info('  2. Access your application at: '.config('app.url'));
            $this->newLine();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to create admin user: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Validate email format
     */
    protected function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Check if email already exists
     */
    protected function emailExists(string $email): bool
    {
        return User::where('email', $email)->exists();
    }
}

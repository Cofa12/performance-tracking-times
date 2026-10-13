<?php

namespace Cofa\PerformanceTracking\Console;

use Illuminate\Console\Command;

class PasswordCommand extends Command
{
    protected $signature = 'performance-tracking:password';

    protected $description = 'Hash a password for the dashboard login, ready for .env';

    public function handle(): int
    {
        $password = (string) $this->secret('Dashboard password');

        if (strlen($password) < 8) {
            $this->error('Use at least 8 characters.');

            return self::FAILURE;
        }

        if ($password !== (string) $this->secret('Repeat it')) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $this->line('Add this line to .env (the single quotes keep the $ signs as they are):');
        $this->newLine();
        $this->line("PERFORMANCE_TRACKING_PASSWORD='".password_hash($password, PASSWORD_DEFAULT)."'");

        return self::SUCCESS;
    }
}

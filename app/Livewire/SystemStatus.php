<?php

namespace App\Livewire;

use App\Services\SystemHealthService;
use Livewire\Component;

class SystemStatus extends Component
{
    public array $healthData = [];

    public bool $loading = false;

    public function mount()
    {
        $this->loadHealthData();
    }

    public function loadHealthData()
    {
        $healthService = app(SystemHealthService::class);
        $this->healthData = $healthService->getSystemHealth();
    }

    public function refreshStatus()
    {
        $this->loading = true;

        try {
            $healthService = app(SystemHealthService::class);
            $this->healthData = $healthService->getSystemHealth(forceRefresh: true);
        } finally {
            $this->loading = false;
        }
    }

    public function render()
    {
        return view('livewire.system-status');
    }
}

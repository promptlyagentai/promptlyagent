<?php

namespace App\Livewire\Settings;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AiPersona extends Component
{
    public ?int $age = null;

    public string $job_description = '';

    public string $location = '';

    public array $skills = [];

    public string $timezone = '';

    public string $newSkill = '';

    public int $newSkillLevel = 5;

    protected $rules = [
        'age' => 'nullable|integer|min:1|max:120',
        'job_description' => 'nullable|string|max:1000',
        'location' => 'nullable|string|max:255',
        'skills' => 'nullable|array',
        'skills.*.name' => 'required|string|max:255',
        'skills.*.level' => 'required|integer|min:1|max:10',
        'timezone' => 'nullable|string|max:100',
    ];

    public function mount()
    {
        $user = Auth::user();
        $this->age = $user->age;
        $this->job_description = $user->job_description ?? '';
        $this->location = $user->location ?? '';
        $this->skills = $user->skills ?? [];
        $this->timezone = $user->timezone ?? config('app.timezone');
    }

    public function updateAiPersona()
    {
        $this->validate();

        $user = Auth::user();
        $user->update([
            'age' => $this->age,
            'job_description' => $this->job_description,
            'location' => $this->location,
            'skills' => $this->skills,
            'timezone' => $this->timezone,
        ]);

        $this->dispatch('ai-persona-updated');
    }

    public function addSkill()
    {
        $this->validate([
            'newSkill' => 'required|string|max:255',
            'newSkillLevel' => 'required|integer|min:1|max:10',
        ]);

        $this->skills[] = [
            'name' => $this->newSkill,
            'level' => $this->newSkillLevel,
        ];
        $this->newSkill = '';
        $this->newSkillLevel = 5;
    }

    public function removeSkill($index)
    {
        if (isset($this->skills[$index])) {
            unset($this->skills[$index]);
            $this->skills = array_values($this->skills);
        }
    }

    public function render()
    {
        return view('livewire.settings.ai-persona');
    }
}

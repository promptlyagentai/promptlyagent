{{--
    AI Persona Settings Page

    Purpose: Configure user profile information for personalized AI interactions

    Features:
    - Basic information (age, location, timezone, job)
    - Skills management with competency levels (1-10)
    - Add/remove skills dynamically
    - Skill level categorization (Beginner, Intermediate, Advanced, Expert)
    - Persistent storage in user preferences
    - Timezone selection via dropdown with regional grouping

    Livewire Component Properties:
    - @property int|null $age User's age (1-120)
    - @property string $job_description Role and responsibilities
    - @property string $location City, Country
    - @property array $skills Array of skill objects {name, level}
    - @property string $timezone User's timezone (e.g., America/New_York)
    - @property string $newSkill Temporary field for adding new skill
    - @property int $newSkillLevel Competency level for new skill (1-10)

    Skill Level Ranges:
    - 1-3: Beginner
    - 4-6: Intermediate
    - 7-8: Advanced
    - 9-10: Expert

    Livewire Component Methods:
    - mount(): Load persona data from user preferences
    - updateAiPersona(): Save all persona data
    - addSkill(): Add new skill to array
    - removeSkill(index): Remove skill by array index

    Events:
    - ai-persona-updated: Dispatched when persona saved successfully

    Use Case:
    - AI agents use this data for context-aware responses
    - Personalized content generation
    - Skill-based recommendations

    Validation:
    - age: nullable, integer, 1-120
    - job_description: nullable, string, max:1000
    - location: nullable, string, max:255
    - skills: array with name (required, max:255) and level (1-10)
    - timezone: nullable, must be valid PHP timezone identifier
--}}
<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public int|null $age = null;
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
        'timezone' => 'nullable|timezone',
    ];

    public function mount(): void
    {
        $user = Auth::user();
        $this->age = $user->age;
        $this->job_description = $user->job_description ?? '';
        $this->location = $user->location ?? '';
        $this->skills = $user->skills ?? [];
        $this->timezone = $user->timezone ?? config('app.timezone');
    }

    public function updateAiPersona(): void
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

    public function addSkill(): void
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

    public function removeSkill($index): void
    {
        if (isset($this->skills[$index])) {
            unset($this->skills[$index]);
            $this->skills = array_values($this->skills);
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('AI Persona')" :subheading="__('Configure your AI persona to enhance chat interactions with personalized context')">
        <form wire:submit="updateAiPersona" class="my-6 w-full space-y-6">
            <!-- Basic Information -->
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Basic Information') }}</flux:heading>
                
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model="age"
                        :label="__('Age')"
                        type="number"
                        min="1"
                        max="120"
                        :placeholder="__('Enter your age')" />

                    <flux:field>
                        <flux:label>{{ __('Timezone') }}</flux:label>
                        <flux:select wire:model="timezone" :placeholder="__('Select your timezone')">
                            @php
                                $timezones = DateTimeZone::listIdentifiers();
                                $grouped = [];
                                foreach ($timezones as $tz) {
                                    $parts = explode('/', $tz, 2);
                                    $region = $parts[0] ?? 'Other';
                                    $grouped[$region][] = $tz;
                                }
                                ksort($grouped);
                            @endphp

                            @foreach($grouped as $region => $tzList)
                                <optgroup label="{{ $region }}">
                                    @foreach($tzList as $tz)
                                        <option value="{{ $tz }}">{{ str_replace('_', ' ', $tz) }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>
                
                <flux:input 
                    wire:model="location" 
                    :label="__('Location')" 
                    type="text"
                    :placeholder="__('City, Country')" />
                
                <flux:field>
                    <flux:label>{{ __('Job Description') }}</flux:label>
                    <textarea
                        wire:model="job_description"
                        rows="3"
                        class="w-full rounded-lg border border-default bg-white px-3 py-2 text-sm transition focus:ring-2 focus:ring-accent dark:bg-white/10 dark:border-white/10 dark:focus:ring-accent"
                        :placeholder="__('Describe your role, responsibilities, and expertise')"></textarea>
                </flux:field>
            </div>

            <!-- Skills Section -->
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Skills & Competencies') }}</flux:heading>
                <flux:subheading>{{ __('Add skills that may be relevant for AI-generated content. Rate your competency from 1 (beginner) to 10 (expert).') }}</flux:subheading>
                
                <!-- Add New Skill -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <flux:input 
                            wire:model="newSkill" 
                            :label="__('Skill Name')" 
                            type="text"
                            :placeholder="__('e.g., Python Programming')" />
                    </div>
                    <div>
                        <flux:field>
                            <flux:label>{{ __('Competency Level') }}</flux:label>
                            <select wire:model="newSkillLevel" class="w-full rounded-lg border border-default bg-surface text-primary px-3 py-2 text-sm transition focus:ring-2 focus:ring-accent">
                                @for($i = 1; $i <= 10; $i++)
                                    <option value="{{ $i }}">{{ $i }} - {{ $i <= 3 ? 'Beginner' : ($i <= 6 ? 'Intermediate' : ($i <= 8 ? 'Advanced' : 'Expert')) }}</option>
                                @endfor
                            </select>
                        </flux:field>
                    </div>
                </div>
                
                <flux:button type="button" wire:click="addSkill" variant="outline" size="sm">
                    {{ __('Add Skill') }}
                </flux:button>
                
                <!-- Existing Skills -->
                @if(count($skills) > 0)
                    <div class="space-y-3">
                        @foreach($skills as $index => $skill)
                            <div class="flex items-center justify-between rounded-lg border border-default bg-surface p-3">
                                <div class="flex-1">
                                    <div class="font-medium text-primary">{{ $skill['name'] }}</div>
                                    <div class="text-sm text-secondary">
                                        Level {{ $skill['level'] }} - 
                                        @if($skill['level'] <= 3)
                                            Beginner
                                        @elseif($skill['level'] <= 6)
                                            Intermediate
                                        @elseif($skill['level'] <= 8)
                                            Advanced
                                        @else
                                            Expert
                                        @endif
                                    </div>
                                </div>
                                <flux:button
                                    type="button"
                                    wire:click="removeSkill({{ $index }})"
                                    variant="ghost"
                                    size="sm"
                                    class="text-[var(--palette-error-700)] hover:text-[var(--palette-error-800)]">
                                    {{ __('Remove') }}
                                </flux:button>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-6 text-secondary">
                        {{ __('No skills added yet. Add your first skill above.') }}
                    </div>
                @endif
            </div>

            <!-- Save Button -->
            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Save AI Persona') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="ai-persona-updated">
                    {{ __('AI Persona saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>

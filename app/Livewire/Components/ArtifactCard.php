<?php

namespace App\Livewire\Components;

use App\Models\Artifact;
use Livewire\Component;

class ArtifactCard extends Component
{
    public Artifact $artifact;

    public bool $isRenaming = false;

    public string $tempTitle = '';

    protected $listeners = [
        'artifact-updated' => '$refresh',
    ];

    public function mount(Artifact $artifact)
    {
        $this->artifact = $artifact;
        $this->tempTitle = $artifact->title ?? 'Untitled Artifact';
    }

    public function startRename()
    {
        $this->isRenaming = true;
        $this->tempTitle = $this->artifact->title ?? 'Untitled Artifact';
    }

    public function cancelRename()
    {
        $this->isRenaming = false;
        $this->tempTitle = $this->artifact->title ?? 'Untitled Artifact';
    }

    public function saveRename()
    {
        $this->validate([
            'tempTitle' => 'required|string|max:255',
        ]);

        $this->artifact->update(['title' => $this->tempTitle]);
        $this->isRenaming = false;

        $this->dispatch('artifact-renamed', artifactId: $this->artifact->id);
        $this->dispatch('notify', [
            'message' => 'Artifact renamed successfully',
            'type' => 'success',
        ]);
    }

    public function openPreview()
    {
        $this->dispatch('open-artifact-drawer', [
            'artifactId' => $this->artifact->id,
            'mode' => 'preview',
        ]);
    }

    public function openEdit()
    {
        $this->dispatch('open-artifact-drawer', [
            'artifactId' => $this->artifact->id,
            'mode' => 'edit',
        ]);
    }

    public function download()
    {
        return redirect()->route('artifacts.download', $this->artifact);
    }

    public function confirmDelete()
    {
        $this->dispatch('open-artifact-drawer', [
            'artifactId' => $this->artifact->id,
            'mode' => 'delete',
        ]);
    }

    public function render()
    {
        return view('livewire.components.artifact-card');
    }
}

<?php

namespace App\Livewire\Components;

use App\Models\AgentSource;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

class EnhancedSourceLink extends Component
{
    public $sourceId;

    public $url;

    public $linkText;

    public $previewData = [];

    public $showPreview = false;

    public function mount($sourceId = null, $url = null, $linkText = null)
    {
        $this->sourceId = $sourceId;
        $this->url = $url;
        $this->linkText = $linkText;

        if ($sourceId) {
            $this->loadPreviewData();
        }
    }

    public function loadPreviewData()
    {
        if ($this->sourceId) {
            $source = AgentSource::find($this->sourceId);
            if ($source) {
                $this->previewData = $source->preview_data;
            }
        }
    }

    public function trackClick()
    {
        try {
            if ($this->sourceId) {
                $source = AgentSource::find($this->sourceId);
                if ($source) {
                    $source->incrementClicks();

                    Log::info('Source link clicked', [
                        'source_id' => $this->sourceId,
                        'url' => $this->url,
                        'execution_id' => $source->execution_id,
                        'total_clicks' => $source->click_count + 1,
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to track source click', [
                'source_id' => $this->sourceId,
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function showPreview()
    {
        $this->showPreview = true;

        if (! $this->previewData && $this->sourceId) {
            $this->loadPreviewData();
        }
    }

    public function hidePreview()
    {
        $this->showPreview = false;
    }

    public function getSourceDomain()
    {
        if (! empty($this->previewData['domain'])) {
            return $this->previewData['domain'];
        }

        return parse_url($this->url, PHP_URL_HOST) ?? 'Unknown';
    }

    public function getSourceTitle()
    {
        if (! empty($this->previewData['title'])) {
            return $this->previewData['title'];
        }

        return $this->linkText ?? $this->getSourceDomain();
    }

    public function render()
    {
        return view('livewire.components.enhanced-source-link');
    }
}

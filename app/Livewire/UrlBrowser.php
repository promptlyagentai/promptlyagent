<?php

namespace App\Livewire;

use App\Services\LinkValidator;
use Livewire\Component;

class UrlBrowser extends Component
{
    public string $url = '';

    public string $title = '';

    public string $description = '';

    public array $tags = [];

    public bool $auto_refresh = false;

    public int $refresh_interval = 60;

    public bool $validating = false;

    public ?array $validation_results = null;

    public ?string $error = null;

    protected $rules = [
        'url' => 'required|url|max:2048',
        'title' => 'required|string|max:255',
        'description' => 'nullable|string|max:1000',
        'tags' => 'array|max:10',
        'tags.*' => 'string|max:50',
        'auto_refresh' => 'boolean',
        'refresh_interval' => 'integer|min:5|max:10080',
    ];

    public function updatedUrl()
    {
        if (! empty($this->url)) {
            $this->validateUrl();
        }
    }

    public function validateUrl()
    {
        $this->validating = true;
        $this->error = null;
        $this->validation_results = null;

        try {
            $this->validate(['url' => 'required|url|max:2048']);

            $linkValidator = app(LinkValidator::class);
            $linkInfo = $linkValidator->validateAndExtractLinkInfo($this->url);

            $isValid = ! empty($linkInfo['status']) && $linkInfo['status'] < 400;

            $this->validation_results = [
                'isValid' => $isValid,
                'metadata' => [
                    'title' => $linkInfo['title'] ?? null,
                    'description' => $linkInfo['description'] ?? null,
                    'favicon' => $linkInfo['favicon'] ?? null,
                ],
                'suggestedTags' => $this->generateTagsFromUrl($this->url, $linkInfo),
            ];

            if (! $isValid) {
                $this->error = 'Failed to fetch URL content (HTTP '.$linkInfo['status'].')';
            } else {
                // Auto-fill fields if they're empty
                if (empty($this->title) && ! empty($linkInfo['title'])) {
                    $this->title = $linkInfo['title'];
                }

                if (empty($this->description) && ! empty($linkInfo['description'])) {
                    $this->description = $linkInfo['description'];
                }

                if (empty($this->tags) && ! empty($this->validation_results['suggestedTags'])) {
                    $this->tags = array_slice($this->validation_results['suggestedTags'], 0, 5);
                }
            }

        } catch (\Exception $e) {
            $this->error = 'Failed to validate URL: '.$e->getMessage();
            $this->validation_results = null;
        } finally {
            $this->validating = false;
        }
    }

    public function importUrl()
    {
        $this->validate();

        if (! $this->validation_results || ! $this->validation_results['isValid']) {
            $this->error = 'Please validate the URL first';

            return;
        }

        // Dispatch event with URL data for parent component to handle
        $this->dispatch('integration-import-selected', [
            'provider_id' => 'url',
            'selected_items' => [
                [
                    'url' => $this->url,
                    'title' => $this->title,
                    'description' => $this->description ?: null,
                    'tags' => $this->tags,
                    'auto_refresh' => $this->auto_refresh,
                    'refresh_interval' => $this->auto_refresh ? $this->refresh_interval : null,
                ],
            ],
        ]);

        // Reset form after dispatching
        $this->reset(['url', 'title', 'description', 'tags', 'auto_refresh', 'refresh_interval', 'validation_results', 'error']);
    }

    private function generateTagsFromUrl(string $url, array $linkInfo): array
    {
        $tags = [];
        $domain = parse_url($url, PHP_URL_HOST);

        // Domain-based tags
        if ($domain) {
            if (str_contains($domain, 'github.com')) {
                $tags[] = 'github';
                $tags[] = 'code';
            } elseif (str_contains($domain, 'stackoverflow.com')) {
                $tags[] = 'stackoverflow';
                $tags[] = 'programming';
            } elseif (str_contains($domain, 'docs.') || str_contains($domain, 'documentation')) {
                $tags[] = 'documentation';
            } elseif (str_contains($domain, 'blog') || str_contains($domain, 'medium.com')) {
                $tags[] = 'blog';
                $tags[] = 'article';
            } elseif (str_contains($domain, 'news') || str_contains($domain, 'bbc.com') || str_contains($domain, 'cnn.com')) {
                $tags[] = 'news';
            }

            // Add clean domain as tag
            $cleanDomain = str_replace(['www.', '.com', '.org', '.net'], '', $domain);
            if (strlen($cleanDomain) > 2 && ! in_array($cleanDomain, $tags)) {
                $tags[] = $cleanDomain;
            }
        }

        // Content-based tags from title
        if (! empty($linkInfo['title'])) {
            $title = strtolower($linkInfo['title']);
            if (str_contains($title, 'api')) {
                $tags[] = 'api';
            }
            if (str_contains($title, 'tutorial')) {
                $tags[] = 'tutorial';
            }
            if (str_contains($title, 'guide')) {
                $tags[] = 'guide';
            }
            if (str_contains($title, 'documentation') || str_contains($title, 'docs')) {
                $tags[] = 'documentation';
            }
            if (str_contains($title, 'reference')) {
                $tags[] = 'reference';
            }
        }

        return array_unique(array_slice($tags, 0, 5));
    }

    public function render()
    {
        return view('livewire.url-browser');
    }
}

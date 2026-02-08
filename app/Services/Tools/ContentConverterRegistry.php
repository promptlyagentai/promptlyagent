<?php

namespace App\Services\Tools;

use App\Services\Tools\Contracts\ContentConverterInterface;
use Illuminate\Support\Facades\Log;

/**
 * Content Converter Registry
 *
 * Central registry for content format converters.
 * Allows integrations to register their converters dynamically.
 */
class ContentConverterRegistry
{
    /**
     * @var array<ContentConverterInterface>
     */
    protected array $converters = [];

    /**
     * Register a content converter
     *
     * @param  ContentConverterInterface  $converter  Converter instance
     */
    public function register(ContentConverterInterface $converter): void
    {
        $this->converters[] = $converter;
    }

    /**
     * Find a converter that supports the given format pair
     *
     * @param  string  $from  Source format
     * @param  string  $to  Target format
     * @return ContentConverterInterface|null Converter instance or null if none found
     */
    public function findConverter(string $from, string $to): ?ContentConverterInterface
    {
        // Sort converters by priority (highest first)
        $sortedConverters = collect($this->converters)
            ->sortByDesc(fn ($converter) => $converter->getPriority())
            ->values();

        // Find first converter that supports the format pair
        foreach ($sortedConverters as $converter) {
            if ($converter->supports($from, $to)) {
                Log::debug('Content converter found', [
                    'from' => $from,
                    'to' => $to,
                    'converter_class' => get_class($converter),
                ]);

                return $converter;
            }
        }

        Log::warning('No content converter found for format pair', [
            'from' => $from,
            'to' => $to,
            'available_converters' => count($this->converters),
        ]);

        return null;
    }

    /**
     * Get all registered converters
     *
     * @return array<ContentConverterInterface>
     */
    public function all(): array
    {
        return $this->converters;
    }

    /**
     * Check if any converter supports the given format pair
     *
     * @param  string  $from  Source format
     * @param  string  $to  Target format
     */
    public function hasConverter(string $from, string $to): bool
    {
        return $this->findConverter($from, $to) !== null;
    }
}

<?php

namespace App\Services;

use InvalidArgumentException;

class ColorSchemeService
{
    /**
     * Required color scale numbers for a complete palette
     */
    private const REQUIRED_SCALE = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];

    /**
     * Maximum number of properties allowed
     */
    private const MAX_PROPERTIES = 200;

    /**
     * Allowed property prefixes for validation
     */
    private static array $allowedPropertyPrefixes = [
        // Palette colors
        '--palette-primary-',
        '--palette-neutral-',
        '--palette-success-',
        '--palette-warning-',
        '--palette-error-',
        '--palette-notify-',
        '--palette-dark-',
        '--palette-white',
        '--palette-black',

        // Semantic tokens
        '--color-page-',
        '--color-surface-',
        '--color-sidebar-',
        '--color-nav-',
        '--color-text-',
        '--color-accent',
        '--color-border-',
        '--color-code-',
        '--color-markdown-',
        '--color-success',
        '--color-warning',
        '--color-error',
    ];

    /**
     * Parse user-provided CSS text into structured array
     */
    public static function parseUserCss(string $cssText): array
    {
        $cssText = trim($cssText);

        if (empty($cssText)) {
            return [];
        }

        // Remove potential <style> tags for safety
        $cssText = preg_replace('/<\/?style[^>]*>/i', '', $cssText);

        // Split by newlines or semicolons
        $lines = preg_split('/[;\n]/', $cssText);

        $colors = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Parse property: value
            if (! preg_match('/^(--[a-z0-9\-]+)\s*:\s*(.+)$/i', $line, $matches)) {
                continue;
            }

            $property = trim($matches[1]);
            $value = trim($matches[2]);

            $colors[$property] = $value;
        }

        // Limit to prevent abuse
        if (count($colors) > self::MAX_PROPERTIES) {
            throw new InvalidArgumentException('Too many properties. Maximum '.self::MAX_PROPERTIES.' allowed.');
        }

        return $colors;
    }

    /**
     * Normalize user colors by auto-mapping custom names to palette-primary-*
     * Also generates state colors (success/warning/error) via OKLCH hue shifting
     */
    public static function normalizeUserColors(array $parsedColors): array
    {
        if (empty($parsedColors)) {
            return [];
        }

        // Check if already normalized (all properties are --palette-primary-*)
        $alreadyNormalized = true;
        foreach (array_keys($parsedColors) as $property) {
            if (! str_starts_with($property, '--palette-primary-')) {
                $alreadyNormalized = false;
                break;
            }
        }

        if ($alreadyNormalized) {
            return self::generateStateColors($parsedColors);
        }

        // Extract properties matching pattern: --<anything>-<number>
        // This handles multi-word names like "deep-purple", "light-blue", etc.
        $pattern = '/^(--.+)-(\d+)$/i';
        $groupedColors = [];

        foreach ($parsedColors as $property => $value) {
            if (preg_match($pattern, $property, $matches)) {
                $prefix = $matches[1]; // Everything before the number (e.g., "--color-deep-purple")
                $number = (int) $matches[2];

                if (! isset($groupedColors[$prefix])) {
                    $groupedColors[$prefix] = [];
                }

                $groupedColors[$prefix][$number] = $value;
            }
        }

        // Find the most common prefix (the one with most colors)
        if (empty($groupedColors)) {
            throw new InvalidArgumentException('No valid color scale pattern detected. Properties should end with a number (50, 100, 200, etc.)');
        }

        $mostCommonPrefix = null;
        $maxCount = 0;

        foreach ($groupedColors as $prefix => $colors) {
            if (count($colors) > $maxCount) {
                $maxCount = count($colors);
                $mostCommonPrefix = $prefix;
            }
        }

        $selectedColors = $groupedColors[$mostCommonPrefix];

        // Validate complete scale
        $providedNumbers = array_keys($selectedColors);
        sort($providedNumbers);

        if ($providedNumbers !== self::REQUIRED_SCALE) {
            $missing = array_diff(self::REQUIRED_SCALE, $providedNumbers);
            $extra = array_diff($providedNumbers, self::REQUIRED_SCALE);

            $errorParts = [];
            if (! empty($missing)) {
                $errorParts[] = 'Missing: '.implode(', ', $missing);
            }
            if (! empty($extra)) {
                $errorParts[] = 'Extra: '.implode(', ', $extra);
            }

            throw new InvalidArgumentException(
                'Incomplete color scale. Required: '.implode(', ', self::REQUIRED_SCALE).'. '.
                implode('. ', $errorParts)
            );
        }

        // Map to --palette-primary-*
        $normalized = [];
        foreach ($selectedColors as $number => $value) {
            $normalized["--palette-primary-{$number}"] = $value;
        }

        // Generate state colors from primary palette
        return self::generateStateColors($normalized);
    }

    /**
     * Generate a complete Tailwind color scale from a single base color
     * Returns array of --palette-primary-* properties with hex values
     */
    public static function generateColorScale(string $baseColor): array
    {
        // Normalize the hex string
        $hex = ltrim($baseColor, '#');

        // Convert 3-digit hex to 6-digit
        if (strlen($hex) === 3 && preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new InvalidArgumentException('Base color must be a 6-digit hex value');
        }

        // Shade definitions: lighter to darker (roughly Tailwind-like stops)
        $stops = [
            50 => 0.95,
            100 => 0.85,
            200 => 0.75,
            300 => 0.6,
            400 => 0.4,
            500 => 0.0,   // Base color
            600 => 0.15,
            700 => 0.35,
            800 => 0.6,
            900 => 0.8,
            950 => 0.92,
        ];

        // Convert hex base color to RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $palette = [];

        foreach ($stops as $shade => $blend) {
            if ($shade < 500) { // blend with white
                $nr = round($r + (255 - $r) * $blend);
                $ng = round($g + (255 - $g) * $blend);
                $nb = round($b + (255 - $b) * $blend);
            } elseif ($shade > 500) { // blend with black
                $nr = round($r * (1 - $blend));
                $ng = round($g * (1 - $blend));
                $nb = round($b * (1 - $blend));
            } else { // base color
                $nr = $r;
                $ng = $g;
                $nb = $b;
            }
            $palette["--palette-primary-$shade"] = sprintf('#%02x%02x%02x', $nr, $ng, $nb);
        }

        return $palette;
    }

    /**
     * Convert OKLCH values to hex color
     */
    private static function oklchToHex(float $l, float $c, float $h): string
    {
        // Convert OKLCH to OKLAB
        $hRad = $h * M_PI / 180;
        $a = $c * cos($hRad);
        $b = $c * sin($hRad);

        // Convert OKLAB to XYZ
        $l_ = $l + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $l - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $l - 0.0894841775 * $a - 1.2914855480 * $b;

        $l3 = $l_ * $l_ * $l_;
        $m3 = $m_ * $m_ * $m_;
        $s3 = $s_ * $s_ * $s_;

        $x = +4.0767416621 * $l3 - 3.3077115913 * $m3 + 0.2309699292 * $s3;
        $y = -1.2684380046 * $l3 + 2.6097574011 * $m3 - 0.3413193965 * $s3;
        $z = -0.0041960863 * $l3 - 0.7034186147 * $m3 + 1.7076147010 * $s3;

        // Convert XYZ to linear RGB
        $rLinear = +3.2404542 * $x - 1.5371385 * $y - 0.4985314 * $z;
        $gLinear = -0.9692660 * $x + 1.8760108 * $y + 0.0415560 * $z;
        $bLinear = +0.0556434 * $x - 0.2040259 * $y + 1.0572252 * $z;

        // Convert linear RGB to sRGB
        $r = self::linearToSrgb($rLinear);
        $g = self::linearToSrgb($gLinear);
        $b = self::linearToSrgb($bLinear);

        // Clamp values to 0-255
        $r = max(0, min(255, round($r * 255)));
        $g = max(0, min(255, round($g * 255)));
        $b = max(0, min(255, round($b * 255)));

        // Convert to hex
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Convert linear RGB to sRGB
     */
    private static function linearToSrgb(float $value): float
    {
        if ($value <= 0.0031308) {
            return $value * 12.92;
        }

        return 1.055 * pow($value, 1 / 2.4) - 0.055;
    }

    /**
     * Generate state color palettes (success/warning/error) from primary palette
     * Uses OKLCH hue shifting: success (+140°), warning (+70°), error (+20°)
     */
    public static function generateStateColors(array $primaryColors): array
    {
        // Start with primary colors
        $allColors = $primaryColors;

        // Extract primary-500 as base for hue shifting
        $basePrimary = $primaryColors['--palette-primary-500'] ?? null;

        if (! $basePrimary) {
            // If no primary-500, can't generate state colors
            return $allColors;
        }

        // Convert base color to OKLCH
        $baseOklch = self::hexToOklch($basePrimary);

        if (! $baseOklch) {
            // If conversion fails, return primary colors only
            return $allColors;
        }

        // Extract base hue and chroma for alignment detection
        $baseHue = $baseOklch['h'];      // 0-360°
        $baseChroma = $baseOklch['c'];   // 0-1 range

        // Define lightness and chroma patterns for color scales
        // Based on tropical-teal palette analysis
        $scalePatterns = [
            50 => ['l' => 0.97, 'c' => 0.01],
            100 => ['l' => 0.94, 'c' => 0.02],
            200 => ['l' => 0.88, 'c' => 0.05],
            300 => ['l' => 0.81, 'c' => 0.10],
            400 => ['l' => 0.73, 'c' => 0.12],
            500 => ['l' => 0.66, 'c' => 0.13],
            600 => ['l' => 0.58, 'c' => 0.12],
            700 => ['l' => 0.50, 'c' => 0.11],
            800 => ['l' => 0.42, 'c' => 0.08],
            900 => ['l' => 0.34, 'c' => 0.07],
            950 => ['l' => 0.24, 'c' => 0.05],
        ];

        // Define semantic hues for state colors
        $stateHues = [
            'success' => 140,  // Green
            'warning' => 70,   // Amber/Yellow
            'error' => 20,     // Red
            'notify' => 230,   // Blue
        ];

        // Detect hue alignment (±30° tolerance)
        $alignmentTolerance = 30;
        $alignedStates = [];
        $closestAlignment = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($stateHues as $state => $targetHue) {
            // Calculate circular distance (handle 0°/360° wraparound)
            $distance = min(
                abs($baseHue - $targetHue),
                360 - abs($baseHue - $targetHue)
            );

            if ($distance <= $alignmentTolerance) {
                $alignedStates[$state] = true;
                if ($distance < $minDistance) {
                    $minDistance = $distance;
                    $closestAlignment = $state;
                }
            } else {
                $alignedStates[$state] = false;
            }
        }

        // If multiple alignments, only use closest
        if (count(array_filter($alignedStates)) > 1) {
            foreach ($alignedStates as $state => $isAligned) {
                if ($state !== $closestAlignment) {
                    $alignedStates[$state] = false;
                }
            }
        }

        // Generate state colors with intelligent alignment
        foreach ($stateHues as $state => $semanticHue) {
            if ($alignedStates[$state]) {
                // ALIGNED: Copy entire primary palette
                foreach (self::REQUIRED_SCALE as $number) {
                    $primaryKey = "--palette-primary-{$number}";
                    $stateKey = "--palette-{$state}-{$number}";

                    if (isset($primaryColors[$primaryKey])) {
                        $allColors[$stateKey] = $primaryColors[$primaryKey];
                    }
                }
            } else {
                // NON-ALIGNED: Generate with semantic hue + matched chroma
                $matchedChroma = min($baseChroma, 0.10);  // Cap at 0.10

                foreach ($scalePatterns as $number => $pattern) {
                    $property = "--palette-{$state}-{$number}";

                    // Scale chroma proportionally while respecting cap
                    $scaledChroma = ($pattern['c'] / 0.13) * $matchedChroma;
                    $scaledChroma = min($scaledChroma, 0.10);  // Hard cap

                    // Convert OKLCH to hex for better browser compatibility
                    $allColors[$property] = self::oklchToHex(
                        $pattern['l'],
                        $scaledChroma,
                        $semanticHue
                    );
                }
            }
        }

        // Generate semantic tokens from palette colors
        // These are used throughout the UI (logo, buttons, alerts, etc.)
        $allColors['--color-accent'] = $primaryColors['--palette-primary-600'] ?? $primaryColors['--palette-primary-500'];
        $allColors['--color-success'] = $allColors['--palette-success-600'] ?? null;
        $allColors['--color-warning'] = $allColors['--palette-warning-600'] ?? null;
        $allColors['--color-error'] = $allColors['--palette-error-600'] ?? null;

        return $allColors;
    }

    /**
     * Convert hex color to OKLCH color space
     * Returns ['l' => lightness, 'c' => chroma, 'h' => hue] or null on failure
     */
    private static function hexToOklch(string $hex): ?array
    {
        // Remove # if present
        $hex = ltrim($hex, '#');

        // Convert short hex to long hex
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6) {
            return null;
        }

        // Convert hex to RGB (0-255)
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        // Convert RGB to linear RGB (0-1)
        $rLinear = self::srgbToLinear($r / 255);
        $gLinear = self::srgbToLinear($g / 255);
        $bLinear = self::srgbToLinear($b / 255);

        // Convert linear RGB to XYZ (D65 illuminant)
        $x = 0.4124564 * $rLinear + 0.3575761 * $gLinear + 0.1804375 * $bLinear;
        $y = 0.2126729 * $rLinear + 0.7151522 * $gLinear + 0.0721750 * $bLinear;
        $z = 0.0193339 * $rLinear + 0.1191920 * $gLinear + 0.9503041 * $bLinear;

        // Convert XYZ to OKLAB
        $l_ = pow(0.8189330101 * $x + 0.3618667424 * $y - 0.1288597137 * $z, 1 / 3);
        $m_ = pow(0.0329845436 * $x + 0.9293118715 * $y + 0.0361456387 * $z, 1 / 3);
        $s_ = pow(0.0482003018 * $x + 0.2643662691 * $y + 0.6338517070 * $z, 1 / 3);

        $l = 0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_;
        $a = 1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_;
        $b_ = 0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_;

        // Convert OKLAB to OKLCH
        $c = sqrt($a * $a + $b_ * $b_);
        $h = atan2($b_, $a) * 180 / M_PI;

        // Normalize hue to 0-360
        if ($h < 0) {
            $h += 360;
        }

        return [
            'l' => $l,
            'c' => $c,
            'h' => $h,
        ];
    }

    /**
     * Convert sRGB value to linear RGB
     */
    private static function srgbToLinear(float $value): float
    {
        if ($value <= 0.04045) {
            return $value / 12.92;
        }

        return pow(($value + 0.055) / 1.055, 2.4);
    }

    /**
     * Validate CSS property name against whitelist
     */
    public static function validatePropertyName(string $property): bool
    {
        // Must start with --
        if (! str_starts_with($property, '--')) {
            return false;
        }

        // Only allow alphanumeric and hyphens
        if (! preg_match('/^--[a-z0-9\-]+$/i', $property)) {
            return false;
        }

        // Check against whitelist
        foreach (self::$allowedPropertyPrefixes as $allowed) {
            if (str_starts_with($property, $allowed)) {
                return true;
            }

            // Also allow exact match (for properties without trailing hyphen)
            if ($property === rtrim($allowed, '-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate color value format
     */
    public static function validateColorValue(string $value): bool
    {
        $value = trim($value);

        // Hex color: #RGB or #RRGGBB or #RRGGBBAA
        if (preg_match('/^#[0-9a-f]{3,8}$/i', $value)) {
            return true;
        }

        // RGB/RGBA: rgb(r,g,b) or rgba(r,g,b,a)
        if (preg_match('/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(,\s*[\d.]+\s*)?\)$/i', $value)) {
            return true;
        }

        // OKLCH: oklch(L C H) or oklch(L C H / A)
        if (preg_match('/^oklch\(\s*[\d.]+%?\s+[\d.]+\s+[\d.]+\s*(\/\s*[\d.]+)?\)$/i', $value)) {
            return true;
        }

        // CSS color keywords (limited set for safety)
        $allowedKeywords = ['transparent', 'currentColor'];
        if (in_array(strtolower($value), array_map('strtolower', $allowedKeywords))) {
            return true;
        }

        return false;
    }

    /**
     * Validate that colors contain complete scale
     */
    public static function validateCompleteScale(array $colors): bool
    {
        $numbers = [];

        foreach (array_keys($colors) as $property) {
            // Extract number from --palette-primary-{number}
            if (preg_match('/--palette-primary-(\d+)$/', $property, $matches)) {
                $numbers[] = (int) $matches[1];
            }
        }

        sort($numbers);

        return $numbers === self::REQUIRED_SCALE;
    }

    /**
     * Generate sanitized style tag for injection
     */
    public static function generateStyleTag(array $colors): string
    {
        if (empty($colors)) {
            return '';
        }

        $sanitizedColors = [];

        foreach ($colors as $property => $value) {
            if (self::validatePropertyName($property) && self::validateColorValue($value)) {
                $sanitizedProperty = self::sanitizePropertyName($property);
                $sanitizedValue = self::sanitizeColorValue($value);
                $sanitizedColors[$sanitizedProperty] = $sanitizedValue;
            }
        }

        if (empty($sanitizedColors)) {
            return '';
        }

        $cssRules = [];
        foreach ($sanitizedColors as $property => $value) {
            $cssRules[] = "    {$property}: {$value};";
        }

        $css = ":root {\n".implode("\n", $cssRules)."\n}";

        return "<style id=\"user-custom-color-scheme\">\n{$css}\n</style>";
    }

    /**
     * Sanitize property name
     */
    private static function sanitizePropertyName(string $property): string
    {
        // Already validated, just ensure lowercase
        return strtolower(trim($property));
    }

    /**
     * Sanitize color value
     */
    private static function sanitizeColorValue(string $value): string
    {
        // Remove any potentially dangerous characters
        // Keep only: alphanumeric, #, %, spaces, commas, periods, forward slash, parentheses
        return preg_replace('/[^a-z0-9#%\s,.\/()\-]/i', '', trim($value));
    }
}

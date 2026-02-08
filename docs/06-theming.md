# Color System Documentation

## Overview

PromptlyAgent uses a semantic color system built on CSS custom properties (CSS variables) that provides:

- **Theme flexibility**: Easy switching between color palettes
- **Dark mode support**: Automatic color adaptation for dark mode
- **Custom user schemes**: Users can define their own color palettes
- **Intelligent state colors**: Automatic generation based on primary colors
- **Semantic naming**: Clear, purpose-driven color names

## Architecture

The color system is organized in three layers:

```
┌─────────────────────────────────────────────┐
│  Semantic Tokens (--color-*)                │
│  What you use in templates                  │
│  Example: --color-accent, --color-success   │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│  Palette Colors (--palette-*)               │
│  Theme-specific color scales                │
│  Example: --palette-primary-600             │
└─────────────────────────────────────────────┘
                    ↓
┌─────────────────────────────────────────────┐
│  Raw Values (#hex or oklch())               │
│  Defined in theme files                     │
│  Example: #468e93                           │
└─────────────────────────────────────────────┘
```

### File Structure

```
resources/css/themes/
├── _base-tokens.css         # Semantic token mappings (rarely edited)
└── palette-tropical-teal.css # Actual color values (edit to change theme)
```

## Color Palettes

Each palette consists of 11 shades from lightest (50) to darkest (950):

### Primary Palette (Teal)
Used for accents, links, and primary actions.

| Shade | Hex     | Usage                    |
|-------|---------|--------------------------|
| 50    | #f6fbfb | Lightest backgrounds     |
| 100   | #e5f2f3 | Light backgrounds        |
| 200   | #d4e9eb | Borders, dividers        |
| 300   | #badcde | Hover states             |
| 400   | #97cace | Active states            |
| 500   | #52a7ad | Base color               |
| **600** | **#468e93** | **Primary accent** (main usage) |
| 700   | #356d70 | Hover on accent          |
| 800   | #214345 | Dark backgrounds         |
| 900   | #102123 | Darker backgrounds       |
| 950   | #070d0e | Darkest backgrounds      |

### Success Palette (Green)
Used for positive feedback, confirmations, and success states.

- **Primary shade (600)**: `#009c49`
- Range: `#fff4e7` (50) → `#002a0f` (950)

### Warning Palette (Red/Coral)
Used for cautions, alerts, and warning states.

- **Primary shade (600)**: `#dc253e`
- Range: `#ffebe6` (50) → `#40040b` (950)

### Error Palette (Pink/Magenta)
Used for errors, destructive actions, and critical alerts.

- **Primary shade (600)**: `#f40068`
- Range: `#ffe8e9` (50) → `#480019` (950)

### Notify Palette (Teal)
Used for informational messages and neutral notifications. Matches primary palette.

- **Primary shade (600)**: `#468e93`
- Range: Same as primary palette

## Semantic Tokens

Use semantic tokens in your templates instead of direct palette references. This ensures consistency and enables theme switching.

### Interactive Elements

```html
<!-- Accent color (primary brand color) -->
<button class="bg-[var(--color-accent)] hover:bg-[var(--color-accent-hover)]">
    <span class="text-[var(--color-accent-foreground)]">Click me</span>
</button>
```

**Available tokens:**
- `--color-accent`: Main accent color (#468e93)
- `--color-accent-hover`: Hover state for accent
- `--color-accent-foreground`: Text color on accent background (white)

### Page Structure

```html
<!-- Page background and text -->
<div class="bg-[var(--color-page-bg)] text-[var(--color-page-text)]">
    Content here
</div>
```

**Available tokens:**
- `--color-page-bg`: Main page background
- `--color-page-text`: Main page text color

### Surfaces (Cards, Panels, Modals)

```html
<!-- Card with elevated surface -->
<div class="bg-[var(--color-surface-bg-elevated)] border-[var(--color-surface-border)]">
    <p class="text-[var(--color-text-primary)]">Card content</p>
</div>
```

**Available tokens:**
- `--color-surface-bg`: Base surface background
- `--color-surface-bg-elevated`: Elevated surface (slightly lighter)
- `--color-surface-border`: Surface borders

### Text Hierarchy

```html
<h1 class="text-[var(--color-text-primary)]">Primary heading</h1>
<p class="text-[var(--color-text-secondary)]">Secondary text</p>
<span class="text-[var(--color-text-tertiary)]">Tertiary text</span>
```

**Available tokens:**
- `--color-text-primary`: Main text color (highest contrast)
- `--color-text-secondary`: Secondary text (medium contrast)
- `--color-text-tertiary`: Tertiary text (lower contrast)

### State Colors

Use state colors for feedback, alerts, and status indicators:

```html
<!-- Success message -->
<div class="bg-[var(--color-success-bg)] border-[var(--color-border-success)]">
    <p class="text-[var(--color-success)]">Operation successful!</p>
</div>

<!-- Warning alert -->
<div class="bg-[var(--color-warning-bg)] border-[var(--color-border-warning)]">
    <p class="text-[var(--color-warning)]">Warning message</p>
</div>

<!-- Error notification -->
<div class="bg-[var(--color-error-bg)] border-[var(--color-border-error)]">
    <p class="text-[var(--color-error)]">Error occurred!</p>
</div>
```

**Available tokens per state:**
- `--color-{state}`: Main state color (600 shade)
- `--color-{state}-bg`: Light background for state
- `--color-{state}-contrast`: Text color with proper contrast
- `--color-border-{state}`: Border color for state

States: `success`, `warning`, `error`

### Code and Markdown

```html
<!-- Inline code -->
<code class="bg-[var(--color-code-bg)] text-[var(--color-code-text)] border-[var(--color-code-border)]">
    console.log()
</code>

<!-- Markdown marker -->
<span class="text-[var(--color-markdown-marker)]">##</span>
```

**Available tokens:**
- `--color-code-bg`: Code block background
- `--color-code-text`: Code text color
- `--color-code-border`: Code block borders
- `--color-markdown-marker`: Markdown syntax markers

## Using Colors in Templates

### Blade Templates

#### Method 1: CSS Variables (Recommended)

```blade
@{{-- Using semantic tokens --}}
<div class="bg-[var(--color-accent)] text-[var(--color-accent-foreground)]">
    Button
</div>

@{{-- Using palette directly (avoid unless necessary) --}}
<div class="bg-[var(--palette-primary-600)]">
    Content
</div>
```

#### Method 2: Tailwind Utilities

```blade
@{{-- For common patterns, use predefined utilities --}}
<div class="bg-accent text-accent-foreground">
    Button
</div>

<p class="text-primary">Primary text</p>
<p class="text-secondary">Secondary text</p>
```

See `_base-tokens.css` for all available utilities.

### Alpine.js / JavaScript

```html
<div x-data="{ accentColor: getComputedStyle(document.documentElement).getPropertyValue('--color-accent') }">
    <span :style="`color: ${accentColor}`">Dynamic color</span>
</div>
```

### Dynamic Color Changes

```javascript
// Get a color value
const accentColor = getComputedStyle(document.documentElement)
    .getPropertyValue('--color-accent');

// Set a custom color (for user themes)
document.documentElement.style.setProperty('--color-accent', '#ff0000');
```

## Dark Mode Support

Dark mode automatically adjusts semantic tokens. No changes needed in your templates!

```blade
@{{-- This works in both light and dark mode --}}
<div class="bg-[var(--color-page-bg)] text-[var(--color-page-text)]">
    Content automatically adapts to dark mode
</div>
```

### Dark Mode Overrides

Dark mode overrides are defined in `_base-tokens.css`:

```css
.dark {
    --color-page-bg: var(--palette-dark-page);
    --color-page-text: var(--palette-neutral-100);
    --color-accent: var(--palette-primary-500); /* Lighter in dark mode */
    /* ... other overrides ... */
}
```

## Custom User Color Schemes

Users can define custom color schemes in Settings > Appearance. The system intelligently handles state colors:

### Alignment Detection

When a user's primary palette aligns with a semantic state color (within ±30°), the entire palette is copied:

- **Green primary** → Copied to success palette
- **Red primary** → Copied to error palette
- **Teal/Blue primary** → Copied to notify palette

### Non-Aligned Colors

When colors don't align, state colors are generated with:
- **Fixed semantic hues**: Success (140°), Warning (70°), Error (20°), Notify (230°)
- **Matched chroma**: Matches the saturation of the base color (capped at 0.10)
- **Hex output**: All colors converted to hex for browser compatibility

### Example: Custom Color Implementation

Users can paste a complete color scale:

```css
--color-my-theme-50: #f0fdf4;
--color-my-theme-100: #dcfce7;
--color-my-theme-200: #bbf7d0;
--color-my-theme-300: #86efac;
--color-my-theme-400: #4ade80;
--color-my-theme-500: #22c55e;
--color-my-theme-600: #16a34a;
--color-my-theme-700: #15803d;
--color-my-theme-800: #166534;
--color-my-theme-900: #14532d;
--color-my-theme-950: #052e16;
```

The system will:
1. Normalize to `--palette-primary-*`
2. Detect this is green (aligns with success at 140°)
3. Copy the entire palette to success colors
4. Generate warning, error, and notify with semantic hues

## Best Practices

### ✅ DO

1. **Use semantic tokens** instead of palette colors:
   ```blade
   <div class="text-[var(--color-accent)]">Good</div>
   ```

2. **Use state colors** for feedback:
   ```blade
   <div class="text-[var(--color-success)]">Success!</div>
   ```

3. **Let dark mode handle color changes** automatically:
   ```blade
   <p class="text-[var(--color-text-primary)]">Auto-adapts</p>
   ```

4. **Use palette directly** only for custom components needing specific shades:
   ```blade
   <div class="bg-[var(--palette-primary-100)]">Light background</div>
   ```

### ❌ DON'T

1. **Don't hardcode hex values**:
   ```blade
   <!-- Bad -->
   <div class="bg-[#468e93]">Button</div>

   <!-- Good -->
   <div class="bg-[var(--color-accent)]">Button</div>
   ```

2. **Don't use wrong semantic tokens**:
   ```blade
   <!-- Bad: Using error color for warnings -->
   <div class="text-[var(--color-error)]">Warning message</div>

   <!-- Good: Use correct state -->
   <div class="text-[var(--color-warning)]">Warning message</div>
   ```

3. **Don't override colors without dark mode consideration**:
   ```blade
   <!-- Bad: Fixed color doesn't adapt -->
   <div style="color: #ffffff;">Text</div>

   <!-- Good: Uses semantic token -->
   <div class="text-[var(--color-text-primary)]">Text</div>
   ```

## Switching Themes

To change the default theme:

1. Create a new palette file in `resources/css/themes/`:
   ```css
   /* palette-my-theme.css */
   @theme {
       --palette-primary-50: #...;
       /* ... all 11 shades ... */
       --palette-primary-950: #...;

       /* Define all state palettes */
   }
   ```

2. Update the import in `resources/css/app.css`:
   ```css
   /* Change this import */
   @import "themes/palette-tropical-teal.css";
   /* to */
   @import "themes/palette-my-theme.css";
   ```

3. Rebuild CSS:
   ```bash
   npm run build
   ```

## Testing Colors

### Visual Verification

Visit `/` (welcome page) to see all color palettes displayed with hex values.

### Programmatic Testing

```php
// Test color generation
$colors = ColorSchemeService::generateColorScale('#1eae9a');

// Test alignment detection
$colors = ColorSchemeService::normalizeUserColors($userColors);

// Verify all shades present
ColorSchemeService::validateCompleteScale($colors);
```

## Reference: All Semantic Tokens

### Page Structure (2 tokens)
- `--color-page-bg`
- `--color-page-text`

### Surfaces (3 tokens)
- `--color-surface-bg`
- `--color-surface-bg-elevated`
- `--color-surface-border`

### Sidebar (3 tokens)
- `--color-sidebar-bg`
- `--color-sidebar-text`
- `--color-sidebar-border`

### Navigation (4 tokens)
- `--color-nav-sidebar-active`
- `--color-nav-sidebar-hover`
- `--color-nav-content-active`
- `--color-nav-content-hover`

### Text Hierarchy (3 tokens)
- `--color-text-primary`
- `--color-text-secondary`
- `--color-text-tertiary`

### Interactive Elements (4 tokens)
- `--color-accent`
- `--color-accent-hover`
- `--color-accent-foreground`
- `--color-accent-content` (Flux UI compatibility)

### Borders (2 tokens)
- `--color-border-default`
- `--color-border-subtle`

### Code & Markdown (4 tokens)
- `--color-code-bg`
- `--color-code-text`
- `--color-code-border`
- `--color-markdown-marker`

### State Colors (12 tokens)
**Per state (success, warning, error):**
- `--color-{state}`: Main color
- `--color-{state}-bg`: Background
- `--color-{state}-contrast`: Text with contrast
- `--color-border-{state}`: Border

## Additional Resources

- **Implementation**: `app/Services/ColorSchemeService.php`
- **Base Tokens**: `resources/css/themes/_base-tokens.css`
- **Default Palette**: `resources/css/themes/palette-tropical-teal.css`
- **Tests**: `tests/Unit/Services/ColorSchemeServiceTest.php`
- **Feature Tests**: `tests/Feature/Settings/ColorSchemeAlignmentTest.php`

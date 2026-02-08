# Theme System Documentation

## Overview

This application uses a **three-layer semantic theming system** that enables complete theme switching by changing a single import statement.

## Architecture

```
┌─────────────────────────────────────────────┐
│         USAGE LAYER (Blade Templates)       │
│    Uses: bg-page, text-primary, etc.       │
└──────────────────┬──────────────────────────┘
                   │
┌──────────────────▼──────────────────────────┐
│   SEMANTIC TOKEN LAYER (_base-tokens.css)   │
│    Maps: bg-page → --color-page-bg          │
└──────────────────┬──────────────────────────┘
                   │
┌──────────────────▼──────────────────────────┐
│   PALETTE LAYER (palette-*.css files)       │
│    Defines: --palette-neutral-50: #f9fafb   │
└─────────────────────────────────────────────┘
```

## How to Switch Themes

1. Create a new palette file: `palette-your-theme.css`
2. Define all required palette variables (see template below)
3. Change import in `resources/css/app.css`:
   ```css
   @import './themes/palette-your-theme.css';  /* ← Change this line */
   ```
4. Run `npm run build`

**That's it!** The entire application updates.

## Available Semantic Tokens (27)

### Page Structure (2)
- `bg-page` - Page background
- `text-page` - Page-level text

### Surfaces (3)
- `bg-surface` - Cards, panels, modals
- `bg-surface-elevated` - Elevated surfaces (popovers, dropdowns)
- `border-surface` - Surface borders

### Sidebar (3)
- `bg-sidebar` - Sidebar background
- `text-sidebar` - Sidebar text
- `border-sidebar` - Sidebar borders

### Text Hierarchy (3)
- `text-primary` - Headings, important text
- `text-secondary` - Body text
- `text-tertiary` - Muted, helper text

### Interactive (4)
- `bg-accent` / `text-accent` - Primary actions, links
- `bg-accent-hover` - Hover states
- `text-accent-foreground` - Text on accent backgrounds
- `--color-accent-content` - Flux UI compatibility

### Borders (2)
- `border-default` - Standard borders
- `border-subtle` - Subtle dividers

### Code & Markdown (4)
- `bg-code` - Inline code background
- `text-code` - Inline code text
- `border-code` - Code borders
- `text-markdown-marker` - List markers, accents in markdown

### State Colors (6)
- `text-success` / `bg-success` - Success states
- `text-warning` / `bg-warning` - Warning states
- `text-error` / `bg-error` - Error states

## Usage Guide

### When to Use Each Token

| Context | Token | Example |
|---------|-------|---------|
| Page backgrounds | `bg-page` | `<body class="bg-page">` |
| Content cards | `bg-surface` | `<div class="bg-surface border-surface">` |
| Modals, popovers | `bg-surface-elevated` | `<div class="bg-surface-elevated">` |
| Headings | `text-primary` | `<h1 class="text-primary">` |
| Body text | `text-secondary` | `<p class="text-secondary">` |
| Helper text | `text-tertiary` | `<span class="text-tertiary">` |
| Primary buttons | `bg-accent text-accent-foreground` | `<button class="bg-accent">` |
| Links | `text-accent` | `<a class="text-accent">` |
| Section borders | `border-default` | `<div class="border border-default">` |
| Subtle dividers | `border-subtle` | `<hr class="border-subtle">` |

### Dark Mode

All tokens automatically adapt to dark mode. No need for `dark:` variants:

```blade
<!-- ✅ Good - uses semantic token -->
<div class="bg-page text-primary">

<!-- ❌ Bad - hardcoded with dark variants -->
<div class="bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100">
```

## Creating a New Theme

### Palette Template

```css
/**
 * Your Theme Name
 */

@theme {
    /* ============================================
       NEUTRAL PALETTE (Required: 50-950)
       ============================================ */
    --palette-neutral-50: #...;
    --palette-neutral-100: #...;
    --palette-neutral-200: #...;
    --palette-neutral-300: #...;
    --palette-neutral-400: #...;
    --palette-neutral-500: #...;
    --palette-neutral-600: #...;
    --palette-neutral-700: #...;
    --palette-neutral-800: #...;
    --palette-neutral-900: #...;
    --palette-neutral-950: #...;

    /* ============================================
       PRIMARY PALETTE (Required: 50-950)
       Your brand/accent color
       ============================================ */
    --palette-primary-50: #...;
    --palette-primary-100: #...;
    --palette-primary-200: #...;
    --palette-primary-300: #...;
    --palette-primary-400: #...;
    --palette-primary-500: #...;
    --palette-primary-600: #...;
    --palette-primary-700: #...;
    --palette-primary-800: #...;
    --palette-primary-900: #...;
    --palette-primary-950: #...;

    /* ============================================
       STATE PALETTES (Required)
       ============================================ */
    --palette-success-50 through 900: ...;
    --palette-warning-50 through 900: ...;
    --palette-error-50 through 900: ...;

    /* ============================================
       SPECIAL COLORS (Required)
       ============================================ */
    --palette-dark-page: #262626;  /* Dark mode page background */
    --palette-dark-surface: #1a1a1a;  /* Lighter than page for contrast */
    --palette-dark-surface-elevated: #2d2d2d;  /* For modals/popovers */
    --palette-white: #ffffff;
    --palette-black: #000000;
}
```

### Color Guidelines

**Light Mode:**
- Page background: white or very light neutral (50-100)
- Surfaces: white with subtle borders (200-300)
- Text primary: dark (900)
- Text secondary: medium (700)
- Accent: medium saturation (600)

**Dark Mode:**
- Page background: `--palette-dark-page` (#262626)
- Surfaces: Lighter than page (#1a1a1a) for contrast
- Text primary: light (100)
- Text secondary: medium-light (300)
- Accent: slightly lighter/more vibrant (500)

### Testing Your Theme

1. Create palette file
2. Update import in `app.css`
3. Run `npm run build`
4. Test in browser:
   - Light mode: Check readability, contrast
   - Dark mode: Verify #262626 background, surface contrast
   - Interactive elements: Hover states, focus rings
   - Flux components: Buttons, inputs, modals
   - Markdown: Code blocks, links, tables

## Files in This Directory

- `_base-tokens.css` - **Rarely edited** - Semantic token definitions and mappings
- `palette-tropical-teal.css` - **Current theme** - Tropical teal color palette
- `README.md` - This file

## Troubleshooting

### Tailwind Not Generating Classes

If `bg-page` or other semantic classes aren't working:

1. Check that `@utility` directives exist in `_base-tokens.css`
2. Try arbitrary values temporarily: `bg-[var(--color-page-bg)]`
3. Run `npm run build` (not just `dev`)

### Dark Mode Not Working

1. Verify `.dark` class on `<html>` element
2. Check that dark overrides exist in `_base-tokens.css` `@layer theme .dark` block
3. Test dark mode toggle functionality

### Poor Contrast

If surfaces blend into backgrounds in dark mode:

1. Adjust `--palette-dark-surface` to be lighter than `--palette-dark-page`
2. Ensure borders use `--palette-neutral-700` or lighter in dark mode
3. Test with actual content, not empty boxes

### Flux Components Breaking

1. Keep zinc aliases intact in `app.css`
2. Test each Flux component type after changes
3. Add specific overrides if needed (document in `app.css`)

## Migration Statistics

This theme system was implemented through a systematic migration from hardcoded colors:

### Resources/Views Directory
- **Tropical-teal patterns**: 142 → 0 (100% migrated)
- **Zinc patterns**: 524 → 52 (90% reduction, remaining are intentional)
- **Files migrated**: 42 Blade templates

### Packages Directory
- **Zinc patterns**: 102 → 21 (79% reduction, remaining are intentional)
- **Files migrated**: 13 package templates

### Remaining Intentional Patterns (73 total)
The following patterns were intentionally preserved:
- **31 focus rings** - Accent-specific (e.g., `focus:ring-indigo-500`)
- **8 loading skeletons** - Intentional gray placeholders
- **12 text colors** - Edge cases/specific UI needs
- **1 code block** - Special syntax highlighting case
- **21 in packages** - Similar intentional patterns

### Migration Tools
Automated migration performed using shell scripts with sed/awk:
- `migrate-zinc-v2.sh` - Interleaved class patterns
- `migrate-zinc-v3.sh` - Cross-color patterns (gray→zinc)
- `migrate-zinc-v4.sh` - Form borders, hover states, icons
- `migrate-zinc-v5.sh` - Dividers, text hovers, badges

### Verification
Final verification confirmed:
✅ Zero tropical-teal hardcoded patterns
✅ All remaining zinc patterns are intentional
✅ All semantic token files present and working
✅ Theme switching functional (tested with alternate palette)
✅ Both light and dark modes working correctly

## Support

For questions or issues with the theme system:
1. Check this README
2. Review `palette-tropical-teal.css` as reference implementation
3. Consult approved plan at: `.claude/plans/wobbly-dazzling-boot.md`
4. Review migration scripts in `/tmp/migrate-zinc-v*.sh` for patterns used

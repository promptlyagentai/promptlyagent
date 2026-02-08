# PromptlyAgent API Documentation Theme

Custom theme assets for Scribe API documentation that persist across documentation regenerations.

## Structure

- `css/` - Custom CSS stylesheets for the PromptlyAgent dark theme
  - `theme-promptlyagent.style.css` - Main theme styles (matching application design)
  - `theme-promptlyagent-custom.css` - Additional custom overrides
  - `theme-promptlyagent.print.css` - Print-specific styles
- `js/` - Custom JavaScript files
  - `theme-default-5.6.0.js` - Modified Scribe JS with PHP as default language

## How It Works

The custom theme template is located at:
```
resources/views/vendor/scribe/themes/promptlyagent/index.blade.php
```

This template references these CSS/JS files, which are stored in `public/scribe-theme/` to avoid being overwritten during `scribe:generate`.

## Theme Features

- **Dark Mode**: Complete dark theme matching the main application (#1d202a background, #030712 sidebar)
- **280px Sidebar**: Consistent with main app navigation
- **Two-Column Layout**: Documentation on left, code examples on right
- **Inter Font**: Typography matching main application
- **Syntax Highlighting**: Custom Obsidian-based color scheme
- **HTTP Method Badges**: Color-coded badges for GET, POST, PUT, DELETE
- **Responsive Design**: Mobile-friendly with collapsible sidebar

## Customization

To modify the theme:

1. Edit CSS files in `public/scribe-theme/css/`
2. Edit the template in `resources/views/vendor/scribe/themes/promptlyagent/index.blade.php`
3. Run `./vendor/bin/sail artisan scribe:generate`

**No restore script needed** - theme persists automatically across regenerations!

## Configuration

Theme is configured in `config/scribe.php`:
```php
'theme' => 'promptlyagent',
```

## Legacy

Previous versions used `scripts/restore-docs-theme.sh` to restore theme files after each generation. This is **no longer necessary** with the current setup.

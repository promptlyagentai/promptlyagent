# PDF Export & Document Generation

PromptlyAgent includes a powerful document generation system powered by Pandoc and LaTeX, enabling you to export artifacts and content as high-quality PDFs with professional styling.

## Overview

The PDF export system supports:
- **Multiple Templates** - Eisvogel (technical), Elegant (business), Academic (papers)
- **YAML Metadata Blocks** - Full control over document styling and layout
- **Image Embedding** - Internal assets, chat attachments, and external URLs
- **Custom Styling** - Fonts, colors, headers, footers, and more
- **Format Flexibility** - PDF, DOCX, ODT, and LaTeX source

## Quick Start

### Basic Export

1. Open any artifact in the artifact drawer
2. Click "Save As..." in the top toolbar
3. Click "Export as PDF"
4. The PDF will download with default settings

### Selecting a Template

1. Open the artifact drawer
2. In the "Save As..." menu, click the settings icon (⚙️) next to "Export as PDF"
3. Choose your preferred template:
   - **Eisvogel** - Professional technical documentation with modern styling
   - **Elegant** - Clean business style with generous margins
   - **Academic** - Academic papers with double spacing and citations
4. The template preference is saved per-artifact

## YAML Metadata Blocks

For advanced control over document styling, add a YAML metadata block at the **very top** of your markdown content (before any other text).

**Critical Requirements:**
- Start with `---` on its own line
- End with `...` on its own line (NOT `---` - this can cause parsing issues)
- Place at the absolute beginning of the document (no content before it)
- Leave a blank line after the closing `...` before your content
- **Use double backslashes `\\` for LaTeX commands** (e.g., `\\today`, `\\small`, `\\thepage`)
  - Single backslash `\today` creates escape sequences like `\t` (tab) which breaks YAML parsing!

**Correct Format:**
```yaml
---
title: "Professional Report"
author: "John Doe"
date: "2026-01-16"
titlepage: true
titlepage-color: "468e93"
titlepage-text-color: "FFFFFF"
toc: true
toc-own-page: true
...

# Your content starts here
```

### Eisvogel Custom Variables

These variables are specific to the Eisvogel template and provide custom styling features:

**Title Page Customization:**
```yaml
titlepage: true                      # Enable custom title page (default: false)
titlepage-color: "468e93"           # Background color (hex without #)
titlepage-text-color: "FFFFFF"      # Text color (default: 5F5F5F)
titlepage-rule-color: "435488"      # Rule line color (default: 435488)
titlepage-rule-height: 4            # Rule line height in points (default: 4)
titlepage-logo: "path/to/logo.png"  # Company/project logo
titlepage-background: "path/bg.pdf" # Title page background image
logo-width: 35mm                    # Logo width (default: 35mm)
```

**Table of Contents:**
```yaml
toc: true                           # Enable table of contents (standard Pandoc)
toc-own-page: true                  # Place TOC on separate page (default: false)
```

**Headers & Footers:**
```yaml
header-left: "Project Name"         # Left header (default: title)
header-center: "Confidential"       # Center header (default: empty)
header-right: \\today                # Right header (default: date)
footer-left: "Company Name"         # Left footer (default: author)
footer-center: ""                   # Center footer (default: empty)
footer-right: \\thepage              # Right footer (default: page number)
disable-header-and-footer: false    # Disable all headers/footers (default: false)
```

**Code Blocks:**
```yaml
listings-disable-line-numbers: false         # Show line numbers (default: false)
listings-no-page-break: false                # Prevent page breaks in code (default: false)
```

**Page Backgrounds & Styling:**
```yaml
page-background: "path/to/background.pdf"    # Page background image
page-background-opacity: 0.2                 # Background opacity 0-1 (default: 0.2)
watermark: "DRAFT"                           # Text watermark on each page
caption-justification: raggedright           # Caption alignment (default: raggedright)
```

**Table Styling:**
```yaml
table-use-row-colors: true                   # Alternating row colors (default: false)
```

**Footnote Options:**
```yaml
footnotes-pretty: true                       # Pretty footnote formatting (default: false)
footnotes-disable-backlinks: true            # Disable footnote backlinks (default: false)
```

**Book Formatting:**
```yaml
book: true                                   # Use book class instead of article (default: false)
classoption: [oneside, openany]             # LaTeX class options
first-chapter: 1                            # Starting chapter number (default: 1)
float-placement-figure: H                   # Figure placement: h, t, b, p, H (default: H)
```

### Standard Pandoc Variables

These variables work with Eisvogel and other LaTeX templates:

**Document Information:**
```yaml
title: "Document Title"
author: "Author Name"                        # Can be array: [Author 1, Author 2]
date: "2026-01-16"                          # Or use: "\\today" for current date
subtitle: "Optional Subtitle"
subject: "Document Subject"
keywords: [keyword1, keyword2, keyword3]
lang: en-US                                 # Language code (BCP 47)
```

**Page Layout:**
```yaml
papersize: a4                               # a4, letter, a5, executive
geometry: margin=1in                        # Custom margins
fontsize: 11pt                              # 10pt, 11pt, 12pt
linestretch: 1.2                            # Line spacing multiplier
```

**Typography:**
```yaml
mainfont: "Latin Modern Roman"
sansfont: "Latin Modern Sans"
monofont: "Latin Modern Mono"
fontfamily: lmodern
```

**Citations & References:**
```yaml
bibliography: references.bib                # BibTeX file
csl: apa.csl                               # Citation style
nocite: |
  @*                                        # Include all bibliography entries
```

**Colors:**
```yaml
linkcolor: blue                             # Link color
urlcolor: blue                              # URL color
```

**Code Highlighting:**
```yaml
listings: true                              # Enable listings package for code

### Elegant Template Options

The Elegant template supports a subset of metadata:

```yaml
title: "Business Proposal"
author: "Sales Team"
date: "2026-01-16"
# Elegant focuses on clean, minimal styling with generous margins
# Colors and headers are fixed for consistency
```

### Academic Template Options

The Academic template is designed for research papers:

```yaml
title: "Research Paper Title"
author: "Dr. Jane Smith"
date: "2026-01-16"
abstract: |
  This is the abstract of the paper.
  It can span multiple lines.
bibliography: references.bib        # BibTeX bibliography file
csl: apa.csl                       # Citation style (APA, Chicago, etc.)
nocite: |
  @*                                # Include all bibliography entries
```

## Image Embedding

The PDF export system automatically handles various image sources:

### Internal Assets

Reference internal assets using the `asset://` protocol:

```markdown
![Description](asset://123)
```

### Chat Attachments

Chat attachments are automatically included:

```markdown
![Screenshot]({APP_URL}/chat/attachment/{id}/download)
```

### External Images

External images are downloaded and embedded (with fallback for failures):

```markdown
![Wikipedia Image](https://upload.wikimedia.org/wikipedia/commons/thumb/a/a0/Example.jpg)
```

**Image Sizing:** All images are automatically constrained to:
- Maximum width: page text width
- Maximum height: page text height
- Aspect ratio: preserved

## Template Configuration

### Application-Level Defaults

Default templates and styling are configured in `config/pandoc.php`:

```php
'default_template' => env('PANDOC_DEFAULT_TEMPLATE', 'eisvogel'),

'default_colors' => [
    'linkcolor' => 'rgb,1:0.275,0.557,0.576',  // Tropical teal
    'urlcolor' => 'rgb,1:0.275,0.557,0.576',
    'toccolor' => 'rgb,1:0.275,0.557,0.576',
],
```

### User Preferences

Users can set default templates in their preferences:

```php
$user->preferences = [
    'pdf_export' => [
        'default_template' => 'elegant',
        'fonts' => [
            'mainfont' => 'Times New Roman',
        ],
        'colors' => [
            'linkcolor' => 'blue',
        ],
    ],
];
```

### Per-Artifact Settings

Each artifact stores its preferred template in metadata:

```php
$artifact->metadata = [
    'pdf_template' => 'eisvogel',
];
```

**Precedence:** Artifact metadata > User preferences > Application defaults

## Advanced Examples

### Professional Technical Report

```yaml
---
title: "System Architecture Documentation"
author: "Engineering Team"
date: "\\today"
titlepage: true
titlepage-color: "468e93"
titlepage-text-color: "FFFFFF"
titlepage-rule-height: 2
toc: true
toc-own-page: true
listings: true
listings-disable-line-numbers: false
header-left: "Architecture Docs"
header-right: "v2.0"
footer-left: "Confidential"
footer-right: "Page \\thepage"
...

# Introduction

This document outlines the system architecture...

## Database Schema

```sql
CREATE TABLE users (
    id BIGINT PRIMARY KEY,
    email VARCHAR(255) UNIQUE
);
```
```

### Business Proposal

```yaml
---
title: "Q1 2026 Marketing Strategy"
author: "Marketing Department"
date: "January 16, 2026"
subtitle: "Digital Transformation Initiative"
...

# Executive Summary

Our digital transformation initiative focuses on...
```

### Academic Paper

```yaml
---
title: "The Impact of AI on Software Development"
author: "Dr. Jane Smith"
date: "2026-01-16"
abstract: |
  This paper examines the effects of artificial intelligence
  on modern software development practices.
keywords: [AI, software development, machine learning]
bibliography: references.bib
csl: ieee.csl
fontsize: 12pt
geometry: margin=1in
linestretch: 2.0
...

# Introduction

Recent advances in artificial intelligence...
```

## Troubleshooting

### Images Not Appearing

**Problem:** External images show as "Image unavailable"

**Solution:**
- Some websites block automated downloads (e.g., Wikipedia)
- Use internal assets via `asset://` protocol instead
- Or download and upload images as artifacts

### Template Not Found

**Problem:** PDF generation fails with template error

**Solution:**
- Verify template name matches available templates: `eisvogel`, `elegant`, `academic`
- Check logs: `docker logs promptlyagent-pandoc-1`

### LaTeX Compilation Errors

**Problem:** PDF generation fails with LaTeX errors

**Solution:**
- Check YAML frontmatter syntax (colons, quotes, indentation)
- Verify color values are hex without `#` prefix
- Review special characters in title/author (escape if needed)

### Oversized Images

**Problem:** Images exceed page boundaries

**Solution:**
- Images are automatically sized to fit
- If issues persist, restart Pandoc containers:
  ```bash
  docker restart promptlyagent-pandoc-1 promptlyagent-pandoc-2
  ```

## API Integration

Export PDFs programmatically via the artifacts API:

```bash
# Download artifact as PDF with specific template
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "https://your-app.com/artifacts/123/download-pdf?template=eisvogel" \
  -o document.pdf

# Other formats
curl "https://your-app.com/artifacts/123/download-docx" -o document.docx
curl "https://your-app.com/artifacts/123/download-odt" -o document.odt
curl "https://your-app.com/artifacts/123/download-latex" -o document.tex
```

## Technical Details

### Architecture

The PDF export system uses:
- **Pandoc 3.x** - Universal document converter
- **XeLaTeX** - PDF engine with Unicode support
- **FastAPI** - Python microservice for Pandoc operations
- **Docker** - Load-balanced Pandoc service (2 instances)

### Supported Input Formats

- Markdown (CommonMark)
- YAML metadata blocks
- Embedded HTML
- LaTeX math (`$...$` and `$$...$$`)
- Code blocks with syntax highlighting

### Supported Output Formats

| Format | Extension | MIME Type |
|--------|-----------|-----------|
| PDF | .pdf | application/pdf |
| Word | .docx | application/vnd.openxmlformats-officedocument.wordprocessingml.document |
| OpenDocument | .odt | application/vnd.oasis.opendocument.text |
| LaTeX | .tex | application/x-latex |

### Performance

- **Average generation time:** 2-5 seconds for typical documents
- **Load balancing:** 2 Pandoc instances with Nginx LB
- **Max file size:** 50MB input, 10MB per embedded asset
- **Timeout:** 120 seconds per conversion

## Resources

- **Eisvogel Template**: [GitHub - Wandmalfarbe/pandoc-latex-template](https://github.com/Wandmalfarbe/pandoc-latex-template)
- **Pandoc Documentation**: [pandoc.org/MANUAL.html](https://pandoc.org/MANUAL.html)
- **LaTeX Colors**: Use RGB format `rgb,1:R,G,B` where R,G,B are 0-1 values
- **Template Files**: `docker/pandoc/templates/*.latex`

## Next Steps

- Explore [Architecture](03-architecture.md) for system design details
- Learn about [Workflows](04-workflows.md) for automating document generation
- Review [Package Development](07-package-development.md) for custom integrations

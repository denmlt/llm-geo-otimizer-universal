# LLM & GEO Optimizer for WordPress

Make your WordPress site discoverable by AI models. Generates `llms.txt`, `llms-full.txt`, and Markdown endpoints so ChatGPT, Perplexity, Claude, Gemini, and other LLMs can find, understand, and cite your content.

## What It Does

AI language models increasingly cite websites in their answers. This plugin implements the [llms.txt standard](https://llmstxt.org/) and adds Markdown endpoints so LLMs can consume your content in a clean, structured format.

| Endpoint | Description |
|----------|-------------|
| `/llms.txt` | Structured site index with page titles, URLs, and descriptions. LLMs use this for quick navigation. |
| `/llms-full.txt` | Full content dump in Markdown (configurable size limit). LLMs use this for deep context. |
| `/any-page.md` | Markdown version of any page with YAML front matter (title, description, URL, date, taxonomies). |
| `?format=md` | Alternative access method for Markdown version of any page. |

## Features

- **Zero configuration** — auto-detects all public post types on activation
- **Auto-regeneration** — cache invalidates when content is published, updated, or deleted
- **Domain-portable** — all URLs generated via `home_url()` / `get_permalink()`, works on any domain without reconfiguration
- **SEO-safe** — all Markdown endpoints return `X-Robots-Tag: noindex`, no duplicate content issues
- **robots.txt audit** — built-in checker shows which AI bots are allowed/blocked in your robots.txt
- **CTA links** — optional call-to-action link at the end of each `.md` page (e.g. "Contact us", "Book a demo")
- **No external dependencies** — no Composer, no API calls, no JavaScript frameworks
- **Clean uninstall** — removes all options and transients when deleted

## Installation

1. Download the latest release ZIP
2. Go to **Plugins > Add New > Upload Plugin** in WordPress admin
3. Upload the ZIP and activate
4. Go to **Settings > LLM & GEO** to configure

Or install manually:

```bash
cd wp-content/plugins/
git clone https://github.com/denmlt/llm-geo-otimizer-universal.git
```

Then activate in WordPress admin.

## Configuration

### Settings > LLM & GEO > General

- **Post Types** — select which post types to include in llms.txt and Markdown endpoints
- **Site Description** — one-line summary shown in llms.txt header
- **Size Limit** — maximum character count for llms-full.txt (default: 100,000 chars / ~25K tokens)
- **CTA Text** — optional link text appended to each .md page. Use `{site_name}` placeholder for your site name
- **CTA URL** — where the CTA link points (e.g. `/contact/`)

### Settings > LLM & GEO > Markdown Pages

Browse all published pages with clickable links to their `.md` versions. Filter by post type.

### Settings > LLM & GEO > robots.txt Check

Audit your robots.txt to see which AI bots (GPTBot, ClaudeBot, PerplexityBot, etc.) are allowed or blocked. Shows recommended rules for any blocked bots.

## How It Works

### llms.txt

The plugin groups content by post type and primary taxonomy:

```markdown
# My Website

> One-line site description

## Posts
- [Post Title](/post-slug.md): Short description

## Products — Electronics
- [Widget Pro](/products/widget-pro.md): Best widget for professionals

## Products — Accessories
- [Widget Case](/products/widget-case.md): Protective case for Widget Pro
```

### Markdown Endpoints

Each page gets a clean Markdown version with YAML front matter:

```markdown
---
title: "Widget Pro"
description: "Best widget for professionals"
url: "https://example.com/products/widget-pro/"
date_modified: "2026-04-20"
Category: "Electronics"
---

# Widget Pro

[Clean content converted from HTML to Markdown]

---

**[Read full article: Widget Pro](https://example.com/products/widget-pro/)**

**[Contact My Website](https://example.com/contact/)**
```

### Content Conversion

The HTML-to-Markdown converter handles:
- Headings (h1-h6)
- Bold, italic, links, images
- Ordered and unordered lists
- Blockquotes
- Tables (converted to Markdown pipe tables)
- YAML front matter with taxonomy terms
- HTML entities decoded to clean UTF-8

## robots.txt

For AI bots to access your content, they must not be blocked in robots.txt. Add these rules to allow LLM crawlers:

```
User-agent: GPTBot
Allow: /

User-agent: ChatGPT-User
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: Google-Extended
Allow: /

User-agent: PerplexityBot
Allow: /

User-agent: Applebot-Extended
Allow: /
```

The plugin's **robots.txt Check** tab shows you the current status for each bot.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Pretty permalinks enabled (Settings > Permalinks > any option except "Plain")

## FAQ

**Will this create duplicate content issues?**
No. All `.md` endpoints and `llms.txt` files return `X-Robots-Tag: noindex`. Search engines (Google, Bing) will not index them. Only AI bots that ignore noindex for citation purposes will read them.

**Does it work with custom post types?**
Yes. The plugin auto-detects all public post types on activation. You can enable/disable each type in settings.

**What happens when I move to a different domain?**
Everything works automatically. URLs are generated dynamically via WordPress functions (`home_url()`, `get_permalink()`). The cache regenerates on first request.

**Does it conflict with Yoast / Rank Math / other SEO plugins?**
No. The plugin doesn't touch your HTML pages, meta tags, sitemaps, or schema. It only adds the `.md` / `llms.txt` endpoints.

**What if I already have static llms.txt files?**
The plugin automatically deletes them on activation. If they reappear (e.g. another tool creates them), you'll see a warning in the admin with a one-click delete button.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for details.

## Author

[Denys Dyuzhaev](https://dyuzhaev.com/)

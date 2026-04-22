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

- **Works with any content source** — Gutenberg, Classic Editor, ACF / SCF, Elementor, and other page builders (see Compatibility below)
- **Zero configuration** — auto-detects all public post types on activation
- **Auto-regeneration** — cache invalidates when content is published, updated, or deleted
- **Domain-portable** — all URLs generated via `home_url()` / `get_permalink()`, works on any domain without reconfiguration
- **SEO-safe** — all Markdown endpoints return `X-Robots-Tag: noindex`, no duplicate content issues
- **SEO plugin support** — reads meta descriptions from Rank Math, Yoast SEO, and All in One SEO
- **robots.txt audit** — built-in checker shows which AI bots are allowed/blocked in your robots.txt
- **CTA links** — optional call-to-action link at the end of each `.md` page (e.g. "Contact us", "Book a demo")
- **Extensible** — `llm_geo_post_content` filter lets themes and plugins inject custom content into Markdown output
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

### Content Extraction

The plugin uses a multi-source content pipeline to ensure it works regardless of how your pages are built:

1. **WordPress content pipeline** — runs `the_content` filter with proper `setup_postdata()`. This handles Gutenberg blocks, Classic Editor, shortcode-based builders (Divi, WPBakery), and any builder that hooks into `the_content` (Beaver Builder, Brizy).

2. **Elementor fallback** — if step 1 returns thin content and Elementor is active, the plugin calls Elementor's rendering API (`get_builder_content_for_display`) directly to extract the full page content.

3. **ACF / SCF fallback** — if content is still thin, the plugin recursively walks all Advanced Custom Fields (or Secure Custom Fields) for the post: text, textarea, WYSIWYG, repeater rows, flexible content layouts, and group sub-fields. Non-content fields (images, files, post objects, colors, URLs) are automatically skipped.

The threshold for "thin content" is 100 characters of plain text. If the standard WordPress pipeline already returns substantial content (e.g. a Gutenberg post), the builder/ACF fallbacks are never triggered. This ensures zero impact on sites that don't need them.

Themes and plugins can also inject custom content via the `llm_geo_post_content` filter:

```php
add_filter('llm_geo_post_content', function ($content, $post) {
    // Append custom data to the Markdown source
    $custom = get_post_meta($post->ID, 'my_custom_field', true);
    if ($custom) {
        $content .= '<div>' . $custom . '</div>';
    }
    return $content;
}, 10, 2);
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

## Compatibility

| Content Source | How It Works | Supported |
|---|---|---|
| Gutenberg (Block Editor) | Content in `post_content`, processed via `the_content` filter | Yes |
| Classic Editor | Same as Gutenberg | Yes |
| Elementor | `the_content` hook + direct API fallback | Yes |
| Divi / WPBakery | Shortcodes in `post_content`, processed via `the_content` filter | Yes |
| Beaver Builder / Brizy | Hooks into `the_content` filter | Yes |
| ACF / SCF (Advanced Custom Fields) | Recursive field extraction (text, WYSIWYG, repeaters, flexible content, groups) | Yes |
| Oxygen / Bricks | Use `llm_geo_post_content` filter for custom integration | Via filter |
| Custom meta fields | Use `llm_geo_post_content` filter | Via filter |

| SEO Plugin | Meta Description | Supported |
|---|---|---|
| Rank Math | `rank_math_description` | Yes |
| Yoast SEO | `_yoast_wpseo_metadesc` | Yes |
| All in One SEO | `_aioseo_description` | Yes |

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Pretty permalinks enabled (Settings > Permalinks > any option except "Plain")

## FAQ

**Will this create duplicate content issues?**
No. All `.md` endpoints and `llms.txt` files return `X-Robots-Tag: noindex`. Search engines (Google, Bing) will not index them. Only AI bots that ignore noindex for citation purposes will read them.

**Does it work with custom post types?**
Yes. The plugin auto-detects all public post types on activation. You can enable/disable each type in settings.

**Does it work with ACF / Elementor / Divi / other page builders?**
Yes. The plugin uses a multi-step content extraction pipeline. If the standard WordPress content area is empty (common with ACF-based pages or Elementor), the plugin automatically falls back to builder APIs and ACF field extraction. See the Compatibility section above.

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

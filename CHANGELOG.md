# Changelog

## 1.1.0 — 2026-10-07

Fixes found running the plugin on a 25-site fleet (Aug 2026).

- **Front page markdown link.** The front page was listed as `[Home](.md)`; its path is `/`, so the
  suffix had nothing to hang on. It now links to `?format=md`, the form that answers.
- **`.md` requests are claimed at `parse_request`.** A post type with a taxonomy in its permalink
  registers rewrite rules of its own depth that sat above this plugin's rule and swallowed
  `…/coral-ridge.md` as a post named "coral-ridge.md" (404 in markdown, 200 in HTML).
- **New filters** so a theme can say what the plugin cannot know:
  - `llm_geo_sections` — the llms.txt sections before they are written (add archives, the author page);
  - `llm_geo_query_args` — the query collecting one post type (drop companion pages that 301);
  - `llm_geo_primary_taxonomy` — which taxonomy groups a post type (a flat list when none fits);
  - `llm_geo_front_matter`, `llm_geo_markdown_title` — the document's `url`/`title` when the page is
    published under another address or name.
- A section item may carry its own `md_url` (pages that are not posts link to themselves).
- No duplicate `#` heading when the content already opens with one.

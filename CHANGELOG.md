# Changelog

## 1.2.4 — 2026-10-07

- `bin/check.sh` runs 4 jobs by default, not 8. An uncached translated document holds a PHP worker
  while it renders its own page through a second request; 8 jobs starved a 2-worker site into
  timeouts and untranslated fallbacks. Keep `JOBS` at most half of `pm.max_children`; locally use 1.

## 1.2.3 — 2026-10-07

- `bin/check.sh` fetches links eight at a time (`JOBS=` to change). One by one it took 15–20 minutes
  on a 260-page bilingual site, because an uncached translated document costs a page render; the
  checks and the report are the same.

## 1.2.2 — 2026-10-07

- A translated `.md` whose page render failed (timeout, a 5xx) was built from the untranslated
  fallback and cached for a week — the wrong language until the next save. That fallback is now
  cached for ten minutes only.

## 1.2.1 — 2026-10-07

- Translated documents kept no `<figure>` at all, so card captions the default-language document
  has ("The most common call", "Usually not a repair") were missing from every translated `.md`.
  Only the pictures are dropped now; the captions stay, in the page's language.

## 1.2.0 — 2026-10-07

- **Multilingual sites (TranslatePress).** `/es/llms.txt`, `/es/llms-full.txt` and `/es/<translated
  slug>.md` used to answer 200 in English — a duplicate under the wrong language — and the output
  buffer filed every file in the dictionary as one string. Now each language has its own files;
  see README → Multilingual sites. No change on a single-language site apart from cache keys.
- **llms-full.txt by usefulness.** It stopped at the first document that did not fit, ordered by
  date, so the blog filled it and services and prices never got in. Front page first, then types in
  `llm_geo_full_post_types` order; a document that does not fit is skipped; no per-document CTA.
- **Cache generation.** Every cache key carries a generation number; `LLM_GEO_Language::flush()`
  (the admin "Regenerate" button, `wp llm-geo flush`, a TranslatePress editor save) drops every file
  and document in every language, on an object cache too. "Regenerate" used to leave each page's
  cached markdown as it was.
- **WP-CLI:** `wp llm-geo flush`, `wp llm-geo warm [--flush]`.
- **`bin/check.sh <url>`** — the site as an assistant reads it, in every language.
- CI: `php -l` on PHP 7.4–8.4, shellcheck.

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

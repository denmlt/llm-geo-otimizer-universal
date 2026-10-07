<?php
defined('ABSPATH') || exit;

class LLM_GEO_Content_Converter {

    private const THIN_THRESHOLD = 100;

    /** The meta description of the last page read by translated_content(). */
    private $rendered_description = '';

    // ──────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────

    /**
     * @param int  $post_id  Post ID.
     * @param bool $with_cta Close the document with the read-more and call-to-action lines. llms-full.txt
     *                       leaves them out: the same two lines under every one of a hundred documents
     *                       are bytes the file's size limit then has no room for.
     */
    public function get_post_markdown($post_id, $with_cta = true) {
        $post = get_post($post_id);
        if (!$post || 'publish' !== $post->post_status) {
            return null;
        }

        $key = LLM_GEO_Language::cache_key('llm_geo_md_' . $post_id);
        $markdown = get_transient($key);

        if (false === $markdown) {
            $this->rendered_description = '';
            $html = LLM_GEO_Language::is_translated() ? $this->translated_content($post) : '';
            if ('' === $html) {
                $html = LLM_GEO_Language::html($this->extract_content($post));
            }
            $content = $this->html_to_markdown($html);

            $front_matter = $this->build_front_matter($post);

            /**
             * The heading this document opens with.
             *
             * A theme may render a post under a different name than it is filed under — an archive's
             * companion page is stored as "Error codes archive" and published as "Sub-Zero error codes:
             * what is on your display". The document a model reads should carry the name the page
             * actually shows, not the one the editor sorts by.
             *
             * @param string  $title The post title.
             * @param WP_Post $post  The post.
             */
            $title = LLM_GEO_Language::text(apply_filters('llm_geo_markdown_title', get_the_title($post), $post));

            // 🔴 And only when the body does not already open with one. A page built from sections is
            // flattened into content that starts with its own H1, so every one of those documents began
            // with the same heading printed twice — which reads, to anything parsing structure, as two
            // documents concatenated.
            $heading = preg_match('/^\s*#\s/', $content) ? '' : "\n# " . $title . "\n";

            $markdown = $front_matter . $heading . "\n" . $content;

            // A translated document costs a page render to build; it keeps for a week. Saving the post
            // clears it in every language (invalidate_cache), and so does a translation flush.
            set_transient($key, $markdown, LLM_GEO_Language::is_translated() ? WEEK_IN_SECONDS : DAY_IN_SECONDS);
        }

        $cta = $with_cta ? $this->build_cta($post) : '';
        if ($cta) {
            $markdown .= "\n\n---\n\n" . $cta;
        }

        return $markdown;
    }

    /**
     * The document is already built for this language — reading it costs nothing.
     */
    public function is_cached($post_id) {
        return false !== get_transient(LLM_GEO_Language::cache_key('llm_geo_md_' . $post_id));
    }

    public function get_excerpt($post, $words = 30) {
        $post = get_post($post);
        if (!$post) {
            return '';
        }

        $seo_keys = [
            'rank_math_description',
            '_yoast_wpseo_metadesc',
            '_aioseo_description',
        ];
        foreach ($seo_keys as $key) {
            $value = get_post_meta($post->ID, $key, true);
            if ($value) {
                return wp_trim_words($value, $words, '');
            }
        }

        if ($post->post_excerpt) {
            return wp_trim_words($post->post_excerpt, $words, '');
        }

        $from_content = wp_trim_words(strip_shortcodes($post->post_content), $words, '');
        if ($from_content) {
            return $from_content;
        }

        return $this->get_acf_excerpt($post->ID, $words);
    }

    public function html_to_markdown($html) {
        $html = wp_kses_post($html);

        for ($i = 6; $i >= 1; $i--) {
            $prefix = str_repeat('#', $i);
            $html = preg_replace(
                '/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/is',
                "\n" . $prefix . ' $1' . "\n",
                $html
            );
        }

        $html = preg_replace('/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $html);
        $html = preg_replace('/<(em|i)>(.*?)<\/\1>/is', '*$2*', $html);

        $html = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $html);

        $html = preg_replace('/<img[^>]+alt=["\']([^"\']*)["\'][^>]+src=["\']([^"\']+)["\'][^>]*\/?>/is', '![$1]($2)', $html);
        $html = preg_replace('/<img[^>]+src=["\']([^"\']+)["\'][^>]+alt=["\']([^"\']*)["\'][^>]*\/?>/is', '![$2]($1)', $html);
        $html = preg_replace('/<img[^>]+src=["\']([^"\']+)["\'][^>]*\/?>/is', '![]($1)', $html);

        $html = preg_replace('/<li[^>]*>(.*?)<\/li>/is', '- $1', $html);
        $html = preg_replace('/<\/?[ou]l[^>]*>/is', "\n", $html);

        $html = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', '> $1', $html);

        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        $html = preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', [$this, 'convert_table'], $html);

        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/\n{3,}/', "\n\n", $html);
        $html = trim($html);

        return $html;
    }

    public function invalidate_cache($post_id) {
        // Every language has its own copy of the document.
        foreach (LLM_GEO_Language::languages() as $code) {
            delete_transient(LLM_GEO_Language::cache_key('llm_geo_md_' . $post_id, $code));
        }
    }

    // ──────────────────────────────────────────────
    // Content extraction pipeline
    // ──────────────────────────────────────────────

    /**
     * The content of a page in the language of this request, read from the page itself.
     *
     * 🔴 Translating the plugin's own HTML through the dictionary left half of every page English.
     * A theme builds sentences with `sprintf( __( 'How a visit in %s is arranged' ), $town )`;
     * on the page TranslatePress translates the template, but this HTML is built outside the page,
     * where that translation never runs, and the finished sentence is not in the dictionary. Every
     * one of them was then filed as a new untranslated string — 1,685 on one site.
     *
     * The translated page is what a visitor in that language reads, so the document is made from
     * its <main>. Empty when the page cannot be fetched; the caller then falls back to the
     * dictionary.
     *
     * @param WP_Post $post The post.
     */
    private function translated_content($post) {
        $url = LLM_GEO_Language::url(get_permalink($post));

        $response = wp_remote_get($url, [
            'timeout'     => 30,
            'redirection' => 3,
            'sslverify'   => false,
            'headers'     => ['X-LLM-GEO-Render' => '1'],
        ]);

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return '';
        }

        $body = (string) wp_remote_retrieve_body($response);

        if (!preg_match('#<main\b[^>]*>(.*)</main>#is', $body, $m)) {
            return '';
        }

        // The page's own description, as the dictionary has it — the stored one is longer and is
        // cut by the theme, so translating the stored one matches nothing.
        if (preg_match('#<meta name="description" content="([^"]*)"#i', $body, $d)) {
            $this->rendered_description = html_entity_decode($d[1], ENT_QUOTES, 'UTF-8');
        }

        // Things that are not prose: code, styles, drawings, the booking widget and its controls,
        // breadcrumbs, and pictures — the default-language document carries none of them either. A
        // <figure> stays: themes put card captions in it, and the default-language document has them.
        $main = preg_replace('#<(script|style|noscript|svg|template|iframe|form|button|select|textarea|nav|picture)\b[^>]*>.*?</\1>#is', '', $m[1]);
        $main = preg_replace('#<img\b[^>]*>#i', '', (string) $main);

        // Template indentation is not content; without this every block leaves a run of blank,
        // tab-filled lines in the markdown.
        $main = preg_replace('#[ \t]*\n\s*#', "\n", (string) $main);

        return LLM_GEO_Language::strip((string) $main);
    }

    private function extract_content($target_post) {
        $saved_post = $GLOBALS['post'] ?? null;

        $GLOBALS['post'] = $target_post;
        setup_postdata($target_post);

        $content = apply_filters('the_content', $target_post->post_content);

        $GLOBALS['post'] = $saved_post;
        if ($saved_post) {
            setup_postdata($saved_post);
        }

        if ($this->is_thin_content($content)) {
            $builder = $this->extract_elementor_content($target_post);
            if ($builder) {
                $content = $builder;
            }
        }

        if ($this->is_thin_content($content)) {
            $acf = $this->extract_acf_content($target_post->ID);
            if ($acf) {
                $content = $acf;
            }
        }

        return apply_filters('llm_geo_post_content', $content, $target_post);
    }

    private function is_thin_content($html) {
        return mb_strlen(trim(strip_tags($html))) < self::THIN_THRESHOLD;
    }

    // ──────────────────────────────────────────────
    // Elementor
    // ──────────────────────────────────────────────

    private function extract_elementor_content($post) {
        if (!class_exists('\Elementor\Plugin')) {
            return '';
        }

        $data = get_post_meta($post->ID, '_elementor_data', true);
        if (empty($data)) {
            return '';
        }

        $frontend = \Elementor\Plugin::$instance->frontend ?? null;
        if (!$frontend || !method_exists($frontend, 'get_builder_content_for_display')) {
            return '';
        }

        return $frontend->get_builder_content_for_display($post->ID);
    }

    // ──────────────────────────────────────────────
    // ACF / SCF
    // ──────────────────────────────────────────────

    private function extract_acf_content($post_id) {
        if (!function_exists('get_fields')) {
            return '';
        }

        $fields = get_fields($post_id);
        if (!$fields || !is_array($fields)) {
            return '';
        }

        $parts = [];
        $this->walk_acf_fields($fields, $parts);

        return implode("\n\n", $parts);
    }

    private function walk_acf_fields(array $fields, array &$parts) {
        foreach ($fields as $key => $value) {
            if ('acf_fc_layout' === $key) {
                continue;
            }

            if ($value instanceof \WP_Post) {
                continue;
            }

            if (is_string($value)) {
                $this->collect_text_value($value, $parts);
                continue;
            }

            if (!is_array($value) || empty($value)) {
                continue;
            }

            if (isset($value['ID'], $value['url'])) {
                continue;
            }

            $first = reset($value);
            if ($first instanceof \WP_Post) {
                continue;
            }

            $this->walk_acf_fields($value, $parts);
        }
    }

    private function collect_text_value($value, array &$parts) {
        $stripped = trim(strip_tags($value));

        if (mb_strlen($stripped) < 10) {
            return;
        }

        if (filter_var(trim($value), FILTER_VALIDATE_URL)) {
            return;
        }

        if (filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
            return;
        }

        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($value))) {
            return;
        }

        $parts[] = ($value !== $stripped) ? $value : '<p>' . esc_html($value) . '</p>';
    }

    private function get_acf_excerpt($post_id, $words = 30) {
        if (!function_exists('get_fields')) {
            return '';
        }

        $fields = get_fields($post_id);
        if (!$fields || !is_array($fields)) {
            return '';
        }

        $parts = [];
        $this->walk_acf_fields($fields, $parts);

        if (empty($parts)) {
            return '';
        }

        $text = strip_tags(implode(' ', $parts));
        return wp_trim_words($text, $words, '');
    }

    // ──────────────────────────────────────────────
    // Markdown parts
    // ──────────────────────────────────────────────

    private function convert_table($match) {
        $table_html = $match[1];
        $rows = [];
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $table_html, $tr_matches);

        foreach ($tr_matches[1] as $tr) {
            preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $tr, $td_matches);
            $cells = array_map(function ($cell) {
                $text = trim(strip_tags($cell));
                return 'Array' === $text ? '—' : $text;
            }, $td_matches[1]);
            $rows[] = $cells;
        }

        if (empty($rows)) {
            return '';
        }

        $col_count = count($rows[0]);
        $output = '| ' . implode(' | ', $rows[0]) . " |\n";
        $output .= '| ' . implode(' | ', array_fill(0, $col_count, '---')) . " |\n";

        for ($i = 1; $i < count($rows); $i++) {
            while (count($rows[$i]) < $col_count) {
                $rows[$i][] = '';
            }
            $output .= '| ' . implode(' | ', $rows[$i]) . " |\n";
        }

        return "\n" . $output;
    }

    private function build_front_matter($post) {
        $meta = [
            'title'         => get_the_title($post),
            'description'   => $this->get_excerpt($post, 30),
            'url'           => get_permalink($post),
            'date_modified' => get_the_modified_date('Y-m-d', $post),
        ];

        $taxonomies = get_object_taxonomies($post->post_type, 'objects');
        foreach ($taxonomies as $tax) {
            $terms = get_the_terms($post, $tax->name);
            if ($terms && !is_wp_error($terms)) {
                $names = array_map(['LLM_GEO_Language', 'text'], wp_list_pluck($terms, 'name'));
                $meta[LLM_GEO_Language::strip($tax->labels->singular_name)] = implode(', ', $names);
            }
        }

        $meta = $this->filter_front_matter($meta, $post);

        // After the theme has had its say, because the theme fills these in the default language too.
        if (LLM_GEO_Language::is_translated()) {
            $meta['title'] = LLM_GEO_Language::text($meta['title']);
            if ('' !== $this->rendered_description) {
                $meta['description'] = $this->rendered_description;
            } else {
                // Whole, then cut — the dictionary has the whole description, not its first thirty words.
                $full = html_entity_decode($this->get_excerpt($post, 1000), ENT_QUOTES, 'UTF-8');
                $meta['description'] = wp_trim_words(LLM_GEO_Language::text($full), 30, '');
            }
            $meta['url'] = LLM_GEO_Language::url($meta['url']);
        }
        if (LLM_GEO_Language::available()) {
            $meta['lang'] = LLM_GEO_Language::tag();
        }

        $yaml = "---\n";
        foreach ($meta as $key => $value) {
            $safe_value = str_replace('"', '\\"', $value);
            $yaml .= "$key: \"$safe_value\"\n";
        }
        $yaml .= "---\n\n";

        return $yaml;
    }

    /**
     * The front matter, after the theme has had a say.
     *
     * Two of its four fields can be wrong, and not because the plugin is doing anything wrong. `url` is the post's own
     * permalink, and a page that exists to supply the copy for an archive redirects to that archive
     * — so the document told a model to cite an address that answers 301. `title` has the same
     * problem for the same reason.
     *
     * @param array   $meta The front matter, field => value.
     * @param WP_Post $post The post.
     */
    private function filter_front_matter($meta, $post) {
        return apply_filters('llm_geo_front_matter', $meta, $post);
    }

    private function build_cta($post) {
        $cta_text = get_option('llm_geo_cta_text', '');
        $cta_url = get_option('llm_geo_cta_url', '');

        $site_name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $lines = [];

        $permalink = LLM_GEO_Language::url(get_permalink($post));
        $title = LLM_GEO_Language::text(html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'));
        $read = LLM_GEO_Language::text('Read full article');
        $lines[] = "**[$read: $title]($permalink)**";

        if ($cta_text && $cta_url) {
            if (strpos($cta_url, '/') === 0) {
                $cta_url = home_url($cta_url);
            }
            $cta_url = LLM_GEO_Language::url($cta_url);
            $cta_text = str_replace('{site_name}', $site_name, LLM_GEO_Language::text($cta_text));
            $lines[] = "**[$cta_text]($cta_url)**";
        }

        return implode("\n\n", $lines);
    }
}

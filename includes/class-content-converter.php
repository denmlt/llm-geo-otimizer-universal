<?php
defined('ABSPATH') || exit;

class LLM_GEO_Content_Converter {

    private const THIN_THRESHOLD = 100;

    // ──────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────

    public function get_post_markdown($post_id) {
        $post = get_post($post_id);
        if (!$post || 'publish' !== $post->post_status) {
            return null;
        }

        $cached = get_transient('llm_geo_md_' . $post_id);
        if (false !== $cached) {
            return $cached;
        }

        $html = $this->extract_content($post);
        $content = $this->html_to_markdown($html);

        $front_matter = $this->build_front_matter($post);
        $markdown = $front_matter . "\n# " . get_the_title($post) . "\n\n" . $content;

        $cta = $this->build_cta($post);
        if ($cta) {
            $markdown .= "\n\n---\n\n" . $cta;
        }

        set_transient('llm_geo_md_' . $post_id, $markdown, DAY_IN_SECONDS);

        return $markdown;
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
        delete_transient('llm_geo_md_' . $post_id);
    }

    // ──────────────────────────────────────────────
    // Content extraction pipeline
    // ──────────────────────────────────────────────

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
                $meta[$tax->labels->singular_name] = implode(', ', wp_list_pluck($terms, 'name'));
            }
        }

        $yaml = "---\n";
        foreach ($meta as $key => $value) {
            $safe_value = str_replace('"', '\\"', $value);
            $yaml .= "$key: \"$safe_value\"\n";
        }
        $yaml .= "---\n\n";

        return $yaml;
    }

    private function build_cta($post) {
        $cta_text = get_option('llm_geo_cta_text', '');
        $cta_url = get_option('llm_geo_cta_url', '');

        $site_name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $lines = [];

        $permalink = get_permalink($post);
        $title = html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8');
        $lines[] = "**[Read full article: $title]($permalink)**";

        if ($cta_text && $cta_url) {
            if (strpos($cta_url, '/') === 0) {
                $cta_url = home_url($cta_url);
            }
            $cta_text = str_replace('{site_name}', $site_name, $cta_text);
            $lines[] = "**[$cta_text]($cta_url)**";
        }

        return implode("\n\n", $lines);
    }
}

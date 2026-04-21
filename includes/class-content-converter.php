<?php
defined('ABSPATH') || exit;

class LLM_GEO_Content_Converter {

    public function html_to_markdown($html) {
        $html = wp_kses_post($html);

        // Headings
        for ($i = 6; $i >= 1; $i--) {
            $prefix = str_repeat('#', $i);
            $html = preg_replace(
                '/<h' . $i . '[^>]*>(.*?)<\/h' . $i . '>/is',
                "\n" . $prefix . ' $1' . "\n",
                $html
            );
        }

        // Bold / italic
        $html = preg_replace('/<(strong|b)>(.*?)<\/\1>/is', '**$2**', $html);
        $html = preg_replace('/<(em|i)>(.*?)<\/\1>/is', '*$2*', $html);

        // Links
        $html = preg_replace('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $html);

        // Images
        $html = preg_replace('/<img[^>]+alt=["\']([^"\']*)["\'][^>]+src=["\']([^"\']+)["\'][^>]*\/?>/is', '![$1]($2)', $html);
        $html = preg_replace('/<img[^>]+src=["\']([^"\']+)["\'][^>]+alt=["\']([^"\']*)["\'][^>]*\/?>/is', '![$2]($1)', $html);

        // Lists
        $html = preg_replace('/<li[^>]*>(.*?)<\/li>/is', '- $1', $html);
        $html = preg_replace('/<\/?[ou]l[^>]*>/is', "\n", $html);

        // Blockquotes
        $html = preg_replace('/<blockquote[^>]*>(.*?)<\/blockquote>/is', '> $1', $html);

        // Paragraphs and line breaks
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);

        // Tables — simple conversion
        $html = preg_replace_callback('/<table[^>]*>(.*?)<\/table>/is', [$this, 'convert_table'], $html);

        // Strip remaining HTML
        $html = strip_tags($html);

        // Clean up whitespace
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $html = preg_replace('/\n{3,}/', "\n\n", $html);
        $html = trim($html);

        return $html;
    }

    private function convert_table($match) {
        $table_html = $match[1];
        $rows = [];
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $table_html, $tr_matches);

        foreach ($tr_matches[1] as $tr) {
            preg_match_all('/<t[hd][^>]*>(.*?)<\/t[hd]>/is', $tr, $td_matches);
            $cells = array_map(function ($cell) {
                $text = trim(strip_tags($cell));
                if ($text === 'Array') {
                    return '—';
                }
                return $text;
            }, $td_matches[1]);
            $rows[] = $cells;
        }

        if (empty($rows)) {
            return '';
        }

        $output = '| ' . implode(' | ', $rows[0]) . " |\n";
        $output .= '| ' . implode(' | ', array_fill(0, count($rows[0]), '---')) . " |\n";

        for ($i = 1; $i < count($rows); $i++) {
            while (count($rows[$i]) < count($rows[0])) {
                $rows[$i][] = '';
            }
            $output .= '| ' . implode(' | ', $rows[$i]) . " |\n";
        }

        return "\n" . $output;
    }

    public function get_post_markdown($post_id) {
        $post = get_post($post_id);
        if (!$post || 'publish' !== $post->post_status) {
            return null;
        }

        $cached = get_transient('llm_geo_md_' . $post_id);
        if (false !== $cached) {
            return $cached;
        }

        $content = apply_filters('the_content', $post->post_content);
        $content = $this->html_to_markdown($content);

        $front_matter = $this->build_front_matter($post);
        $markdown = $front_matter . "\n# " . get_the_title($post) . "\n\n" . $content;

        $cta = $this->build_cta($post);
        if ($cta) {
            $markdown .= "\n\n---\n\n" . $cta;
        }

        set_transient('llm_geo_md_' . $post_id, $markdown, DAY_IN_SECONDS);

        return $markdown;
    }

    private function build_front_matter($post) {
        $meta = [
            'title'         => get_the_title($post),
            'description'   => $this->get_description($post),
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

    private function get_description($post) {
        if (function_exists('get_post_meta')) {
            $rank_math = get_post_meta($post->ID, 'rank_math_description', true);
            if ($rank_math) {
                return $rank_math;
            }
        }

        $excerpt = $post->post_excerpt;
        if (!$excerpt) {
            $excerpt = wp_trim_words(strip_shortcodes($post->post_content), 30, '');
        }

        return $excerpt;
    }

    private function build_cta($post) {
        $cta_text = get_option('llm_geo_cta_text', '');
        $cta_url = get_option('llm_geo_cta_url', '');

        $site_name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $lines = [];

        // Link to the original HTML page
        $permalink = get_permalink($post);
        $title = html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8');
        $lines[] = "**[Read full article: $title]($permalink)**";

        // CTA link
        if ($cta_text && $cta_url) {
            if (strpos($cta_url, '/') === 0) {
                $cta_url = home_url($cta_url);
            }
            $cta_text = str_replace('{site_name}', $site_name, $cta_text);
            $lines[] = "**[$cta_text]($cta_url)**";
        }

        return implode("\n\n", $lines);
    }

    public function invalidate_cache($post_id) {
        delete_transient('llm_geo_md_' . $post_id);
    }
}

<?php
defined('ABSPATH') || exit;

class LLM_GEO_LLMS_Generator {

    public function __construct() {
        add_action('save_post', [$this, 'on_content_change'], 20);
        add_action('delete_post', [$this, 'on_content_change'], 20);
        add_action('init', [$this, 'register_rewrite_rules']);
        add_action('template_redirect', [$this, 'serve_files']);
    }

    public function register_rewrite_rules() {
        add_rewrite_rule('^llms\.txt$', 'index.php?llm_geo_file=llms', 'top');
        add_rewrite_rule('^llms-full\.txt$', 'index.php?llm_geo_file=llms-full', 'top');
        add_filter('query_vars', function ($vars) {
            $vars[] = 'llm_geo_file';
            return $vars;
        });
        add_filter('redirect_canonical', [$this, 'prevent_trailing_slash']);
    }

    public function prevent_trailing_slash($redirect_url) {
        if (get_query_var('llm_geo_file')) {
            return false;
        }
        return $redirect_url;
    }

    public function serve_files() {
        $file = get_query_var('llm_geo_file');
        if (!$file) {
            return;
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');

        if ('llms' === $file) {
            echo $this->generate_llms_txt();
        } elseif ('llms-full' === $file) {
            echo $this->generate_llms_full();
        }
        exit;
    }

    public function on_content_change($post_id) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post || 'publish' !== $post->post_status) {
            return;
        }

        $post_types = get_option('llm_geo_post_types', []);
        if (!in_array($post->post_type, $post_types, true)) {
            return;
        }

        delete_transient('llm_geo_llms_txt');
        delete_transient('llm_geo_llms_full');

        $converter = new LLM_GEO_Content_Converter();
        $converter->invalidate_cache($post_id);
    }

    public function generate_llms_txt() {
        $cached = get_transient('llm_geo_llms_txt');
        if (false !== $cached) {
            return $cached;
        }

        $site_name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $description = html_entity_decode(get_option('llm_geo_site_description', get_bloginfo('description')), ENT_QUOTES, 'UTF-8');

        $output = "# $site_name\n\n";
        $output .= "> $description\n\n";

        $post_types = get_option('llm_geo_post_types', ['post', 'page']);
        $sections = $this->build_sections($post_types);

        foreach ($sections as $section_title => $items) {
            if (empty($items)) {
                continue;
            }
            $output .= "## $section_title\n";
            foreach ($items as $item) {
                $md_url = $this->get_md_url($item['url']);
                $output .= "- [{$item['title']}]($md_url): {$item['description']}\n";
            }
            $output .= "\n";
        }

        set_transient('llm_geo_llms_txt', $output, DAY_IN_SECONDS);
        return $output;
    }

    public function generate_llms_full() {
        $cached = get_transient('llm_geo_llms_full');
        if (false !== $cached) {
            return $cached;
        }

        $limit = (int) get_option('llm_geo_llms_full_limit', 100000);
        $site_name = get_bloginfo('name');
        $converter = new LLM_GEO_Content_Converter();

        $output = "# $site_name — Full Content\n\n";
        $output .= "Generated: " . current_time('Y-m-d') . "\n\n---\n\n";

        $post_types = get_option('llm_geo_post_types', ['post', 'page']);
        $posts = get_posts([
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'menu_order date',
            'order'          => 'ASC',
        ]);

        foreach ($posts as $post) {
            $md = $converter->get_post_markdown($post->ID);
            if (!$md) {
                continue;
            }

            if (strlen($output) + strlen($md) > $limit) {
                break;
            }

            $output .= $md . "\n\n---\n\n";
        }

        set_transient('llm_geo_llms_full', $output, DAY_IN_SECONDS);
        return $output;
    }

    private function build_sections($post_types) {
        $sections = [];

        foreach ($post_types as $pt) {
            $type_obj = get_post_type_object($pt);
            if (!$type_obj) {
                continue;
            }

            $taxonomies = get_object_taxonomies($pt, 'objects');
            $primary_tax = null;
            foreach ($taxonomies as $tax) {
                if (!$tax->_builtin) {
                    $primary_tax = $tax;
                    break;
                }
            }

            if ($primary_tax) {
                $terms = get_terms([
                    'taxonomy'   => $primary_tax->name,
                    'hide_empty' => true,
                ]);

                if ($terms && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $section_title = html_entity_decode($type_obj->labels->name . ' — ' . $term->name, ENT_QUOTES, 'UTF-8');
                        $posts = get_posts([
                            'post_type'      => $pt,
                            'post_status'    => 'publish',
                            'posts_per_page' => 50,
                            'tax_query'      => [[
                                'taxonomy' => $primary_tax->name,
                                'terms'    => $term->term_id,
                            ]],
                            'orderby'        => 'title',
                            'order'          => 'ASC',
                        ]);

                        $items = [];
                        foreach ($posts as $post) {
                            $items[] = [
                                'title'       => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                                'url'         => get_permalink($post),
                                'description' => $this->short_description($post),
                            ];
                        }
                        $sections[$section_title] = $items;
                    }
                }
            } else {
                $posts = get_posts([
                    'post_type'      => $pt,
                    'post_status'    => 'publish',
                    'posts_per_page' => 50,
                    'orderby'        => 'menu_order date',
                    'order'          => 'ASC',
                ]);

                $items = [];
                foreach ($posts as $post) {
                    $items[] = [
                        'title'       => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                        'url'         => get_permalink($post),
                        'description' => $this->short_description($post),
                    ];
                }
                if ($items) {
                    $sections[$type_obj->labels->name] = $items;
                }
            }
        }

        return $sections;
    }

    private function short_description($post) {
        if ($post->post_excerpt) {
            return html_entity_decode(wp_trim_words($post->post_excerpt, 15, ''), ENT_QUOTES, 'UTF-8');
        }

        $rank_math = get_post_meta($post->ID, 'rank_math_description', true);
        if ($rank_math) {
            return html_entity_decode(wp_trim_words($rank_math, 15, ''), ENT_QUOTES, 'UTF-8');
        }

        return html_entity_decode(wp_trim_words(strip_shortcodes($post->post_content), 15, ''), ENT_QUOTES, 'UTF-8');
    }

    private function get_md_url($permalink) {
        $path = wp_parse_url($permalink, PHP_URL_PATH);
        if (!$path) {
            return $permalink . '?format=md';
        }
        $path = rtrim($path, '/');
        return $path . '.md';
    }
}

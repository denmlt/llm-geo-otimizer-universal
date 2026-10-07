<?php
defined('ABSPATH') || exit;

class LLM_GEO_LLMS_Generator {

    private $converter;

    public function __construct() {
        $this->converter = new LLM_GEO_Content_Converter();

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
        $this->converter->invalidate_cache($post_id);
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

        /**
         * The assembled sections, before they are written out.
         *
         * Everything above is built from posts, so a page that is not a post cannot get in — and the
         * author archive, which is the page saying who writes the guides and how they are checked,
         * was therefore absent from a file whose whole purpose is telling a model what to trust.
         *
         * @param array $sections Section title => list of ['title','url','description'].
         */
        $sections = apply_filters('llm_geo_sections', $sections);

        foreach ($sections as $section_title => $items) {
            if (empty($items)) {
                continue;
            }
            $output .= "## $section_title\n";
            foreach ($items as $item) {
                // An entry may name its own address. The markdown endpoint serves posts, so a page
                // that is not one — an author archive, say — has no .md form and links to itself.
                $md_url = isset($item['md_url']) ? $item['md_url'] : $this->get_md_url($item['url']);
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
            $md = $this->converter->get_post_markdown($post->ID);
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

    /**
     * Let a theme decide which posts belong in llms.txt.
     *
     * Not every published page is a page worth telling a model about. The common case, and the one
     * this was added for: a theme that uses ordinary pages as companions for post type archives —
     * the page holds the content, the archive is the public URL, and the page itself 301s. Listed
     * unfiltered, llms.txt hands an AI crawler a set of redirects in the one file whose entire
     * purpose is to point at canonical content.
     *
     * Sitemaps have had a hook for this since forever. This is the same hook for this file.
     *
     * @param array  $args WP_Query arguments.
     * @param string $pt   Post type being collected.
     * @return array
     */
    private function query_args($args, $pt) {
        /**
         * Filter the query used to collect posts of one type for llms.txt.
         *
         * @param array  $args Query arguments.
         * @param string $pt   Post type.
         */
        return apply_filters('llm_geo_query_args', $args, $pt);
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

            /**
             * Which taxonomy groups this post type in llms.txt.
             *
             * The default — the first non-builtin taxonomy — is a good guess and a bad rule. A site
             * can attach a custom taxonomy to `post` as a secondary relation while the posts are
             * really organized by category, and the guess then groups every article by a taxonomy
             * none of them use. That is not a visible bug: it produces empty groups, and the whole
             * post type quietly vanishes from the file.
             *
             * Return a WP_Taxonomy, a taxonomy name, or null for a flat list.
             *
             * @param WP_Taxonomy|null $primary_tax Taxonomy chosen by the default rule.
             * @param string           $pt          Post type.
             */
            $primary_tax = apply_filters('llm_geo_primary_taxonomy', $primary_tax, $pt);

            if (is_string($primary_tax)) {
                $primary_tax = get_taxonomy($primary_tax) ?: null;
            }

            $grouped = [];

            if ($primary_tax) {
                $terms = get_terms([
                    'taxonomy'   => $primary_tax->name,
                    'hide_empty' => true,
                ]);

                if ($terms && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $section_title = html_entity_decode($type_obj->labels->name . ' — ' . $term->name, ENT_QUOTES, 'UTF-8');
                        $posts = get_posts($this->query_args([
                            'post_type'      => $pt,
                            'post_status'    => 'publish',
                            'posts_per_page' => 50,
                            'tax_query'      => [[
                                'taxonomy' => $primary_tax->name,
                                'terms'    => $term->term_id,
                            ]],
                            'orderby'        => 'title',
                            'order'          => 'ASC',
                        ], $pt));

                        $items = [];
                        foreach ($posts as $post) {
                            $items[] = [
                                'title'       => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
                                'url'         => get_permalink($post),
                                'description' => $this->short_description($post),
                            ];
                        }
                        if ($items) {
                            $grouped[$section_title] = $items;
                        }
                    }
                }
            }

            if ($grouped) {
                $sections = array_merge($sections, $grouped);
            } else {
                // No taxonomy, or a taxonomy none of these posts actually use. Either way the type
                // gets listed flat rather than not at all — an empty group is the one outcome that
                // helps nobody, and it is what this branch used to produce silently.
                $posts = get_posts($this->query_args([
                    'post_type'      => $pt,
                    'post_status'    => 'publish',
                    'posts_per_page' => 50,
                    'orderby'        => 'menu_order date',
                    'order'          => 'ASC',
                ], $pt));

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
        $excerpt = $this->converter->get_excerpt($post, 15);
        return html_entity_decode($excerpt, ENT_QUOTES, 'UTF-8');
    }

    /**
     * The markdown address for a page.
     *
     * 🔴 The front page came out as `[Home](.md)`, the one broken link in a file of 366. Its path is
     * `/`, which is truthy, so the fallback below was never reached; `rtrim('/', '/')` then left an
     * empty string and `.md` on its own. The rewrite rule is `(.+)\.md$` and needs at least one
     * character before the suffix, so the front page cannot have a `.md` address at all — the
     * `?format=md` form is the one that works, and it is what the fallback was already for.
     *
     * This is the page a model reads first to learn what the site is, on all 25 sites.
     */
    private function get_md_url($permalink) {
        $path = wp_parse_url($permalink, PHP_URL_PATH);
        $path = $path ? rtrim($path, '/') : '';
        if ('' === $path) {
            return $permalink . '?format=md';
        }
        return $path . '.md';
    }
}

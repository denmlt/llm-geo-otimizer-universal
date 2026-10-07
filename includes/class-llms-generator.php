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

        LLM_GEO_Language::prepare();

        // Built before TranslatePress is told to keep off: trp_translate() honours the same switch.
        $body = 'llms' === $file ? $this->generate_llms_txt() : ('llms-full' === $file ? $this->generate_llms_full() : '');

        LLM_GEO_Language::claim_response();
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        if (LLM_GEO_Language::available()) {
            header('Content-Language: ' . LLM_GEO_Language::tag());
        }

        echo $body;
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

        foreach (['llm_geo_llms_txt', 'llm_geo_llms_full'] as $key) {
            foreach (LLM_GEO_Language::languages() as $code) {
                delete_transient(LLM_GEO_Language::cache_key($key, $code));
            }
        }
        $this->converter->invalidate_cache($post_id);
    }

    public function generate_llms_txt() {
        $key = LLM_GEO_Language::cache_key('llm_geo_llms_txt');
        $cached = get_transient($key);
        if (false !== $cached) {
            return $cached;
        }

        $site_name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $description = LLM_GEO_Language::text(html_entity_decode(get_option('llm_geo_site_description', get_bloginfo('description')), ENT_QUOTES, 'UTF-8'));

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
        $sections = $this->localize_sections($sections);

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

        $output .= $this->languages_section('/llms.txt');

        set_transient($key, $output, DAY_IN_SECONDS);
        return $output;
    }

    /**
     * Sections in the language of the request: titles, descriptions and addresses.
     *
     * Done once, after the theme's filters, because the theme adds entries in the default language
     * too. A string the dictionary does not have stays as it is.
     */
    private function localize_sections($sections) {
        if (!LLM_GEO_Language::is_translated()) {
            return $sections;
        }

        $out = [];
        foreach ($sections as $section_title => $items) {
            $parts = array_map(['LLM_GEO_Language', 'text'], explode(' — ', (string) $section_title));
            $title = implode(' — ', $parts);

            foreach ((array) $items as $item) {
                $item['title'] = LLM_GEO_Language::text($item['title']);
                if (empty($item['translated'])) {
                    $item['description'] = LLM_GEO_Language::text($item['description']);
                }
                $item['url'] = LLM_GEO_Language::url($item['url']);
                if (isset($item['md_url'])) {
                    $item['md_url'] = $this->localize_md_url($item['md_url']);
                }
                $out[$title][] = $item;
            }
        }

        return $out;
    }

    /**
     * A markdown address in this language. `/page.md` is the page's address with a suffix, so the
     * page's address is translated and the suffix put back.
     */
    private function localize_md_url($md_url) {
        if (!preg_match('#^(.*)\.md$#', $md_url, $m)) {
            return LLM_GEO_Language::url($md_url);
        }
        $page = 0 === strpos($m[1], '/') ? home_url($m[1] . '/') : $m[1] . '/';
        return $this->get_md_url(LLM_GEO_Language::url($page));
    }

    /**
     * Where the same file lives in the site's other languages.
     */
    private function languages_section($path) {
        $alternates = LLM_GEO_Language::alternates($path);
        if (!$alternates) {
            return '';
        }

        $output = "## " . LLM_GEO_Language::text('Other languages') . "\n";
        foreach ($alternates as $code => $url) {
            $output .= "- [" . LLM_GEO_Language::name($code) . "]($url): " . str_replace('_', '-', $code) . "\n";
        }
        return $output . "\n";
    }

    public function generate_llms_full() {
        $key = LLM_GEO_Language::cache_key('llm_geo_llms_full');
        $cached = get_transient($key);
        if (false !== $cached) {
            return $cached;
        }

        $limit = (int) get_option('llm_geo_llms_full_limit', 100000);
        $site_name = html_entity_decode(get_bloginfo('name'), ENT_QUOTES, 'UTF-8');
        $description = LLM_GEO_Language::text(html_entity_decode(get_option('llm_geo_site_description', get_bloginfo('description')), ENT_QUOTES, 'UTF-8'));

        $output = "# $site_name — " . LLM_GEO_Language::text('Full Content') . "\n\n";
        $output .= "> $description\n\n";
        $output .= "Generated: " . current_time('Y-m-d') . "\n";
        if (LLM_GEO_Language::available()) {
            $output .= "Language: " . LLM_GEO_Language::tag() . "\n";
        }
        $output .= "\n---\n\n";

        $left_out = 0;
        $complete = true;

        // A translated document is read from its rendered page — about a second each. Built cold,
        // the whole file is minutes of work, longer than any request is allowed to run. Each request
        // builds what it can in this budget; the documents are cached one by one, and the file is
        // cached for a day only once nothing was skipped for lack of time.
        $deadline = microtime(true) + 20;

        // 🔴 It used to stop at the first document that did not fit. Ordered by date, that meant the
        // blog filled the file and the services, prices and error codes — the pages a model is
        // asked about — never got in. Now the most useful types come first, and a document that
        // does not fit is skipped rather than ending the file, so the shorter ones after it still do.
        $posts = $this->full_posts();
        foreach ($posts as $i => $post) {
            if (strlen($output) > $limit - 1500) {
                $left_out += count($posts) - $i;
                break;
            }

            if (LLM_GEO_Language::is_translated() && !$this->converter->is_cached($post->ID) && microtime(true) > $deadline) {
                $complete = false;
                $left_out++;
                continue;
            }

            $md = $this->converter->get_post_markdown($post->ID, false);
            if (!$md) {
                continue;
            }

            if (strlen($output) + strlen($md) > $limit) {
                $left_out++;
                continue;
            }

            $output .= $md . "\n\n---\n\n";
        }

        if ($left_out) {
            $index = untrailingslashit(LLM_GEO_Language::url(home_url('/llms.txt')));
            $note = LLM_GEO_Language::text('Documents not included in this file: %d. Every page is listed in %s, and each one has its own markdown version.');
            $output .= sprintf($note, $left_out, $index) . "\n\n";
        }

        $output .= $this->languages_section('/llms-full.txt');

        set_transient($key, $output, $complete ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
        return $output;
    }

    /**
     * Every post for llms-full.txt, most useful first: the front page, then the post types in the
     * order the `llm_geo_full_post_types` filter gives (by default the configured order).
     *
     * @return WP_Post[]
     */
    private function full_posts() {
        $post_types = (array) get_option('llm_geo_post_types', ['post', 'page']);

        /**
         * The post types of llms-full.txt, in the order they are written. Types left out of the
         * returned list are left out of the file.
         *
         * @param string[] $post_types Configured post types.
         */
        $post_types = (array) apply_filters('llm_geo_full_post_types', $post_types);

        $posts = [];
        $front = (int) get_option('page_on_front');
        if ($front && 'publish' === get_post_status($front)) {
            $posts[$front] = get_post($front);
        }

        foreach ($post_types as $pt) {
            $found = get_posts($this->query_args([
                'post_type'      => $pt,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'menu_order title',
                'order'          => 'ASC',
            ], $pt));
            foreach ($found as $post) {
                $posts[$post->ID] = $post;
            }
        }

        return array_values($posts);
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
                                'translated'  => LLM_GEO_Language::is_translated(),
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
                        'translated'  => LLM_GEO_Language::is_translated(),
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
        if (LLM_GEO_Language::is_translated()) {
            // The dictionary has the whole description, not its first fifteen words: translate it
            // whole, then cut. Cut first and the fragment matches nothing — and TranslatePress files
            // every fragment it does not know as a new untranslated string.
            $full = html_entity_decode($this->converter->get_excerpt($post, 1000), ENT_QUOTES, 'UTF-8');
            return wp_trim_words(LLM_GEO_Language::text($full), 15, '');
        }

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
        // A language's front page (/es/) has no `.md` form either: /es.md is not a path anything answers.
        $home = rtrim((string) wp_parse_url(LLM_GEO_Language::url(LLM_GEO_Language::default_url(home_url('/'))), PHP_URL_PATH), '/');
        if ('' === $path || $path === $home) {
            return $permalink . '?format=md';
        }
        return $path . '.md';
    }
}

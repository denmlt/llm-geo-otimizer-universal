<?php
defined('ABSPATH') || exit;

class LLM_GEO_Markdown_Endpoint {

    private $converter;

    public function __construct() {
        $this->converter = new LLM_GEO_Content_Converter();

        add_action('init', [$this, 'register_rewrite_rules']);
        add_action('parse_request', [$this, 'claim_md_requests'], 0);
        add_action('template_redirect', [$this, 'handle_request']);
        add_filter('query_vars', [$this, 'add_query_vars']);
    }

    /**
     * A path ending in .md is ours, whichever rewrite rule got to it first.
     *
     * 🔴 "top" is not a guarantee. A post type with a taxonomy in its permalink registers rules of
     * its own depth, and on the Miami fleet the location type — /service-area/{county}/{city}/{place}/
     * — produced `service-area/[^/]+/[^/]+/([^/]+)/?$`, which sat above this plugin's rule and
     * swallowed `.../coral-ridge.md` as a location named "coral-ridge.md". Nineteen neighborhood
     * pages answered 404 in markdown while answering 200 in HTML, and llms.txt listed all nineteen.
     *
     * Rule ordering is not something this plugin can rely on in somebody else's theme, so the
     * suffix is claimed here instead — after the request is parsed, before the query is built.
     *
     * @param WP $wp The request.
     */
    public function claim_md_requests($wp) {
        $path = isset($wp->request) ? (string) $wp->request : '';

        if ('' === $path || !preg_match('#^(.+)\.md$#', $path, $matches)) {
            return;
        }

        $wp->query_vars   = ['llm_geo_md_slug' => $matches[1]];
        $wp->matched_rule = '(.+)\.md$';
    }

    public function register_rewrite_rules() {
        add_rewrite_rule(
            '(.+)\.md$',
            'index.php?llm_geo_md_slug=$matches[1]',
            'top'
        );
        add_filter('redirect_canonical', [$this, 'prevent_trailing_slash']);
    }

    public function prevent_trailing_slash($redirect_url) {
        if (get_query_var('llm_geo_md_slug')) {
            return false;
        }
        return $redirect_url;
    }

    public function add_query_vars($vars) {
        $vars[] = 'llm_geo_md_slug';
        return $vars;
    }

    public function handle_request() {
        // Method 1: rewrite rule (.md URL)
        $slug = get_query_var('llm_geo_md_slug');

        // Method 2: query parameter (?format=md)
        if (!$slug && isset($_GET['format']) && 'md' === $_GET['format']) {
            $this->serve_current_post();
            return;
        }

        // Method 3: Accept header content negotiation
        if (!$slug && $this->accepts_markdown()) {
            $this->serve_current_post();
            return;
        }

        if (!$slug) {
            return;
        }

        $post = $this->resolve_post_from_slug($slug);
        if (!$post) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "# 404 Not Found\n\nThis page does not exist.";
            exit;
        }

        $this->serve_post_markdown($post->ID);
    }

    private function serve_current_post() {
        if (!is_singular()) {
            return;
        }

        $post = get_queried_object();
        if (!$post || 'publish' !== $post->post_status) {
            return;
        }

        $post_types = get_option('llm_geo_post_types', []);
        if (!in_array($post->post_type, $post_types, true)) {
            return;
        }

        $this->serve_post_markdown($post->ID);
    }

    private function serve_post_markdown($post_id) {
        LLM_GEO_Language::prepare();
        $markdown = $this->converter->get_post_markdown($post_id);

        if (!$markdown) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "# 404 Not Found";
            exit;
        }

        LLM_GEO_Language::claim_response();
        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Robots-Tag: noindex');
        if (LLM_GEO_Language::available()) {
            header('Content-Language: ' . LLM_GEO_Language::tag());
        }
        header('Vary: Accept');
        $token_count = (int) (str_word_count($markdown) * 1.3);
        header('X-Markdown-Tokens: ' . $token_count);
        echo $markdown;
        exit;
    }

    private function accepts_markdown() {
        $accept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
        return strpos($accept, 'text/markdown') !== false;
    }

    private function resolve_post_from_slug($slug) {
        // Remove leading/trailing slashes
        $slug = trim($slug, '/');

        // Try finding by exact path match via url_to_postid
        $url = home_url('/' . $slug . '/');
        $post_id = url_to_postid($url);

        // A translated address (/es/sintomas/lavaplatos-bota-agua.md) names the post by its translated
        // slugs. SEO Pack maps the base back before WordPress sees the request — but not the last
        // segment, which still carries `.md` — so the request arrives half-translated
        // (symptoms/lavaplatos-bota-agua) and matches nothing. Each segment is looked up in SEO Pack's
        // own slug table; a segment it does not know is already an original.
        if (!$post_id && LLM_GEO_Language::is_translated()) {
            $original = LLM_GEO_Language::original_path($slug);
            if ($original !== $slug) {
                $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', home_url('/'));
                $post_id = url_to_postid($origin . '/' . $original . '/');
                $slug = $original;
            }
        }

        if ($post_id) {
            $post = get_post($post_id);
            if ($post && 'publish' === $post->post_status) {
                return $post;
            }
        }

        // Fallback: try last segment as post slug
        $parts = explode('/', $slug);
        $post_slug = end($parts);

        $post_types = get_option('llm_geo_post_types', []);
        $posts = get_posts([
            'name'           => $post_slug,
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
        ]);

        return $posts[0] ?? null;
    }
}

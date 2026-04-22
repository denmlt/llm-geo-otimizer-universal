<?php
defined('ABSPATH') || exit;

class LLM_GEO_Markdown_Endpoint {

    private $converter;

    public function __construct() {
        $this->converter = new LLM_GEO_Content_Converter();

        add_action('init', [$this, 'register_rewrite_rules']);
        add_action('template_redirect', [$this, 'handle_request']);
        add_filter('query_vars', [$this, 'add_query_vars']);
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
        $markdown = $this->converter->get_post_markdown($post_id);

        if (!$markdown) {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "# 404 Not Found";
            exit;
        }

        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $markdown;
        exit;
    }

    private function resolve_post_from_slug($slug) {
        // Remove leading/trailing slashes
        $slug = trim($slug, '/');

        // Try finding by exact path match via url_to_postid
        $url = home_url('/' . $slug . '/');
        $post_id = url_to_postid($url);

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

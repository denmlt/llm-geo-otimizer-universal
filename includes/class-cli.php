<?php
defined('ABSPATH') || exit;

/**
 * Cache control for llms.txt, llms-full.txt and the markdown documents.
 */
class LLM_GEO_CLI {

    /**
     * Drop every cached file and markdown document, in every language.
     *
     * ## EXAMPLES
     *
     *     wp llm-geo flush
     */
    public function flush($args, $assoc_args) {
        LLM_GEO_Language::flush();
        WP_CLI::success('Cache dropped; files and documents rebuild on the next request.');
    }

    /**
     * Build the cache the way a crawler reads the site: llms.txt in every language, every markdown
     * document it lists, then llms-full.txt.
     *
     * A translated document is read from its rendered page, which costs a page render each; a cold
     * translated llms-full.txt is minutes of work. Run this after a deploy or a flush so no visitor
     * pays for it.
     *
     * ## OPTIONS
     *
     * [--flush]
     * : Drop the cache first.
     *
     * ## EXAMPLES
     *
     *     wp llm-geo warm --flush
     */
    public function warm($args, $assoc_args) {
        if (!empty($assoc_args['flush'])) {
            LLM_GEO_Language::flush();
        }

        $failed = 0;

        foreach ($this->language_homes() as $code => $home) {
            $index = $home . 'llms.txt';
            $body = $this->fetch($index);

            if (null === $body) {
                WP_CLI::warning("$code: $index did not answer 200");
                $failed++;
                continue;
            }

            preg_match_all('#\]\(([^)\s]+)\)#', $body, $m);
            $links = array_unique(array_filter($m[1], static function ($u) {
                return false !== strpos($u, '.md') || false !== strpos($u, 'format=md');
            }));

            $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $home);
            $progress = \WP_CLI\Utils\make_progress_bar("$code: " . count($links) . ' documents', count($links));

            foreach ($links as $link) {
                $url = 0 === strpos($link, '/') ? $origin . $link : $link;
                if (null === $this->fetch($url)) {
                    WP_CLI::warning("$code: $url did not answer 200");
                    $failed++;
                }
                $progress->tick();
            }
            $progress->finish();

            if (null === $this->fetch($home . 'llms-full.txt')) {
                WP_CLI::warning("$code: llms-full.txt did not answer 200");
                $failed++;
            }
        }

        $failed ? WP_CLI::error("$failed requests failed.") : WP_CLI::success('Warm.');
    }

    /**
     * code => the language's home URL with a trailing slash.
     */
    private function language_homes() {
        $home = trailingslashit(home_url('/'));
        $homes = [LLM_GEO_Language::default_language() => $home];

        foreach (LLM_GEO_Language::alternates('/') as $code => $url) {
            $homes[$code] = trailingslashit($url);
        }

        return $homes;
    }

    private function fetch($url) {
        $response = wp_remote_get($url, ['timeout' => 60, 'redirection' => 0, 'sslverify' => false]);
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return null;
        }
        return (string) wp_remote_retrieve_body($response);
    }
}

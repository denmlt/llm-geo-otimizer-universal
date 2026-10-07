<?php
defined('ABSPATH') || exit;

/**
 * The language of the request, for a site that serves more than one.
 *
 * 🔴 With TranslatePress on the site, `/es/llms.txt` and `/es/<page>.md` answered 200 — in English.
 * TranslatePress translates HTML pages through its output buffer; this plugin's files are plain text
 * and markdown, so the buffer saw each whole file as one unknown string, filed it in the dictionary
 * as an "original", and served it untranslated. A Spanish address returning an English copy of the
 * English file is a duplicate under the wrong language, which is worse than not having one.
 *
 * Everything here is a no-op on a single-language site: without TranslatePress, or on the default
 * language, every method hands its input back unchanged and every cache key stays what it was.
 * The translations come from the site's own TranslatePress dictionary — the same strings the HTML
 * pages show — never from a machine translation service.
 */
final class LLM_GEO_Language {

    /**
     * TranslatePress is active and able to translate.
     */
    public static function available() {
        return function_exists('trp_translate') && class_exists('TRP_Translate_Press');
    }

    /**
     * The site's default language code (en_US), or the WordPress locale.
     */
    public static function default_language() {
        $settings = get_option('trp_settings', []);
        return isset($settings['default-language']) ? (string) $settings['default-language'] : get_locale();
    }

    /**
     * The language of this request.
     */
    public static function current() {
        global $TRP_LANGUAGE;
        return self::available() && is_string($TRP_LANGUAGE) && '' !== $TRP_LANGUAGE ? $TRP_LANGUAGE : self::default_language();
    }

    /**
     * The request is in a language other than the default one.
     */
    public static function is_translated() {
        return self::available() && self::current() !== self::default_language();
    }

    /**
     * The other published languages, code => absolute URL of $path in that language.
     *
     * @param string $path Site-relative path, e.g. '/llms.txt'.
     */
    public static function alternates($path) {
        if (!self::available()) {
            return [];
        }

        $settings = get_option('trp_settings', []);
        $out = [];

        foreach ((array) ($settings['publish-languages'] ?? []) as $code) {
            if ($code === self::current()) {
                continue;
            }
            $url = self::url_for(self::origin_url($path), $code);
            // A file keeps its name: the converter hands back /es/llms.txt/.
            $out[$code] = false !== strpos(basename($path), '.') ? untrailingslashit($url) : $url;
        }

        return $out;
    }

    /**
     * A transient key for a language (this request's by default).
     *
     * The default language keeps no language suffix. Every key carries the cache generation, so
     * flush() empties every language at once — on an object cache too, where transients are not
     * rows that a LIKE query could reach.
     *
     * @param string      $key      Base key.
     * @param string|null $language Language code; null for the language of this request.
     */
    public static function cache_key($key, $language = null) {
        $language = null === $language ? self::current() : $language;
        $suffix = self::available() && $language !== self::default_language() ? '_' . strtolower($language) : '';

        return $key . $suffix . '_g' . (int) get_option('llm_geo_cache_generation', 1);
    }

    /**
     * Every language the site is translated into, the default one included.
     *
     * @return string[]
     */
    public static function languages() {
        $settings = get_option('trp_settings', []);
        $languages = self::available() ? (array) ($settings['translation-languages'] ?? []) : [];

        return $languages ? $languages : [self::default_language()];
    }

    /**
     * Drop every cached file and document, in every language.
     */
    public static function flush() {
        update_option('llm_geo_cache_generation', (int) get_option('llm_geo_cache_generation', 1) + 1, false);

        // Leave no dead rows behind where transients are rows.
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\\_transient\\_llm\\_geo\\_%'
                OR option_name LIKE '\\_transient\\_timeout\\_llm\\_geo\\_%'"
        );
    }

    /**
     * A string as the site's dictionary has it in this language. Plain text in, plain text out.
     */
    public static function text($text) {
        $text = (string) $text;
        if (!self::is_translated() || '' === trim($text)) {
            return $text;
        }

        // Only the angle brackets are escaped: the dictionary holds "Cooktops & rangetops" with a bare
        // ampersand, and an escaped one is a different string that matches nothing.
        // WordPress stores term names escaped ("Cooktops &amp; rangetops"): decode first, so one string
        // is looked up once and in the form the page shows.
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $translated = trp_translate(str_replace(['<', '>'], ['&lt;', '&gt;'], $text), self::current(), false);

        return html_entity_decode(self::strip($translated), ENT_QUOTES, 'UTF-8');
    }

    /**
     * An HTML fragment translated node by node, exactly as the page itself is translated.
     */
    public static function html($html) {
        $html = (string) $html;
        if (!self::is_translated() || '' === trim($html)) {
            return $html;
        }

        return self::strip(trp_translate($html, self::current(), false));
    }

    /**
     * A URL in this language: language prefix and translated slugs, as TranslatePress builds them.
     */
    public static function url($url) {
        $url = (string) $url;
        if (!self::is_translated() || '' === $url) {
            return $url;
        }

        return self::url_for($url, self::current());
    }

    /**
     * A URL of this language taken back to the default language — the address WordPress can resolve.
     */
    public static function default_url($url) {
        $url = (string) $url;
        if (!self::is_translated() || '' === $url) {
            return $url;
        }

        return self::url_for($url, self::default_language());
    }

    /**
     * A site path with translated slugs (sintomas/nevera-no-enfria) in its default-language form
     * (symptoms/refrigerator-not-cooling), segment by segment, from SEO Pack's slug table.
     */
    public static function original_path($path) {
        if (!self::is_translated() || !class_exists('TRP_Slug_Query')) {
            return $path;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        if (!$segments) {
            return $path;
        }

        $query = new TRP_Slug_Query();
        $map = (array) $query->get_original_slugs_from_translated($segments, self::current());

        foreach ($segments as &$segment) {
            if (isset($map[$segment])) {
                $segment = $map[$segment];
            }
        }
        unset($segment);

        return implode('/', $segments);
    }

    /**
     * The BCP 47 tag of this request (en-US, es-US).
     */
    public static function tag() {
        return str_replace('_', '-', self::current());
    }

    /**
     * The human name of a language, as the site's switcher shows it.
     */
    public static function name($code) {
        if (!self::available()) {
            return $code;
        }

        $languages = TRP_Translate_Press::get_trp_instance()->get_component('languages');
        $names = $languages ? $languages->get_language_names([$code], 'native_name') : [];

        return isset($names[$code]) ? $names[$code] : $code;
    }

    /**
     * Turn on TranslatePress's gettext translation for this request.
     *
     * 🔴 TranslatePress starts translating gettext strings at `wp_head` (priority 100). These files
     * are served at `template_redirect`, before it — so every string a theme builds with
     * `sprintf( __( 'What we repair in %s' ), … )` stayed English, and the whole sentence then went
     * to the dictionary as an unknown original. The page itself translates the template; so must
     * the file.
     */
    public static function prepare() {
        static $done = false;
        if ($done || !self::is_translated()) {
            return;
        }
        $done = true;

        $gettext = TRP_Translate_Press::get_trp_instance()->get_component('gettext_manager');
        if ($gettext && method_exists($gettext, 'apply_gettext_filter')) {
            $gettext->apply_gettext_filter();
        }
    }

    /**
     * Keep TranslatePress's output buffer off this response: it is already in the right language,
     * and the buffer would only file the whole document in the dictionary as one string.
     */
    public static function claim_response() {
        add_filter('trp_stop_translating_page', '__return_true');
    }

    /**
     * Remove TranslatePress's gettext markers. Inside an HTML page its buffer strips them; this
     * output never passes through that buffer, so they would otherwise reach the file.
     */
    public static function strip($text) {
        if (class_exists('TRP_Translation_Manager') && method_exists('TRP_Translation_Manager', 'strip_gettext_tags')) {
            return TRP_Translation_Manager::strip_gettext_tags($text);
        }
        return $text;
    }

    private static function url_for($url, $code) {
        $converter = TRP_Translate_Press::get_trp_instance()->get_component('url_converter');
        return $converter ? (string) $converter->get_url_for_language($code, $url, '') : $url;
    }

    /**
     * $path on the default-language home, whatever the language of this request.
     */
    private static function origin_url($path) {
        $home = self::default_url(home_url('/'));
        return untrailingslashit($home) . '/' . ltrim($path, '/');
    }
}

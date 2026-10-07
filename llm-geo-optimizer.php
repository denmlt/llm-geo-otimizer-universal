<?php
/**
 * Plugin Name: LLM & GEO Optimizer
 * Plugin URI: https://github.com/denmlt/llm-geo-otimizer-universal
 * Description: Generates llms.txt, llms-full.txt, and Markdown endpoints for any WordPress site. Helps AI models (ChatGPT, Perplexity, Claude, Gemini) discover and cite your content.
 * Version: 1.2.4
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Denys Dyuzhaev
 * Author URI: https://dyuzhaev.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: llm-geo
 */

defined('ABSPATH') || exit;

define('LLM_GEO_VERSION', '1.2.4');
define('LLM_GEO_PATH', plugin_dir_path(__FILE__));
define('LLM_GEO_URL', plugin_dir_url(__FILE__));

require_once LLM_GEO_PATH . 'includes/class-language.php';
require_once LLM_GEO_PATH . 'includes/class-content-converter.php';
require_once LLM_GEO_PATH . 'includes/class-llms-generator.php';
require_once LLM_GEO_PATH . 'includes/class-markdown-endpoint.php';
require_once LLM_GEO_PATH . 'includes/class-admin-settings.php';

final class LLM_GEO_Optimizer {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        new LLM_GEO_LLMS_Generator();
        new LLM_GEO_Markdown_Endpoint();

        if (is_admin()) {
            new LLM_GEO_Admin_Settings();
        }
    }
}

add_action('plugins_loaded', ['LLM_GEO_Optimizer', 'instance']);

register_activation_hook(__FILE__, function () {
    $static_files = [
        ABSPATH . 'llms.txt',
        ABSPATH . 'llms-full.txt',
    ];
    foreach ($static_files as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }

    // Auto-detect all public post types
    $types = get_post_types(['public' => true], 'names');
    unset($types['attachment']);
    add_option('llm_geo_post_types', array_values($types));

    add_option('llm_geo_site_description', get_bloginfo('description'));
    add_option('llm_geo_llms_full_limit', 100000);
    flush_rewrite_rules();
    set_transient('llm_geo_activated', true, 30);
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('options-general.php?page=llm-geo-optimizer');
    array_unshift($links, '<a href="' . $url . '">Settings</a>');
    return $links;
});

register_uninstall_hook(__FILE__, 'llm_geo_uninstall');
function llm_geo_uninstall() {
    delete_option('llm_geo_post_types');
    delete_option('llm_geo_site_description');
    delete_option('llm_geo_llms_full_limit');
    delete_option('llm_geo_cta_text');
    delete_option('llm_geo_cta_url');

    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\\_transient\\_llm\\_geo\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_llm\\_geo\\_%'"
    );
    delete_option('llm_geo_cache_generation');
}

/*
 * A translation edited in TranslatePress changes what the translated files say. Their cache does
 * not know that, so it is dropped — the next request rebuilds them.
 */
add_action('trp_save_editor_translations_regular_strings', ['LLM_GEO_Language', 'flush']);
add_action('trp_save_editor_translations_gettext_strings', ['LLM_GEO_Language', 'flush']);

if (defined('WP_CLI') && WP_CLI) {
    require_once LLM_GEO_PATH . 'includes/class-cli.php';
    WP_CLI::add_command('llm-geo', 'LLM_GEO_CLI');
}

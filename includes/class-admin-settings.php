<?php
defined('ABSPATH') || exit;

class LLM_GEO_Admin_Settings {

    private static $llm_bots = [
        'GPTBot'           => 'OpenAI (ChatGPT)',
        'ChatGPT-User'     => 'ChatGPT browsing',
        'ClaudeBot'        => 'Anthropic (Claude)',
        'Google-Extended'   => 'Google Gemini',
        'PerplexityBot'    => 'Perplexity',
        'Applebot-Extended' => 'Apple Intelligence',
        'Amazonbot'        => 'Amazon Alexa',
        'meta-externalagent' => 'Meta AI',
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'activation_notice']);
    }

    public function add_menu() {
        add_options_page(
            'LLM & GEO Optimizer',
            'LLM & GEO',
            'manage_options',
            'llm-geo-optimizer',
            [$this, 'render_page']
        );
    }

    public function register_settings() {
        register_setting('llm_geo_settings', 'llm_geo_post_types', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_post_types'],
            'default'           => ['post', 'page'],
        ]);

        register_setting('llm_geo_settings', 'llm_geo_site_description', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default'           => '',
        ]);

        register_setting('llm_geo_settings', 'llm_geo_llms_full_limit', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 100000,
        ]);

        add_settings_section(
            'llm_geo_general',
            'General Settings',
            null,
            'llm-geo-optimizer'
        );

        add_settings_field(
            'llm_geo_post_types',
            'Post Types to Include',
            [$this, 'render_post_types_field'],
            'llm-geo-optimizer',
            'llm_geo_general'
        );

        add_settings_field(
            'llm_geo_site_description',
            'Site Description (for llms.txt)',
            [$this, 'render_description_field'],
            'llm-geo-optimizer',
            'llm_geo_general'
        );

        add_settings_field(
            'llm_geo_llms_full_limit',
            'llms-full.txt Size Limit (bytes)',
            [$this, 'render_limit_field'],
            'llm-geo-optimizer',
            'llm_geo_general'
        );

        // CTA section
        register_setting('llm_geo_settings', 'llm_geo_cta_text', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('llm_geo_settings', 'llm_geo_cta_url', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_cta_url'],
            'default'           => '',
        ]);

        add_settings_section(
            'llm_geo_cta',
            'Markdown CTA (call-to-action link at the end of each .md page)',
            null,
            'llm-geo-optimizer'
        );

        add_settings_field(
            'llm_geo_cta_text',
            'CTA Text',
            [$this, 'render_cta_text_field'],
            'llm-geo-optimizer',
            'llm_geo_cta'
        );

        add_settings_field(
            'llm_geo_cta_url',
            'CTA URL',
            [$this, 'render_cta_url_field'],
            'llm-geo-optimizer',
            'llm_geo_cta'
        );
    }

    public function sanitize_cta_url($input) {
        $input = sanitize_text_field($input);
        if ('' === $input) {
            return '';
        }
        if (strpos($input, '/') === 0) {
            return $input;
        }
        return esc_url_raw($input);
    }

    public function sanitize_post_types($input) {
        if (!is_array($input)) {
            return [];
        }
        return array_map('sanitize_key', $input);
    }

    public function render_post_types_field() {
        $selected = get_option('llm_geo_post_types', []);
        $types = get_post_types(['public' => true], 'objects');
        unset($types['attachment']);

        foreach ($types as $slug => $type) {
            $checked = in_array($slug, $selected, true) ? 'checked' : '';
            printf(
                '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="llm_geo_post_types[]" value="%s" %s> %s <code>(%s)</code></label>',
                esc_attr($slug),
                $checked,
                esc_html($type->labels->name),
                esc_html($slug)
            );
        }
    }

    public function render_description_field() {
        $value = get_option('llm_geo_site_description', '');
        printf(
            '<textarea name="llm_geo_site_description" rows="3" cols="60" class="large-text">%s</textarea><p class="description">Shown in the header of llms.txt. Use a concise description of what this site offers.</p>',
            esc_textarea($value)
        );
    }

    public function render_limit_field() {
        $value = get_option('llm_geo_llms_full_limit', 100000);
        printf(
            '<input type="number" name="llm_geo_llms_full_limit" value="%d" min="10000" max="500000" step="10000"> <p class="description">Maximum character count for llms-full.txt (~4 chars per token). Default: 100,000 (~25K tokens).</p>',
            $value
        );
    }

    public function render_cta_text_field() {
        $value = get_option('llm_geo_cta_text', '');
        printf(
            '<input type="text" name="llm_geo_cta_text" value="%s" class="regular-text" placeholder="Contact {site_name}"><p class="description">Link text shown at the end of each .md page. Use <code>{site_name}</code> for site name. Leave empty to disable.</p>',
            esc_attr($value)
        );
    }

    public function render_cta_url_field() {
        $value = get_option('llm_geo_cta_url', '');
        printf(
            '<input type="url" name="llm_geo_cta_url" value="%s" class="regular-text" placeholder="%s"><p class="description">Full URL or relative path (e.g. <code>/contact/</code>).</p>',
            esc_attr($value),
            esc_attr(home_url('/contact/'))
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle static file deletion
        if (isset($_POST['llm_geo_delete_static']) && check_admin_referer('llm_geo_delete_static')) {
            @unlink(ABSPATH . 'llms.txt');
            @unlink(ABSPATH . 'llms-full.txt');
            echo '<div class="notice notice-success"><p>Static files deleted. The plugin now serves these endpoints dynamically.</p></div>';
        }

        // Handle manual regeneration
        if (isset($_POST['llm_geo_regenerate']) && check_admin_referer('llm_geo_regenerate_nonce')) {
            delete_transient('llm_geo_llms_txt');
            delete_transient('llm_geo_llms_full');
            echo '<div class="notice notice-success"><p>Cache cleared. Files will regenerate on next request.</p></div>';
        }

        // Warn if static files exist
        $conflicts = [];
        if (file_exists(ABSPATH . 'llms.txt')) {
            $conflicts[] = 'llms.txt';
        }
        if (file_exists(ABSPATH . 'llms-full.txt')) {
            $conflicts[] = 'llms-full.txt';
        }
        if ($conflicts) {
            echo '<div class="notice notice-warning"><p><strong>Conflict:</strong> Static files found at webroot: <code>' . implode('</code>, <code>', $conflicts) . '</code>. Your web server serves these directly, bypassing the plugin. <form method="post" style="display:inline;">' . wp_nonce_field('llm_geo_delete_static', '_wpnonce', true, false) . '<button type="submit" name="llm_geo_delete_static" class="button button-small">Delete static files</button></form></p></div>';
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        ?>
        <div class="wrap">
            <h1>LLM & GEO Optimizer</h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('options-general.php?page=llm-geo-optimizer&tab=general')); ?>" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">General</a>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=llm-geo-optimizer&tab=pages')); ?>" class="nav-tab <?php echo 'pages' === $active_tab ? 'nav-tab-active' : ''; ?>">Markdown Pages</a>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=llm-geo-optimizer&tab=robots')); ?>" class="nav-tab <?php echo 'robots' === $active_tab ? 'nav-tab-active' : ''; ?>">robots.txt Check</a>
            </nav>

            <?php if ('general' === $active_tab) : ?>

            <div class="card" style="max-width:700px;margin:20px 0;padding:15px;">
                <h2 style="margin-top:0;">Generated Files</h2>
                <table class="widefat" style="margin-bottom:15px;">
                    <tr>
                        <td><strong>llms.txt</strong></td>
                        <td><a href="<?php echo esc_url(home_url('/llms.txt')); ?>" target="_blank"><?php echo esc_html(home_url('/llms.txt')); ?></a></td>
                    </tr>
                    <tr>
                        <td><strong>llms-full.txt</strong></td>
                        <td><a href="<?php echo esc_url(home_url('/llms-full.txt')); ?>" target="_blank"><?php echo esc_html(home_url('/llms-full.txt')); ?></a></td>
                    </tr>
                    <tr>
                        <td><strong>Markdown endpoints</strong></td>
                        <td><code>/any-page-slug.md</code> or <code>?format=md</code></td>
                    </tr>
                </table>

                <form method="post">
                    <?php wp_nonce_field('llm_geo_regenerate_nonce'); ?>
                    <button type="submit" name="llm_geo_regenerate" class="button">Regenerate Now</button>
                </form>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields('llm_geo_settings');
                do_settings_sections('llm-geo-optimizer');
                submit_button('Save Settings');
                ?>
            </form>

            <?php elseif ('pages' === $active_tab) : ?>

            <?php $this->render_pages_tab(); ?>

            <?php else : ?>

            <?php $this->render_robots_tab(); ?>

            <?php endif; ?>
        </div>
        <?php
    }

    private function render_pages_tab() {
        $post_types = get_option('llm_geo_post_types', []);
        $per_page = 50;
        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $filter_type = isset($_GET['post_type_filter']) ? sanitize_key($_GET['post_type_filter']) : '';

        $args = [
            'post_type'      => $filter_type ? [$filter_type] : $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'post_type title',
            'order'          => 'ASC',
        ];

        $query = new WP_Query($args);
        $total_pages = $query->max_num_pages;
        ?>
        <div style="margin-top:20px;">
            <form method="get" style="margin-bottom:15px;">
                <input type="hidden" name="page" value="llm-geo-optimizer">
                <input type="hidden" name="tab" value="pages">
                <label><strong>Filter by type:</strong>
                    <select name="post_type_filter" onchange="this.form.submit()">
                        <option value="">All types</option>
                        <?php foreach ($post_types as $pt) :
                            $obj = get_post_type_object($pt);
                            if (!$obj) continue;
                            ?>
                            <option value="<?php echo esc_attr($pt); ?>" <?php selected($filter_type, $pt); ?>><?php echo esc_html($obj->labels->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>

            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Markdown URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($query->have_posts()) : while ($query->have_posts()) : $query->the_post();
                        $permalink = get_permalink();
                        $path = wp_parse_url($permalink, PHP_URL_PATH);
                        $md_url = $path ? home_url(rtrim($path, '/') . '.md') : $permalink . '?format=md';
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url($permalink); ?>" target="_blank"><?php the_title(); ?></a></td>
                            <td><code><?php echo esc_html(get_post_type()); ?></code></td>
                            <td><a href="<?php echo esc_url($md_url); ?>" target="_blank"><?php echo esc_html($md_url); ?></a></td>
                        </tr>
                    <?php endwhile; else : ?>
                        <tr><td colspan="3">No published posts found for selected types.</td></tr>
                    <?php endif; wp_reset_postdata(); ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
            <div class="tablenav" style="margin-top:10px;">
                <div class="tablenav-pages">
                    <?php
                    $base_url = admin_url('options-general.php?page=llm-geo-optimizer&tab=pages');
                    if ($filter_type) $base_url .= '&post_type_filter=' . $filter_type;
                    echo paginate_links([
                        'base'    => $base_url . '&paged=%#%',
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $total_pages,
                    ]);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_robots_tab() {
        $robots_content = $this->get_robots_txt_content();
        $analysis = $this->analyze_robots_txt($robots_content);
        ?>
        <div style="margin-top:20px;max-width:900px;">

            <div class="card" style="padding:15px;margin-bottom:20px;">
                <h2 style="margin-top:0;">robots.txt Analysis</h2>
                <p>For AI models to discover your <code>llms.txt</code> and crawl your content, the relevant bots must not be blocked in <code>robots.txt</code>.</p>

                <?php if (null === $robots_content) : ?>
                    <div class="notice notice-info inline"><p><strong>No robots.txt found.</strong> WordPress will use its default virtual robots.txt which allows all bots. Your LLM endpoints are accessible.</p></div>
                <?php else : ?>

                <?php if (!empty($analysis['blocked'])) : ?>
                    <div class="notice notice-error inline" style="margin:10px 0;">
                        <p><strong>Blocked AI bots detected!</strong> The following bots are blocked in your robots.txt and cannot access your content:</p>
                    </div>
                <?php elseif (!empty($analysis['not_mentioned'])) : ?>
                    <div class="notice notice-warning inline" style="margin:10px 0;">
                        <p><strong>Some AI bots are not explicitly allowed.</strong> They may still crawl (default is allow), but explicit <code>Allow</code> rules are recommended.</p>
                    </div>
                <?php else : ?>
                    <div class="notice notice-success inline" style="margin:10px 0;">
                        <p><strong>All AI bots are allowed.</strong> Your content is fully accessible to LLM crawlers.</p>
                    </div>
                <?php endif; ?>

                <table class="widefat striped" style="margin-top:15px;">
                    <thead>
                        <tr>
                            <th>Bot</th>
                            <th>Service</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (self::$llm_bots as $bot => $service) :
                            if (in_array($bot, $analysis['allowed'], true)) {
                                $status = '<span style="color:#00a32a;font-weight:600;">&#10003; Allowed</span>';
                            } elseif (in_array($bot, $analysis['blocked'], true)) {
                                $status = '<span style="color:#d63638;font-weight:600;">&#10007; Blocked</span>';
                            } else {
                                $status = '<span style="color:#996800;">&#9679; Not mentioned (allowed by default)</span>';
                            }
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($bot); ?></code></td>
                            <td><?php echo esc_html($service); ?></td>
                            <td><?php echo $status; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <?php if (!empty($analysis['blocked'])) : ?>
            <div class="card" style="padding:15px;margin-bottom:20px;">
                <h2 style="margin-top:0;">Recommended robots.txt rules</h2>
                <p>Add these rules to your <code>robots.txt</code> to allow blocked AI bots:</p>
                <textarea class="large-text code" rows="<?php echo count($analysis['blocked']) * 3 + 1; ?>" readonly style="background:#f0f0f0;"><?php
                    foreach ($analysis['blocked'] as $bot) {
                        echo "User-agent: $bot\nAllow: /\n\n";
                    }
                ?></textarea>
                <p class="description">
                    <?php if (file_exists(ABSPATH . 'robots.txt')) : ?>
                        Edit the static file at <code><?php echo esc_html(ABSPATH . 'robots.txt'); ?></code>
                    <?php else : ?>
                        WordPress generates robots.txt dynamically. You can customize it with the <code>robots_txt</code> filter in your theme, or create a static <code>robots.txt</code> at your webroot.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="card" style="padding:15px;">
                <h2 style="margin-top:0;">Current robots.txt</h2>
                <p><a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank"><?php echo esc_html(home_url('/robots.txt')); ?></a></p>
                <?php if (null !== $robots_content) : ?>
                <textarea class="large-text code" rows="15" readonly style="background:#f6f7f7;"><?php echo esc_textarea($robots_content); ?></textarea>
                <?php else : ?>
                <p class="description">No static robots.txt file found. WordPress uses a virtual default.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function get_robots_txt_content() {
        $static_file = ABSPATH . 'robots.txt';
        if (file_exists($static_file)) {
            return file_get_contents($static_file);
        }

        // Try fetching the virtual WP robots.txt
        $response = wp_remote_get(home_url('/robots.txt'), [
            'timeout'   => 5,
            'sslverify' => false,
        ]);

        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            return wp_remote_retrieve_body($response);
        }

        return null;
    }

    private function analyze_robots_txt($content) {
        $result = [
            'allowed'       => [],
            'blocked'       => [],
            'not_mentioned' => [],
        ];

        if (null === $content) {
            $result['not_mentioned'] = array_keys(self::$llm_bots);
            return $result;
        }

        $lines = explode("\n", $content);
        $current_agents = [];
        $wildcard_disallow_all = false;

        // First pass: check if User-agent: * has Disallow: /
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if (preg_match('/^User-agent:\s*\*\s*$/i', $line)) {
                for ($j = $i + 1; $j < count($lines); $j++) {
                    $next = trim($lines[$j]);
                    if ('' === $next || 0 === stripos($next, 'user-agent:')) {
                        break;
                    }
                    if (preg_match('/^Disallow:\s*\/\s*$/i', $next)) {
                        $wildcard_disallow_all = true;
                    }
                }
            }
        }

        foreach (self::$llm_bots as $bot => $service) {
            $bot_lower = strtolower($bot);
            $mentioned = false;
            $is_allowed = null;

            foreach ($lines as $i => $line) {
                $line = trim($line);
                if (preg_match('/^User-agent:\s*(.+)$/i', $line, $m)) {
                    if (strtolower(trim($m[1])) === $bot_lower) {
                        $mentioned = true;
                        // Check the rules after this User-agent line
                        for ($j = $i + 1; $j < count($lines); $j++) {
                            $next = trim($lines[$j]);
                            if ('' === $next || 0 === stripos($next, 'user-agent:')) {
                                break;
                            }
                            if (preg_match('/^Disallow:\s*\/\s*$/i', $next)) {
                                $is_allowed = false;
                            }
                            if (preg_match('/^Allow:\s*\/\s*$/i', $next)) {
                                $is_allowed = true;
                            }
                        }
                    }
                }
            }

            if ($mentioned && true === $is_allowed) {
                $result['allowed'][] = $bot;
            } elseif ($mentioned && false === $is_allowed) {
                $result['blocked'][] = $bot;
            } elseif (!$mentioned && $wildcard_disallow_all) {
                $result['blocked'][] = $bot;
            } elseif ($mentioned) {
                $result['allowed'][] = $bot;
            } else {
                $result['not_mentioned'][] = $bot;
            }
        }

        return $result;
    }

    public function activation_notice() {
        if (!get_transient('llm_geo_activated')) {
            return;
        }
        delete_transient('llm_geo_activated');
        echo '<div class="notice notice-info is-dismissible"><p><strong>LLM & GEO Optimizer</strong> activated. <a href="' . admin_url('options-general.php?page=llm-geo-optimizer') . '">Configure settings &rarr;</a></p></div>';
    }
}

<?php
/**
 * Plugin Name: Propeller Embed
 * Plugin URI: https://www.propellerbonds.com/propeller-embed-plugin
 * Description: Embeds a Propeller app in WordPress with third-party cookie guidance, retry support, an admin settings page, and self-hosted auto-updates.
 * Version: 1.3.13
 * Author: Propeller Bonds
 * License: GPL-2.0-or-later
 * Text Domain: propeller-embed
 */

/* Define FILTER_VALIDATE_BOOLEAN if it's missing (e.g., in older PHP versions)
By adding this check, you are making the plugin more resilient and silencing 
the error from your development tools without needing to change your 
local environment configuration or install WordPress.


if (!defined('ABSPATH')) {
    exit;
}

if (!defined('FILTER_VALIDATE_BOOLEAN')) {
    define('FILTER_VALIDATE_BOOLEAN', 258);
}
*/
final class Propeller_Embed_Plugin
{
    private const OPTION_KEY = 'propeller_embed_settings';
    private const SETTINGS_GROUP = 'propeller_embed_settings_group';
    private const MENU_SLUG = 'propeller-embed-settings';
    private const SHORTCODE = 'propeller_embed';
    private const SWITCHER_SHORTCODE = 'propeller_embed_switcher';
    private const VERSION = '1.3.14';
    private const UPDATE_CACHE_KEY = 'propeller_embed_update_payload';
    private const UPDATE_CACHE_TTL = 3600;

    private static ?Propeller_Embed_Plugin $instance = null;
    private int $instance_counter = 0;

    public static function instance(): Propeller_Embed_Plugin
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_shortcode']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'register_frontend_assets']);
        add_action('admin_post_propeller_embed_check_updates', [$this, 'handle_check_updates']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update_information']);
        add_filter('plugins_api', [$this, 'filter_plugins_api'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'clear_update_cache_after_upgrade'], 10, 2);
    }

    public function register_shortcode(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
        add_shortcode(self::SWITCHER_SHORTCODE, [$this, 'render_switcher_shortcode']);
    }

    public function register_frontend_assets(): void
    {
        wp_register_style(
            'propeller-embed-style',
            plugin_dir_url(__FILE__) . 'assets/propeller-embed.css',
            [],
            self::VERSION
        );

        wp_register_script(
            'propeller-embed-script',
            plugin_dir_url(__FILE__) . 'assets/propeller-embed.js',
            [],
            self::VERSION,
            true
        );
    }

    public function add_settings_link(array $links): array
    {
        $url = admin_url('options-general.php?page=' . self::MENU_SLUG);
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'propeller-embed') . '</a>');
        return $links;
    }

    public function register_admin_menu(): void
    {
        add_options_page(
            __('Propeller Embed Settings', 'propeller-embed'),
            __('Propeller Embed', 'propeller-embed'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            self::SETTINGS_GROUP,
            self::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => $this->get_default_settings(),
            ]
        );

        add_settings_section(
            'propeller_embed_main_section',
            __('Default Embed Settings', 'propeller-embed'),
            function (): void {
                echo '<p>' . esc_html__('These settings control the default behavior of the [propeller_embed] shortcode. Any shortcode attribute can override these defaults per page. You can also use [propeller_embed_switcher] to show one embed at a time behind a dropdown.', 'propeller-embed') . '</p>';
                echo '<p>' . esc_html__('Recommended baseline for a cross-origin Propeller app: allow="storage-access; fullscreen", referrerpolicy="strict-origin-when-cross-origin", loading="lazy", and leave sandbox blank unless you have fully tested a restrictive policy.', 'propeller-embed') . '</p>';
            },
            self::MENU_SLUG
        );

        add_settings_section(
            'propeller_embed_update_section',
            __('Self-hosted auto-update settings', 'propeller-embed'),
            function (): void {
                echo '<p>' . esc_html__('Point the plugin at a JSON manifest hosted on your own site. WordPress will use that manifest to detect and install new versions.', 'propeller-embed') . '</p>';
                echo '<p><code>' . esc_html__('https://www.propellerbonds.com/wp-content/uploads/2026/05/propeller-embed-plugin.zip', 'propeller-embed') . '</code></p>';
            },
            self::MENU_SLUG
        );

        $main_fields = [
            'iframe_src' => __('Iframe URL', 'propeller-embed'),
            'iframe_path_for_new_tab' => __('Open-in-new-tab path', 'propeller-embed'),
            'iframe_width' => __('Iframe width', 'propeller-embed'),
            'iframe_height' => __('Iframe height (px)', 'propeller-embed'),
            'initial_timeout_ms' => __('Initial timeout (ms)', 'propeller-embed'),
            'expected_message_type' => __('Expected postMessage type', 'propeller-embed'),
            'allowed_iframe_origins' => __('Allowed iframe origins', 'propeller-embed'),
            'iframe_allow' => __('Iframe allow attribute', 'propeller-embed'),
            'iframe_sandbox' => __('Iframe sandbox attribute', 'propeller-embed'),
            'iframe_referrerpolicy' => __('Iframe referrerpolicy', 'propeller-embed'),
            'iframe_loading' => __('Iframe loading mode', 'propeller-embed'),
            'debug' => __('Enable debug logging', 'propeller-embed'),
            'warning_title' => __('Warning title', 'propeller-embed'),
            'warning_body' => __('Warning body', 'propeller-embed'),
            'retry_button_label' => __('Retry button label', 'propeller-embed'),
            'new_tab_label' => __('Open-in-new-tab label', 'propeller-embed'),
            'help_html' => __('Help content', 'propeller-embed'),
        ];

        foreach ($main_fields as $field_key => $label) {
            add_settings_field(
                $field_key,
                $label,
                [$this, 'render_field'],
                self::MENU_SLUG,
                'propeller_embed_main_section',
                ['field_key' => $field_key]
            );
        }

        $update_fields = [
            'update_json_url' => __('Update JSON URL', 'propeller-embed'),
            'plugin_homepage' => __('Plugin homepage URL', 'propeller-embed'),
            'release_channel' => __('Release channel', 'propeller-embed'),
        ];

        foreach ($update_fields as $field_key => $label) {
            add_settings_field(
                $field_key,
                $label,
                [$this, 'render_field'],
                self::MENU_SLUG,
                'propeller_embed_update_section',
                ['field_key' => $field_key]
            );
        }
    }

    /** @param mixed $input */
    public function sanitize_settings($input): array
    {
        $defaults = $this->get_default_settings();
        $existing = $this->get_settings();
        $input = is_array($input) ? $input : [];

        $sanitized = $existing;

        $sanitized['iframe_src'] = !empty($input['iframe_src']) ? esc_url_raw(trim((string) $input['iframe_src'])) : $defaults['iframe_src'];
        $sanitized['iframe_path_for_new_tab'] = $this->sanitize_path($input['iframe_path_for_new_tab'] ?? $defaults['iframe_path_for_new_tab']);
        $sanitized['iframe_width'] = $this->sanitize_width($input['iframe_width'] ?? $defaults['iframe_width']);
        $sanitized['iframe_height'] = $this->sanitize_positive_int($input['iframe_height'] ?? $defaults['iframe_height'], (int) $defaults['iframe_height']);
        $sanitized['initial_timeout_ms'] = $this->sanitize_positive_int($input['initial_timeout_ms'] ?? $defaults['initial_timeout_ms'], (int) $defaults['initial_timeout_ms']);
        $sanitized['expected_message_type'] = sanitize_text_field($input['expected_message_type'] ?? $defaults['expected_message_type']);
        $sanitized['allowed_iframe_origins'] = $this->sanitize_origins_text($input['allowed_iframe_origins'] ?? $defaults['allowed_iframe_origins']);
        $sanitized['iframe_allow'] = $this->sanitize_allow_attribute($input['iframe_allow'] ?? $defaults['iframe_allow']);
        $sanitized['iframe_sandbox'] = $this->sanitize_sandbox_attribute($input['iframe_sandbox'] ?? $defaults['iframe_sandbox']);
        $sanitized['iframe_referrerpolicy'] = $this->sanitize_referrerpolicy($input['iframe_referrerpolicy'] ?? $defaults['iframe_referrerpolicy']);
        $sanitized['iframe_loading'] = $this->sanitize_loading($input['iframe_loading'] ?? $defaults['iframe_loading']);
        $sanitized['debug'] = !empty($input['debug']) ? '1' : '0';
        $sanitized['warning_title'] = sanitize_text_field($input['warning_title'] ?? $defaults['warning_title']);
        $sanitized['warning_body'] = sanitize_textarea_field($input['warning_body'] ?? $defaults['warning_body']);
        $sanitized['retry_button_label'] = sanitize_text_field($input['retry_button_label'] ?? $defaults['retry_button_label']);
        $sanitized['new_tab_label'] = sanitize_text_field($input['new_tab_label'] ?? $defaults['new_tab_label']);
        $sanitized['help_html'] = wp_kses_post($input['help_html'] ?? $defaults['help_html']);
        $sanitized['update_json_url'] = !empty($input['update_json_url']) ? esc_url_raw(trim((string) $input['update_json_url'])) : $defaults['update_json_url'];
        $sanitized['plugin_homepage'] = !empty($input['plugin_homepage']) ? esc_url_raw(trim((string) $input['plugin_homepage'])) : $defaults['plugin_homepage'];
        $sanitized['release_channel'] = $this->sanitize_release_channel($input['release_channel'] ?? $defaults['release_channel']);

        $merged = array_merge($defaults, $sanitized);
        $this->clear_update_cache();

        return $merged;
    }

    public function render_field(array $args): void
    {
        $field_key = $args['field_key'] ?? '';
        $settings = $this->get_settings();
        $name = self::OPTION_KEY . '[' . $field_key . ']';
        $value = $settings[$field_key] ?? '';

        switch ($field_key) {
            case 'iframe_src':
                printf(
                    '<input type="url" class="regular-text code" name="%1$s" value="%2$s" placeholder="https://yourwebsite.propeller.insure/axelerator-public" />',
                    esc_attr($name),
                    esc_attr($value)
                );
                echo '<p class="description">' . esc_html__('The full iframe URL used by default.', 'propeller-embed') . '</p>';
                break;

            case 'iframe_path_for_new_tab':
                printf(
                    '<input type="text" class="regular-text code" name="%1$s" value="%2$s" placeholder="/axelerator-public" />',
                    esc_attr($name),
                    esc_attr($value)
                );
                echo '<p class="description">' . esc_html__('Path appended to the iframe origin for the “open in new tab” link.', 'propeller-embed') . '</p>';
                break;

            case 'iframe_width':
                printf(
                    '<input type="text" class="small-text" name="%1$s" value="%2$s" placeholder="90%%" />',
                    esc_attr($name),
                    esc_attr($value)
                );
                echo '<p class="description">' . esc_html__('Examples: 90%, 100%, 1200px.', 'propeller-embed') . '</p>';
                break;

            case 'iframe_height':
            case 'initial_timeout_ms':
                printf(
                    '<input type="number" min="1" step="1" class="small-text" name="%1$s" value="%2$s" />',
                    esc_attr($name),
                    esc_attr((string) $value)
                );
                break;

            case 'expected_message_type':
            case 'warning_title':
            case 'retry_button_label':
            case 'new_tab_label':
                printf(
                    '<input type="text" class="regular-text" name="%1$s" value="%2$s" />',
                    esc_attr($name),
                    esc_attr($value)
                );
                break;

            case 'allowed_iframe_origins':
                printf(
                    '<textarea class="large-text code" rows="5" name="%1$s">%2$s</textarea>',
                    esc_attr($name),
                    esc_textarea($value)
                );
                echo '<p class="description">' . esc_html__('One origin per line, for example https://propellerwebsite.propeller.insure', 'propeller-embed') . '</p>';
                break;

            case 'iframe_allow':
            case 'iframe_sandbox':
                printf(
                    '<input type="text" class="large-text code" name="%1$s" value="%2$s" />',
                    esc_attr($name),
                    esc_attr($value)
                );
                if ($field_key === 'iframe_allow') {
                    echo '<p class="description">' . esc_html__('Recommended: storage-access; fullscreen. Add more only when the embedded app genuinely needs them.', 'propeller-embed') . '</p>';
                } else {
                    echo '<p class="description">' . esc_html__('Leave blank for maximum compatibility. A restrictive sandbox can break login, cookies, popups, forms, or redirects in embedded apps.', 'propeller-embed') . '</p>';
                }
                break;

            case 'iframe_referrerpolicy':
                $policies = [
                    'no-referrer' => 'no-referrer',
                    'no-referrer-when-downgrade' => 'no-referrer-when-downgrade',
                    'origin' => 'origin',
                    'origin-when-cross-origin' => 'origin-when-cross-origin',
                    'same-origin' => 'same-origin',
                    'strict-origin' => 'strict-origin',
                    'strict-origin-when-cross-origin' => 'strict-origin-when-cross-origin',
                    'unsafe-url' => 'unsafe-url',
                ];
                echo '<select name="' . esc_attr($name) . '">';
                foreach ($policies as $policy_value => $policy_label) {
                    echo '<option value="' . esc_attr($policy_value) . '" ' . selected($value, $policy_value, false) . '>' . esc_html($policy_label) . '</option>';
                }
                echo '</select>';
                echo '<p class="description">' . esc_html__('Recommended: strict-origin-when-cross-origin.', 'propeller-embed') . '</p>';
                break;

            case 'iframe_loading':
                echo '<select name="' . esc_attr($name) . '">';
                echo '<option value="lazy" ' . selected($value, 'lazy', false) . '>lazy</option>';
                echo '<option value="eager" ' . selected($value, 'eager', false) . '>eager</option>';
                echo '</select>';
                echo '<p class="description">' . esc_html__('Use lazy for most pages. Use eager only when the iframe must begin loading immediately above the fold.', 'propeller-embed') . '</p>';
                break;

            case 'warning_body':
                printf(
                    '<textarea class="large-text" rows="4" name="%1$s">%2$s</textarea>',
                    esc_attr($name),
                    esc_textarea($value)
                );
                break;

            case 'help_html':
                wp_editor(
                    $value,
                    'propeller_embed_help_html',
                    [
                        'textarea_name' => $name,
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                    ]
                );
                echo '<p class="description">' . esc_html__('Displayed inside the expandable help section below the warning.', 'propeller-embed') . '</p>';
                break;

            case 'debug':
                printf(
                    '<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
                    esc_attr($name),
                    checked($value, '1', false),
                    esc_html__('Show client-side debug logs and console output.', 'propeller-embed')
                );
                break;

            case 'update_json_url':
            case 'plugin_homepage':
                printf(
                    '<input type="url" class="large-text code" name="%1$s" value="%2$s" />',
                    esc_attr($name),
                    esc_attr($value)
                );
                if ($field_key === 'update_json_url') {
                    echo '<p class="description">' . esc_html__('The self-hosted JSON manifest that describes the latest version and ZIP package URL.', 'propeller-embed') . '</p>';
                } else {
                    echo '<p class="description">' . esc_html__('Used for plugin details and the “View details” link in WordPress.', 'propeller-embed') . '</p>';
                }
                break;

            case 'release_channel':
                echo '<select name="' . esc_attr($name) . '">';
                echo '<option value="stable" ' . selected($value, 'stable', false) . '>stable</option>';
                echo '<option value="beta" ' . selected($value, 'beta', false) . '>beta</option>';
                echo '</select>';
                echo '<p class="description">' . esc_html__('WordPress will only offer updates when the manifest channel matches this setting, or when the manifest omits a channel.', 'propeller-embed') . '</p>';
                break;
        }
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = $this->get_settings();
        $shortcode_example = '[' . self::SHORTCODE . ' src="https://yourwebsite.propeller.insure/axelerator-public" height="1000" debug="true" referrerpolicy="strict-origin-when-cross-origin" allow="storage-access; fullscreen"]';
        $switcher_example = '[' . self::SWITCHER_SHORTCODE . ']';
        $manifest = $this->get_remote_update_manifest(false);
        $check_url = wp_nonce_url(
            admin_url('admin-post.php?action=propeller_embed_check_updates'),
            'propeller_embed_check_updates'
        );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Propeller Embed Settings', 'propeller-embed'); ?></h1>
            <?php $this->render_admin_notice_from_query(); ?>

            <p><?php echo esc_html__('Use the shortcode below in any post, page, or widget area that supports shortcodes.', 'propeller-embed'); ?></p>
            <p><code><?php echo esc_html('[' . self::SHORTCODE . ']'); ?></code></p>
            <p><code><?php echo esc_html($shortcode_example); ?></code></p>
            <p><code><?php echo esc_html($switcher_example); ?></code></p>

            <div style="margin:16px 0 20px;padding:16px;background:#fff;border:1px solid #dcdcde;max-width:1100px;">
                <h2 style="margin-top:0;"><?php echo esc_html__('Update status', 'propeller-embed'); ?></h2>
                <p><strong><?php echo esc_html__('Installed version:', 'propeller-embed'); ?></strong> <?php echo esc_html(self::VERSION); ?></p>
                <p><strong><?php echo esc_html__('Configured JSON URL:', 'propeller-embed'); ?></strong> <code><?php echo esc_html((string) $settings['update_json_url']); ?></code></p>
                <?php if (is_array($manifest)) : ?>
                    <p><strong><?php echo esc_html__('Latest manifest version:', 'propeller-embed'); ?></strong> <?php echo esc_html((string) ($manifest['version'] ?? '—')); ?></p>
                    <p><strong><?php echo esc_html__('Manifest channel:', 'propeller-embed'); ?></strong> <?php echo esc_html((string) ($manifest['channel'] ?? 'stable')); ?></p>
                    <p><strong><?php echo esc_html__('Package URL:', 'propeller-embed'); ?></strong> <code><?php echo esc_html((string) ($manifest['download_url'] ?? '')); ?></code></p>
                    <p><strong><?php echo esc_html__('Update available now:', 'propeller-embed'); ?></strong>
                        <?php
                        echo esc_html(
                            $this->manifest_has_usable_update($manifest)
                                ? __('Yes', 'propeller-embed')
                                : __('No', 'propeller-embed')
                        );
                        ?>
                    </p>
                <?php else : ?>
                    <p><?php echo esc_html__('No valid manifest is cached yet. Save your update settings, then click the button below.', 'propeller-embed'); ?></p>
                <?php endif; ?>
                <p>
                    <a href="<?php echo esc_url($check_url); ?>" class="button button-secondary"><?php echo esc_html__('Check for updates now', 'propeller-embed'); ?></a>
                </p>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields(self::SETTINGS_GROUP);
                do_settings_sections(self::MENU_SLUG);
                submit_button();
                ?>
            </form>

            <div style="margin-top:24px;max-width:1100px;">
                <h2><?php echo esc_html__('Recommended JSON manifest', 'propeller-embed'); ?></h2>
                <pre style="background:#fff;padding:16px;border:1px solid #dcdcde;overflow:auto;">{
  "name": "Get Bonds",
  "slug": "propeller-embed-plugin",
  
  "author": "Propeller Bonds",
  "channel": "stable",

  "homepage": "https://www.propellerbonds.com/propeller-embed-plugins",
  "download_url": "https://www.propellerbonds.com/wp-content/uploads/2026/05/propeller-embed-plugin.zip",
  "requires": "6.0",
  "tested": "6.8",
  "requires_php": "7.4",
  "last_updated": "2026-05-13",
  "sections": {
    "description": "Embeds a Propeller app in WordPress with cookie guidance, retry support, one-at-a-time dropdown switching, dynamic embed initialization, and self-hosted auto-updates.",
    "installation": "Upload the ZIP in Plugins → Add New → Upload Plugin. Activate it, then configure it under Settings → Propeller Embed.",
    "changelog": "= 1.3.9 =
* Added [propeller_embed_switcher] for true one-at-a-time iframe loading.
* Added dynamic re-initialization for embeds inserted after page load.
* Updated the recommended JSON manifest author and homepage fields."
  }
}</pre>
                <p><?php echo esc_html__('Versioning strategy: keep your plugin folder/file slug unchanged, bump the plugin header version, upload the new ZIP to a stable HTTPS URL, then update the JSON manifest version and download_url to match. Each release should also keep the per-instance message handling backward compatible unless the embedded app is updated in lockstep.', 'propeller-embed'); ?></p>
            </div>
        </div>
        <?php
    }

    public function render_shortcode(array $atts = [], ?string $content = null): string
    {
        $settings = $this->get_settings();

        $atts = shortcode_atts(
            [
                'src' => $settings['iframe_src'],
                'width' => $settings['iframe_width'],
                'height' => (string) $settings['iframe_height'],
                'debug' => $settings['debug'] === '1' ? 'true' : 'false',
                'timeout_ms' => (string) $settings['initial_timeout_ms'],
                'expected_message_type' => $settings['expected_message_type'],
                'allowed_origins' => $settings['allowed_iframe_origins'],
                'allow' => $settings['iframe_allow'],
                'sandbox' => $settings['iframe_sandbox'],
                'referrerpolicy' => $settings['iframe_referrerpolicy'],
                'loading' => $settings['iframe_loading'],
                'warning_title' => $settings['warning_title'],
                'warning_body' => $settings['warning_body'],
                'retry_label' => $settings['retry_button_label'],
                'new_tab_label' => $settings['new_tab_label'],
                'new_tab_path' => $settings['iframe_path_for_new_tab'],
                'show_help' => 'true',
            ],
            $atts,
            self::SHORTCODE
        );

        $raw_src = esc_url_raw((string) $atts['src']);
        if ($raw_src === '') {
            return '<div class="propeller-embed-error">' . esc_html__('Propeller Embed is missing a valid iframe URL.', 'propeller-embed') . '</div>';
        }

        $iframe_width = $this->sanitize_width($atts['width']);
        $iframe_height = $this->sanitize_positive_int($atts['height'], (int) $settings['iframe_height']);
        $debug = filter_var($atts['debug'], FILTER_VALIDATE_BOOLEAN);
        $timeout_ms = $this->sanitize_positive_int($atts['timeout_ms'], (int) $settings['initial_timeout_ms']);
        $expected_message_type = sanitize_text_field($atts['expected_message_type']);
        $iframe_allow = $this->sanitize_allow_attribute($atts['allow']);
        $iframe_sandbox = $this->sanitize_sandbox_attribute($atts['sandbox']);
        $iframe_referrerpolicy = $this->sanitize_referrerpolicy($atts['referrerpolicy']);
        $iframe_loading = $this->sanitize_loading($atts['loading']);
        $warning_title = sanitize_text_field($atts['warning_title']);
        $warning_body = sanitize_text_field($atts['warning_body']);
        $retry_label = sanitize_text_field($atts['retry_label']);
        $new_tab_label = sanitize_text_field($atts['new_tab_label']);
        $new_tab_path = $this->sanitize_path($atts['new_tab_path']);
        $show_help = filter_var($atts['show_help'], FILTER_VALIDATE_BOOLEAN);

        $instance_id = 'propeller-embed-' . wp_generate_uuid4();
        $handshake_timeout_ms = max($timeout_ms, 3000);
        $iframe_src_with_instance = add_query_arg(
            [
                'propeller_embed_instance' => $instance_id,
            ],
            $raw_src
        );
        $iframe_src = esc_url($iframe_src_with_instance);

        $parsed_url = wp_parse_url($raw_src);
        $iframe_origin = '';
        $iframe_host = '';
        if (!empty($parsed_url['scheme']) && !empty($parsed_url['host'])) {
            $iframe_origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
            $iframe_host = $parsed_url['host'];
        }

        $allowed_origins_text = is_string($atts['allowed_origins']) ? $atts['allowed_origins'] : '';
        $allowed_origins = $this->normalize_origins_text_to_array($allowed_origins_text);
        if ($iframe_origin !== '' && !in_array($iframe_origin, $allowed_origins, true)) {
            $allowed_origins[] = $iframe_origin;
        }
        $allowed_origins = array_values(array_unique(array_filter($allowed_origins)));

        $config = [
            'debug' => $debug,
            'initialTimeoutMs' => $timeout_ms,
            'handshakeTimeoutMs' => $handshake_timeout_ms,
            'expectedMessageType' => $expected_message_type,
            'allowedIframeOrigins' => $allowed_origins,
            'newTabPath' => $new_tab_path,
            'handshakeInitType' => 'propeller-handshake-init',
            'handshakeAckType' => 'propeller-handshake-ack',
        ];

        wp_enqueue_style('propeller-embed-style');
        wp_enqueue_script('propeller-embed-script');

        $json = wp_json_encode($config);
        if ($json) {
            wp_add_inline_script(
                'propeller-embed-script',
                'window.PropellerEmbedInstances = window.PropellerEmbedInstances || {}; window.PropellerEmbedInstances[' . wp_json_encode($instance_id) . '] = ' . $json . ';',
                'before'
            );
        }

        $help_html = $show_help ? wp_kses_post($settings['help_html']) : '';

        ob_start();
        ?>
        <div class="propeller-embed" id="<?php echo esc_attr($instance_id); ?>" data-instance-id="<?php echo esc_attr($instance_id); ?>">
            <div class="propeller-cookie-warning" hidden aria-live="polite">
                <strong class="propeller-cookie-warning-title"><?php echo esc_html($warning_title); ?></strong>

                <div class="propeller-cookie-warning-text">
                    <?php echo esc_html($warning_body); ?>
                    <code class="cookie-domain-label"><?php echo esc_html($iframe_host); ?></code>
                    <?php echo esc_html__('to function correctly. If third-party cookies are blocked, it may not load properly.', 'propeller-embed'); ?>
                </div>

                <div class="cookie-warning-detail"></div>

                <?php if ($show_help && $help_html !== '') : ?>
                    <details class="propeller-cookie-details">
                        <summary><strong><?php echo esc_html__('How to allow third-party cookies', 'propeller-embed'); ?></strong></summary>
                        <div class="propeller-cookie-help">
                            <?php echo $help_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    </details>
                <?php endif; ?>

                <div class="propeller-cookie-actions">
                    <button class="retry-embed" type="button">
                        <?php echo esc_html($retry_label); ?>
                    </button>

                    <a class="open-new-tab" href="<?php echo esc_url($iframe_src); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($new_tab_label); ?>
                    </a>
                </div>

                <div class="propeller-debug-panel" <?php echo $debug ? '' : 'hidden="hidden" style="display:none;" aria-hidden="true"'; ?>>
                    <div class="propeller-debug-panel-header">
                        <strong><?php echo esc_html__('Debug panel', 'propeller-embed'); ?></strong>
                        <span class="propeller-debug-instance-label"><?php echo esc_html($instance_id); ?></span>
                        <button type="button" class="propeller-debug-clear button-link"><?php echo esc_html__('Clear log', 'propeller-embed'); ?></button>
                    </div>
                    <div class="propeller-debug-meta">
                        <span><strong><?php echo esc_html__('Instance:', 'propeller-embed'); ?></strong> <code><?php echo esc_html($instance_id); ?></code></span>
                        <span><strong><?php echo esc_html__('Origin:', 'propeller-embed'); ?></strong> <code><?php echo esc_html($iframe_origin); ?></code></span>
                        <span><strong><?php echo esc_html__('Handshake:', 'propeller-embed'); ?></strong> <code class="propeller-debug-handshake-state">pending</code></span>
                        <span><strong><?php echo esc_html__('Last state:', 'propeller-embed'); ?></strong> <code class="propeller-debug-last-state">none</code></span>
                        <span><strong><?php echo esc_html__('Last reason:', 'propeller-embed'); ?></strong> <code class="propeller-debug-last-reason">initialized</code></span>
                        <span><strong><?php echo esc_html__('Messages seen:', 'propeller-embed'); ?></strong> <code class="propeller-debug-message-count">0</code></span>
                        <span><strong><?php echo esc_html__('Source matches:', 'propeller-embed'); ?></strong> <code class="propeller-debug-source-match-count">0</code></span>
                        <span><strong><?php echo esc_html__('Query instance:', 'propeller-embed'); ?></strong> <code class="propeller-debug-query-instance"><?php echo esc_html($instance_id); ?></code></span>
                        <span><strong><?php echo esc_html__('Last origin:', 'propeller-embed'); ?></strong> <code class="propeller-debug-last-origin">none</code></span>
                        <span><strong><?php echo esc_html__('Handshake token:', 'propeller-embed'); ?></strong> <code class="propeller-debug-token">pending</code></span>
                        <span class="propeller-debug-meta-full"><strong><?php echo esc_html__('Current iframe src:', 'propeller-embed'); ?></strong> <code class="propeller-debug-current-src"><?php echo esc_html($iframe_src); ?></code></span>
                    </div>
                    <pre class="cookie-debug-log" <?php echo $debug ? '' : 'hidden="hidden" style="display:none;"'; ?>></pre>
                </div>
            </div>

            <div class="propeller-iframe-wrap">
                <iframe
                    class="propeller-app"
                    src="<?php echo esc_url($iframe_src); ?>"
                    width="<?php echo esc_attr($iframe_width); ?>"
                    height="<?php echo esc_attr((string) $iframe_height); ?>"
                    referrerpolicy="<?php echo esc_attr($iframe_referrerpolicy); ?>"
                    allow="<?php echo esc_attr($iframe_allow); ?>"
                    <?php if ($iframe_sandbox !== '') : ?>sandbox="<?php echo esc_attr($iframe_sandbox); ?>"<?php endif; ?>
                    loading="<?php echo esc_attr($iframe_loading); ?>"
                    allowfullscreen>
                </iframe>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }


    public function render_switcher_shortcode(array $atts = [], ?string $content = null): string
    {
        $switcher_id = 'propeller-embed-switcher-' . wp_generate_uuid4();

        $options = [
            'default' => [
                'label' => __('Main Site Search', 'propeller-embed'),
                'description_html' => '<p><strong>' . esc_html__('How it works', 'propeller-embed') . '</strong> ' . esc_html__('once the plugin is installed and configured it can be used in two ways.', 'propeller-embed') . '</p><p><strong>1.</strong> ' . esc_html__('This is just the Propeller embed shortcode of our main site search site.', 'propeller-embed') . '</p><pre>[propeller_embed]</pre>',
                'shortcode' => '[propeller_embed]',
            ],
            'texas' => [
                'label' => __('Texas - Automobile Club ($25,000)', 'propeller-embed'),
                'description_html' => '<p><strong>2.</strong> ' . esc_html__('Below is an example of using propeller_embed shortcode to a specific bond URL to our main site.', 'propeller-embed') . '</p><p><strong>' . esc_html__('Texas - Automobile Club ($25,000)', 'propeller-embed') . '</strong></p><p>' . esc_html__('Link', 'propeller-embed') . ': <code>https://propellerwebsite.propeller.insure/axelerator-public/RQ1001089C1?bond_id=286</code></p><p>' . esc_html__('Would be embedded as:', 'propeller-embed') . '</p><pre>[propeller_embed src="https://propellerwebsite.propeller.insure/axelerator-public/RQ1001089C1?bond_id=286" height="1000"]</pre>',
                'shortcode' => '[propeller_embed src="https://propellerwebsite.propeller.insure/axelerator-public/RQ1001089C1?bond_id=286" height="1000"]',
            ],
        ];

        $default_key = 'default';
        $rendered = [];
        foreach ($options as $key => $option) {
            $rendered[$key] = [
                'label' => (string) $option['label'],
                'description_html' => (string) $option['description_html'],
                'html' => do_shortcode((string) $option['shortcode']),
            ];
        }

        ob_start();
        ?>
        <div class="propeller-embed-switcher" id="<?php echo esc_attr($switcher_id); ?>">
            <div class="propeller-embed-switcher-controls">
                <label for="<?php echo esc_attr($switcher_id); ?>-select" class="propeller-embed-switcher-label"><strong><?php echo esc_html__('Select embed:', 'propeller-embed'); ?></strong></label>
                <select id="<?php echo esc_attr($switcher_id); ?>-select" class="propeller-embed-switcher-select" aria-label="<?php echo esc_attr__('Select embed', 'propeller-embed'); ?>">
                    <?php foreach ($options as $key => $option) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $default_key); ?>><?php echo esc_html((string) $option['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="propeller-embed-switcher-copy">
                <?php foreach ($options as $key => $option) : ?>
                    <div class="propeller-embed-switcher-copy-item" data-option="<?php echo esc_attr($key); ?>" <?php echo $key === $default_key ? '' : 'hidden="hidden" style="display:none;"'; ?>>
                        <?php echo wp_kses_post((string) $option['description_html']); ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="propeller-embed-switcher-output"><?php echo $rendered[$default_key]['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        </div>

        <script>
        (function () {
            var root = document.getElementById(<?php echo wp_json_encode($switcher_id); ?>);
            if (!root) return;

            var select = root.querySelector('.propeller-embed-switcher-select');
            var output = root.querySelector('.propeller-embed-switcher-output');
            var copyItems = root.querySelectorAll('.propeller-embed-switcher-copy-item');
            var embeds = <?php echo wp_json_encode($rendered); ?>;

            function setDescription(value) {
                for (var i = 0; i < copyItems.length; i += 1) {
                    var item = copyItems[i];
                    var active = item.getAttribute('data-option') === value;
                    item.hidden = !active;
                    item.style.display = active ? '' : 'none';
                }
            }

            function destroyExistingEmbeds() {
                var existing = output.querySelectorAll('.propeller-embed[data-instance-id]');
                for (var i = 0; i < existing.length; i += 1) {
                    var api = existing[i].PropellerEmbedDebug;
                    if (api && typeof api.destroy === 'function') {
                        api.destroy();
                    }
                }
            }

            function setEmbed(value) {
                if (!embeds[value]) return;
                setDescription(value);
                destroyExistingEmbeds();
                output.innerHTML = embeds[value].html;

                if (window.PropellerEmbed && typeof window.PropellerEmbed.initAll === 'function') {
                    window.PropellerEmbed.initAll(output);
                }
            }

            if (select) {
                select.addEventListener('change', function () {
                    setEmbed(this.value);
                });
            }

            setDescription(select ? select.value : <?php echo wp_json_encode($default_key); ?>);
            if (window.PropellerEmbed && typeof window.PropellerEmbed.initAll === 'function') {
                window.PropellerEmbed.initAll(output);
            }
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param  mixed $transient
     * @return mixed
     */
    public function inject_update_information($transient)
    {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $manifest = $this->get_remote_update_manifest(false);
        if (!is_array($manifest) || !$this->manifest_has_usable_update($manifest)) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        $transient->response[$plugin_file] = (object) [
            'slug' => dirname($plugin_file),
            'plugin' => $plugin_file,
            'new_version' => (string) $manifest['version'],
            'package' => (string) $manifest['download_url'],
            'url' => (string) ($manifest['homepage'] ?? $this->get_settings()['plugin_homepage']),
            'tested' => (string) ($manifest['tested'] ?? ''),
            'requires' => (string) ($manifest['requires'] ?? ''),
            'requires_php' => (string) ($manifest['requires_php'] ?? ''),
            'icons' => [],
            'banners' => [],
            'banners_rtl' => [],
        ];

        return $transient;
    }

    /**
     * @param  mixed $result
     * @return mixed
     */
    public function filter_plugins_api($result, string $action, object $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== dirname(plugin_basename(__FILE__))) {
            return $result;
        }

        $manifest = $this->get_remote_update_manifest(false);
        $settings = $this->get_settings();

        if (!is_array($manifest)) {
            return $result;
        }

        $sections = $manifest['sections'] ?? [];
        if (!is_array($sections)) {
            $sections = [];
        }

        return (object) [
            'name' => (string) ($manifest['name'] ?? 'Get Bonds'),
            'slug' => dirname(plugin_basename(__FILE__)),
            'version' => (string) ($manifest['version'] ?? self::VERSION),
            'author' => '<a href="' . esc_url((string) ($manifest['homepage'] ?? $settings['plugin_homepage'])) . '">' . esc_html((string) ($manifest['author'] ?? 'Propeller Bonds')) . '</a>',
            'homepage' => (string) ($manifest['homepage'] ?? $settings['plugin_homepage']),
            'download_link' => (string) ($manifest['download_url'] ?? ''),
            'requires' => (string) ($manifest['requires'] ?? ''),
            'tested' => (string) ($manifest['tested'] ?? ''),
            'requires_php' => (string) ($manifest['requires_php'] ?? ''),
            'last_updated' => (string) ($manifest['last_updated'] ?? ''),
            'sections' => array_merge(
                [
                    'description' => 'Embeds a Propeller app in WordPress with cookie guidance, retry support, and self-hosted auto-updates.',
                    'installation' => 'Upload the ZIP in Plugins → Add New → Upload Plugin, activate it, then configure it under Settings → Propeller Embed.',
                    'changelog' => '= ' . self::VERSION . " =\n* Current installed build.",
                ],
                $sections
            ),
            'banners' => [],
            'icons' => [],
        ];
    }

    public function handle_check_updates(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do that.', 'propeller-embed'));
        }

        check_admin_referer('propeller_embed_check_updates');

        $this->clear_update_cache();
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);

        $manifest = $this->get_remote_update_manifest(true);

        $query_args = [
            'page' => self::MENU_SLUG,
        ];

        if (is_array($manifest)) {
            $query_args['update_check'] = $this->manifest_has_usable_update($manifest) ? 'available' : 'current';
            $query_args['update_version'] = rawurlencode((string) ($manifest['version'] ?? ''));
        } else {
            $query_args['update_check'] = 'failed';
        }

        wp_safe_redirect(add_query_arg($query_args, admin_url('options-general.php')));
        exit;
    }

    /** @param mixed $upgrader */
    public function clear_update_cache_after_upgrade($upgrader, array $hook_extra): void
    {
        if (($hook_extra['type'] ?? '') !== 'plugin') {
            return;
        }

        $plugins = $hook_extra['plugins'] ?? [];
        $plugin_file = plugin_basename(__FILE__);

        if (($hook_extra['plugin'] ?? '') === $plugin_file || (is_array($plugins) && in_array($plugin_file, $plugins, true))) {
            $this->clear_update_cache();
            delete_site_transient('update_plugins');
            wp_clean_plugins_cache(true);
        }
    }

    private function render_admin_notice_from_query(): void
    {
        $status = isset($_GET['update_check']) ? sanitize_key((string) $_GET['update_check']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $version = isset($_GET['update_version']) ? sanitize_text_field(wp_unslash((string) $_GET['update_version'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ($status === '') {
            return;
        }

        $class = 'notice-info';
        $message = '';

        if ($status === 'available') {
            $class = 'notice-success';
            $message = sprintf(
                /* translators: %s latest version */
                __('Update check complete. WordPress should now offer version %s.', 'propeller-embed'),
                $version !== '' ? $version : __('(unknown)', 'propeller-embed')
            );
        } elseif ($status === 'current') {
            $class = 'notice-info';
            $message = __('Update check complete. No newer compatible version is currently available.', 'propeller-embed');
        } elseif ($status === 'failed') {
            $class = 'notice-error';
            $message = __('Update check failed. Verify that your JSON URL is reachable, returns valid JSON, and includes a newer version plus a ZIP package URL.', 'propeller-embed');
        }

        if ($message !== '') {
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    private function get_settings(): array
    {
        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return array_merge($this->get_default_settings(), $settings);
    }

    private function get_default_settings(): array
    {
        // Base hardcoded defaults — act as a safe fallback if the local JSON is missing or malformed.
        $defaults = [
            'iframe_src'              => 'https://yourwebsite.propeller.insure/axelerator-public',
            'iframe_path_for_new_tab' => '/axelerator-public',
            'iframe_width'            => '90%',
            'iframe_height'           => 1000,
            'initial_timeout_ms'      => 8000,
            'expected_message_type'   => 'propeller-page-state',
            'allowed_iframe_origins'  => "https://propellerwebsite.propeller.insure\nhttps://yourwebsite.propeller.insure",
            'iframe_allow'            => 'storage-access; fullscreen',
            'iframe_sandbox'          => '',
            'iframe_referrerpolicy'   => 'strict-origin-when-cross-origin',
            'iframe_loading'          => 'lazy',
            'debug'                   => '0',
            'warning_title'           => 'Third-party cookies might be blocked.',
            'warning_body'            => 'This embedded app needs cookies from ',
            'retry_button_label'      => 'I changed settings — Retry',
            'new_tab_label'           => 'Open in a new tab (recommended)',
            'help_html'               => '<p><strong>Chrome:</strong> Settings → Privacy &amp; security → Third-party cookies → allow them, or add an exception for the relevant domain.</p><p><strong>Safari:</strong> Settings → Privacy → turn off <em>Prevent cross-site tracking</em>.</p><p><strong>Firefox:</strong> Settings → Privacy &amp; Security → Enhanced Tracking Protection → Standard, or add an exception.</p><p>After changing the setting, reload this page or click Retry.</p>',
            'update_json_url'         => 'https://www.propellerbonds.com/wp-content/uploads/2026/03/plugin-update.json',
            'plugin_homepage'         => 'https://www.propellerbonds.com/propeller-embed-plugin',
            'release_channel'         => 'stable',
        ];

        // Merge applicable values from the bundled plugin-update.json.
        // Reading is done once per request via a static cache to avoid repeated disk I/O.
        static $json_defaults = null;
        if ($json_defaults === null) {
            $json_file = __DIR__ . '/plugin-update.json';
            $json_defaults = [];

            if (is_readable($json_file)) {
                $raw = file_get_contents($json_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                if ($raw !== false) {
                    $parsed = json_decode($raw, true);
                    if (is_array($parsed)) {
                        // Map JSON fields → settings keys where applicable.
                        // 'update_json_url' is intentionally excluded: it points *to* the remote
                        // manifest and cannot be derived from the local copy.
                        $field_map = [
                            'name' => 'name',
                            'homepage' => 'plugin_homepage',
                            'channel'  => 'release_channel',
                            'version' => 'version',
                        ];
                        foreach ($field_map as $json_key => $setting_key) {
                            if (isset($parsed[$json_key]) && is_string($parsed[$json_key]) && $parsed[$json_key] !== '') {
                                    $json_defaults[$setting_key] = $parsed[$json_key];
                            }
                        }
                    }
                }
            }
        }

        return array_merge($defaults, $json_defaults);
    }

    private function get_remote_update_manifest(bool $force_refresh): ?array
    {
        $settings = $this->get_settings();
        $url = trim((string) $settings['update_json_url']);
        if ($url === '') {
            return null;
        }

        $cache_key = self::UPDATE_CACHE_KEY . '_' . md5($url);

        if (!$force_refresh) {
            $cached = get_site_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || $body === '') {
            return null;
        }

        $manifest = json_decode($body, true);
        if (!is_array($manifest)) {
            return null;
        }

        $manifest = $this->sanitize_update_manifest($manifest);
        if (!isset($manifest['version'], $manifest['download_url'])) {
            return null;
        }

        set_site_transient($cache_key, $manifest, self::UPDATE_CACHE_TTL);
        return $manifest;
    }

    private function sanitize_update_manifest(array $manifest): array
    {
        $clean = [];
        $clean['name'] = sanitize_text_field($manifest['name'] ?? 'Get Bonds');
        $clean['slug'] = sanitize_key($manifest['slug'] ?? dirname(plugin_basename(__FILE__)));
        $clean['version'] = sanitize_text_field($manifest['version'] ?? '');
        $clean['author'] = sanitize_text_field($manifest['author'] ?? 'Propeller Bonds');
        $clean['channel'] = $this->sanitize_release_channel($manifest['channel'] ?? 'stable');
        $clean['homepage'] = esc_url_raw((string) ($manifest['homepage'] ?? ''));
        $clean['download_url'] = esc_url_raw((string) ($manifest['download_url'] ?? ''));
        $clean['requires'] = sanitize_text_field($manifest['requires'] ?? '');
        $clean['tested'] = sanitize_text_field($manifest['tested'] ?? '');
        $clean['requires_php'] = sanitize_text_field($manifest['requires_php'] ?? '');
        $clean['last_updated'] = sanitize_text_field($manifest['last_updated'] ?? '');
        $clean['sections'] = [];

        if (!empty($manifest['sections']) && is_array($manifest['sections'])) {
            foreach ($manifest['sections'] as $section_key => $section_value) {
                $clean['sections'][sanitize_key((string) $section_key)] = wp_kses_post((string) $section_value);
            }
        }

        return $clean;
    }

    private function manifest_has_usable_update(array $manifest): bool
    {
        $settings = $this->get_settings();
        $manifest_channel = $this->sanitize_release_channel($manifest['channel'] ?? 'stable');
        $selected_channel = $this->sanitize_release_channel($settings['release_channel']);

        if ($manifest_channel !== $selected_channel) {
            return false;
        }

        $new_version = (string) ($manifest['version'] ?? '');
        $package = (string) ($manifest['download_url'] ?? '');

        if ($new_version === '' || $package === '') {
            return false;
        }

        return version_compare($new_version, self::VERSION, '>');
    }

    private function clear_update_cache(): void
    {
        $settings = $this->get_settings();
        $url = trim((string) $settings['update_json_url']);
        if ($url !== '') {
            delete_site_transient(self::UPDATE_CACHE_KEY . '_' . md5($url));
        }
        delete_site_transient('update_plugins');
    }

    /** @param mixed $value */
    private function sanitize_positive_int($value, int $fallback): int
    {
        $value = absint($value);
        return $value > 0 ? $value : $fallback;
    }

    /** @param mixed $value */
    private function sanitize_width($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '90%';
        }

        if (preg_match('/^\d+(?:\.\d+)?%$/', $value)) {
            return $value;
        }

        if (preg_match('/^\d+(?:\.\d+)?px$/', $value)) {
            return $value;
        }

        if (preg_match('/^\d+$/', $value)) {
            return $value . 'px';
        }

        return '90%';
    }

    /** @param mixed $value */
    private function sanitize_path($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '/axelerator-public';
        }

        if ($value[0] !== '/') {
            $value = '/' . $value;
        }

        return preg_replace('#/+#', '/', $value) ?: '/axelerator-public';
    }

    /** @param mixed $value */
    private function sanitize_origins_text($value): string
    {
        $origins = $this->normalize_origins_text_to_array((string) $value);
        return implode("\n", $origins);
    }

    private function normalize_origins_text_to_array(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $value) ?: [];
        $origins = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $sanitized = esc_url_raw($line);
            if ($sanitized === '') {
                continue;
            }

            $parts = wp_parse_url($sanitized);
            if (empty($parts['scheme']) || empty($parts['host'])) {
                continue;
            }

            $origin = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $origin .= ':' . (int) $parts['port'];
            }

            $origins[] = $origin;
        }

        return array_values(array_unique($origins));
    }

    /** @param mixed $value */
    private function sanitize_allow_attribute($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'storage-access; fullscreen';
        }

        $parts = preg_split('/[;,]+/', $value) ?: [];
        $clean = [];

        foreach ($parts as $part) {
            $token = strtolower(trim((string) $part));
            if ($token === '') {
                continue;
            }
            $token = preg_replace('/[^a-z0-9\- ]/', '', $token) ?: '';
            if ($token !== '') {
                $clean[] = preg_replace('/\s+/', ' ', $token);
            }
        }

        return $clean ? implode('; ', array_values(array_unique($clean))) : 'storage-access; fullscreen';
    }

    /** @param mixed $value */
    private function sanitize_sandbox_attribute($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $allowed = [
            'allow-downloads',
            'allow-forms',
            'allow-modals',
            'allow-orientation-lock',
            'allow-pointer-lock',
            'allow-popups',
            'allow-popups-to-escape-sandbox',
            'allow-presentation',
            'allow-same-origin',
            'allow-scripts',
            'allow-storage-access-by-user-activation',
            'allow-top-navigation',
            'allow-top-navigation-by-user-activation',
            'allow-top-navigation-to-custom-protocols',
        ];

        $parts = preg_split('/\s+/', $value) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $token = strtolower(trim((string) $part));
            if (in_array($token, $allowed, true)) {
                $clean[] = $token;
            }
        }

        return implode(' ', array_values(array_unique($clean)));
    }

    /** @param mixed $value */
    private function sanitize_referrerpolicy($value): string
    {
        $allowed = [
            'no-referrer',
            'no-referrer-when-downgrade',
            'origin',
            'origin-when-cross-origin',
            'same-origin',
            'strict-origin',
            'strict-origin-when-cross-origin',
            'unsafe-url',
        ];

        $value = strtolower(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : 'strict-origin-when-cross-origin';
    }

    /** @param mixed $value */
    private function sanitize_loading($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['lazy', 'eager'], true) ? $value : 'lazy';
    }

    /** @param mixed $value */
    private function sanitize_release_channel($value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['stable', 'beta'], true) ? $value : 'stable';
    }
}

Propeller_Embed_Plugin::instance();

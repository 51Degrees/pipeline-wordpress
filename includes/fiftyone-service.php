<?php
/**
 *  Plugin Name: 51Degrees
 *  Plugin URI:  https://51degrees.com/
 *  Description: Device detection and location-aware content for WordPress, with cloud-driven robots.txt management for AI/search crawlers and suspicious-activity protection against abusive traffic.
 *  Version:     1.0.11
 *  Author:      51Degrees
 *  Author URI:  https://51degrees.com/
 *  Text Domain: fiftyonedegrees
 *  License:     EUPL-1.2
 *
 *  This Original Work is copyright of 51 Degrees Mobile Experts Limited.
 *  Copyright 2019 51 Degrees Mobile Experts Limited, 5 Charlotte Close,
 *  Caversham, Reading, Berkshire, United Kingdom RG4 7BY.
 *
 *  This Original Work is licensed under the European Union Public Licence (EUPL) 
 *  v.1.2 and is subject to its terms as set out below.
 *
 *  If a copy of the EUPL was not distributed with this file, You can obtain
 *  one at https://opensource.org/licenses/EUPL-1.2.
 *
 *  The 'Compatible Licences' set out in the Appendix to the EUPL (as may be
 *  amended by the European Commission) shall be deemed incompatible for
 *  the purposes of the Work and the provisions of the compatibility
 *  clause in Article 5 of the EUPL shall not apply.
 */

require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/standard-tdls.php';
require_once __DIR__ . '/cloud-metadata.php';

class FiftyoneService {

    /**
     * Setup action hooks for the plugin. These hooks are handled
     * by wordpress.
     * 
     * See available actions:
     * https://codex.wordpress.org/Plugin_API/Action_Reference
     *
     * @access      private
     * @since       1.0.11
     * @return      void
     */
    public function setup_wp_actions() {

        // The main init action. This runs the processing.
        add_action(
            'init',
            array($this, 'fiftyonedegrees_init'));     

        // Admin actions. These are initialization actions to run before
        // loading the admin interface.
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_register_settings'));
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_setup_blocks'));
        add_action(
            'admin_init',
            array($this, 'submit_rk_submit_action'));

        // Admin menu actions. These are actions run before the admin
        // menu is written.
        add_action(
            'admin_menu',
            array($this, 'fiftyonedegrees_register_options_page'));

        // Enqueue scripts actions for admin.
        add_action(
            'admin_enqueue_scripts',
            array($this, 'fiftyonedegrees_admin_enqueue_scripts'));
        
        // Add Javascript to the enqueued scripts.
        add_action(
            'wp_enqueue_scripts',
            array($this, 'fiftyonedegrees_javascript'));

        // Add the JSON rest endpoint.
        add_action(
            'rest_api_init',
            array($this, 'fiftyonedegrees_rest_api_init'));     

        // Cache Resource Key data / pipeline after saving options page
        add_action(
            'update_option',
            array($this, 'fiftyonedegrees_update_option'),
            10,
            10);
	    add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_register_settings'));

        // Rebuild pipeline when permalink structure changes
        add_action(
            'updated_option',
            array($this, 'fiftyonedegrees_updated_option'),
            10,
            3);

        add_action(
            'updated_option',
            array($this, 'fiftyonedegrees_suspicious_enable_updated'),
            10,
            3);

        // Build pipeline when resource key is first created (e.g. via WP-CLI)
        add_action(
            'add_option',
            array($this, 'fiftyonedegrees_add_option'),
            10,
            2);

        // Trigger Cloud robots.txt regeneration after robots settings are saved.
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_re_generate_robots'));

        // Show admin notice when pipeline was auto-enabled.
        add_action(
            'admin_notices',
            array($this, 'fiftyonedegrees_pipeline_autoenable_notice'));
        add_action(
            'admin_notices',
            array($this, 'fiftyonedegrees_suspicious_toggle_failed_notice'));
    }
    
    /**
     * Setup filter hooks for the plugin. These hooks are handled
     * by wordpress.
     * 
     * See available filters:
     * https://codex.wordpress.org/Plugin_API/Filter_Reference
     *
     * @since       1.0.11
     * @param       string $pluginName name of the plugin
     * @return      void
     */
    public function setup_wp_filters($pluginName) {
        
        // Plugin page settings actions.
        add_filter(
            'plugin_action_links_' . $pluginName,
            array($this, 'fiftyonedegrees_add_plugin_page_settings_link'));

        // Add block filter
        add_filter(
            'render_block',
            array($this, 'fiftyonedegrees_block_filter'),
            10,
            2);
        // Register a custom block category
        add_filter(
            'block_categories_all',
            array($this, 'fiftyonedegrees_block_categories'));
        // Show and hide the conditional-group-block based on properties
        add_filter(
            'render_block',
            array($this, 'fiftyonedegrees_render_block'),
            10,
            2);
    }


    /**
     * Main initialization function. This calls the pipeline to process the
     * request. If there is a problem processing, then an error is logged.
     * 
     * @return void
     */
    static function fiftyonedegrees_init() {

        if (get_option(Options::PIPELINE_ENABLE, 'on') === 'off') {
            return;
        }

        // Error logging happens inside process().
        Pipeline::process();

    }
      
    /**
     * Register the settings used by the plugin.
     * 
     * @return void
     */
    function fiftyonedegrees_register_settings() {
        // This is the cached pipeline for the current Resource Key.
        add_option(Options::PIPELINE);
        // This is the Resource Key set by the user to be used to access
        // cloud services.
        add_option(Options::RESOURCE_KEY);

        // Robots.txt settings
        add_option(Options::ROBOTS_ENABLE, 'off');
        add_option(Options::ROBOTS_ENFORCE, 'off');
        add_option(Options::ROBOTS_REDIRECT_URL, '');
        add_option(Options::ROBOTS_CUSTOM_TOP, '');
        add_option(Options::ROBOTS_CUSTOM_BOTTOM, '');
        add_option(Options::ROBOTS_STANDARD_TDL_SELECTED, array());
        add_option(Options::ROBOTS_CUSTOM_TDL, array());
        add_option(Options::ROBOTS_PLAINTEXT_CACHE, '');
        add_option(Options::PIPELINE_ENABLE, 'on');

        // Register the new settings with wordpress.
        register_setting(
            Options::GROUP_KEY,
            Options::RESOURCE_KEY);

        // Suspicious activity detection settings.
        add_option(Options::SUSPICIOUS_ENABLE, 'off');
        add_option(Options::SUSPICIOUS_REDIRECT_URL, '');
        add_option(Options::SUSPICIOUS_REQUESTS, 5);
        add_option(Options::SUSPICIOUS_WINDOW, 30);

        register_setting(Options::SUSPICIOUS_GROUP_KEY, Options::SUSPICIOUS_ENABLE, [
            'type' => 'string',
            'sanitize_callback' => function ($v) { return $v === 'on' ? 'on' : 'off'; },
            'default' => 'off',
        ]);
        register_setting(Options::SUSPICIOUS_GROUP_KEY, Options::SUSPICIOUS_REDIRECT_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);
        register_setting(Options::SUSPICIOUS_GROUP_KEY, Options::SUSPICIOUS_REQUESTS, [
            'type' => 'integer',
            'sanitize_callback' => function ($v) { return max(1, (int) $v); },
            'default' => 5,
        ]);
        register_setting(Options::SUSPICIOUS_GROUP_KEY, Options::SUSPICIOUS_WINDOW, [
            'type' => 'integer',
            'sanitize_callback' => function ($v) { return max(1, min(3600, (int) $v)); },
            'default' => 30,
        ]);

        register_setting(Options::ROBOTS_GROUP_KEY, Options::ROBOTS_ENABLE);
        register_setting(Options::ROBOTS_GROUP_KEY, Options::ROBOTS_ENFORCE);
        register_setting(
            Options::ROBOTS_GROUP_KEY,
            Options::ROBOTS_REDIRECT_URL,
            ['sanitize_callback' => ['FiftyoneService', 'sanitize_robots_redirect_url']]);
        register_setting(
            Options::ROBOTS_GROUP_KEY,
            Options::ROBOTS_CUSTOM_TOP,
            ['sanitize_callback' => ['FiftyoneService', 'sanitize_robots_textarea']]);
        register_setting(
            Options::ROBOTS_GROUP_KEY,
            Options::ROBOTS_CUSTOM_BOTTOM,
            ['sanitize_callback' => ['FiftyoneService', 'sanitize_robots_textarea']]);
        register_setting(
            Options::ROBOTS_GROUP_KEY,
            Options::ROBOTS_ALLOWED_CATEGORIES,
            ['sanitize_callback' => ['FiftyoneService', 'sanitize_categories']]);

        register_setting(
            Options::ROBOTS_GROUP_KEY,
            Options::ROBOTS_STANDARD_TDL_SELECTED,
            ['sanitize_callback' => ['FiftyoneService', 'sanitize_standard_tdl_selected']]);
        register_setting(
            Options::ROBOTS_GROUP_KEY,
            Options::ROBOTS_CUSTOM_TDL,
            ['sanitize_callback' => ['FiftyoneService', 'sanitize_tdl']]);
        register_setting(Options::GROUP_KEY, Options::PIPELINE_ENABLE);

        // PMP (Preference Management Platform) settings.
        add_option(Options::PMP_ENABLE,            'off');
        add_option(Options::PMP_TCF_VENDOR_STRING, '');
        add_option(Options::PMP_ALT_LABEL,         '');
        add_option(Options::PMP_ALT_URL,           '');
        add_option(Options::PMP_BRAND_NAME,        '');
        add_option(Options::PMP_BRAND_LOGO_URL,    '');
        add_option(Options::PMP_BRAND_TERMS_URL,   '');
        add_option(Options::PMP_SHOW_STANDARD,     'off');

        register_setting(Options::PMP_GROUP_KEY, Options::PMP_ENABLE, [
            'type' => 'string',
            'sanitize_callback' => ['FiftyoneService', 'sanitize_pmp_enable'],
            'default' => 'off',
        ]);
        register_setting(Options::PMP_GROUP_KEY, Options::PMP_TCF_VENDOR_STRING, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting(Options::PMP_GROUP_KEY, Options::PMP_ALT_LABEL, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting(Options::PMP_GROUP_KEY, Options::PMP_ALT_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting(Options::PMP_GROUP_KEY, Options::PMP_BRAND_NAME, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting(Options::PMP_GROUP_KEY, Options::PMP_BRAND_LOGO_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting(Options::PMP_GROUP_KEY, Options::PMP_BRAND_TERMS_URL, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting(Options::PMP_GROUP_KEY, Options::PMP_SHOW_STANDARD, [
            'type' => 'string',
            'sanitize_callback' => function ($v) { return $v === 'on' ? 'on' : 'off'; },
            'default' => 'off',
        ]);
    }

    static function sanitize_robots_textarea($value) {
        return sanitize_textarea_field($value);
    }

    static function sanitize_robots_redirect_url($value) {
        return esc_url_raw($value);
    }

    static function sanitize_standard_tdl_selected($value) {
        if (!is_array($value)) {
            return [];
        }
        $valid_ids = array_column(FiftyOneDegreesStandardTdls::load(), 'id');
        return array_values(array_unique(array_intersect($value, $valid_ids)));
    }

    static function sanitize_categories($value) {
        if (!is_array($value)) {
            return [];
        }
        $available = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();
        $valid = array_keys($available);
        if (empty($valid)) {
            $valid = ['Index', 'Train', 'Input', 'Search', 'Monitor',
                       'Archiving', 'Preview', 'Security', 'Analytics',
                       'Feed', 'Discovery'];
        }
        return array_values(array_intersect($value, $valid));
    }

    static function sanitize_tdl($value) {
        if (is_string($value)) {
            $lines = explode("\n", $value);
        } elseif (is_array($value)) {
            $lines = $value;
        } else {
            return [];
        }
        $clean = [];
        foreach ($lines as $url) {
            $url = esc_url_raw(trim($url));
            if (!empty($url)) {
                $clean[] = $url;
            }
        }
        return array_values(array_unique($clean));
    }

    /**
     * Sanitizer for Options::PMP_ENABLE. When the caller asks for 'on',
     * verifies that fields without a sensible default are filled in.
     * Missing values force the option back to 'off' and register a
     * settings error listing the missing field labels.
     *
     * @param  string $value the submitted PMP_ENABLE value
     * @return string        'on' if validation passes, 'off' otherwise
     */
    static function sanitize_pmp_enable($value) {
        if ($value !== 'on') {
            return 'off';
        }
        // Terms / Privacy URL plus the two alternative-button fields
        // are required server-side. PMP widget rejects an empty alt
        // button outright; Terms URL has no runtime fallback. The
        // remaining fields (TCF Vendor String, Brand Name) have
        // sensible runtime defaults so they stay optional.
        $required = [
            Options::PMP_BRAND_TERMS_URL => 'Terms / Privacy URL',
            Options::PMP_ALT_LABEL       => 'Alternative Button Label',
            Options::PMP_ALT_URL         => 'Alternative Button URL',
        ];
        $missing = [];
        foreach ($required as $key => $label) {
            $raw = isset($_POST[$key]) ? wp_unslash($_POST[$key]) : '';
            if (trim((string) $raw) === '') {
                $missing[] = $label;
            }
        }
        if (!empty($missing)) {
            add_settings_error(
                Options::PMP_GROUP_KEY,
                'pmp_required_missing',
                sprintf(
                    /* translators: %s is a comma-separated list of field labels. */
                    __('PMP cannot be enabled: missing required field(s): %s.', 'fiftyonedegrees'),
                    implode(', ', $missing)
                ),
                'error'
            );
            return 'off';
        }
        return 'on';
    }

    /**
     * Register the JSON endpoint for the pipeline. This is where the
     * JavaScript will callback to instead of the 51Degrees domain.
     * 
     * @return void
     */
    function fiftyonedegrees_rest_api_init() {	
        register_rest_route('fiftyonedegrees/v4', "json", array(
            'methods' => 'POST',
            'args' => array(),
            'callback' => array('Pipeline','getJSON'),
            'permission_callback' => '__return_true'
        ));
    }

    /**
     * Checks if the Resource Key has been changed, and stores the new one
     * if it has. When the new option has been updated, the pipeline will be
     * rebuilt.
     * 
     * @return void
     */
    function submit_rk_submit_action() {

        if (isset($_POST[Options::RESOURCE_KEY]) &&
            isset($_POST["action"]) &&
            $_POST[Options::RESOURCE_KEY] !==
            get_option(Options::RESOURCE_KEY)) {

            $resource_key = sanitize_text_field(wp_unslash(
                $_POST[Options::RESOURCE_KEY]));
            update_option(Options::RESOURCE_KEY, $resource_key);

            if (!isset($cachedPipeline['error'])) {
                if (get_option(Options::ENABLE_GA) &&
                    get_option(Options::RESOURCE_KEY_UPDATED)) {
                
                    wp_redirect(get_admin_url() .
                        'options-general.php?page=51Degrees&tab=google-analytics');
                    exit();
                }
            }
            else {
                wp_redirect(get_admin_url() .
                    'options-general.php?page=51Degrees&tab=setup' );
                exit();
            }

        }
    }

    /**
     * Add stylesheet for admin pages.
     * 
     * @return void
     */
    function fiftyonedegrees_admin_enqueue_scripts() {
        wp_enqueue_style(
            "fiftyonedegrees_admin_styles",
            plugin_dir_url(__FILE__) . "../assets/css/fod.css");
        wp_enqueue_style(
            "fiftyonedegrees_admin_styles_icons",
            "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css");
        wp_enqueue_script(
            "fiftyonedegrees_jQuery",
            plugin_dir_url(__FILE__) . '../assets/js/51D.js',
            array('jquery') #dependencies
        );			
    }

    /**
     * After any option is updated, check if the option was something that
     * needs to be taken care of. For Resource Key, the flow data needs to
     * be removed from the session cache, and a new pipeline created.
     * 
     * @return void
     */
    function fiftyonedegrees_update_option($option, $old_value, $new_value) {

        if ($option === Options::RESOURCE_KEY) {

            // Remove the cached flowdata from the session.
            if (session_status() === PHP_SESSION_ACTIVE &&
                isset($_SESSION["fiftyonedegrees_data"])) {
                unset($_SESSION["fiftyonedegrees_data"]);
                update_option(
                    Options::SESSION_INVALIDATED,
                    time());
            }

            // Invalidate cloud metadata transient
            FiftyOneDegreesCloudMetadata::invalidate_all();

            // Stale against the previous key — clear.
            delete_option(Options::ROBOTS_LAST_REFRESH);

            self::build_and_save_pipeline($new_value);

            if ($old_value !== $new_value) {
                update_option(Options::RESOURCE_KEY_UPDATED, true);
                delete_option(Options::GA_DIMENSIONS);
            }
            else {
                delete_option(Options::RESOURCE_KEY_UPDATED);
            }
            
        }

        if ($option === Options::GA_TRACKING_ID &&
            $old_value !== $new_value) {
            update_option(Options::GA_ID_UPDATED, true);
            delete_option(Options::GA_DIMENSIONS);
        }

        if ($option === Options::GA_SEND_PAGE_VIEW_VAL &&
            $old_value !== $new_value) {
            update_option(Options::GA_SEND_PAGE_VIEW_UPDATED, true);
        }

        if ($option === Options::ROBOTS_ENFORCE &&
            $new_value === 'on' &&
            get_option(Options::PIPELINE_ENABLE, 'on') !== 'on') {
            update_option(Options::PIPELINE_ENABLE, 'on');
            set_transient(
                'fiftyonedegrees_pipeline_auto_enabled',
                'Robots Enforce',
                30);
        }

        if ($option === Options::PIPELINE_ENABLE &&
            $new_value === 'off' &&
            get_option(Options::ROBOTS_ENFORCE, 'off') === 'on') {
            update_option(Options::ROBOTS_ENFORCE, 'off');
        }
    }

    /**
     * Builds the pipeline when the resource key option is first created,
     * e.g. via WP-CLI where update_option hook does not fire.
     *
     * @param string $option the option name
     * @param mixed $value the option value
     * @return void
     */
    function fiftyonedegrees_add_option($option, $value)
    {
        if ($option === Options::RESOURCE_KEY && $value) {
            self::build_and_save_pipeline($value);
        }
    }

    // On error: leave PIPELINE alone, record error string for setup.php.
    private static function build_and_save_pipeline($resource_key) {
        // Empty key: nothing to validate. Drop all cached state so the setup
        // tab renders clean (no stale red box from a previous failed save).
        if (empty($resource_key)) {
            delete_option(Options::PIPELINE);
            delete_option(Options::PIPELINE_VALIDATION_ERROR);
            return;
        }

        $pipeline = Pipeline::make_pipeline($resource_key);

        if ($pipeline && !isset($pipeline['error'])) {
            update_option(Options::PIPELINE, $pipeline);
            delete_option(Options::PIPELINE_VALIDATION_ERROR);
            return;
        }

        if (is_array($pipeline) && isset($pipeline['error']) && $pipeline['error'] !== '') {
            update_option(Options::PIPELINE_VALIDATION_ERROR, (string) $pipeline['error']);
        }
    }

    /**
     * Called on admin_init. When returning to the robots settings page after a
     * settings save (WordPress adds settings-updated=true to the URL), triggers
     * an immediate Cloud API call to refresh the cached robots.txt content.
     *
     * @return void
     */
    function fiftyonedegrees_re_generate_robots() {
        if (!isset($_GET['settings-updated'])) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        $tab  = isset($_GET['tab'])  ? sanitize_text_field(wp_unslash($_GET['tab']))  : '';
        if ($page !== '51Degrees' || $tab !== 'robots') {
            return;
        }
        $success = FiftyOneDegreesRobotsTxt::fetch_from_cloud();
        if ($success) {
            set_transient('fiftyonedegrees_robots_generate_success', true, 30);
        }
    }

    /**
     * Displays an admin notice when device detection was automatically enabled
     * because a dependent feature (e.g. Robots Enforce) requires it.
     *
     * @return void
     */
    function fiftyonedegrees_pipeline_autoenable_notice() {
        $feature = get_transient('fiftyonedegrees_pipeline_auto_enabled');
        if ($feature === false) {
            return;
        }
        delete_transient('fiftyonedegrees_pipeline_auto_enabled');
        echo '<div class="notice notice-warning is-dismissible"><p>' .
            '<strong>51Degrees:</strong> Device detection was automatically ' .
            'enabled because ' . esc_html($feature) . ' requires it.' .
            '</p></div>';
    }

    function fiftyonedegrees_suspicious_toggle_failed_notice() {
        $message = get_transient('fiftyonedegrees_suspicious_toggle_failed');
        if ($message === false) {
            return;
        }
        delete_transient('fiftyonedegrees_suspicious_toggle_failed');
        echo '<div class="notice notice-error is-dismissible"><p>' .
            '<strong>51Degrees:</strong> ' . esc_html($message) .
            '</p></div>';
    }

    /**
     * Rebuilds the pipeline when the permalink structure changes.
     * Hooked to 'updated_option' (fires after the DB write) so that
     * rest_url() returns the URL for the new permalink structure.
     *
     * @return void
     */
    function fiftyonedegrees_updated_option($option, $old_value, $new_value) {
        if ($option === 'permalink_structure') {
            $resource_key = get_option(Options::RESOURCE_KEY);
            if ($resource_key) {
                if (session_status() === PHP_SESSION_ACTIVE &&
                    isset($_SESSION["fiftyonedegrees_data"])) {
                    unset($_SESSION["fiftyonedegrees_data"]);
                    update_option(Options::SESSION_INVALIDATED, time());
                }
                self::build_and_save_pipeline($resource_key);
            }
        }
    }

    /**
     * Synchronously rebuild the cached pipeline whenever
     * SUSPICIOUS_ENABLE is toggled. The cached pipeline's engine list is
     * the single source of truth for both engine selection and the
     * query.id.usage evidence decision; keep it in sync on every toggle.
     */
    public function fiftyonedegrees_suspicious_enable_updated($option, $old_value, $value) {
        if ($option !== Options::SUSPICIOUS_ENABLE) {
            return;
        }

        // $rebuilding = false reset via finally because the revert's update_option re-fires this hook; WP dispatcher guarantees the re-entry path.
        static $rebuilding = false;
        if ($rebuilding) {
            return;
        }

        $rebuilding = true;
        try {
            update_option(Options::SESSION_INVALIDATED, time());

            $resource_key = get_option(Options::RESOURCE_KEY);
            if (empty($resource_key)) {
                return;
            }

            delete_option(Options::PIPELINE_VALIDATION_ERROR);
            self::build_and_save_pipeline($resource_key);
            $had_error = (bool) get_option(Options::PIPELINE_VALIDATION_ERROR);

            if ($had_error) {
                update_option(Options::SUSPICIOUS_ENABLE, $old_value);
                delete_option(Options::PIPELINE_VALIDATION_ERROR);
                set_transient(
                    'fiftyonedegrees_suspicious_toggle_failed',
                    'Suspicious activity detection setting could not be applied — the '
                    . '51Degrees cloud was unreachable. Your previous setting was '
                    . 'preserved.',
                    MINUTE_IN_SECONDS * 10
                );
            }
        } finally {
            $rebuilding = false;
        }
    }

    /**
     * Register the options page for the plugin.
     * 
     * @return void
     */
    function fiftyonedegrees_register_options_page() {
        add_options_page(
            '51Degrees',
            '51Degrees',
            'manage_options',
            '51Degrees',
            array($this, 'fiftyonedegrees_admin_page'));
    }


    /**
     * Set the link to settings for this plugin.
     * 
     * @param string[] $links array of links to add to.
     * @return string[] updated array of links.
     */
    function fiftyonedegrees_add_plugin_page_settings_link($links) {
        $links[] = '<a href="' .
            admin_url('options-general.php?page=51Degrees') .
            '">' . __('Settings') . '</a>';
        return $links;
    }

    /**
     * Inlude the admin page for this plugin.
     * 
     * @return void
     */
    function fiftyonedegrees_admin_page() {
        include plugin_dir_path(__FILE__) . "../admin.php";
    }

    /**
     * Add the 51Degrees JavaScript to the page.
     *
     * @return void
     */
    function fiftyonedegrees_javascript() {
        wp_enqueue_script(
            "fiftyonedegrees",
            plugin_dir_url(__FILE__) . "../assets/js/fod.js");
        wp_add_inline_script(
            "fiftyonedegrees",
            Pipeline::getJavaScript(),
            "before");

        if (get_option(Options::PMP_ENABLE, 'off') !== 'on') {
            return;
        }
        if (empty(get_option(Options::RESOURCE_KEY))) {
            return;
        }

        $url = self::pmp_resolve_script_url();
        if (empty($url)) {
            return;
        }

        // Footer load: PMP attaches its popup container as a sibling of this
        // script tag (see view.ts getRoot()). In <head> that container would
        // be unrenderable (head has display:none).
        wp_register_script('fiftyonedegrees-pmp', $url, [], null, true);
        wp_enqueue_script('fiftyonedegrees-pmp');

        add_filter('script_loader_tag', [$this, 'pmp_add_data_attributes'], 10, 3);

        // Glue invoked by PMP via data-action-url=javascript:...
        // Persists the choice as a cookie so the server-side pipeline can
        // read it as query.id.usage evidence, re-issues the REST call so the
        // cloud generates 51DiD with the chosen preference, then signals
        // PMP that TCF listeners can be notified.
        $rest_url = esc_url_raw(rest_url('fiftyonedegrees/v4/json'));
        $pmp_glue = <<<JS
(function () {
  window.fiftyoneDegreesPmpOnChoice = function (preference) {
    document.cookie = '51d_pmp_pref=' + encodeURIComponent(preference) +
                      '; path=/; SameSite=Lax';
    var done = function () {
      if (window.__51d_pmp && window.__51d_pmp.markTcfReady) {
        window.__51d_pmp.markTcfReady();
      }
    };
    fetch('{$rest_url}', { method: 'POST', credentials: 'include' })
      .then(done, done);
  };
})();
JS;
        wp_add_inline_script('fiftyonedegrees', $pmp_glue, 'after');
    }

    /**
     * Composes the PMP bundle URL for the new query-parameter endpoint.
     * Returns an empty string when the resource key is missing -- the
     * enqueue path uses that to short-circuit registration.
     *
     * The base URL comes from FiftyOneDegreesCloudMetadata, which honours
     * the FOD_CLOUD_API_URL env var used across the plugin (robots,
     * suspicious, cloud metadata) and falls back to
     * https://cloud.51degrees.com when unset.
     *
     * The accept-language query parameter is forwarded from get_locale()
     * with the WordPress underscore form normalised to RFC 7231 dashes
     * (e.g. de_DE -> de-DE). The server picks the closest available
     * bundle, falling back to en-us when nothing matches; we no longer
     * keep a hand-maintained allowlist of supported locales here.
     *
     * @return string
     */
    private static function pmp_resolve_script_url() {
        $key = get_option(Options::RESOURCE_KEY);
        if (empty($key)) {
            return '';
        }
        $locale = function_exists('get_locale') ? get_locale() : 'en_US';
        $acceptLang = str_replace('_', '-', (string) $locale);
        return sprintf(
            '%s/api/v4/pmp?resource=%s&accept-language=%s',
            FiftyOneDegreesCloudMetadata::get_cloud_host_url(),
            rawurlencode($key),
            rawurlencode($acceptLang));
    }

    /**
     * Default TCF Vendor String -- core segment with consent granted
     * to every vendor, purpose and special feature from IAB GVL
     * version 158 (cmpId=51, publisherCountryCode=AA). Regenerate
     * via the @iabtcf/core script when the GVL bumps a major
     * version; admins can override per-site via the PMP settings.
     */
    public const PMP_DEFAULT_TCF_VENDOR_STRING =
        'CQkMqUAQkMqUAAfABAENCfFsAP_wAEPgAAAAMetR_G__bWlr-bb3abtkeYxP9_hr7sQxBgbIkm4FzLvW7JwHx2EZNAzatiIKmRIAu3TBIQNlHJDURUCgKIgFryDMaE2U4TNKJ6BkiFMZI2tQCFxvm4tjeQCY4ur_9kc1mB-t7dr82dzyy6hHn3a5fmS1UJCdIYetDfv8ZBOT-9IEd-x8v4v4_EbpEm8eS1n_pGtp4jc6Yns_dBmxt-Tyff7Pn__7l_e7X_ve_n3zv8oXn7rr____f_-7___2b_-___b-__7Z_zI_4MegAmGh0QRlkQKBAoCEECABQVhABQIAgAASAogIATBgQ5AwAXWESAEAKAAYIAQAAgwABAAAJAAhEAEABAIAQIBAoAAwAIAgIAGBgADABQiAQAAgOgYpgQQCBYAJEZUBpgQgAJBAS2VCCUDAgrhCkWOAQQIiYKAAAEAAoAAEB8LAQklBKxIIAuILoAEAAAAKIEWBFIWYAgqDNFoKwJOAyNMASPMEiSnQRAEwQkZBkQmqCQeKYohQQ5AbFLMAdPEFADLtZIQ_1As3AIAA.IMetX_H__bX9v-f736ft0eY1f9_j77uQxBhfJs-4FzLvW_JwX32E7NF36tqYKmRIEu3bBIQNtHJnUTVihaogVrzHsak2c4TtKJ-BkiHMZe29YCF5vm4tj-QKZ5_r_93d92T_9_dv-3dzy3_1nv3f9_-f1eLide5_tH_v_bROb-_I_9_7-_4v8_t_rk2_eT1v_9evv7__-________9_____________-____f________________________f__________9____4AA';

    /**
     * Returns the configured TCF Vendor String or the built-in
     * all-vendors-enabled default when none is set.
     */
    public static function pmp_tcf_vendor_string() {
        $value = get_option(Options::PMP_TCF_VENDOR_STRING);
        return empty($value)
            ? self::PMP_DEFAULT_TCF_VENDOR_STRING
            : $value;
    }

    /**
     * Returns the configured Brand Name or the WordPress site name
     * when none is set.
     */
    public static function pmp_brand_name() {
        $value = get_option(Options::PMP_BRAND_NAME);
        return empty($value) ? get_bloginfo('name') : $value;
    }

    /**
     * Returns the configured Alternative Button Label or 'Remove ads' when
     * none is set.
     */
    public static function pmp_alt_label() {
        $value = get_option(Options::PMP_ALT_LABEL);
        return empty($value) ? 'Remove ads' : $value;
    }

    /**
     * Returns the configured Alternative Button URL or
     * 'https://example.com' when none is set.
     */
    public static function pmp_alt_url() {
        $value = get_option(Options::PMP_ALT_URL);
        return empty($value) ? 'https://example.com' : $value;
    }

    /**
     * Adds PMP data-* attributes to the <script> tag for the
     * fiftyonedegrees-pmp handle. Hooks 'script_loader_tag' because
     * wp_enqueue_script does not support arbitrary HTML attributes.
     *
     * @param  string $tag    the rendered <script> element
     * @param  string $handle the enqueued-script handle
     * @param  string $src    the script src attribute value
     * @return string
     */
    public function pmp_add_data_attributes($tag, $handle, $src) {
        if ($handle !== 'fiftyonedegrees-pmp') {
            return $tag;
        }

        $attrs = [
            'data-tcf-vendor'       => self::pmp_tcf_vendor_string(),
            'data-tcf-vendor-id'    => 51, // Hardcoded for now; randomized rotation is planned at runtime.
            'data-brand-name'       => self::pmp_brand_name(),
            'data-brand-logo'       => get_option(Options::PMP_BRAND_LOGO_URL),
            'data-brand-terms-url'  => get_option(Options::PMP_BRAND_TERMS_URL),
            'data-alt-name'         => self::pmp_alt_label(),
            'data-alt-url'          => self::pmp_alt_url(),
            'data-action-url'       => "javascript:window.fiftyoneDegreesPmpOnChoice('{preference}')",
        ];
        if (get_option(Options::PMP_SHOW_STANDARD, 'off') === 'on') {
            $attrs['data-show-standard'] = 'true';
        }

        $attr_html = '';
        foreach ($attrs as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $attr_html .= sprintf(' %s="%s"', esc_attr($k), esc_attr($v));
        }
        return str_replace('<script src=', '<script' . $attr_html . ' src=', $tag);
    }

    /**
     * Block filter function to replace tokens in the format
     * '{Pipeline::get("engine","property")}'.
     * 
     * @param string $block_content the existing blocb content to parse
     * @param object $block not used in this function
     * @return string the updated block content
     */
    public function fiftyonedegrees_block_filter($block_content, $block) {
        $content = $block_content;
        $pattern = '/\{Pipeline::get\("[A-Za-z]+",[ ]*"[A-Za-z]+"\)\}/';
        preg_match_all(
            $pattern,
            $block_content,
            $matches,
            PREG_PATTERN_ORDER);

        foreach ($matches as $pattern_matches) {
            foreach ($pattern_matches as $match) {
                $args = str_replace("{Pipeline::get(", "", $match);
                $args = str_replace(")}", "", $args);
                $args = str_replace("\"", "", $args);
                $args = str_replace(" ", "", $args);
                $args = explode(",", $args);

                try {
                    $value = Pipeline::get($args[0], $args[1]);
                } catch (\Throwable $exception) {
                    $value = null;
                }

                switch (gettype($value)) {
                    case "string":
                        break;
                    case "boolean":
                        if ($value) {
                            $value = "true";
                        } else {
                            $value = "false";
                        }
                        break;
                    case "array":
                        $value = implode(",", $value);
                        break;
                    default:
                        $value = json_encode($value);
                }

                $content = str_replace($match, $value, $content);
            }
        }
        return $content;
    }
    
    /**
     * Add a '51Degrees' category to the list of block categories
     * available in the editor.
     * 
     * @param array $categories the existing list of categories
     * @return object the updated categories including the 51Degrees category
     */
    function fiftyonedegrees_block_categories($categories) {
        $category_slugs = wp_list_pluck($categories, 'slug');

        return in_array('51Degrees', $category_slugs, true) ?
            $categories :
            array_merge(
                $categories,
                array(
                    array(
                        'slug'  => '51Degrees',
                        'title' => __( '51Degrees', '51D' ),
                        'icon'  => null,
                    ),
                )
        );
    }

    /**
     * Setup everything needed for editing 51Degrees blocks. For example,
     * a list of properties is initialized to be used in a drop down list.
     * 
     * @return void
     */
    function fiftyonedegrees_setup_blocks() {

        wp_register_script(
            'fiftyonedegrees-conditional-group-block',
            plugins_url('../conditional-group-block/build/index.js', __FILE__),
            [
                'wp-i18n',
                'wp-element',
                'wp-blocks',
                'wp-components',
                'wp-editor'
            ],
            '1.0.0'
        );

        wp_register_style(
            'fiftyonedegrees-conditional-group-block',
            plugins_url('../conditional-group-block/src/editor.css', __FILE__),
            [],
            '1.0.0'
        );
            
        register_block_type(
            'fiftyonedegrees/conditional-group-block',
            [
                'editor_script' => 'fiftyonedegrees-conditional-group-block',
                'style'         => 'fiftyonedegrees-conditional-group-block',
            ]
        );
        
        // Add list of properties to select field in editor interface
        $propertySelect = [
            [
                "label" => "Property", 
                "value" => ""
            ]
        ];
        if (Pipeline::$data) {
            foreach (Pipeline::$data["properties"] as $dataKey =>
                $engineProperties) {
                foreach ($engineProperties as $property) {
                    $propertySelect[] = array(
                        "label" => strtolower(
                            $property["name"] . " (" . $dataKey . ")"),
                        "value" => strtolower(
                            $dataKey . "|" . $property["name"])
                    );
                }
            }

            wp_localize_script(
                "fiftyonedegrees-conditional-group-block",
                'fiftyoneProperties',
                $propertySelect);
        }
    }
    
    /**
     * Handles conditional blocks.
     * Compares the target property value set in the block with the actual
     * property value from the flow data for the requesting device using the
     * operator specified in the block. If the result is true then the block
     * is rendered, otherwise not.
     * 
     * @param string $block_content content of the block to potentially be
     * displayed
     * @param object $block the block itself, containing the options used to
     * determine whether to display the content
     * @return string|null either the value of $block_content if the condition
     * is met, otherwise null
     */
    function fiftyonedegrees_render_block($block_content, $block) {

        if ('fiftyonedegrees/conditional-group-block' === $block['blockName']) {

            if(isset($block["attrs"]["property"]) &&
                !empty($block["attrs"]["property"]) &&
                isset($block["attrs"]["operator"]) &&
                !empty($block["attrs"]["operator"]) &&
                isset($block["attrs"]["value"]) &&
                !empty($block["attrs"]["value"])){

                $property = $block["attrs"]["property"];

                // Split property and engine by pipe
                $engineDataKey = explode("|", $property)[0];
                $propertyName = explode("|", $property)[1];

                // Get property value
                $value = Pipeline::get($engineDataKey, $propertyName);

                // JSON encode to string if not a string already
                if (!is_string($value)) {

                    $value = json_encode($value);
                }

                $compareValue = $block["attrs"]["value"];

                if (empty($compareValue)) {
                    return null;       
                }

                $operator = $block["attrs"]["operator"];

                // Default to not show and then overwrite based on
                // operator rules
                $show = false;

                switch ($operator) {
                    case "is":
                        $show = $value === $compareValue;
                        break;
                    case "not":
                        $show = $value !== $compareValue;
                        break;
                    case "contains":
                        $show = strpos($value, $compareValue) !== false;
                        break;
                }
                
                if (!$show) {
                    return null;
                }

            } else {
                return null;
            }

        }

        return $block_content;
    }

    public function delete_pipeline_options() {
        delete_option(Options::RESOURCE_KEY);
        delete_option(Options::PIPELINE);
        delete_option(Options::PIPELINE_ENABLE);
        delete_option(Options::SESSION_INVALIDATED);
        delete_option(Options::PIPELINE_VALIDATION_ERROR);
    }

    public function delete_pmp_options() {
        delete_option(Options::PMP_ENABLE);
        delete_option(Options::PMP_TCF_VENDOR_STRING);
        delete_option(Options::PMP_ALT_LABEL);
        delete_option(Options::PMP_ALT_URL);
        delete_option(Options::PMP_BRAND_NAME);
        delete_option(Options::PMP_BRAND_LOGO_URL);
        delete_option(Options::PMP_BRAND_TERMS_URL);
        delete_option(Options::PMP_SHOW_STANDARD);
    }
}
?>

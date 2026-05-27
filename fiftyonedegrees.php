<?php
/**
 *  Plugin Name: 51Degrees
 *  Plugin URI:  https://51degrees.com/
 *  Description: Device detection and location-aware content for WordPress, with cloud-driven robots.txt management for AI/search crawlers and suspicious-activity protection against abusive traffic.
 *  Version:     1.0.11
 *  Requires PHP: 8.2
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

// Exit if accessed directly.
if (!defined('ABSPATH')) { exit; }

/**
 * Main Fiftyonedegrees class.
 * 
 * This is the bulk of the plugin, and where everything else is referenced
 * from.
 * 
 * This class should be used as a singleton, the single instance of this
 * class is returned via the static method get_instance().
 *
 * @since       1.0.0
 */
class Fiftyonedegrees {
    /**
     * @var         Fiftyonedegrees $instance
     * @since       1.0.0
     */
    private static $instance;
    private $ga_service;
    private $fiftyone_service;

    /**
     * Constructor.
     * Initializes the instance of this plugin.
     * 
     * @access private
     */
    private function __construct() {
        $this->load_includes();
        $this->setup_constants();
        $this->fiftyone_service = new FiftyoneService();
        $this->ga_service = new Fiftyonedegrees_Google_Analytics();
        $this->setup_wp_actions();
        $this->setup_wp_filters();
        $this->setup_oauth_actions();
    }

    /**
     * Get active instance.
     * 
     * This class is lazily loaded, to the first request to this method
     * will construct the singleton instance.
     *
     * @access      public
     * @since       1.0.0
     * @return      object self::$instance
     */
    public static function get_instance() {

        if (!isset( Fiftyonedegrees::$instance)) {
            self::$instance = new Fiftyonedegrees();
        }
        return self::$instance;
    }

    /**
     * Setup plugin constants.
     * 
     * All constants are global, so must be prefixed with "FIFTYONEDEGREES_".
     *
     * @access      private
     * @since       1.0.0
     * @return      void
     */
    private function setup_constants() {
        // Setting Global Values.
        define('FIFTYONEDEGREES_PLUGIN_DIR', plugin_dir_path( __FILE__ ));
        define('FIFTYONEDEGREES_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('FIFTYONEDEGREES_PROMPT', 'force');
        define('FIFTYONEDEGREES_ACCESS_TYPE', 'offline');
        // Production credentials. Local dev can override these by defining
        // FIFTYONEDEGREES_DEV_CLIENT_ID / _SECRET / _REDIRECT earlier in
        // the request (e.g. via wp-config.php inside wp-env). The DEV
        // constants are never committed to source control — see the
        // dev-oauth-setup note in the project vault for setup.
        define('FIFTYONEDEGREES_CLIENT_ID',
            defined('FIFTYONEDEGREES_DEV_CLIENT_ID')
                ? FIFTYONEDEGREES_DEV_CLIENT_ID
                : '296335631462-e36u9us90puu4de17ct7rnklu3j8q63n.apps.googleusercontent.com');
        define('FIFTYONEDEGREES_CLIENT_SECRET',
            defined('FIFTYONEDEGREES_DEV_CLIENT_SECRET')
                ? FIFTYONEDEGREES_DEV_CLIENT_SECRET
                : 'V9lcL-V3SxtGSWWcGsFW9QeI');
        // Production redirect URI is a TODO placeholder until 51Degrees
        // provisions the real relay URL (release ship-gate). Until then
        // the runtime guard in setup_oauth_actions() refuses to wire the
        // OAuth handlers and surfaces an admin notice — accidental
        // release with the placeholder produces a loud failure instead
        // of a silent redirect to a non-existent host.
        define('FIFTYONEDEGREES_REDIRECT',
            defined('FIFTYONEDEGREES_DEV_REDIRECT')
                ? FIFTYONEDEGREES_DEV_REDIRECT
                : 'https://TODO-relay-url');
        define('FIFTYONEDEGREES_CUSTOM_DIMENSION_SCOPE', "HIT");
    }

    /**
     * Include necessary files.
     *
     * @access      private
     * @since       1.0.11
     * @return      void
     */
    private function load_includes() {

        // Load the Google API PHP Client Library.
        include_once __DIR__ . '/lib/vendor/autoload.php';
        require_once __DIR__ . '/includes/pipeline.php';
        require_once __DIR__ . '/includes/fiftyone-service.php';
        require_once __DIR__ . '/includes/ga-service.php';
        require_once __DIR__ . '/includes/ga-tracking-gtag.php';
        require_once __DIR__ . '/options.php';
        require_once __DIR__ . '/includes/suspicious-activity.php';
        require_once __DIR__ . '/includes/oauth-state.php';
        require_once __DIR__ . '/includes/oauth-notice.php';
        require_once __DIR__ . '/includes/google-client-factory.php';
        require_once __DIR__ . '/includes/oauth-migration.php';

        // OAuth callback and start handlers land in later commits. Guard
        // with file_exists so the bootstrap stays loadable while the
        // files are being introduced one at a time — once both exist,
        // the class_exists checks in setup_oauth_actions() pick them up.
        $oauth_callback_file = __DIR__ . '/includes/oauth-callback.php';
        if (file_exists($oauth_callback_file)) {
            require_once $oauth_callback_file;
        }
        $oauth_start_file = __DIR__ . '/includes/oauth-start.php';
        if (file_exists($oauth_start_file)) {
            require_once $oauth_start_file;
        }

        // Include Custom_Dimensions class
        if (!class_exists('Fiftyonedegrees_Custom_Dimensions')) {
            require_once('includes/ga-custom-dimension-class.php');
        }
    }

    function setup_wp_actions() {
        $this->fiftyone_service->setup_wp_actions();
        $this->ga_service->setup_wp_actions();
    }

    function setup_wp_filters() {
        $this->fiftyone_service->setup_wp_filters(plugin_basename(__FILE__));
    }
    
    function delete_options() {
        $this->ga_service->delete_ga_options();
        $this->fiftyone_service->delete_pipeline_options();
        $this->fiftyone_service->delete_pmp_options();
        SuspiciousActivity::delete_options();
        FiftyOneDegreesRobotsTxt::delete_options();
        FiftyOneDegreesOauthState::delete_options();
        FiftyOneDegreesOauthMigration::delete_options();
    }

    function execute_ga_tracking_steps() {
        $this->ga_service->execute_ga_tracking_steps();
    }

    /**
     * Wires up the OAuth flow on admin_init / admin_post.
     *
     * Migration runs unconditionally and idempotently — it cleans up
     * OOB-era state regardless of whether the new OAuth flow has a
     * working redirect URL yet.
     *
     * The callback and start handlers are gated on FIFTYONEDEGREES_REDIRECT
     * not being a placeholder. If the build shipped with the TODO URL
     * (no real relay configured), we refuse to wire the handlers and
     * surface a sticky admin notice instead — accidental release should
     * fail loudly rather than redirect users to a non-existent host.
     *
     * Once the handler classes land in later commits, the class_exists
     * checks pick them up without further changes to this file.
     */
    private function setup_oauth_actions() {
        add_action('admin_init', ['FiftyOneDegreesOauthMigration', 'run'], 10);
        add_action(
            'fiftyonedegrees_refresh_robots_txt',
            ['FiftyOneDegreesOauthState', 'cron_cleanup']
        );

        if (strpos(FIFTYONEDEGREES_REDIRECT, 'TODO') !== false) {
            add_action('admin_notices', [$this, 'render_placeholder_url_notice']);
            return;
        }

        if (class_exists('FiftyOneDegreesOauthCallback')) {
            add_action('admin_init', ['FiftyOneDegreesOauthCallback', 'handle'], 5);
        }
        if (class_exists('FiftyOneDegreesOauthStart')) {
            add_action(
                'admin_post_fiftyonedegrees_oauth_start',
                ['FiftyOneDegreesOauthStart', 'handle']
            );
        }
    }

    /**
     * Sticky admin notice shown when the plugin shipped with a
     * placeholder OAuth redirect URL. Visible to all admins on every
     * wp-admin page until the build is replaced.
     */
    public function render_placeholder_url_notice() {
        echo '<div class="notice notice-error"><p><strong>'
            . esc_html__('51Degrees:', 'fiftyonedegrees')
            . '</strong> '
            . esc_html__(
                'OAuth is disabled — this build of the plugin shipped with a placeholder redirect URL. Google Analytics cannot be connected until a real relay URL is configured. Please contact 51Degrees support.',
                'fiftyonedegrees'
            )
            . '</p></div>';
    }
}


// ====================== active - inactive - delete hooks =========================

require_once(__DIR__ . '/options.php');
require_once(__DIR__ . '/includes/cloud-metadata.php');
require_once(__DIR__ . '/includes/standard-tdls.php');
require_once(__DIR__ . '/includes/robots-txt.php');
FiftyOneDegreesRobotsTxt::init();

// Activate Plugin
/**
 * Create instance of fiftyonedegrees class.
 */
function load_fiftyonedegrees() {
	return Fiftyonedegrees::get_instance();
}

add_action('plugin_loaded', 'load_fiftyonedegrees');

function fiftyonedegrees_activate() {
    add_option(Options::ROBOTS_PLAINTEXT_CACHE, '');

    if (!wp_next_scheduled('fiftyonedegrees_refresh_robots_txt')) {
        wp_schedule_event(time(), 'daily', 'fiftyonedegrees_refresh_robots_txt');
    }
}
register_activation_hook(__FILE__, 'fiftyonedegrees_activate');

// Deactivation is reversible and runs on plugin auto-update too, so it
// must not destroy persistent data. Only stop scheduled work and drop
// derived caches; the user's saved options stay put.
register_deactivation_hook(__FILE__, 'fiftyonedegrees_deactivate');

// Uninstall is terminal — wipe every option row the plugin ever wrote.
register_uninstall_hook(__FILE__, 'fiftyonedegrees_uninstall');

function fiftyonedegrees_deactivate() {
    wp_clear_scheduled_hook('fiftyonedegrees_refresh_robots_txt');
    FiftyOneDegreesCloudMetadata::invalidate_all();
}

function fiftyonedegrees_uninstall() {
    wp_clear_scheduled_hook('fiftyonedegrees_refresh_robots_txt');
    Fiftyonedegrees::get_instance()->delete_options();
    FiftyOneDegreesCloudMetadata::invalidate_all();
}

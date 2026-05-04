<?php
/**
 *  Plugin Name: 51Degrees - Optimize by Device & Location
 *  Plugin URI:  https://51degrees.com/
 *  Description: Optimize your website for a range of devices and personalize your content based on your user’s location.
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
        define('FIFTYONEDEGREES_RESPONSE_TYPE', 'code');
        define('FIFTYONEDEGREES_CLIENT_ID',
            '296335631462-e36u9us90puu4de17ct7rnklu3j8q63n.apps.googleusercontent.com');
        define(
            'FIFTYONEDEGREES_CLIENT_SECRET',
            'V9lcL-V3SxtGSWWcGsFW9QeI');
        define( 'FIFTYONEDEGREES_REDIRECT', 'urn:ietf:wg:oauth:2.0:oob');
        define(
            'FIFTYONEDEGREES_SCOPE',
            Google_Service_Analytics::ANALYTICS_READONLY .
            " " .  Google_Service_Analytics::ANALYTICS_EDIT);
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
    }

    function execute_ga_tracking_steps() {
        $this->ga_service->execute_ga_tracking_steps();
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

register_deactivation_hook(__FILE__, 'fiftyonedegrees_deactivate'); //in-active
register_uninstall_hook(__FILE__, 'fiftyonedegrees_deactivate'); // delete

function fiftyonedegrees_deactivate() {
    wp_clear_scheduled_hook('fiftyonedegrees_refresh_robots_txt');
    Fiftyonedegrees::get_instance()->delete_options();
    delete_option(Options::RESOURCE_KEY);
    delete_option(Options::PIPELINE);
}

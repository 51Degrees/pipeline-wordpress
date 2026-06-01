<?php
/*
    This Original Work is copyright of 51 Degrees Mobile Experts Limited.
    Copyright 2019 51 Degrees Mobile Experts Limited, 5 Charlotte Close,
    Caversham, Reading, Berkshire, United Kingdom RG4 7BY.

    This Original Work is licensed under the European Union Public Licence (EUPL) 
    v.1.2 and is subject to its terms as set out below.

    If a copy of the EUPL was not distributed with this file, You can obtain
    one at https://opensource.org/licenses/EUPL-1.2.

    The 'Compatible Licences' set out in the Appendix to the EUPL (as may be
    amended by the European Commission) shall be deemed incompatible for
    the purposes of the Work and the provisions of the compatibility
    clause in Article 5 of the EUPL shall not apply.
*/

use Google\Service\Analytics\CustomDimension;

require_once __DIR__ . '/../options.php';

/**
 * Google Analytics Service class 
 *
 * @since       1.0.0
 * 
 * @package Fiftyonedegrees
 * @author  Fatima Tariq
 */
class Fiftyonedegrees_Google_Analytics {

    /**
     * Instance of the tracking GTag.
     */
    private $gtag_tracking_inst;
 
     /**
     * Constructor.
     * Initializes the instance of this service.
     * 
     * @access public
     */
    public function __construct() {
        $this->gtag_tracking_inst = new Fiftyonedegrees_Tracking_Gtag();
    }

    /**
     * Returns a configured Google_Client carrying the stored access token,
     * or false when no token is available. Token acquisition happens in
     * the OAuth callback (oauth-callback.php); this method is the reuse
     * path called by the custom-dimensions code in this file.
     *
     * The factory's `make()` constructs a fresh client with the right
     * credentials/scope/redirect block — the test seam lives at this
     * method (callers mock authenticate() rather than the factory), so
     * we deliberately do not introduce an inner build_client() override.
     *
     * @return Google_Client|false
     */
    public function authenticate() {

        $ga_google_authtoken = get_option(Options::GA_TOKEN);
        if (empty($ga_google_authtoken)) {
            return false;
        }

        $client = FiftyOneDegreesGoogleClientFactory::make();
        $client->setAccessToken($ga_google_authtoken);

        if ($client->isAccessTokenExpired()) {
            $refresh = $client->getRefreshToken();
            if (empty($refresh)) {
                return false;
            }
            $new_token = $client->fetchAccessTokenWithRefreshToken($refresh);
            if (isset($new_token['error'])) {
                error_log('51Degrees GA token refresh failed: ' . $new_token['error']);
                return false;
            }
            update_option(Options::GA_TOKEN, $client->getAccessToken());
            update_option(Options::GA_AUTH_DATE, time());
        }

        return $client;
    }

    /**
     * Builds the UA Management API service. Still wired live via the
     * Custom Dimensions admin_init handlers below — those reach the UA
     * Management API which Google shut down on 2024-07-01, so any call
     * lands a 404 / dead-API error. The methods are retained until a
     * later commit replaces them with their GA4 Admin API equivalents
     * so each intermediate commit on the branch still compiles. After
     * the v3 schema migration the UA-paired option rows are gone, so
     * the live code paths are non-functional at runtime until that
     * later commit lands.
     *
     * @param Google_Client $client
     * @return Google_Service_Analytics
     */
    public function get_google_analytics_service ($client) {
        try {

            // Create an authorized analytics service object.
            $service = new Google_Service_Analytics($client);

        }
        catch (Google_Service_Exception $e) {

            error_log($e->getMessage());
        }
        catch (Exception $e) {

            error_log($e->getMessage());
        }

        return $service;
    }

    /**
     * Builds the GA4 Admin API service. Sits behind a method (rather
     * than being inlined into the caller) so tests can Mockery-mock
     * the GA4 admin client without instantiating the real apiclient
     * service class.
     *
     * @param Google_Client $client
     * @return Google_Service_GoogleAnalyticsAdmin
     */
    public function get_ga4_admin_service($client) {
        return new Google_Service_GoogleAnalyticsAdmin($client);
    }

    /**
     * Cache freshness marker for Options::GA_PROPERTIES. Lazy-fetch
     * call sites must use this transient to gate API calls — a bare
     * `empty(get_option(GA_PROPERTIES))` check would keep re-hitting
     * accountSummaries.list on every render for accounts that
     * genuinely have zero GA4 properties.
     */
    public const GA_PROPERTIES_FRESHNESS_TRANSIENT = 'fiftyonedegrees_ga_properties_fresh';

    /**
     * TTL on the freshness marker. Five minutes is short enough that
     * newly-created GA4 properties show up in the dropdown within
     * one admin-page reload, and long enough that an admin who
     * leaves the settings tab open does not burn quota on every
     * keypress-triggered re-render.
     */
    public const GA_PROPERTIES_FRESHNESS_TTL = 300;

    /**
     * Populates Options::GA_PROPERTIES with the GA4 properties the
     * authenticated admin can see and stamps the freshness transient
     * so subsequent renders within the TTL window do not re-fetch.
     *
     * Returns the stored array on success; an empty array means the
     * API returned no properties (the dropdown renders its empty
     * branch). On auth failure (401 / 403) the property service
     * throws FiftyOneDegreesGa4AuthError — we catch and propagate to
     * Options::GA_ERROR so the admin sees a reconnect prompt rather
     * than an unexplained empty dropdown.
     *
     * See FiftyOneDegreesGa4PropertyService for the per-row shape.
     *
     * @param Google_Service_GoogleAnalyticsAdmin $admin
     * @return array<int,array<string,string>>
     */
    public function get_analytics_properties_list($admin) {
        if (!get_option(Options::GA_TOKEN)) {
            return [];
        }

        try {
            $properties = FiftyOneDegreesGa4PropertyService::list_account_summaries($admin);
        }
        catch (FiftyOneDegreesGa4AuthError $e) {
            update_option(
                Options::GA_ERROR,
                'Google Analytics permission was revoked or the access '
                . 'token is no longer valid. Please reconnect Google '
                . 'Analytics.'
            );
            return [];
        }

        update_option(Options::GA_PROPERTIES, $properties);
        set_transient(
            self::GA_PROPERTIES_FRESHNESS_TRANSIENT,
            '1',
            self::GA_PROPERTIES_FRESHNESS_TTL
        );
        return $properties;
    }

    /**
     * UA Management API account-id lookup. Still wired live via the
     * Custom Dimensions code path below; non-functional at runtime
     * after the v3 schema migration sweeps the UA tracking-id row.
     * Removed in a later commit alongside the Custom Dimensions
     * migration to the GA4 Admin API.
     *
     * @param Google_Service_Analytics $analytics_service
     * @param string $trackingId
     * @return string accountId
     */
    public function get_account_id($analytics_service, $trackingId) {

        if (!empty($trackingId)) {

            try {
                // Get the list of accounts and web properties.
                $accounts = $analytics_service->management_accountSummaries->listManagementAccountSummaries();
                foreach ($accounts->getItems() as $account) {
                    $accountId = $account->getId();
                    foreach ($account->getWebProperties() as $property) {
                        if ($property->getId() === $trackingId) {
                            return $accountId;
                        }
                    }
                }
            }
            catch (apiServiceException $e) {
                error_log('There was an Analytics API service error ' .
                $e->getCode() . ':' . $e->getMessage());
                return "";
              
            }
            catch (apiException $e) {
                error_log('There was a general API error ' .
                $e->getCode() . ':' . $e->getMessage());

                return "";
            }
        }
        return ""; 
    }

    /**
     * UA Management API custom-dimensions read. Still wired live but
     * non-functional after the v3 schema migration (depends on
     * GA_TRACKING_ID which is swept) and on a Google API that was
     * shut down on 2024-07-01. Removed in a later commit alongside
     * the Custom Dimensions migration to the GA4 Admin API.
     *
     * @return array array containing custom dimensions list
     * and max available custom dimension index
     */
    public function get_custom_dimensions() {
        $trackingId = get_option(Options::GA_TRACKING_ID);
        $maxCustomDimIndex = get_option(Options::GA_MAX_DIMENSIONS);
        $client = $this->authenticate();

        if ($client) {

            $service = $this->get_google_analytics_service($client);

            // Get accountId from tracking Id
            $accountId = $this->get_account_id($service, $trackingId);
            update_option(Options::GA_ACCOUNT_ID, $accountId);

            // Get the list of custom dimensions for the web property.
            $customDimensions = $service->management_customDimensions->listManagementCustomDimensions($accountId, $trackingId);
            
            // Create a map with custom dimensions name and indices.
            $custom_dimensions_map = array();
            foreach ($customDimensions->getItems() as $customDimension) {
                $customDimensionName = $customDimension->getName();
                $customDimensionIndex = $customDimension->getIndex();
                $custom_dimensions_map[$customDimensionName] = $customDimensionIndex;
            }

            // Get Maximum Custom Dimension Index
            $maxCustomDimIndex = count($customDimensions->getItems());
            update_option(Options::GA_MAX_DIMENSIONS, $maxCustomDimIndex);
    
        } 
        else {
            error_log("User is not authenticated.");
        }

        return array(
            "cust_dims_map" => $custom_dimensions_map,
            "max_cust_dim_index" => $maxCustomDimIndex );
    }

    /**
     * UA Management API custom-dimensions write. Still wired live but
     * non-functional after the v3 schema migration (depends on
     * GA_TRACKING_ID + GA_ACCOUNT_ID which are swept) and on a Google
     * API that was shut down on 2024-07-01. Removed in a later commit
     * alongside the Custom Dimensions migration to the GA4 Admin API.
     *
     * @return int number of new custom dimensions inserted.
     */
    public function insert_custom_dimensions() {

        $calls = 0;        
        $accountId = get_option(Options::GA_ACCOUNT_ID);
        $trackingId = get_option(Options::GA_TRACKING_ID);
        $cust_dim_map = get_option(Options::GA_CUSTOM_DIMENSIONS_MAP);
        $client = $this->authenticate();

        if ($client) {

            $service = $this->get_google_analytics_service($client);

            foreach ($cust_dim_map as $dimension) {

                $custDimName = $dimension["custom_dimension_name"];
                $custDimGAIndex = $dimension["custom_dimension_ga_index"];
                $custDimIndex = $dimension["custom_dimension_index"];

                if ($custDimGAIndex === -1) {

                    $customDimension = new CustomDimension();
                    $customDimension->setName($custDimName);
                    $customDimension->setIndex($custDimIndex);
                    $customDimension->setScope(FIFTYONEDEGREES_CUSTOM_DIMENSION_SCOPE);
                    $customDimension->setActive(true);

                    try {

                        // Insert Custom Dimension in Google Analytics
                        $result = $service->management_customDimensions->insert($accountId, $trackingId, $customDimension);
                        $calls = $calls + 1;

                    }
                    catch (Exception $e) {

                        $jsonError = json_decode($e->getMessage(), $assoc = true);
                        $message = "Could not insert Custom Dimensions in Google " .
                            "Analytics account.";
                        
                        if (strpos($e->getMessage(), "maximum allowed entities")
                            !== false) {
                            $message = $message . " Your Analytics account " .
                                "allows a maximum of " .
                                $this->get_custom_dimensions()['max_cust_dim_index'] .
                                " Custom Dimensions.";
                        }
                        update_option(
                            Options::GA_ERROR,
                            $message . " Error message from Google was: '" .
                            $jsonError["error"]["message"] . "'");
                        error_log($e->getMessage());
                        return -1;
                    }
                }
            }    
        }
        else {
            error_log("User is not authenticated.");
            return -1;
        }  

        return $calls;
    }
    
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
        // (Legacy OOB Access Code admin_init hook removed during the
        // OAuth refactor — the UI input that produced its POST is gone
        // and the handler method was unreachable code.)
        //
        // The Custom Dimensions handlers below (_update_cd_indices and
        // _enable_tracking) still call into UA Management API code paths
        // that the v3 schema migration leaves non-functional. They stay
        // registered to keep this commit buildable; a later commit
        // rewrites them on the GA4 Admin API.
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_ga_logout'));
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_ga_set_property'));
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_ga_update_cd_indices'));
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_ga_change_screen'));
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_ga_enable_tracking'));
            
        // Head actions. These are actions to run before generating an HTML
        // head section.
        add_action(
            'wp_head',
            array($this, 'fiftyonedegrees_ga_add_analytics_code'),
            10);
    }

    /**
     * Construct a list of Google Analytics custom dimensions and store in
     * an option.
     * 
     * This is called either when GA is enabled, or when the custom dimensions
     * are updated.
     * 
     * @param array $cachedPipeline 51Degrees pipeline
     * @return void
     */
    function populate_selected_dimensions($cachedPipeline) {

        if (!isset($cachedPipeline['error'])) {
                    
            $passed_dimensions = array();
            foreach ($_POST as $key=>$dimension) {
                if (strpos($key, "51D_") !== false) {
                    $key = sanitize_text_field(wp_unslash(
                        str_replace("51D_","", $key)));
                    $passed_dimensions[$key] =
                        sanitize_text_field(wp_unslash($dimension));
                }
            }
            update_option(
                Options::GA_DIMENSIONS,
                $passed_dimensions);
            update_option(
                Options::GA_DIMENSIONS_UPDATED,
                true);
        }
    }

    /**
     * If a POST has been made with new Google Analytics custom dimensions,
     * then update them within the plugin.
     * 
     * @return void
     */
    function fiftyonedegrees_ga_update_cd_indices() {

        if (isset($_POST["fiftyonedegrees_ga_update_cd_indices"])) {

            if ("Update Custom Dimension Mappings" ===
                $_POST["fiftyonedegrees_ga_update_cd_indices"]) {
                $this->populate_selected_dimensions(
                    get_option(Options::PIPELINE));

            }
            wp_redirect(get_admin_url() .
                'options-general.php?page=51Degrees&tab=google-analytics');
        }       
    }

    /**
     * Add Google Analytics JavaScript to the page.
     * 
     * @return void
     */
    function fiftyonedegrees_ga_add_analytics_code() {
        
        echo sprintf(
            esc_html('%1$s'),
            get_option(Options::GA_JS));			  
    }

    /**
     * If a POST has been made to enable/disable Google Analytics,
     * then enable it and update the custom dimensions within the plugin.
     * 
     * @return void
     */
    function fiftyonedegrees_ga_enable_tracking() {

        if (isset($_POST[Options::ENABLE_GA])) {

            if ("Enable Google Analytics Tracking" ===
                $_POST[Options::ENABLE_GA]) {

                $cachedPipeline =
                    get_option(Options::PIPELINE);
                $this->populate_selected_dimensions($cachedPipeline);

                if (!isset($cachedPipeline['error'])) {

                    $this->execute_ga_tracking_steps();
                }
                
            }
            else {
                delete_option(Options::GA_JS);
                delete_option(Options::ENABLE_GA);            
            }

            delete_option(Options::RESOURCE_KEY_UPDATED);
            delete_option(Options::GA_ID_UPDATED);
            delete_option(Options::GA_SEND_PAGE_VIEW_UPDATED);
            delete_option(Options::GA_DIMENSIONS_UPDATED);
            wp_redirect(get_admin_url() .
                'options-general.php?page=51Degrees&tab=google-analytics');
        }
                            
    }

    /**
     * Sets up the options needed to add custom dimentions to Google Analytics.
     * 
     * @return void
     */
    function execute_ga_tracking_steps() {

        //Prepare Custom Dimensions
        $customDimensionsTable = new Fiftyonedegrees_Custom_Dimensions();
        $customDimensionsTable->prepare_items();           

        // Get Google analytics Tracking Javascript to be added to the
        // header. 
        $gtag_code = $this->gtag_tracking_inst->output_ga_tracking_code();
        update_option(Options::GA_JS, $gtag_code);

        // Insert Custom Dimensions in Google Analytics
        $added = $this->insert_custom_dimensions();
        
        // Mark tracking is enabled.
        if ($added >= 0) {
            update_option(Options::ENABLE_GA, "enabled");
        }
    }

    /**
     * Run if a POST is recieved to update Google Analytics options.
     * 
     * @return void
     */
    function fiftyonedegrees_ga_change_screen() {

        if (isset($_POST["fiftyonedegrees_ga_change_settings"])) {
            
            delete_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN);
            wp_redirect(get_admin_url() .
                'options-general.php?page=51Degrees&tab=google-analytics' );
        }          
    }   

    /**
     * Handles the GA4 property dropdown form submission. On a valid
     * pick we persist the chosen Property ID plus the Measurement ID
     * of its first WEB_DATA_STREAM (resolved via a second Admin API
     * call) so the frontend gtag emission has both pieces ready
     * without further round-trips.
     *
     * Four failure surfaces, all routed through a one-shot admin
     * notice (GA_TRACKING_ID_ERROR / GA_ERROR) so the admin sees a
     * concrete cause rather than a silently-failing form:
     *
     *   - sentinel selected or non-numeric value (admin clicked Save
     *     without picking a real property, or a hand-crafted POST):
     *     GA_TRACKING_ID_ERROR flag
     *   - authentication expired with no refresh path available:
     *     GA_ERROR + clear CD screen
     *   - GA4 Admin API rejects the call as unauthorized (token scope
     *     revoked at Google's end): GA_ERROR with reconnect copy +
     *     clear CD screen
     *   - property has no Web data stream (mobile-only / Firebase-
     *     only property): GA_ERROR + clear CD screen
     *
     * GA_TRACKING_ID_ERROR is reused as the "no property selected"
     * sentinel — the underlying option-key name predates the GA4
     * migration but is kept to avoid a schema change in the middle
     * of this commit series; renames live in the final cleanup pass.
     *
     * Side effects on success are deferred to the end so a failure
     * along the way (no Web stream, auth error) does not leave
     * GA_PROPERTY_ID half-written without its paired Measurement ID.
     *
     * @return void
     */
    function fiftyonedegrees_ga_set_property() {
        if (!get_option(Options::GA_TOKEN)) {
            return;
        }
        if (!isset($_POST['submit']) || 'Save Changes' !== $_POST['submit']) {
            return;
        }
        if (!isset($_POST[Options::GA_PROPERTY_ID])) {
            return;
        }

        delete_option(Options::GA_TRACKING_ID_ERROR);

        $property_id = sanitize_text_field(wp_unslash(
            $_POST[Options::GA_PROPERTY_ID]));

        // Sentinel — admin pressed Save without picking a real
        // property. The dropdown's "Select Analytics Property"
        // option carries an empty value attribute so the browser
        // sends '', but we also catch the literal display text in
        // case a stale browser cached the pre-fix markup. Anything
        // non-numeric is rejected here too: GA4 property ids are
        // numeric strings and a hand-crafted POST shouldn't leak
        // arbitrary text into a Google API resource path.
        if ($property_id === ''
            || $property_id === 'Select Analytics Property'
            || !ctype_digit($property_id)
        ) {
            update_option(Options::GA_TRACKING_ID_ERROR, true);
            delete_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN);
            wp_redirect(get_admin_url() .
                'options-general.php?page=51Degrees&tab=google-analytics');
            return;
        }

        $client = $this->authenticate();
        if (!$client) {
            update_option(
                Options::GA_ERROR,
                'Google Analytics authentication expired. Please reconnect.'
            );
            delete_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN);
            wp_redirect(get_admin_url() .
                'options-general.php?page=51Degrees&tab=google-analytics');
            return;
        }

        $admin = $this->get_ga4_admin_service($client);

        try {
            $measurement_id = FiftyOneDegreesGa4PropertyService::get_measurement_id(
                $admin,
                $property_id
            );
        }
        catch (FiftyOneDegreesGa4AuthError $e) {
            update_option(
                Options::GA_ERROR,
                'Google Analytics permission was revoked or the access '
                . 'token is no longer valid. Please reconnect Google '
                . 'Analytics.'
            );
            delete_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN);
            wp_redirect(get_admin_url() .
                'options-general.php?page=51Degrees&tab=google-analytics');
            return;
        }

        if ($measurement_id === null) {
            update_option(
                Options::GA_ERROR,
                'The selected GA4 property has no Web data stream. '
                . 'Add a Web stream in Google Analytics Admin, '
                . 'then reload this page.'
            );
            delete_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN);
            wp_redirect(get_admin_url() .
                'options-general.php?page=51Degrees&tab=google-analytics');
            return;
        }

        // All checks passed — persist the paired ids together so the
        // intermediate render state cannot ever see a property id
        // without its measurement id (or vice versa).
        update_option(Options::GA_PROPERTY_ID, $property_id);
        update_option(Options::GA_MEASUREMENT_ID, $measurement_id);

        // Invalidate the dropdown-freshness transient so the next
        // render re-pulls account summaries — picks up any GA4
        // changes the admin made between connect and submit.
        delete_transient(self::GA_PROPERTIES_FRESHNESS_TRANSIENT);

        if (isset($_POST[Options::GA_SEND_PAGE_VIEW]) &&
            'on' === $_POST[Options::GA_SEND_PAGE_VIEW]) {
            update_option(Options::GA_SEND_PAGE_VIEW, 'true');
            update_option(Options::GA_SEND_PAGE_VIEW_VAL, 'On');
        }
        else {
            delete_option(Options::GA_SEND_PAGE_VIEW);
            update_option(Options::GA_SEND_PAGE_VIEW_VAL, 'Off');
        }

        update_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN, 'enabled');
        wp_redirect(get_admin_url() .
            'options-general.php?page=51Degrees&tab=google-analytics');
    }

    /**
     * If logout from Google Analytics is requested in the admin interface,
     * then remove all existing options relating to Google Analytics.
     * 
     * @return void
     */
    function fiftyonedegrees_ga_logout() {

        if (isset($_POST['ga_log_out'])) {
            
            $this->delete_ga_options();

            wp_redirect(get_admin_url() .
                'options-general.php?page=51Degrees&tab=google-analytics' );
        }
    }

    /**
     * Delete all the options relating to Google Analytics. This will disable
     * the Google Analytics feature.
     *
     * Keep the option set in sync with FiftyOneDegreesOauthMigration::SWEEP_KEYS
     * — both lists encode "this is GA state owned by the integration",
     * the migration uses its subset to force re-consent on schema bump
     * and this method uses the superset for full uninstall.
     */
    function delete_ga_options() {
        // auth artifacts
        delete_option(Options::GA_AUTH_CODE);
        delete_option(Options::GA_TOKEN);
        delete_option(Options::GA_AUTH_DATE);

        // selected property / account
        delete_option(Options::GA_PROPERTIES);
        delete_transient(self::GA_PROPERTIES_FRESHNESS_TRANSIENT);
        delete_option(Options::GA_TRACKING_ID);
        delete_option(Options::GA_MEASUREMENT_ID);
        delete_option(Options::GA_PROPERTY_ID);
        delete_option(Options::GA_ACCOUNT_ID);

        // settings + dimensions
        delete_option(Options::GA_MAX_DIMENSIONS);
        delete_option(Options::GA_SEND_PAGE_VIEW);
        delete_option(Options::GA_JS);
        delete_option(Options::ENABLE_GA);
        delete_option(Options::GA_ERROR);
        delete_option(Options::RESOURCE_KEY_UPDATED);
        delete_option(Options::GA_DIMENSIONS);
        delete_option(Options::GA_DIMENSIONS_UPDATED);
        delete_option(Options::GA_ID_UPDATED);
        delete_option(Options::GA_SEND_PAGE_VIEW_UPDATED);
        delete_option(Options::GA_TRACKING_ID_ERROR);
        delete_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN);
    }
}
    

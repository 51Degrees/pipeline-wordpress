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

require_once __DIR__ . '/../options.php';
require_once __DIR__ . '/oauth-relay-client.php';

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
            // Refresh through the relay (it holds the client secret), not
            // directly against Google. Google's refresh grant does not return a
            // new refresh token, so we preserve the stored one and merge the
            // fresh access token / expiry back in.
            $resource  = (string) get_option(Options::RESOURCE_KEY);
            $new_token = $this->refresh_via_relay($refresh, $resource);
            if (!is_array($new_token)
                || isset($new_token['error'])
                || !isset($new_token['access_token'])
            ) {
                $reason = is_array($new_token) && isset($new_token['error'])
                    ? $new_token['error']
                    : 'unknown';
                error_log('51Degrees GA token refresh failed: ' . $reason);
                return false;
            }

            $merged = is_array($ga_google_authtoken) ? $ga_google_authtoken : [];
            $merged['access_token'] = $new_token['access_token'];
            foreach (['expires_in', 'scope', 'token_type'] as $field) {
                if (isset($new_token[$field])) {
                    $merged[$field] = $new_token[$field];
                }
            }
            $merged['created'] = time();
            $merged['refresh_token'] = $refresh; // relay does not echo it back

            $client->setAccessToken($merged);
            update_option(Options::GA_TOKEN, $merged);
            update_option(Options::GA_AUTH_DATE, time());
        }

        return $client;
    }

    /**
     * Test seam over the relay refresh call. Production delegates to the
     * relay client; tests override to return a canned token array or
     * ['error' => ...] without performing real HTTP.
     *
     * @param string $refresh_token the stored refresh token
     * @param string $resource      the site's resource key
     * @return array
     */
    protected function refresh_via_relay($refresh_token, $resource)
    {
        return FiftyOneDegreesOauthRelayClient::refresh($refresh_token, $resource);
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
     * Drives the GA4 Custom Dimensions side of "Enable Tracking":
     * pre-flight against the per-property cap, then loop create()
     * the dimensions that the admin's CD-map references but the
     * property does not yet carry. Side effects:
     *
     *   - On success: returns true. Invalidates the CD cache
     *     transient so the next CD-tab render shows the freshly-
     *     created dimensions. Frontend gtag emission already
     *     reads the parameter names from GA_CUSTOM_DIMENSIONS_MAP
     *     so no further wiring is needed.
     *   - On any failure: sets Options::GA_ERROR with a copy that
     *     identifies the specific failure mode (no property
     *     selected / auth revoked / would exceed cap / per-row
     *     create failed). Returns false. Caller must NOT mark
     *     ENABLE_GA as enabled.
     *
     * Partial-failure semantics: on per-row create failure mid-
     * loop the method returns false immediately. Dimensions
     * created in earlier iterations remain on the GA4 property.
     * Re-running Enable Tracking is safe — the upfront skip
     * against $existing_params plus the 409-idempotent path
     * inside Ga4DimensionService::create_custom_dimension cover
     * the retry. The admin sees the failing-row's parameter name
     * in the GA_ERROR notice and can resolve at the GA4 console.
     *
     * The method is intentionally instance-scoped (not static) so
     * tests can Mockery-partial it together with authenticate() /
     * get_ga4_admin_service() the way the property submit handler
     * already does.
     *
     * @return bool true when every required CD is now present on
     *              the property; false on any failure (with
     *              GA_ERROR populated for the admin notice).
     */
    public function apply_custom_dimensions_to_ga4() {
        $property_id = get_option(Options::GA_PROPERTY_ID);
        if (empty($property_id)) {
            update_option(
                Options::GA_ERROR,
                'No GA4 property selected. Pick a property on the '
                . 'Google Analytics tab before enabling tracking.'
            );
            return false;
        }

        $cd_map = get_option(Options::GA_CUSTOM_DIMENSIONS_MAP);
        // is_array gate also catches corrupted option values (e.g.
        // a string accidentally stored where the map array belongs);
        // log the corruption and treat as failure so the admin
        // notices instead of silently marking tracking enabled with
        // no dimensions.
        if (!is_array($cd_map)) {
            if ($cd_map !== false) {
                error_log(
                    '51Degrees: GA_CUSTOM_DIMENSIONS_MAP is not an array; '
                    . 'option is corrupted and will be ignored. Re-save '
                    . 'the Custom Dimensions screen to rebuild it.'
                );
            }
            // No usable map -> nothing to create, but tracking
            // can still emit a bare fod event under the
            // Measurement ID. Treat as success.
            return true;
        }
        if (empty($cd_map)) {
            // Legitimate "no dimensions configured" state. Frontend
            // will emit a bare fod event; that is a valid tracking
            // state for an admin who wants pageviews only.
            return true;
        }

        $client = $this->authenticate();
        if (!$client) {
            update_option(
                Options::GA_ERROR,
                'Google Analytics authentication expired. Please reconnect.'
            );
            return false;
        }

        $admin = $this->get_ga4_admin_service($client);

        try {
            $existing = FiftyOneDegreesGa4DimensionService::list_custom_dimensions(
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
            return false;
        }

        $existing_params = array_column($existing, 'parameter_name');

        // Inclusion set: a property is included iff it appears as a key
        // in GA_DIMENSIONS_INCLUDED. Absent option (option === false)
        // means "admin has never submitted the form" -> default to
        // include-all so first-time Enable keeps historical behaviour.
        // Removing a property here does not delete the CD on GA4 (GA4
        // has no delete API for CDs); it just stops the plugin from
        // creating new ones.
        $included_map = get_option(Options::GA_DIMENSIONS_INCLUDED);
        $has_included_map = is_array($included_map);

        // Filter to "new for this property" — anything already
        // present at the same parameter_name is handled by the
        // 409-idempotent path inside create_custom_dimension if
        // we attempt it, but skipping it up front saves an API
        // round-trip and avoids burning quota.
        $to_create = [];
        foreach ($cd_map as $row) {
            if (!is_array($row)) {
                continue;
            }
            $prop = isset($row['property_name']) && is_scalar($row['property_name'])
                ? (string) $row['property_name']
                : '';
            if ($has_included_map && $prop !== ''
                && !isset($included_map[$prop])
            ) {
                continue;
            }
            $param = isset($row['parameter_name']) && is_scalar($row['parameter_name'])
                ? trim((string) $row['parameter_name'])
                : '';
            if ($param === '' || in_array($param, $existing_params, true)) {
                continue;
            }
            $to_create[] = $row;
        }

        if (FiftyOneDegreesGa4DimensionService::would_exceed_limit(
                count($existing),
                count($to_create)
            )
        ) {
            update_option(
                Options::GA_ERROR,
                sprintf(
                    'Cannot enable tracking: GA4 allows up to %d '
                    . 'Custom Dimensions per property and the selected '
                    . 'mapping would exceed this limit (%d existing + '
                    . '%d new). Reduce the number of selected '
                    . 'properties or use a GA4 360 account.',
                    FiftyOneDegreesGa4DimensionService::MAX_DIMENSIONS_PER_PROPERTY,
                    count($existing),
                    count($to_create)
                )
            );
            return false;
        }

        foreach ($to_create as $row) {
            $param = (string) $row['parameter_name'];
            $label = isset($row['property_name']) && is_scalar($row['property_name'])
                ? (string) $row['property_name']
                : $param;

            try {
                $ok = FiftyOneDegreesGa4DimensionService::create_custom_dimension(
                    $admin,
                    $property_id,
                    $param,
                    $label
                );
            }
            catch (FiftyOneDegreesGa4AuthError $e) {
                update_option(
                    Options::GA_ERROR,
                    'Google Analytics permission was revoked while '
                    . 'creating Custom Dimensions. Please reconnect '
                    . 'Google Analytics and try again.'
                );
                return false;
            }

            if (!$ok) {
                update_option(
                    Options::GA_ERROR,
                    sprintf(
                        'Failed to create GA4 Custom Dimension "%s". '
                        . 'Check the Custom Definitions screen in '
                        . 'Google Analytics Admin and either remove '
                        . 'the conflicting entry or pick a different '
                        . 'parameter name here.',
                        $param
                    )
                );
                return false;
            }
        }

        // Invalidate the CD list cache so the just-created
        // dimensions show up on the next render of the CD tab
        // instead of waiting for the per-property transient TTL
        // to expire. Prefix is inlined (rather than read off the
        // Fiftyonedegrees_Custom_Dimensions class constant) so
        // this call does not transitively load WP_List_Table —
        // the CD class is wp-admin scoped and not autoloaded in
        // every request path. Keep the literal in sync with
        // Fiftyonedegrees_Custom_Dimensions::CACHE_TRANSIENT_PREFIX.
        delete_transient('fiftyonedegrees_ga_cd_cache_' . $property_id);

        return true;
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
            $included = array();
            $include_prefix = '51D_include_';
            foreach ($_POST as $key=>$dimension) {
                // The form marker shares the 51D_ namespace with
                // listbox names; skip it explicitly so it does not get
                // captured as a phantom property below.
                if ($key === '51D_form_submitted') {
                    continue;
                }
                if (strpos($key, $include_prefix) === 0) {
                    // Inclusion checkbox — name carries the property,
                    // presence in $_POST means the box was ticked
                    // (HTML omits unchecked checkboxes entirely).
                    $property = sanitize_text_field(wp_unslash(
                        substr($key, strlen($include_prefix))));
                    if ($property !== '') {
                        $included[$property] = true;
                    }
                    continue;
                }
                if (strpos($key, "51D_") === 0) {
                    $key = sanitize_text_field(wp_unslash(
                        str_replace("51D_","", $key)));
                    $passed_dimensions[$key] =
                        sanitize_text_field(wp_unslash($dimension));
                }
            }
            update_option(
                Options::GA_DIMENSIONS,
                $passed_dimensions);
            // Only update the inclusion map if the form actually
            // carried the marker — populate_selected_dimensions is
            // invoked from two paths (Enable + Update mappings) and
            // both render the marker, but other callers wired in the
            // future must not silently reset the map to "nothing
            // included" by virtue of POSTing without checkboxes.
            if (isset($_POST['51D_form_submitted'])) {
                update_option(
                    Options::GA_DIMENSIONS_INCLUDED,
                    $included);
            }
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

                // Regenerate the cached gtag head fragment so the
                // frontend stops emitting parameters for properties
                // the admin just unticked. Without this, GA4 keeps
                // receiving events with archived parameter names and
                // auto-unarchives the matching Custom Dimensions,
                // making the inclusion toggle appear inert.
                // Defensive try/catch: a failure here must not block
                // the redirect back to the GA tab — the inclusion map
                // is already persisted above, and the previous GA_JS
                // stays cached as a safe fallback.
                try {
                    $this->regenerate_gtag_code();
                } catch (\Throwable $e) {
                    error_log(
                        '51Degrees: failed to regenerate gtag code on '
                        . 'Update Custom Dimension Mappings: '
                        . $e->getMessage()
                    );
                }
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
     * Wires up the GA4 Custom Dimensions side of "Enable Google
     * Analytics Tracking": refreshes the CD-map option from the
     * latest 51Degrees property metadata, regenerates the inline
     * gtag head fragment, and creates any missing GA4 Custom
     * Dimensions on the property via the Ga4DimensionService.
     *
     * Only marks tracking enabled when every step succeeds —
     * apply_custom_dimensions_to_ga4 sets Options::GA_ERROR on
     * failure, which the admin UI surfaces as a one-shot notice.
     */
    function execute_ga_tracking_steps() {
        // Same defensive try/catch rationale as the Update path: a
        // gtag-regen failure must not prevent the redirect or the
        // GA4 apply step below from running. The previous GA_JS
        // stays cached as a safe fallback.
        try {
            $this->regenerate_gtag_code();
        } catch (\Throwable $e) {
            error_log(
                '51Degrees: failed to regenerate gtag code on '
                . 'Enable Google Analytics Tracking: '
                . $e->getMessage()
            );
        }

        if ($this->apply_custom_dimensions_to_ga4()) {
            update_option(Options::ENABLE_GA, 'enabled');
        }
    }

    /**
     * Refreshes GA_CUSTOM_DIMENSIONS_MAP from the current Pipeline
     * property list + admin-saved selections, then rebuilds the cached
     * gtag head fragment so the frontend reflects the latest inclusion
     * state. Extracted as a protected seam so tests that exercise the
     * Update / Enable hooks can stub the heavy class loading without
     * needing ABSPATH or the WP_List_Table base.
     */
    protected function regenerate_gtag_code() {
        require_once dirname(__DIR__)
            . '/includes/ga-custom-dimension-class.php';
        $customDimensionsTable = new Fiftyonedegrees_Custom_Dimensions();
        $customDimensionsTable->prepare_items();

        $gtag_code = $this->gtag_tracking_inst->output_ga_tracking_code();
        update_option(Options::GA_JS, $gtag_code);
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
        // CD list cache transient — best-effort against the current
        // property; stale entries for prior properties expire via
        // TTL. Done first so the read of GA_PROPERTY_ID below still
        // sees the row. Prefix inlined; keep in sync with
        // Fiftyonedegrees_Custom_Dimensions::CACHE_TRANSIENT_PREFIX
        // (see comment in apply_custom_dimensions_to_ga4).
        $current_property_id = get_option(Options::GA_PROPERTY_ID);
        if (!empty($current_property_id)) {
            delete_transient('fiftyonedegrees_ga_cd_cache_' . $current_property_id);
        }

        // auth artifacts
        delete_option(Options::GA_AUTH_CODE);
        delete_option(Options::GA_TOKEN);
        delete_option(Options::GA_AUTH_DATE);

        // selected property / account
        delete_option(Options::GA_PROPERTIES);
        delete_transient(self::GA_PROPERTIES_FRESHNESS_TRANSIENT);
        delete_option(Options::GA_MEASUREMENT_ID);
        delete_option(Options::GA_PROPERTY_ID);
        delete_option(Options::GA_ACCOUNT_ID);

        // settings + dimensions
        delete_option(Options::GA_SEND_PAGE_VIEW);
        delete_option(Options::GA_JS);
        delete_option(Options::ENABLE_GA);
        delete_option(Options::GA_ERROR);
        delete_option(Options::RESOURCE_KEY_UPDATED);
        delete_option(Options::GA_DIMENSIONS);
        delete_option(Options::GA_DIMENSIONS_UPDATED);
        delete_option(Options::GA_DIMENSIONS_INCLUDED);
        delete_option(Options::GA_ID_UPDATED);
        delete_option(Options::GA_SEND_PAGE_VIEW_UPDATED);
        delete_option(Options::GA_TRACKING_ID_ERROR);
        delete_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN);
    }
}
    

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

/**
 * Option keys used by the plugin when storing options using Wordpress'
 * get_option/update_option methods.
 */
class Options
{
 
    /**
     * Options group key for this plugin's options.
     */
    const GROUP_KEY = "fiftyonedegrees_options";
    const ROBOTS_GROUP_KEY = "fiftyonedegrees_options_robots";

    /**
     * Key for storing the constructed pipeline.
     */
    const PIPELINE = "fiftyonedegrees_resource_key_pipeline";

    /**
     * Key for storing the Resource Key used by the pipeline.
     */
    const RESOURCE_KEY = "fiftyonedegrees_resource_key";

    /**
     * Key for storing a flag indicating whether the Resource Key
     * option has been updated.
     */
    const RESOURCE_KEY_UPDATED = "fiftyonedegrees_resource_key_updated";

    /**
     * Key for storing the time at which the processing result
     * cached in the session was invalidated by updating the pipeline.
     * Value is stored as an int as returned by time().
     */
    const SESSION_INVALIDATED = "fiftyonedegrees_session_invalidated";

    /**
     * Key for storing whether or not Google Analytics tracking is
     * enabled in the plugin.
     */
    const ENABLE_GA = "fiftyonedegrees_ga_enable_tracking";

    /**
     * Key for storing the Google Analytics access token.
     */
    const GA_TOKEN = "fiftyonedegrees_ga_access_token";

    /**
     * Key for storing the Google Analytics authorization code.
     */
    const GA_AUTH_CODE = "fiftyonedegrees_ga_auth_code";

    /**
     * Key for storing the list of Google Analytics properties.
     */
    const GA_PROPERTIES = "fiftyonedegrees_ga_properties_list";

    /**
     * Key for storing the Google Analytics tracking id.
     */
    const GA_TRACKING_ID = "fiftyonedegrees_ga_tracking_id";

    /**
     * Key for storing the Google Analytics account id.
     */
    const GA_ACCOUNT_ID = "fiftyonedegrees_ga_account_id";

    /**
     * Key for storing the maximum number of custom dimensions
     * that can be set for Google Analytics.
     */
    const GA_MAX_DIMENSIONS = "fiftyonedegrees_ga_max_cust_dim_index";

    /**
     * Key for storing an error message from Google Analytics if one
     * occurred during configuration.
     */
    const GA_ERROR = "fiftyonedegrees_ga_error";

    /**
     * Key for storing whether or not page views should be sent to
     * Google Analytics. This takes the value of 'true' or 'false'.
     */
    const GA_SEND_PAGE_VIEW = "fiftyonedegrees_ga_send_page_view";

    /**
     * Key for storing whether or no page views should be sent to
     * Google Analytics. This takes the value of 'On' or 'Off'.
     */
    const GA_SEND_PAGE_VIEW_VAL = "fiftyonedegrees_ga_send_page_view_val";

    /**
     * Key to store the list of custom dimensions sent to Google
     * Analytics.
     */
    const GA_DIMENSIONS = "fiftyonedegrees_passed_dimensions";

    /**
     * Key to store whether or not the GA_DIMENSIONS option has been
     * updated.
     */
    const GA_DIMENSIONS_UPDATED = "fiftyonedegrees_passed_dimensions_updated";

    /**
     * Key to store Google Analytics JavaScript code.
     */
    const GA_JS = "fiftyonedegrees_ga_tracking_javascript";

    /**
     * Key to store whether or not Google Analyics tracking id has been
     * updated.
     */
    const GA_ID_UPDATED = "fiftyonedegrees_ga_tracking_id_update_flag";

    /**
     * Key to store whether or not the Google Analytics send page view option
     * has been updated.
     */
    const GA_SEND_PAGE_VIEW_UPDATED = "fiftyonedegrees_ga_send_page_view_update_flag";

    /**
     * Key used to store a boolean flag if there was an error using the Google
     * Analytics tracking id.
     */
    const GA_TRACKING_ID_ERROR = "fiftyonedegrees_ga_tracking_id_error";

    /**
     * Key used to store whether or not the Google Analytics custom dimensions
     * admin screen is available. If Google Analytics has been set up correctly,
     * then this will have the value 'enabled'.
     */
    const GA_CUSTOM_DIMENSIONS_SCREEN = "fiftyonedegrees_ga_custom_dimension_screen";

    /**
     * Key used to store the map of custom dimensions to be sent to Google
     * Analytics.
     */
    const GA_CUSTOM_DIMENSIONS_MAP = "fiftyonedegrees_ga_cust_dims_map";

    /**
     * Key used to store the data at which the access token was aquired for
     * Google Analytics.
     */
    const GA_AUTH_DATE = "fiftyonedegrees_ga_auth_date";

    /**
     * Options group key for suspicious activity detection settings.
     */
    const SUSPICIOUS_GROUP_KEY = "fiftyonedegrees_suspicious_options";

    /**
     * Key for storing whether suspicious activity detection is enabled.
     * Value is 'on' or 'off'.
     */
    const SUSPICIOUS_ENABLE = "fiftyonedegrees_suspicious_enable";

    /**
     * Key for storing the URL to redirect suspicious visitors to.
     */
    const SUSPICIOUS_REDIRECT_URL = "fiftyonedegrees_suspicious_redirect_url";

    /**
     * Key for storing the number of requests that triggers a redirect.
     */
    const SUSPICIOUS_REQUESTS = "fiftyonedegrees_suspicious_requests";

    /**
     * Key for storing the time window (in seconds) for counting requests.
     */
    const SUSPICIOUS_WINDOW = "fiftyonedegrees_suspicious_window";

    /**
     * Key used to store selected standard TDL IDs.
     * Stored as an array of string IDs matching entries in config/robots-standard-tdls.json.
     */
    const ROBOTS_STANDARD_TDL_SELECTED = "fiftyonedegrees_robots_standard_tdl_selected";

    /**
     * Key used to store user-entered custom TDL URLs.
     * Stored as an indexed array of URL strings.
     */
    const ROBOTS_CUSTOM_TDL = "fiftyonedegrees_robots_custom_tdl";

    /**
     * Key used to store whether robots.txt hosting is enabled.
     */
    const ROBOTS_ENABLE = "fiftyonedegrees_robots_enable";

    /**
     * Key used to store whether crawler enforcement (302 redirects) is enabled.
     */
    const ROBOTS_ENFORCE = "fiftyonedegrees_robots_enforce";

    /**
     * Key used to store the redirect URL for bot enforcement.
     */
    const ROBOTS_REDIRECT_URL = "fiftyonedegrees_robots_redirect_url";

    /**
     * Key used to store custom top entries for robots.txt.
     */
    const ROBOTS_CUSTOM_TOP = "fiftyonedegrees_robots_custom_top";

    /**
     * Key used to store custom bottom entries for robots.txt.
     */
    const ROBOTS_CUSTOM_BOTTOM = "fiftyonedegrees_robots_custom_bottom";

    /**
     * Key used to store allowed crawler categories for robots.txt enforcement.
     * When never saved (null), defaults apply: deny Index, Train, Input; allow all others.
     */
    const ROBOTS_ALLOWED_CATEGORIES = "fiftyonedegrees_robots_allowed_categories";

    /**
     * Key used to cache the cloud-generated plain-text robots.txt content.
     */
    const ROBOTS_PLAINTEXT_CACHE = "fiftyonedegrees_robots_plaintext_cache";

    /**
     * Durable record of the last cloud refresh attempt for robots.txt.
     * Shape: ['status' => 'success'|'error', 'timestamp' => int,
     *        'message' => string, 'http_status' => ?int].
     * Survives the daily cron's 60-second error transient so admins
     * see a stale-cache indicator next time they open the admin page.
     */
    const ROBOTS_LAST_REFRESH = "fiftyonedegrees_robots_last_refresh";

    // Last make_pipeline() error message; stored separately from
    // Options::PIPELINE so a good cached pipeline is preserved.
    const PIPELINE_VALIDATION_ERROR = "fiftyonedegrees_pipeline_validation_error";

    /**
     * Key used to store whether the 51Degrees pipeline (device detection) is enabled.
     */
    const PIPELINE_ENABLE = "fiftyonedegrees_pipeline_enable";

    /**
     * Options group key for the PMP (Preference Management Platform) settings tab.
     */
    const PMP_GROUP_KEY = "fiftyonedegrees_pmp_options";

    /**
     * Key for storing whether the Preference Management Platform widget
     * is enabled on public pages. Value: 'on' or 'off'.
     */
    const PMP_ENABLE = "fiftyonedegrees_pmp_enable";

    /**
     * Key for storing the hostname of the 51Degrees cloud server
     * that serves PMP bundles. The plugin composes the full bundle
     * URL as https://{host}/pmp/{resource-key}/pmp-{locale}.js.
     * Default: 'cloud.51degrees.com'.
     */
    const PMP_CLOUD_HOST = "fiftyonedegrees_pmp_cloud_host";

    /**
     * Key for storing the publisher's static TCF Vendor consent string.
     * Generated externally via TCF Tools; PMP overlays purpose consents
     * onto this base string at runtime.
     */
    const PMP_TCF_VENDOR_STRING = "fiftyonedegrees_pmp_tcf_vendor_string";

    /**
     * Key for storing the label shown on the Alternative button
     * (e.g. 'Pay', 'Subscribe').
     */
    const PMP_ALT_LABEL = "fiftyonedegrees_pmp_alt_label";

    /**
     * Key for storing the URL the user is navigated to when the
     * Alternative button is clicked.
     */
    const PMP_ALT_URL = "fiftyonedegrees_pmp_alt_url";

    /**
     * Key for storing the publisher's brand name displayed in the
     * PMP popup.
     */
    const PMP_BRAND_NAME = "fiftyonedegrees_pmp_brand_name";

    /**
     * Key for storing the URL of the publisher's brand logo image
     * rendered in the PMP popup.
     */
    const PMP_BRAND_LOGO_URL = "fiftyonedegrees_pmp_brand_logo_url";

    /**
     * Key for storing the URL of the publisher's terms / privacy
     * policy page linked from the PMP popup.
     */
    const PMP_BRAND_TERMS_URL = "fiftyonedegrees_pmp_brand_terms_url";

    /**
     * Key for storing whether the Standard marketing option is shown
     * in the PMP popup alongside Personalized and the alternative.
     * Value: 'on' or 'off'.
     */
    const PMP_SHOW_STANDARD = "fiftyonedegrees_pmp_show_standard";
}
?>

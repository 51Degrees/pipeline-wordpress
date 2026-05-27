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

        return $client;
    }

    /**
     * Retrieves Google Analytics Object
	 * @param Google_Client $client
     * @return Google_Service_Analytics service service object
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
     * Retrieves web properties list for the authorized user.
	 * @param Google_Service_Analytics $analytics_service
     * @return array properties list
     */	
    public function get_analytics_properties_list($analytics_service) {
  
        if (!get_option(Options::GA_TOKEN)) {
            echo "You must authenticate to access your Analytics Account.";
            return;
        }
      
		try {
			// Get the list of accounts for the authorized user.
			$properties = $analytics_service->management_webproperties->listManagementWebproperties('~all');
			$propertiesList = array();
			if (count($properties->getItems()) > 0) {
				foreach ($properties->getItems() as $property) {
					$propertyId = $property->getId();
					$propertyName = $property->getName();
					$property = array();
					$property["id"] = $propertyId;
					$property["name"] = $propertyName . " (" . $propertyId . ") "; 
					array_push($propertiesList, $property);           
				}
			}
			else {
				echo 'No Properties found for this user.';
				return;
			}  
		}
		catch (Exception $e) {
			error_log($e->getMessage());
		}

        update_option(Options::GA_PROPERTIES , $propertiesList);
    
        return $propertiesList;
    }

    /**
     * Retrieves account id for the web property being used.
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
     * Retrieves custom dimensions for the authorized user.
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
     * Inserts Custom Dimension into analytics account.
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
        // (Legacy OOB Access Code admin_init hook removed in S-10. The UI
        // input that produced its POST disappeared in S-9; the method
        // itself was unreachable code and was deleted alongside.)
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_ga_logout'));
        add_action(
            'admin_init',
            array($this, 'fiftyonedegrees_ga_set_tracking_id'));
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
     * If a change is made to the Google Analytics token, then update all
     * the relevant options.
     * 
     * @return void
     */
    function fiftyonedegrees_ga_set_tracking_id() {         
        if (get_option(Options::GA_TOKEN)) {
            if (isset($_POST['submit']) &&
                "Save Changes" === $_POST['submit']) {

                delete_option(Options::GA_TRACKING_ID_ERROR);
                update_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN, "enabled");

                if (isset($_POST[Options::GA_TRACKING_ID]) &&
                    "Select Analytics Property" ===
                    $_POST[Options::GA_TRACKING_ID]) {

                    update_option(Options::GA_TRACKING_ID_ERROR, true);
                    delete_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN);                        
                }
                else if (isset($_POST[Options::GA_TRACKING_ID])) {

                    $ga_tracking_id = sanitize_text_field(wp_unslash(
                        $_POST[Options::GA_TRACKING_ID]));
                    
                    update_option(
                        Options::GA_TRACKING_ID,
                        $ga_tracking_id);

                    if (isset($_POST[Options::GA_SEND_PAGE_VIEW]) &&
                        "on" === $_POST[Options::GA_SEND_PAGE_VIEW]) {
                        update_option(
                            Options::GA_SEND_PAGE_VIEW,
                            'true');
                        update_option(Options::GA_SEND_PAGE_VIEW_VAL, "On");
                    }  
                    else {
                        delete_option(Options::GA_SEND_PAGE_VIEW);
                        update_option(Options::GA_SEND_PAGE_VIEW_VAL, "Off");                   
                    }
                    
                }					
                wp_redirect(get_admin_url() .
                    'options-general.php?page=51Degrees&tab=google-analytics' );
            }     
        }
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
     */
    function delete_ga_options() {

        delete_option(Options::GA_AUTH_CODE);
        delete_option(Options::GA_TOKEN);
        delete_option(Options::GA_AUTH_DATE);
        delete_option(Options::GA_PROPERTIES);
        delete_option(Options::GA_TRACKING_ID);
        delete_option(Options::GA_ACCOUNT_ID);
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
    

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
 * Tracking gtag.js class.
 *
 * @since 1.0.0
 *
 * @package Fiftyonedegrees
 * @author  Fatima Tariq
 */

class Fiftyonedegrees_Tracking_Gtag {

	/**
	 * Holds the name of the tracking type.
	 */
	public $name = 'gtag';

	/**
	 * Version of the tracking class.
	 */
	public $version = '1.0.0';

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function __construct() {

	}

    /**
     * Retrieves Custom Dimensions from Properties Map to be used 
	 * in gtag Javascript. 
     * @return array array list containing dimensions, events and
     * delayed evidence information.
     */
	public function get_properties_as_custom_dimensions() {
        
		$custom_dimensions = get_option(Options::GA_CUSTOM_DIMENSIONS_MAP);
		
		$ga_dimensions_map = array();
		foreach ( $custom_dimensions as $dimension ) {        
			$key = "dimension" . $dimension["custom_dimension_index"];
			$value = strtolower($dimension["property_name"]);
			$ga_dimensions_map[$key] = $value;
		}
	
		$ga_events_map = array();
		foreach ( $custom_dimensions as $dimension ) {        
			$key = strtolower($dimension["property_name"]);
			$value = "data." .
				strtolower($dimension["custom_dimension_datakey"]) .
				"." .
				strtolower($dimension["property_name"]);
			$ga_events_map[$key] = $value;
		} 
		
		$delayed_evidence = false;
		foreach ( $custom_dimensions as $dimension ) {        
			if ("location" ===
				strtolower($dimension["custom_dimension_datakey"])) {
				$delayed_evidence = true;
			}
		}		
		return array (
			"dimensions_map" => $ga_dimensions_map,
			"events_map" => $ga_events_map,
			"delayed_evidence" => $delayed_evidence);
	}

    /**
     * Retrieves Google Analytics Tracking Code
	 * to be added in the Theme Header.
     * @return Javascript javascript code
     */
	public function output_ga_tracking_code() {
		
		$google_trackingId = get_option(Options::GA_TRACKING_ID);

		$property_exists_func = $this->check_property_exists();
		$gtag_code = $this->output_gtag_code();
		$gtag_code_tagged = $this->output_gtag_code_tagged_property();

		ob_start();

		echo "\n\t";
		echo "<!-- This code is added by 51Degrees WordPress Plugin.-->\n\t";
		
		echo sprintf( esc_html( '%1$s' ), $property_exists_func ) . "\n\t\t"; 

        echo "<!-- Global site tag (gtag.js) - Google Analytics -->\n\t\t";
		echo "<!-- This has been generated using the guide here " .
			"https://developers.google.com/analytics/devguides/collection/gtagjs/custom-dims-mets -->\n"; ?>
        
		<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_html($google_trackingId);?>"></script>

        <script>
			var head = document.getElementsByTagName('head')[0];
			var js = document.createElement("script");

			js.type = "text/javascript";
			
			if ( property_exists ) {
				js.src = '<?php echo FIFTYONEDEGREES_PLUGIN_URL . "assets/js/ga-integration-tracking.js"; ?>';
			}
			else {
				js.src = '<?php echo FIFTYONEDEGREES_PLUGIN_URL . "assets/js/ga-51d-tracking.js"; ?>';
			}

			head.appendChild(js);
		</script>

		<?php		
		echo "\r\t<!-- End 51Degrees Wordpress Plugin -->\n\n";
		$code = ob_get_contents();
		ob_end_clean();
		return $code;
	}

	/**
	 * Generate gtag code that runs when gtag does not 
	 * already exist in the header i.e. property is not
	 * already been tagged.
	 * @return Javascript $gtag_code
	 */
	public function output_gtag_code() {

		$google_trackingId = get_option(Options::GA_TRACKING_ID);
		$send_page_view = get_option(Options::GA_SEND_PAGE_VIEW) ?
			'true' : 'false';
		$maps = $this->get_properties_as_custom_dimensions();
		$dims = $maps["dimensions_map"];
		$events = $maps["events_map"];
		$delayed_evidence = $maps["delayed_evidence"];

		ob_start();	
		?>

			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
			gtag('js', new Date());
	
			const configuration = {
				'send_page_view': '<?php echo esc_html($send_page_view); ?>',
				'custom_map' : {
					<?php
					echo sprintf(esc_html( '%1$s' ), implode(",\r\n                    ", array_map(
						function ($v, $k) { return sprintf("'%s' : '%s'", $k, $v); },
						$dims,
						array_keys($dims)
					))); ?>
				}				
			};
	
			const trackingId = '<?php echo esc_html($google_trackingId); ?>';
			gtag('config', trackingId, configuration);
	
			window.addEventListener("load", function () {
				
				var update = function(data){		
					gtag('event', 'fod', {
					'send_to': trackingId,
					<?php
						echo sprintf(esc_html('%1$s'), implode(",\r\n                        ", array_map(
							function ($v, $k) { return sprintf("'%s' : %s", $k, $v); },
							$events,
							array_keys($events)
						))); ?>
					});
				};

			<?php if ( $delayed_evidence ) { ?>
				fod.complete(update, "location");
			<?php } else { ?>
				fod.complete(update);
			<?php }	?>

			});

		<?php
	
		$gtag_code = ob_get_contents();
		ob_end_clean();

		$jsFile = fopen(FIFTYONEDEGREES_PLUGIN_DIR .
			"assets/js/ga-51d-tracking.js", "w") or die("Unable to open file!");
		fwrite($jsFile, $gtag_code);
		fclose($jsFile);

		return $gtag_code;
	}

	/**
	 * Generate gtag code that runs when gtag does 
	 * already exist in the header i.e. property is
	 * already been tagged.
	 * @return Javascript $gtag_code
	 */
	public function output_gtag_code_tagged_property() {

		$google_trackingId = get_option(Options::GA_TRACKING_ID);	
		$maps = $this->get_properties_as_custom_dimensions();
		$dims = $maps["dimensions_map"];
		$events = $maps["events_map"];
		$delayed_evidence = $maps["delayed_evidence"];

		ob_start();	
		?>

			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
	
			var custom_map = {
				<?php
				echo sprintf(esc_html('%1$s'), implode(",\r\n                    ", array_map(
					function ($v, $k) { return sprintf("'%s' : '%s'", $k, $v); },
					$dims,
					array_keys($dims)
				))); ?>
			};
	
			const trackingId = '<?php echo esc_html($google_trackingId); ?>';

			for (let i = 0, len = window.dataLayer.length; i < len; i += 1) {
				if(window.dataLayer[i][0] == "config" && window.dataLayer[i][1] == trackingId ) {
					if(window.dataLayer[i].length > 2) {
						if( window.dataLayer[i][2]["custom_map"] !== undefined) {
							var datalayer_custom_map = window.dataLayer[i][2]["custom_map"];
							for([key, val] of Object.entries(custom_map)) {
								datalayer_custom_map[key] = val;
							}
						}
					} 
				}
			}

			window.addEventListener("load", function () {
				
				var update = function(data) {	
					gtag('event', 'fod', {
					'send_to': trackingId,
					<?php
						echo sprintf(esc_html('%1$s'), implode(",\r\n                        ", array_map(
							function ($v, $k) { return sprintf("'%s' : %s", $k, $v); },
							$events,
							array_keys($events)
						))); ?>
					});
				};

			<?php if ($delayed_evidence) { ?>
				fod.complete(update, "location");
			<?php } else { ?>
				fod.complete(update);
			<?php }	?>

			});

		<?php
	
		$gtag_code = ob_get_contents();
		ob_end_clean();

		$jsFile = fopen( FIFTYONEDEGREES_PLUGIN_DIR .
			"assets/js/ga-integration-tracking.js", "w") or die("Unable to open file!");
		fwrite($jsFile, $gtag_code);
		fclose($jsFile);

		return $gtag_code;
	}

	/**
	 * Retrieves Javascript function that returns true 
	 * if property is already been tagged in the 
	 * header by other plugin.
	 * @return Javascript javascript function
	 */
	public function check_property_exists() {

		$google_trackingId = get_option(Options::GA_TRACKING_ID);

		ob_start();
		?>

		<script>
			const func_check_property_exists = function() {

				dataLayer = window.dataLayer;
				if (dataLayer == undefined) {
					return false;
				}

				for (let i = 0, len = dataLayer.length; i < len; i += 1) {
					if(dataLayer[i][0] == "config" && dataLayer[i][1] == "<?php echo esc_html( $google_trackingId); ?>") {
						if(window.dataLayer[i].length > 2) {
							if(window.dataLayer[i][2]["custom_map"] !== undefined) {
								return true;
							}
						}						
					}
				}
				return false;
			}
				
			let property_exists = func_check_property_exists();

		</script>

		<?php
		$js_code = ob_get_contents();
		ob_end_clean();
		return $js_code;
	}
}
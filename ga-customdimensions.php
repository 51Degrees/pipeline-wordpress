<!--
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
-->

<!-- Following enabledButton variable and 51D.js needs to be populated at the load of this page only. -->
<script type="text/javascript">var enabledButton = "<?php echo esc_html( get_option("fiftyonedegrees_ga_enable_tracking") ); ?>";</script>

<?php

if (!get_option(Options::GA_TOKEN) &&
    empty(get_option(Options::GA_TOKEN))) {
        echo '<span class="fod-pipeline-status error">' .
            'Please Authenticate with Google Analytics first.</span>';
}
else { 
    
    if (get_option(Options::PIPELINE)['error']) {
        echo '<p></p><span class="fod-pipeline-status error">' .
            'Provided Resource Key does not contain any Custom Dimensions.' .
            ' Please enter a valid Resource Key. </span>';        
    }
    else if (get_option(Options::GA_ERROR)) {
        echo '<p></p><span class="fod-pipeline-status warn">' .
            esc_html(get_option(Options::GA_ERROR)) . '</span>';
        delete_option(Options::GA_ERROR);
    }
    else if (get_option(Options::ENABLE_GA)) {
        if (get_option(Options::RESOURCE_KEY_UPDATED) ||
            get_option(Options::GA_ID_UPDATED) ||
            get_option(Options::GA_SEND_PAGE_VIEW_UPDATED) ||
            get_option(Options::GA_DIMENSIONS)) {

            // Include Fiftyonedegrees class
            if (!class_exists('Fiftyonedegrees')) {
                require_once('fiftyonedegrees.php');
            } 

            $instance = Fiftyonedegrees::get_instance();
            $instance->execute_ga_tracking_steps();

            if (get_option(Options::RESOURCE_KEY_UPDATED)) {
                echo '<p></p><span class="fod-pipeline-status good">' .
                    'Google Analytics Tracking is enabled for the Properties' .
                    ' available in the new Resource Key. </span>';      
                delete_option(Options::RESOURCE_KEY_UPDATED);
            }
            else if (get_option(Options::GA_DIMENSIONS_UPDATED)) {
                echo '<p></p><span class="fod-pipeline-status good">' .
                'Google Analytics Custom Dimensions mapping has been updated.</span>';
                delete_option(Options::GA_DIMENSIONS_UPDATED);
            }                    
            else {
                echo '<p></p><span class="fod-pipeline-status good">' .
                'Google Analytics Tracking is enabled for new Google ' .
                'Analytics Property Settings.</span>';
                delete_option(Options::GA_ID_UPDATED);
                delete_option(Options::GA_SEND_PAGE_VIEW_UPDATED);
            }
        }
        else {
            echo '<p></p><span class="fod-pipeline-status good">' .
            'Google Analytics Custom Dimensions data collection is ' .
            'now enabled for you. </span>';
        }            
    }
    
    if (!get_option(Options::PIPELINE)['error']) {
?>
            
<form method="post" action="options.php">	
    <table style="height: 50%">
        <tbody>
            <tr>
                <td>
                    <p>
                        The following properties are available with the provided
                        Resource Key. Please Click
                        <b>Enable Google Analytics Tracking</b> to send them as
                        Custom Dimensions to
                        <b>
                            <?php echo esc_html(get_option(Options::GA_MEASUREMENT_ID));?>
                        </b>
                        Google Analytics Property or <b>Go Back</b> to change.
                    </p>
                </td>
                <td>
                    <button type="submit" class="button-primary" name="fiftyonedegrees_ga_change_settings">
                        <span style="font-size:16px;">&laquo;</span> Go Back</i>
                    </button>
                </td>
            </tr>
            </tbody>
        </table>
    </form>
<?php
    
    // Include Custom_Dimensions class
    if (!class_exists('Fiftyonedegrees_Custom_Dimensions')) {
        require_once('includes/ga-custom-dimension-class.php');
    }    
    //Prepare Custom Dimensions Table
    $customDimensionsTable = new Fiftyonedegrees_Custom_Dimensions();
    $customDimensionsTable->prepare_items();
?>

<form method="post" action="options.php">
    <?php
    // Marker so populate_selected_dimensions can distinguish "no
    // include_* keys posted because the admin unticked everything"
    // from "no include_* keys posted because the form was never
    // shown". Without this, the first form (Go Back) below would
    // wipe the inclusion map.
    ?>
    <input type="hidden" name="51D_form_submitted" value="1" />
    <?php $customDimensionsTable->display();?>

    <table style="width: 100%">
        <tbody>
            <tr>         
            <?php if ("enabled" !== get_option(Options::ENABLE_GA)) { ?>
                <td style="width: 90%">
                    <input type="submit" class="button-primary" value="Enable Google Analytics Tracking" name="<?php echo Options::ENABLE_GA; ?>" />
                </td>
            <?php } else { ?>
                <td style="width: 90%">
                    <input type="submit" class="button-primary" value="Disable Google Analytics Tracking" name="<?php echo Options::ENABLE_GA; ?>" />
                </td>
            <?php } ?> 
                <td>
                    <input type="submit" class="button-primary" value="Update Custom Dimension Mappings" name="<?php echo "fiftyonedegrees_ga_update_cd_indices"; ?>" />
                </td>
            </tr>
        </tbody>
    </table>
</form>

<?php } } ?>


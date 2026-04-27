<h2><img style="vertical-align:middle" width="200" height="auto" src="<?php echo esc_url( plugin_dir_url(__FILE__) ) . "assets/images/logo.png";?>"/></h2>

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

$allowed_tabs = ['setup', 'google-analytics', 'robots', 'properties', 'help'];

if (isset($_GET["tab"]) && in_array(sanitize_text_field($_GET["tab"]), $allowed_tabs, true)) {

        $active_tab = sanitize_text_field( $_GET["tab"] );
    
    } else {

        $active_tab = "setup";

    }

?>

<h2 class="nav-tab-wrapper">
    <a href="?page=51Degrees&tab=setup" class="nav-tab <?php echo $active_tab == 'setup' ? 'nav-tab-active' : ''; ?>">Setup</a>
    <a href="?page=51Degrees&tab=google-analytics" class="nav-tab <?php echo $active_tab == 'google-analytics' ? 'nav-tab-active' : ''; ?>" style="<?php echo !get_option(Options::RESOURCE_KEY) ? 'pointer-events:none;color:#C0C0C0;' : ''; ?>">Google Analytics</a>

    <a href="?page=51Degrees&tab=properties" class="nav-tab <?php echo $active_tab == 'properties' ? 'nav-tab-active' : '';  ?>" style="<?php echo !get_option(Options::RESOURCE_KEY) ? 'pointer-events:none;color:#C0C0C0;' : ''; ?>">Properties</a>
    <a href="?page=51Degrees&tab=robots" class="nav-tab <?php echo $active_tab == 'robots' ? 'nav-tab-active' : ''; ?>" style="<?php echo !get_option(Options::RESOURCE_KEY) ? 'pointer-events:none;color:#C0C0C0;' : ''; ?>">Robots.txt</a>
    <a href="?page=51Degrees&tab=help" class="nav-tab <?php echo $active_tab == 'help' ? 'nav-tab-active' : ''; ?>">Help</a>
</h2>
                  
<?php

include plugin_dir_path(__FILE__) . $active_tab . ".php";

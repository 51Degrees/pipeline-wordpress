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
<script src=<?php echo esc_url("https://player.vimeo.com/api/player.js"); ?>></script>
<h3>Integration With Google Analytics</h3>

<div style="padding:47.25% 0 0 0;position:relative;height:0px">
    <iframe src="https://player.vimeo.com/video/631017900?h=8e8c844804&badge=0&autopause=0&player_id=0&app_id=58479" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;" title="51Degrees WordPress Plugin explainer"></iframe>
</div>

<p>
    Below are the steps to enable google analytics custom dimensions tracking
    with 51Degrees Plugin.
</p>

<p>
    1. To integrate with Google Analytics go to the
    <code><b>Google Analytics</b></code> tab and click
    <code><b>Log in with Google Analytics Account</b></code> button.
</p>
<p>
    <img src="<?php echo plugin_dir_url( __FILE__ ) . "assets/images/screenshot-1.png"?>" alt="GoogleAnalytics1">
</p>

<p>
    2. Follow the steps and give 51Degrees plugin the required permissions and
    copy the provided Google Analytics <code><b>Access Code</b></code> in the
    end.
</p>
<p>
    <img src="<?php echo plugin_dir_url( __FILE__ ) . "assets/images/screenshot-2.png"?>" alt="GoogleAnalytics2">
</p>

<p>
    3. Enter the copied Code in the <code><b>Access Code</b></code>
    text field and click <code><b>Authenticate</b></code>. This will
    connect your Google Analytics Account to 51Degrees Plugin.
</p>
<p>
    <img src="<?php echo plugin_dir_url( __FILE__ ) . "assets/images/screenshot-3.png"?>" alt="GoogleAnalytics3">
</p>

<p>
    4. After authentication, use the drop down box to select which Google
    Analytics Account and Property you want to enable custom dimensions for.
    Check <code><b>Send Page View</b></code> if you want to send Default Page
    View hit along with your custom dimensions. It is only recommended if you
    have not already integrated with any other Google Analytics plugin to
    avoid duplication. Click <code><b>Save Changes</b></code>.
</p>

<p>
    <img src="<?php echo plugin_dir_url( __FILE__ ) . "assets/images/screenshot-4.png"?>" alt="GoogleAnalytics4">
</p>

<p>
    5. This will prompt to the new custom dimensions screen where you can find
    all the custom dimensions available with your Resource Key. Click on
    Enable Google Analytics Tracking button to enable tracking of all the
    Device Data Properties as custom dimensions.
</p>
<p>
    <img src="<?php echo plugin_dir_url( __FILE__ ) . "assets/images/screenshot-5.png"?>" alt="GoogleAnalytics5">
</p>

<h3>Use in templates and advanced features</h3>

<p>
    Please visit our
    <a href="https://51degrees.com/documentation/_other_integrations__wordpress.html?utm_source=code&utm_medium=comment&utm_campaign=pipeline-wordpress&utm_content=help.php&utm_term=use-in-templates-and-advanced-features" target="_blank">
        documentation
    </a>
    page for more information on advanced features including Value Replacement
    and Conditional Blocks.
</p>

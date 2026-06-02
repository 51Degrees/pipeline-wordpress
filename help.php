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
<h3>Integration With Google Analytics</h3>

<!-- TODO(post-1.0.13): re-record explainer video for the GA4 flow,
     then restore the Vimeo embed below. The previous clip
     (https://player.vimeo.com/video/631017900) documented the legacy
     Universal Analytics Access-Code flow that was removed in 1.0.13,
     so we hide it until a fresh recording is available — leaving the
     stale video in would actively mislead first-time integrators.
<script src=<?php echo esc_url("https://player.vimeo.com/api/player.js"); ?>></script>
<div style="padding:47.25% 0 0 0;position:relative;height:0px">
    <iframe src="https://player.vimeo.com/video/631017900?h=8e8c844804&badge=0&autopause=0&player_id=0&app_id=58479" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen style="position:absolute;top:0;left:0;width:100%;height:100%;" title="51Degrees WordPress Plugin explainer"></iframe>
</div>
-->


<p>
    Below are the steps to enable Google Analytics 4 custom dimension
    tracking with the 51Degrees plugin.
</p>

<p>
    <b>Before you start:</b>
</p>
<ul style="list-style: disc; padding-left: 1.5em;">
    <li>
        Generate a 51Degrees Resource Key for the device-detection
        properties you need at
        <a href="https://configure.51degrees.com/zHPMyDk6" target="_blank">the
        Configurator</a>, then paste it into the <code><b>Setup</b></code>
        tab and save. The Resource Key unlocks the
        <code><b>Properties</b></code>, <code><b>Google Analytics</b></code>
        and other settings tabs — without it the plugin has no device
        data to send. See
        <a href="https://51degrees.com/documentation/_concepts__configurator.html" target="_blank">the
        Configurator documentation</a> for details.
    </li>
    <li>
        Create a GA4 property and a Web data stream in
        <a href="https://analytics.google.com/" target="_blank">Google
        Analytics</a> for the site you are integrating. Note the
        Measurement ID (format <code>G-XXXXXXXXXX</code>) — the plugin
        will surface it once authentication completes.
    </li>
</ul>

<ol style="padding-left: 1.5em;">
    <li style="margin-bottom: 1.5em;">
        <p>
            Open the <code><b>Google Analytics</b></code> tab and click
            <code><b>Connect Google Analytics</b></code>. You will be
            redirected to the Google consent screen.
        </p>
        <p>
            <img src="<?php echo plugin_dir_url( __FILE__ ) . "assets/images/screenshot-1.png"?>" alt="GoogleAnalytics1">
        </p>
    </li>

    <li style="margin-bottom: 1.5em;">
        <p>
            Sign in with the Google account that owns the GA4 property
            and grant the requested permissions. The plugin asks for the
            <code>analytics.edit</code> scope so it can list your
            properties and create custom dimensions on your behalf.
            After you approve, Google redirects you back to the plugin
            automatically — no codes to copy.
        </p>
        <p>
            <img src="<?php echo plugin_dir_url( __FILE__ ) . "assets/images/screenshot-2.png"?>" alt="GoogleAnalytics2">
        </p>
    </li>

    <li style="margin-bottom: 1.5em;">
        <p>
            Back in the plugin, the <code><b>Connect</b></code> button
            is replaced with a <code><b>Logout</b></code> button. Use
            the <code><b>Analytics Account/Property</b></code> drop-down
            to pick the GA4 property you want to send data to. Click the
            refresh icon next to the drop-down if your newly-created
            property does not appear immediately. Optionally check
            <code><b>Send Page View</b></code> (only if no other Google
            Analytics plugin is already sending page views, to avoid
            duplication). Click <code><b>Save Changes</b></code>.
        </p>
    </li>

    <li style="margin-bottom: 1.5em;">
        <p>
            The plugin opens the Custom Dimensions screen, listing every
            51Degrees device property the Resource Key resolves and the
            GA4 event parameter each one will be sent as. The mappings
            are pre-populated; adjust any individual parameter name if
            it would collide with a custom dimension you defined
            yourself on the property.
        </p>
        <p>
            <img src="<?php echo plugin_dir_url( __FILE__ ) . "assets/images/screenshot-3.png"?>" alt="GoogleAnalytics3">
        </p>
    </li>

    <li style="margin-bottom: 1.5em;">
        <p>
            Click <code><b>Enable Google Analytics Tracking</b></code>.
            The plugin creates the listed custom dimensions in your GA4
            property via the GA4 Admin API (display names prefixed with
            <code>Fifty One Degrees</code>) and begins emitting a
            <code>fod</code> event with the device-property parameters
            on every public page load. No further setup is required in
            the GA4 console.
        </p>
        <p>
            <img src="<?php echo plugin_dir_url( __FILE__ ) . "assets/images/screenshot-4.png"?>" alt="GoogleAnalytics4">
        </p>
    </li>
</ol>

<p>
    <b>Verifying:</b> open any public page in a fresh incognito window
    with DevTools <code><b>Network</b></code> tab open and filter by
    <code>collect</code>. You should see one
    <code>POST /g/collect?...&amp;en=fod&amp;ep.&lt;param&gt;=...</code>
    request per page load with status <code>204 No Content</code>. The
    custom dimensions appear under <b>Admin → Custom definitions</b> in
    Google Analytics (GA4 may delay listing newly-created dimensions in
    the console for several minutes — the API call succeeds immediately).
</p>

<h3>Use in templates and advanced features</h3>

<p>
    Please visit our
    <a href="https://51degrees.com/documentation/_other_integrations__wordpress.html" target="_blank">
        documentation
    </a>
    page for more information on advanced features including Value Replacement
    and Conditional Blocks.
</p>

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

// Render path for the Settings > 51Degrees > Google Analytics tab. Included
// by admin.php (active_tab dispatch). The OAuth gauntlet itself runs in
// FiftyOneDegreesOauthCallback / FiftyOneDegreesOauthStart on admin_init /
// admin_post; this file is presentation only.

// ─── Notice channel (shared with start + callback handlers) ─────────────

// One-shot notice transient: rendered exactly once, then cleared so a
// refresh doesn't replay. Slug maps 1:1 to a key under oauth.notice.* in
// languages/oauth-strings.yaml. consume() encapsulates read+delete so the
// UI doesn't drift from the start/callback writer semantics.
$fiftyonedegrees_notice_slug = class_exists('FiftyOneDegreesOauthNotice')
    ? FiftyOneDegreesOauthNotice::consume()
    : '';

if ($fiftyonedegrees_notice_slug !== '' && class_exists('FiftyOneDegreesStrings')) {
    $css = ($fiftyonedegrees_notice_slug === 'success' || $fiftyonedegrees_notice_slug === 'migration_done')
        ? 'notice notice-success'
        : 'notice notice-error';

    // Slugs where a Try-again button does not help the admin:
    //   success / migration_done — flow already finished cleanly.
    //   multisite_unsupported / https_required / placeholder_url —
    //   the start handler will re-trip the same gate, so the click
    //   would loop them back to this notice unchanged.
    $fiftyonedegrees_no_retry = [
        'success'               => true,
        'migration_done'        => true,
        'multisite_unsupported' => true,
        'https_required'        => true,
        'placeholder_url'       => true,
    ];

    $fiftyonedegrees_retry_html = '';
    if (!isset($fiftyonedegrees_no_retry[$fiftyonedegrees_notice_slug])
        && !is_multisite()
        && class_exists('FiftyOneDegreesOauthStart')
    ) {
        $fiftyonedegrees_retry_url = wp_nonce_url(
            admin_url('admin-post.php?action=fiftyonedegrees_oauth_start'),
            FiftyOneDegreesOauthStart::NONCE_ACTION
        );
        $fiftyonedegrees_retry_html = sprintf(
            ' <a href="%s" class="button button-secondary" style="margin-left:8px;">%s</a>',
            esc_url($fiftyonedegrees_retry_url),
            esc_html__('Try again', 'fiftyonedegrees')
        );
    }

    printf(
        '<div class="%s"><p>%s%s</p></div>',
        esc_attr($css),
        esc_html(FiftyOneDegreesStrings::get('oauth.notice.' . $fiftyonedegrees_notice_slug)),
        $fiftyonedegrees_retry_html
    );
}

// Belt-and-braces success marker. The callback PRG appends ?oauth-success=1
// alongside setting the transient; if a hook subscriber consumed the
// transient first (unlikely but possible), this still surfaces success.
// Gated on:
//   - empty transient slug (avoid double-banner with the path above)
//   - GA_TOKEN actually present (avoid spoofing the banner by visiting
//     the URL with ?oauth-success=1 manually, which would otherwise show
//     "Connected to Google Analytics" above a Connect button)
if ($fiftyonedegrees_notice_slug === ''
    && isset($_GET['oauth-success'])
    && $_GET['oauth-success'] === '1'
    && !empty(get_option(Options::GA_TOKEN))
    && class_exists('FiftyOneDegreesStrings')
) {
    printf(
        '<div class="notice notice-success"><p>%s</p></div>',
        esc_html(FiftyOneDegreesStrings::get('oauth.notice.success'))
    );
}

// ─── Multisite hard guard ───────────────────────────────────────────────

// Multisite OAuth is on the roadmap for 1.0.13. The start handler refuses
// any flow from a multisite install, so the UI must surface the limitation
// regardless of whether the user has a stale GA_TOKEN from an older build
// (which would otherwise render the connected state with no caveat). The
// notice fires once at the top of the tab body and the Connect-button
// branch is hard-gated below so a partial-deploy with FiftyOneDegreesStrings
// missing still cannot render a multisite-incompatible Connect button.
$fiftyonedegrees_is_multisite = is_multisite();

if ($fiftyonedegrees_is_multisite && class_exists('FiftyOneDegreesStrings')) {
    printf(
        '<div class="notice notice-info"><p>%s</p></div>',
        esc_html(FiftyOneDegreesStrings::get('oauth.notice.multisite_unsupported'))
    );
}

// ─── Tab body ───────────────────────────────────────────────────────────

// Show any pending GA_ERROR notice independently of the body branch.
// Coupling it to the no-token branch would force the pre-auth UI
// (Connect button) every time a connected-state operation failed —
// e.g. a failed customDimensions.create — which misleads the admin
// into thinking they were disconnected. Token state alone drives the
// body branch; the error message is presentation overlay.
if (get_option(Options::GA_ERROR)) {
    echo '<p></p><span class="fod-pipeline-status error">' .
        esc_html(get_option(Options::GA_ERROR)) .
        '</span>';
    delete_option(Options::GA_ERROR);
}

if (!get_option(Options::GA_TOKEN) ||
    empty(get_option(Options::GA_TOKEN))) {
    ?>

    <p>
        It is required to
        <a href="https://support.google.com/analytics/answer/1008015?hl=en/" target="_blank">
            Set up
        </a>
        an account and a website profile at
        <a href="https://analytics.google.com/" target="_blank">
            Google Analytics
        </a>
        to send 51Degrees Custom Dimensions to Google Analytics.
        Once Set Up, create a connection between 51Degrees and your
        Google Analytics account.
    </p>

    <div class="notice notice-warning inline">
        <p>
            <strong>Before connecting:</strong> access is only authorized for a
            resource key whose registered domains include this site. In the
            <a href="https://configure.51degrees.com/?utm_source=code&utm_medium=comment&utm_campaign=pipeline-wordpress&utm_content=google-analytics.php&utm_term=before-connecting" target="_blank">Configurator</a>,
            create the resource key with this site's domain added to it —
            otherwise the Google Analytics connection will be refused.
        </p>
    </div>

    <?php
    // Connect button is hard-gated on !is_multisite() so a future refactor
    // that touches the Strings class loading order cannot accidentally
    // bypass the multisite guard. The info notice above already explained
    // the limitation; here we simply omit the button.
    if (!$fiftyonedegrees_is_multisite && class_exists('FiftyOneDegreesOauthStart')) {
        // Same-window redirect (no target="_blank") — the callback must
        // land in the same browser context that holds the admin session.
        $fiftyonedegrees_connect_url = wp_nonce_url(
            admin_url('admin-post.php?action=fiftyonedegrees_oauth_start'),
            FiftyOneDegreesOauthStart::NONCE_ACTION
        );
        ?>
        <p>
            <a href="<?php echo esc_url($fiftyonedegrees_connect_url); ?>"
               class="button button-primary">
                <?php echo esc_html__('Connect Google Analytics', 'fiftyonedegrees'); ?>
            </a>
        </p>
        <p class="description">
            <?php echo esc_html__('Please ensure you grant the "See and download your Google Analytics data" permission when signing in.', 'fiftyonedegrees'); ?>
        </p>
        <?php
    }
}
else if (get_option(Options::GA_CUSTOM_DIMENSIONS_SCREEN)) {

    include plugin_dir_path(__FILE__) . "/ga-customdimensions.php";

}
else {
    // Lazy seed of the property dropdown. Gated on a freshness
    // transient (not on `empty(GA_PROPERTIES)`) because the option
    // value is written as an empty array both when the admin
    // genuinely has zero GA4 properties and when the Admin API
    // returned a transient failure — `empty([])` is true in both
    // cases and would otherwise burn quota on every page render.
    // The transient carries no payload; its existence alone is the
    // "fetched recently" signal, with TTL set in ga-service.
    // ga-service::authenticate() also drives the silent access-token
    // refresh path, so an expired token gets refreshed here
    // transparently.
    if (get_transient(Fiftyonedegrees_Google_Analytics::GA_PROPERTIES_FRESHNESS_TRANSIENT) === false) {
        $fiftyonedegrees_ga_svc = new Fiftyonedegrees_Google_Analytics();
        $fiftyonedegrees_ga_client = $fiftyonedegrees_ga_svc->authenticate();
        if ($fiftyonedegrees_ga_client) {
            // get_ga4_admin_service always returns a configured
            // instance; auth-error mapping (revoked scope etc.)
            // lives inside get_analytics_properties_list, which
            // sets GA_ERROR before re-throwing as needed.
            $fiftyonedegrees_ga_admin = $fiftyonedegrees_ga_svc->get_ga4_admin_service($fiftyonedegrees_ga_client);
            $fiftyonedegrees_ga_svc->get_analytics_properties_list($fiftyonedegrees_ga_admin);
        }
    }
    ?>

    <form method="post" action="options.php">
        <table class="form-table">
            <tbody>
            <?php
                if (get_option(Options::GA_TRACKING_ID_ERROR)) {
            ?>
                    <p></p>
                    <?php echo '<span class="fod-pipeline-status error"><b>Please Select Analytics Property.</b></span>';
                    }
                    ?>

                <tr>
                    <th scope="row">
                        <label class="pt-20">Google Authentication</label>
                    </th>
                    <td>
                        <input type="submit" class="button-primary" value="Logout" name="ga_log_out" />
                        <p class="description">
                            You have allowed your site to access the data
                            from your Google Analytics account. Click on logout
                            button to disconnect or re-authenticate.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row" >
                        <label class="pt-20" for="<?php echo Options::GA_PROPERTY_ID; ?>">
                            Analytics Account/Property
                        </label>
                    </th>
                    <td>
                        <select id="<?php echo Options::GA_PROPERTY_ID; ?>" name = "<?php echo Options::GA_PROPERTY_ID; ?>">
                            <option value="">Select Analytics Property</option>
                            <script>
                                var preSelectedPropertyId = "<?php echo esc_html(get_option(Options::GA_PROPERTY_ID)); ?>";
                                var propertiesList = <?php echo sprintf(esc_html('%1$s'), json_encode(get_option(Options::GA_PROPERTIES)));?>;
                                if (Array.isArray(propertiesList)) {
                                    for (var i = 0, len = propertiesList.length; i < len; i++) {
                                        var row = propertiesList[i];
                                        // Defense against stale pre-upgrade
                                        // option shape: skip rows without a
                                        // GA4-shaped property_id.
                                        if (!row || !row["property_id"]) {
                                            continue;
                                        }
                                        var label = row["property_name"] +
                                            " (" + row["account_name"] + ")";
                                        if (preSelectedPropertyId == row["property_id"]) {
                                            document.write('<option value="' +
                                                row["property_id"] +
                                                '" selected>' +
                                                label +
                                                '</option>');
                                        }
                                        else {
                                            document.write(
                                                '<option value="' +
                                                row["property_id"] +
                                                '">' +
                                                label +
                                                '</option>');
                                        }
                                    }
                                }
                            </script>
                        </select>
                        <a href=<?php echo esc_url( "?page=51Degrees&tab=google-analytics" ); ?>>
                        <span class="fa-stack fa-lg" style="font-size:15px;">
                                <i class="fa fa-circle fa-stack-2x" style="color:#666666;"></i>
                                <i class="fa fa-refresh fa-stack-1x fa-inverse"></i>
                        </span>
                        </a>
                        <p class="description">
                            Select your Google Analytics Property to send 51Degrees
                            Custom Dimensions to.<br>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row" >
                        <label class="pt-20" for="<?php echo Options::GA_SEND_PAGE_VIEW; ?>">
                            Send Page View
                        </label>
                    </th>
                    <td>
                        <?php if (get_option(Options::GA_SEND_PAGE_VIEW)) { ?>
                            <input type="checkbox" id="<?php echo Options::GA_SEND_PAGE_VIEW; ?>" name="<?php echo Options::GA_SEND_PAGE_VIEW; ?>" checked>
                        <?php } else { ?>
                            <input type="checkbox" id="<?php echo Options::GA_SEND_PAGE_VIEW; ?>" name="<?php echo Options::GA_SEND_PAGE_VIEW; ?>">
                        <?php } ?>
                        <label for="<?php echo Options::GA_SEND_PAGE_VIEW; ?>">
                            Send Page View
                            <span class="fa-stack fa-lg" style="font-size:12px;">
                                <i class="fa fa-circle fa-stack-2x" style="color:#666666;"></i>
                                <i class="fa fa-info fa-stack-1x fa-inverse" title="Send a pageview for each page your users visit to get the information including:
1. Time spent by the user on each page or The total time a user spends on your site.
2. The geographic location.
3. Information related to browser and operating system.
4. Internal links clicked etc.">
                            </i>
                            </span>
                        </label>
                        <p class="description">
                            Check Send Page View to send default Page View hit
                            with custom dimensions.<br>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(); ?>
    </form>

<?php }

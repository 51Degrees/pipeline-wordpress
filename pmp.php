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

if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/includes/page-picker.php';
?>

<form method="post" action="options.php">

    <?php settings_fields(Options::PMP_GROUP_KEY); ?>

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::PMP_ENABLE); ?>">
                        <?php esc_html_e('Enable PMP', 'fiftyonedegrees'); ?>
                    </label>
                </th>
                <td>
                    <input type="hidden" name="<?php echo esc_attr(Options::PMP_ENABLE); ?>" value="off">
                    <input type="checkbox"
                           name="<?php echo esc_attr(Options::PMP_ENABLE); ?>"
                           id="<?php echo esc_attr(Options::PMP_ENABLE); ?>"
                           value="on"
                           <?php checked(get_option(Options::PMP_ENABLE), 'on'); ?>>
                    <label for="<?php echo esc_attr(Options::PMP_ENABLE); ?>">
                        <?php esc_html_e('Show the Preference Management Platform popup on public pages.', 'fiftyonedegrees'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::PMP_TCF_VENDOR_STRING); ?>">
                        <?php esc_html_e('TCF Vendor String', 'fiftyonedegrees'); ?>
                    </label>
                </th>
                <td>
                    <textarea name="<?php echo esc_attr(Options::PMP_TCF_VENDOR_STRING); ?>"
                              id="<?php echo esc_attr(Options::PMP_TCF_VENDOR_STRING); ?>"
                              class="large-text code"
                              rows="4"><?php echo esc_textarea(FiftyoneService::pmp_tcf_vendor_string()); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Base IAB TCF v2 consent string. It carries the static half of the TC payload — vendor consents and legitimate-interest signals — and PMP overlays the purpose consents on top of it at runtime, derived from the visitor\'s Standard or Personalized choice. The combined string is exposed via __tcfapi to downstream ad tech (Prebid.js, ad servers, analytics). The built-in default consents every vendor on IAB Global Vendor List v158; override here to restrict the allowed vendor set or to upgrade the GVL version. Generate custom strings with TCF Tools.', 'fiftyonedegrees'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::PMP_ALT_LABEL); ?>">
                        <?php esc_html_e('Alternative Button Label', 'fiftyonedegrees'); ?> *
                    </label>
                </th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr(Options::PMP_ALT_LABEL); ?>"
                           id="<?php echo esc_attr(Options::PMP_ALT_LABEL); ?>"
                           value="<?php echo esc_attr(FiftyoneService::pmp_alt_label()); ?>"
                           class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Label on the opt-out button shown alongside Standard / Personalized — e.g. "Remove ads", "Subscribe", "Pay".', 'fiftyonedegrees'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::PMP_ALT_URL); ?>">
                        <?php esc_html_e('Alternative Button URL', 'fiftyonedegrees'); ?> *
                    </label>
                </th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr(Options::PMP_ALT_URL); ?>"
                           id="<?php echo esc_attr(Options::PMP_ALT_URL); ?>"
                           value="<?php echo esc_attr(FiftyoneService::pmp_alt_url()); ?>"
                           class="large-text"
                           autocomplete="off">
                    <?php
                    fiftyonedegrees_render_page_picker(
                        Options::PMP_ALT_URL,
                        __('-- Select a page --', 'fiftyonedegrees')
                    );
                    ?>
                    <p class="description">
                        <?php esc_html_e('Destination the visitor is sent to when they click the alternative button — typically a subscription, checkout, or paywall page.', 'fiftyonedegrees'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::PMP_BRAND_NAME); ?>">
                        <?php esc_html_e('Brand Name', 'fiftyonedegrees'); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr(Options::PMP_BRAND_NAME); ?>"
                           id="<?php echo esc_attr(Options::PMP_BRAND_NAME); ?>"
                           value="<?php echo esc_attr(FiftyoneService::pmp_brand_name()); ?>"
                           class="regular-text">
                    <p class="description">
                        <?php esc_html_e("Your site's brand, shown in the popup heading. Replace the placeholder default with your own brand for visitor-facing copy.", 'fiftyonedegrees'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::PMP_BRAND_LOGO_URL); ?>">
                        <?php esc_html_e('Brand Logo URL', 'fiftyonedegrees'); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr(Options::PMP_BRAND_LOGO_URL); ?>"
                           id="<?php echo esc_attr(Options::PMP_BRAND_LOGO_URL); ?>"
                           value="<?php echo esc_attr(FiftyoneService::pmp_brand_logo_url()); ?>"
                           class="large-text"
                           autocomplete="off">
                    <p class="description">
                        <?php esc_html_e("Your brand's logo, shown in the popup. Defaults to the bundled 51Degrees logo as a placeholder — point it at your own logo asset for visitor-facing copy.", 'fiftyonedegrees'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::PMP_BRAND_TERMS_URL); ?>">
                        <?php esc_html_e('Terms / Privacy URL', 'fiftyonedegrees'); ?> *
                    </label>
                </th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr(Options::PMP_BRAND_TERMS_URL); ?>"
                           id="<?php echo esc_attr(Options::PMP_BRAND_TERMS_URL); ?>"
                           value="<?php echo esc_attr(get_option(Options::PMP_BRAND_TERMS_URL)); ?>"
                           class="large-text"
                           autocomplete="off">
                    <?php
                    fiftyonedegrees_render_page_picker(
                        Options::PMP_BRAND_TERMS_URL,
                        __('-- Select a page --', 'fiftyonedegrees')
                    );
                    ?>
                    <p class="description">
                        <?php esc_html_e('Linked from the popup so visitors can read your terms and privacy policy before making a choice. Required for a meaningful consent record.', 'fiftyonedegrees'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::PMP_SHOW_STANDARD); ?>">
                        <?php esc_html_e('Show Standard Marketing Option', 'fiftyonedegrees'); ?>
                    </label>
                </th>
                <td>
                    <input type="hidden" name="<?php echo esc_attr(Options::PMP_SHOW_STANDARD); ?>" value="off">
                    <input type="checkbox"
                           name="<?php echo esc_attr(Options::PMP_SHOW_STANDARD); ?>"
                           id="<?php echo esc_attr(Options::PMP_SHOW_STANDARD); ?>"
                           value="on"
                           <?php checked(get_option(Options::PMP_SHOW_STANDARD), 'on'); ?>>
                    <label for="<?php echo esc_attr(Options::PMP_SHOW_STANDARD); ?>">
                        <?php esc_html_e('Show the Standard marketing option alongside Personalized and the Alternative button.', 'fiftyonedegrees'); ?>
                    </label>
                </td>
            </tr>
        </tbody>
    </table>

    <?php submit_button(__('Save Changes', 'fiftyonedegrees')); ?>

</form>

<h2><?php esc_html_e('Developer Hook', 'fiftyonedegrees'); ?></h2>
<p>
    <?php esc_html_e('When a visitor makes a choice, the widget invokes', 'fiftyonedegrees'); ?>
    <code>window.onPMPCompletion(preference)</code>.
    <?php esc_html_e("The plugin provides a no-op default. Override it on your page to bootstrap anything that depends on the visitor's preference — analytics, Prebid.js, lazy ad stacks, etc. PMP itself acts as the CMP via the TCF API, so a separate consent manager isn't needed.", 'fiftyonedegrees'); ?>
</p>
<pre><code>&lt;script&gt;
window.onPMPCompletion = function (preference) {
    // preference is 'standard', 'personalized' or 'non-marketing'
    // The alternative button stores 'non-marketing'.
    console.log('PMP choice:', preference);
};
&lt;/script&gt;</code></pre>

<h2><?php esc_html_e('Your Current Preference', 'fiftyonedegrees'); ?></h2>
<p>
    <?php esc_html_e('PMP keeps the choice made in this browser in two cookies on this site.', 'fiftyonedegrees'); ?>
</p>
<table class="form-table" role="presentation">
    <tbody>
        <tr>
            <th scope="row"><?php esc_html_e('Choice', 'fiftyonedegrees'); ?> (<code>__mtm_pref</code>)</th>
            <td><strong><span id="fod-pmp-current-pref"><em><?php esc_html_e('checking…', 'fiftyonedegrees'); ?></em></span></strong></td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e('Shared across sites', 'fiftyonedegrees'); ?> (<code>__51d_pmp_share</code>)</th>
            <td><span id="fod-pmp-current-share"><em><?php esc_html_e('checking…', 'fiftyonedegrees'); ?></em></span></td>
        </tr>
    </tbody>
</table>
<p>
    <button type="button" class="button" id="fod-pmp-clear-pref">
        <?php esc_html_e('Clear Preference', 'fiftyonedegrees'); ?>
    </button>
</p>
<p class="description">
    <?php esc_html_e('Clear Preference expires both cookies in this browser, so the popup appears again on the next visit to a public page. A choice shared across sites is also kept by the 51Degrees cloud service in a cookie on its own domain, which this page cannot clear, and PMP can take the choice from there instead of showing the popup.', 'fiftyonedegrees'); ?>
</p>
<script>
(function () {
    var prefEl  = document.getElementById('fod-pmp-current-pref');
    var shareEl = document.getElementById('fod-pmp-current-share');
    var btn     = document.getElementById('fod-pmp-clear-pref');
    // The plugin sets no data-cookie-domain, so PMP writes both cookies
    // for this host with Path=/. Expiring them takes the same path and
    // no Domain.
    var PREF_COOKIE  = '__mtm_pref';
    var SHARE_COOKIE = '__51d_pmp_share';
    var EXPIRED      = '=; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
    var NONE_LABEL   = <?php echo wp_json_encode(__('(none)', 'fiftyonedegrees')); ?>;

    // Returns the named cookie's value, or null when it is absent.
    // document.cookie joins its name=value pairs with '; '.
    function readCookie(name) {
        var pairs = document.cookie ? document.cookie.split('; ') : [];
        for (var i = 0; i < pairs.length; i++) {
            var eq = pairs[i].indexOf('=');
            if (eq > 0 && pairs[i].substring(0, eq) === name) {
                return pairs[i].substring(eq + 1);
            }
        }
        return null;
    }

    function render() {
        prefEl.textContent  = readCookie(PREF_COOKIE) || NONE_LABEL;
        shareEl.textContent = readCookie(SHARE_COOKIE) || NONE_LABEL;
    }

    btn.addEventListener('click', function () {
        document.cookie = PREF_COOKIE + EXPIRED;
        document.cookie = SHARE_COOKIE + EXPIRED;
        render();
    });

    render();
})();
</script>

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
                    <input type="text"
                           name="<?php echo esc_attr(Options::PMP_TCF_VENDOR_STRING); ?>"
                           id="<?php echo esc_attr(Options::PMP_TCF_VENDOR_STRING); ?>"
                           value="<?php echo esc_attr(FiftyoneService::pmp_tcf_vendor_string()); ?>"
                           class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Static TCF vendor consent string generated via TCF Tools.', 'fiftyonedegrees'); ?>
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
                           class="regular-text"
                           autocomplete="off">
                    <?php
                    fiftyonedegrees_render_page_picker(
                        Options::PMP_ALT_URL,
                        __('-- Select a page --', 'fiftyonedegrees')
                    );
                    ?>
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
                           class="regular-text"
                           autocomplete="off">
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
                           class="regular-text"
                           autocomplete="off">
                    <?php
                    fiftyonedegrees_render_page_picker(
                        Options::PMP_BRAND_TERMS_URL,
                        __('-- Select a page --', 'fiftyonedegrees')
                    );
                    ?>
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

<h2><?php esc_html_e('Your Current Preference', 'fiftyonedegrees'); ?></h2>
<p>
    <?php esc_html_e('Locally stored PMP choice on this browser:', 'fiftyonedegrees'); ?>
    <strong><span id="fod-pmp-current-pref"><em><?php esc_html_e('checking…', 'fiftyonedegrees'); ?></em></span></strong>
</p>
<p>
    <button type="button" class="button" id="fod-pmp-clear-pref">
        <?php esc_html_e('Clear Preference', 'fiftyonedegrees'); ?>
    </button>
</p>
<script>
(function () {
    var display = document.getElementById('fod-pmp-current-pref');
    var btn     = document.getElementById('fod-pmp-clear-pref');
    var STORAGE_KEY = '__51d_pmp_pref';
    var NONE_LABEL  = <?php echo wp_json_encode(__('(none)', 'fiftyonedegrees')); ?>;

    function render() {
        var pref = null;
        try { pref = localStorage.getItem(STORAGE_KEY); } catch (e) {}
        display.textContent = pref || NONE_LABEL;
    }

    btn.addEventListener('click', function () {
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
        render();
    });

    render();
})();
</script>

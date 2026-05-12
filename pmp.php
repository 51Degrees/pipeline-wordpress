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

$resource_key    = get_option(Options::RESOURCE_KEY, '');
$cached_pipeline = get_option(Options::PIPELINE);

// Without IdProbGlobal or IdProbLic in the Resource Key the cloud does not
// produce a 51DiD value, so PMP has nothing to gate.
if (!empty($resource_key) && isset($cached_pipeline['pipeline']) && isset($cached_pipeline['available_engines'])) {
    $has_iddprob = false;
    foreach ($cached_pipeline['available_engines'] as $engine) {
        try {
            $props = $cached_pipeline['pipeline']->getElement($engine)->getProperties();
            if (isset($props['idproblic']) || isset($props['idprobglobal'])) {
                $has_iddprob = true;
                break;
            }
        } catch (\Throwable $e) {
        }
    }
    if (!$has_iddprob) {
        echo '<p></p><span class="fod-pipeline-status warn"><b>' .
            esc_html__('Resource Key does not include 51DiD properties (IdProbGlobal / IdProbLic). PMP cannot perform identity gating without them.', 'fiftyonedegrees') .
            ' <a href="https://configure.51degrees.com" target="_blank">' .
            esc_html__('Configure your key', 'fiftyonedegrees') . '</a></b></span>';
    }
}
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
                    <label for="<?php echo esc_attr(Options::PMP_CLOUD_HOST); ?>">
                        <?php esc_html_e('Cloud Host', 'fiftyonedegrees'); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr(Options::PMP_CLOUD_HOST); ?>"
                           id="<?php echo esc_attr(Options::PMP_CLOUD_HOST); ?>"
                           value="<?php echo esc_attr(get_option(Options::PMP_CLOUD_HOST, 'cloud.51degrees.com')); ?>"
                           class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Hostname of the 51Degrees cloud server. The bundle URL is built as https://{host}/pmp/{resource-key}/pmp-{locale}.js.', 'fiftyonedegrees'); ?>
                    </p>
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
                           class="regular-text">
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
                           value="<?php echo esc_attr(get_option(Options::PMP_BRAND_LOGO_URL)); ?>"
                           class="regular-text">
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
                           class="regular-text">
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
                        <?php esc_html_e('Show Standard Option', 'fiftyonedegrees'); ?>
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

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

require_once __DIR__ . '/includes/fiftyone-strings.php';
require_once __DIR__ . '/includes/page-picker.php';

$cachedPipeline = get_option(Options::PIPELINE);
$suspicious_enabled = get_option(Options::SUSPICIOUS_ENABLE, 'off');

if ($suspicious_enabled === 'on') {
    if (isset($cachedPipeline['error'])) {
        echo '<p></p><span class="fod-pipeline-status warn"><b>' .
            esc_html(FiftyOneDegreesStrings::get('suspicious.notice.resource_key_warning')) .
            '</b></span>';
    }

    $engine_datakey = SuspiciousActivity::id_engine_datakey();
    if ($engine_datakey !== null) {
        echo '<p></p><span class="fod-pipeline-status good"><b>' .
            esc_html(FiftyOneDegreesStrings::get('suspicious.notice.advanced_active')) .
            '</b></span>';
    } else {
        echo '<p></p><span class="fod-pipeline-status warn"><b>' .
            esc_html(FiftyOneDegreesStrings::get('suspicious.notice.basic_active')) .
            '</b></span>';
    }
}

?>

<form method="post" action="options.php">

    <?php settings_fields(Options::SUSPICIOUS_GROUP_KEY); ?>

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row">
                    <?php echo esc_html(FiftyOneDegreesStrings::get('suspicious.field.enable_label')); ?>
                </th>
                <td>
                    <input type="hidden" name="<?php echo esc_attr(Options::SUSPICIOUS_ENABLE); ?>" value="off">
                    <input type="checkbox"
                           name="<?php echo esc_attr(Options::SUSPICIOUS_ENABLE); ?>"
                           id="<?php echo esc_attr(Options::SUSPICIOUS_ENABLE); ?>"
                           value="on"
                           <?php checked(get_option(Options::SUSPICIOUS_ENABLE), 'on'); ?>>
                    <label for="<?php echo esc_attr(Options::SUSPICIOUS_ENABLE); ?>">
                        <?php echo esc_html(FiftyOneDegreesStrings::get('suspicious.field.enable_checkbox')); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::SUSPICIOUS_REDIRECT_URL); ?>">
                        <?php echo esc_html(FiftyOneDegreesStrings::get('suspicious.field.redirect_url_label')); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr(Options::SUSPICIOUS_REDIRECT_URL); ?>"
                           id="<?php echo esc_attr(Options::SUSPICIOUS_REDIRECT_URL); ?>"
                           value="<?php echo esc_attr(get_option(Options::SUSPICIOUS_REDIRECT_URL)); ?>"
                           class="regular-text">
                    <?php
                    fiftyonedegrees_render_page_picker(
                        Options::SUSPICIOUS_REDIRECT_URL,
                        FiftyOneDegreesStrings::get('common.page_picker.placeholder')
                    );
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::SUSPICIOUS_REQUESTS); ?>">
                        <?php echo esc_html(FiftyOneDegreesStrings::get('suspicious.field.requests_label')); ?>
                    </label>
                </th>
                <td>
                    <input type="number"
                           name="<?php echo esc_attr(Options::SUSPICIOUS_REQUESTS); ?>"
                           id="<?php echo esc_attr(Options::SUSPICIOUS_REQUESTS); ?>"
                           value="<?php echo esc_attr(get_option(Options::SUSPICIOUS_REQUESTS, 5)); ?>"
                           min="1"
                           class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::SUSPICIOUS_WINDOW); ?>">
                        <?php echo esc_html(FiftyOneDegreesStrings::get('suspicious.field.window_label')); ?>
                    </label>
                </th>
                <td>
                    <input type="number"
                           name="<?php echo esc_attr(Options::SUSPICIOUS_WINDOW); ?>"
                           id="<?php echo esc_attr(Options::SUSPICIOUS_WINDOW); ?>"
                           value="<?php echo esc_attr(get_option(Options::SUSPICIOUS_WINDOW, 30)); ?>"
                           min="1"
                           max="3600"
                           class="small-text">
                </td>
            </tr>
        </tbody>
    </table>

    <?php submit_button(FiftyOneDegreesStrings::get('suspicious.button.save')); ?>

</form>

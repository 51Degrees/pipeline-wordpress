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

$cachedPipeline = get_option(Options::PIPELINE);
$suspicious_enabled = get_option(Options::SUSPICIOUS_ENABLE, 'off');

if ($suspicious_enabled === 'on') {
    if (isset($cachedPipeline['error'])) {
        echo '<p></p><span class="fod-pipeline-status warn"><b>' .
            esc_html__('Your 51Degrees Resource Key isn\'t working. Check it on the Setup tab. Until this is fixed, tracking runs in basic mode (see below).', '51D') .
            '</b></span>';
    }

    $engine_datakey = SuspiciousActivity::id_engine_datakey();
    if ($engine_datakey !== null) {
        echo '<p></p><span class="fod-pipeline-status good"><b>' .
            esc_html__('Advanced tracking active. Visitors are identified individually even when they share a network.', '51D') .
            '</b></span>';
    } else {
        echo '<p></p><span class="fod-pipeline-status warn"><b>' .
            esc_html__('Basic tracking is active. Visitors who look identical to the site are grouped and blocked together if any one of them triggers the limit.', '51D') .
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
                    <?php esc_html_e('Enable', '51D'); ?>
                </th>
                <td>
                    <input type="hidden" name="<?php echo esc_attr(Options::SUSPICIOUS_ENABLE); ?>" value="off">
                    <input type="checkbox"
                           name="<?php echo esc_attr(Options::SUSPICIOUS_ENABLE); ?>"
                           id="<?php echo esc_attr(Options::SUSPICIOUS_ENABLE); ?>"
                           value="on"
                           <?php checked(get_option(Options::SUSPICIOUS_ENABLE), 'on'); ?>>
                    <label for="<?php echo esc_attr(Options::SUSPICIOUS_ENABLE); ?>">
                        <?php esc_html_e('Enable suspicious activity detection', '51D'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::SUSPICIOUS_REDIRECT_URL); ?>">
                        <?php esc_html_e('Redirect URL', '51D'); ?>
                    </label>
                </th>
                <td>
                    <input type="text"
                           name="<?php echo esc_attr(Options::SUSPICIOUS_REDIRECT_URL); ?>"
                           id="<?php echo esc_attr(Options::SUSPICIOUS_REDIRECT_URL); ?>"
                           value="<?php echo esc_attr(get_option(Options::SUSPICIOUS_REDIRECT_URL)); ?>"
                           class="regular-text">
                    <?php
                    $pages = get_pages();
                    if (!empty($pages)) {
                        echo '<p>';
                        echo '<select id="suspicious-page-select">';
                        echo '<option value="">' . esc_html__('-- Select a page --', '51D') . '</option>';
                        foreach ($pages as $page) {
                            echo '<option value="' . esc_url(get_permalink($page->ID)) . '">'
                                . esc_html($page->post_title) . '</option>';
                        }
                        echo '</select>';
                        echo '</p>';
                    }
                    ?>
                    <script>
                    (function() {
                        var sel = document.getElementById('suspicious-page-select');
                        if (sel) {
                            sel.addEventListener('change', function() {
                                if (this.value) {
                                    document.getElementById('<?php echo esc_js(Options::SUSPICIOUS_REDIRECT_URL); ?>').value = this.value;
                                    this.selectedIndex = 0;
                                }
                            });
                        }
                    })();
                    </script>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="<?php echo esc_attr(Options::SUSPICIOUS_REQUESTS); ?>">
                        <?php esc_html_e('Number of requests', '51D'); ?>
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
                        <?php esc_html_e('Within seconds', '51D'); ?>
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

    <?php submit_button(esc_html__('Save Changes', '51D')); ?>

</form>

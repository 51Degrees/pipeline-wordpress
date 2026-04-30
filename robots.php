<?php

if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/includes/cloud-metadata.php';
require_once __DIR__ . '/includes/standard-tdls.php';
require_once __DIR__ . '/includes/robots-txt.php';
require_once __DIR__ . '/includes/fiftyone-strings.php';
require_once __DIR__ . '/includes/page-picker.php';

$robots_enable      = get_option(Options::ROBOTS_ENABLE, 'off');
$robots_enforce     = get_option(Options::ROBOTS_ENFORCE, 'off');
$redirect_url       = get_option(Options::ROBOTS_REDIRECT_URL, '');
$custom_top         = get_option(Options::ROBOTS_CUSTOM_TOP, '');
$custom_bottom      = get_option(Options::ROBOTS_CUSTOM_BOTTOM, '');
$saved_allowed      = get_option(Options::ROBOTS_ALLOWED_CATEGORIES, null);
$default_denied     = FiftyOneDegreesRobotsTxt::DEFAULT_DENIED_CATEGORIES;
$standard_selected  = get_option(Options::ROBOTS_STANDARD_TDL_SELECTED, []);
$custom_tdl         = get_option(Options::ROBOTS_CUSTOM_TDL, []);
$standard_tdls      = FiftyOneDegreesStandardTdls::load();
if (!is_array($standard_selected)) {
    $standard_selected = [];
}
if (!is_array($custom_tdl)) {
    $custom_tdl = [];
}

FiftyOneDegreesCloudMetadata::invalidate_crawler_usage();
$crawler_categories = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

$supports_crawler = FiftyOneDegreesCloudMetadata::supports_crawler();
$supports_crawler_usage = FiftyOneDegreesCloudMetadata::supports_crawler_usage();
$supports_robots_txt = FiftyOneDegreesCloudMetadata::supports_robots_txt();
?>

<div class="wrap">
    <h2><?php echo esc_html(FiftyOneDegreesStrings::get('robots.page.title')); ?></h2>
    <p><?php echo esc_html(FiftyOneDegreesStrings::get('robots.page.description')); ?></p>
    <?php if (!$supports_crawler): ?>
        <div class="notice notice-warning">
            <p><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.no_crawler')); ?></p>
        </div>
    <?php elseif (!$supports_crawler_usage): ?>
        <div class="notice notice-info">
            <p><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.no_crawler_usage')); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!$supports_robots_txt): ?>
        <div class="notice notice-info">
            <p><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.no_robots_txt')); ?></p>
        </div>
    <?php endif; ?>

    <?php if (empty($crawler_categories) && $supports_crawler_usage): ?>
        <div class="notice notice-error">
            <p><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.categories_fetch_failed')); ?></p>
        </div>
    <?php endif; ?>

    <?php
    $generate_success = get_transient('fiftyonedegrees_robots_generate_success');
    if ($generate_success !== false):
        delete_transient('fiftyonedegrees_robots_generate_success');
    ?>
        <div class="notice notice-success is-dismissible">
            <p><?php echo esc_html(FiftyOneDegreesStrings::get('robots.notice.generate_success')); ?></p>
        </div>
    <?php endif; ?>

    <?php
    $robots_cloud_error = get_transient('fiftyonedegrees_robots_cloud_error');
    if ($robots_cloud_error !== false): ?>
        <div class="notice notice-error">
            <p><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.cloud_api_error', esc_html($robots_cloud_error))); ?></p>
        </div>
    <?php endif; ?>

    <?php if (file_exists(ABSPATH . 'robots.txt')): ?>
        <div class="notice notice-error">
            <p><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.physical_file')); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php settings_fields(Options::ROBOTS_GROUP_KEY); ?>

        <input type="hidden" name="<?php echo Options::ROBOTS_ENABLE; ?>" value="off">
        <input type="hidden" name="<?php echo Options::ROBOTS_ENFORCE; ?>" value="off">

        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.enable_label')); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo Options::ROBOTS_ENABLE; ?>" value="on" <?php checked('on', $robots_enable); ?> />
                        <?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.enable_checkbox')); ?>
                    </label>
                    <p class="description"><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.enable_description')); ?></p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.enforce_label')); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="<?php echo Options::ROBOTS_ENFORCE; ?>" value="on" <?php checked('on', $robots_enforce); ?> />
                        <?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.enforce_checkbox')); ?>
                    </label>
                    <p class="description"><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.enforce_description')); ?></p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><label for="<?php echo Options::ROBOTS_REDIRECT_URL; ?>"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.redirect_url_label')); ?></label></th>
                <td>
                    <input type="url" name="<?php echo Options::ROBOTS_REDIRECT_URL; ?>" id="<?php echo esc_attr(Options::ROBOTS_REDIRECT_URL); ?>" value="<?php echo esc_attr($redirect_url); ?>" class="regular-text" placeholder="<?php echo esc_attr(FiftyOneDegreesStrings::get('robots.field.redirect_url_placeholder')); ?>" />
                    <p class="description"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.redirect_url_description')); ?></p>
                    <?php
                    fiftyonedegrees_render_page_picker(
                        Options::ROBOTS_REDIRECT_URL,
                        FiftyOneDegreesStrings::get('common.page_picker.placeholder')
                    );
                    ?>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.categories_label')); ?></th>
                <td>
                    <fieldset>
                        <p class="description"><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.categories_description')); ?></p>
                        <br>
                        <?php foreach ($crawler_categories as $cat_name => $cat_desc): ?>
                            <?php
                            if ($saved_allowed === null || $saved_allowed === false) {
                                $is_allowed = !in_array($cat_name, $default_denied, true);
                            } else {
                                $is_allowed = in_array($cat_name, $saved_allowed, true);
                            }
                            ?>
                            <label style="display:block; margin-bottom: 12px;">
                                <input type="checkbox"
                                    name="<?php echo esc_attr(Options::ROBOTS_ALLOWED_CATEGORIES); ?>[]"
                                    value="<?php echo esc_attr($cat_name); ?>"
                                    <?php checked($is_allowed); ?>>
                                <strong><?php echo esc_html($cat_name); ?></strong>
                                <?php if (!empty($cat_desc)): ?>
                                    <span class="description" style="display:block; margin-left: 24px; margin-top: 2px;"><?php echo esc_html($cat_desc); ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </fieldset>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.tdl_label')); ?></th>
                <td>
                    <fieldset>

                        <?php if (!empty($standard_tdls)): ?>
                        <p><strong><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.tdl_standard_section_label')); ?></strong></p>
                        <p class="description"><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.tdl_standard_section_description')); ?></p>
                        <br>
                        <?php foreach ($standard_tdls as $tdl_entry): ?>
                            <?php $entry_id = $tdl_entry['id']; ?>
                            <label style="display:block; margin-bottom: 12px;">
                                <input type="checkbox"
                                    name="<?php echo esc_attr(Options::ROBOTS_STANDARD_TDL_SELECTED); ?>[]"
                                    value="<?php echo esc_attr($entry_id); ?>"
                                    <?php checked(in_array($entry_id, $standard_selected, true)); ?>>
                                <strong><?php echo esc_html($tdl_entry['label']); ?></strong>
                                <?php if (!empty($tdl_entry['description'])): ?>
                                    <span class="description" style="display:block; margin-left: 24px; margin-top: 2px;"><?php echo esc_html($tdl_entry['description']); ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                        <hr style="margin: 15px 0;">
                        <?php endif; ?>

                        <p class="description"><?php echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.tdl_custom_description')); ?></p>
                        <br>
                        <textarea
                            name="<?php echo esc_attr(Options::ROBOTS_CUSTOM_TDL); ?>"
                            class="large-text code"
                            rows="5"
                            placeholder="<?php echo esc_attr(FiftyOneDegreesStrings::get('robots.field.tdl_custom_placeholder')); ?>"
                        ><?php echo esc_textarea(implode("\n", $custom_tdl)); ?></textarea>
                    </fieldset>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><label for="<?php echo Options::ROBOTS_CUSTOM_TOP; ?>"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.custom_top_label')); ?></label></th>
                <td>
                    <textarea name="<?php echo Options::ROBOTS_CUSTOM_TOP; ?>" rows="5" class="large-text code" placeholder="<?php echo esc_attr(FiftyOneDegreesStrings::get('robots.field.custom_top_placeholder')); ?>"><?php echo esc_textarea($custom_top); ?></textarea>
                    <p class="description"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.custom_top_description')); ?></p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><label for="<?php echo Options::ROBOTS_CUSTOM_BOTTOM; ?>"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.custom_bottom_label')); ?></label></th>
                <td>
                    <textarea name="<?php echo Options::ROBOTS_CUSTOM_BOTTOM; ?>" rows="5" class="large-text code" placeholder="<?php echo esc_attr(FiftyOneDegreesStrings::get('robots.field.custom_bottom_placeholder')); ?>"><?php echo esc_textarea($custom_bottom); ?></textarea>
                    <p class="description"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.field.custom_bottom_description')); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(FiftyOneDegreesStrings::get('robots.button.save')); ?>
    </form>

    <hr>

    <h3><?php echo esc_html(FiftyOneDegreesStrings::get('robots.preview.title')); ?></h3>
    <p><?php echo esc_html(FiftyOneDegreesStrings::get('robots.preview.description')); ?></p>
    <pre style="background: #f0f0f1; padding: 15px; border: 1px solid #ccc; max-height: 400px; overflow: auto; white-space: pre-wrap;"><?php
        $output = FiftyOneDegreesRobotsTxt::generate_robots_txt_content(get_option('blog_public'));
        if (empty(trim($output))) {
            $output = FiftyOneDegreesStrings::get('robots.preview.empty');
        }
        echo esc_html($output);
    ?></pre>

    <p>
        <a href="<?php echo esc_url(home_url('/robots.txt')); ?>" target="_blank"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.links.view')); ?></a>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <?php
        $annotated_cache = get_option(Options::ROBOTS_ANNOTATEDTEXT_CACHE, '');
        if (!empty($annotated_cache)): ?>
            <a href="<?php echo esc_url(rest_url('fiftyonedegrees/v4/annotated-robots')); ?>"><?php echo esc_html(FiftyOneDegreesStrings::get('robots.links.download')); ?></a>
        <?php else: ?>
            <em><?php echo esc_html(FiftyOneDegreesStrings::get('robots.links.not_generated')); ?></em>
        <?php endif; ?>
    </p>
</div>

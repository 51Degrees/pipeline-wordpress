<?php
/* *********************************************************************
 * This Original Work is copyright of 51 Degrees Mobile Experts Limited.
 * Copyright 2026 51 Degrees Mobile Experts Limited, Davidson House,
 * Forbury Square, Reading, Berkshire, United Kingdom RG1 3EU.
 *
 * This Original Work is licensed under the European Union Public Licence
 * (EUPL) v.1.2 and is subject to its terms as set out below.
 *
 * If a copy of the EUPL was not distributed with this file, You can obtain
 * one at https://opensource.org/licenses/EUPL-1.2.
 *
 * The 'Compatible Licences' set out in the Appendix to the EUPL (as may be
 * amended by the European Commission) shall be deemed incompatible for
 * the purposes of the Work and the provisions of the compatibility
 * clause in Article 5 of the EUPL shall not apply.
 *
 * If using the Work as, or as part of, a network application, by
 * including the attribution notice(s) required under Article 5 of the EUPL
 * in the end user terms of the application under an appropriate heading,
 * such notice(s) shall fulfill the requirements of that article.
 * ********************************************************************* */



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

$supports_iscrawler     = FiftyOneDegreesCloudMetadata::supports_iscrawler();
$supports_crawler_usage = FiftyOneDegreesCloudMetadata::supports_crawler_usage();
$supports_robots_txt    = FiftyOneDegreesCloudMetadata::supports_robots_txt();

// Only invalidate + fetch fresh categories when the resource key
// advertises CrawlerUsage AND the failure-backoff isn't already
// active. Otherwise the page eats two cloud timeouts on every load
// when cloud is down.
$crawler_usage_fail_active = is_array(get_transient('fiftyonedegrees_crawler_usage_fail'));
if ($supports_crawler_usage && !$crawler_usage_fail_active) {
    FiftyOneDegreesCloudMetadata::invalidate_crawler_usage();
}
$crawler_categories = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();

$cloud_failure_signal = FiftyOneDegreesCloudMetadata::get_failure_signal();
$last_refresh         = get_option(Options::ROBOTS_LAST_REFRESH, null);
$plaintext_cache      = get_option(Options::ROBOTS_PLAINTEXT_CACHE, '');
?>

<div class="wrap">
    <h2> echo esc_html(FiftyOneDegreesStrings::get('robots.page.title')); ?></h2>
    <p> echo esc_html(FiftyOneDegreesStrings::get('robots.page.description')); ?></p>
     if ($cloud_failure_signal !== null): ?>
        
        $http_status   = isset($cloud_failure_signal['http_status']) ? $cloud_failure_signal['http_status'] : null;
        $cache_suffix  = !empty($plaintext_cache)
            ? ' ' . FiftyOneDegreesStrings::get('robots.notice.cached_state_suffix')
            : '';
        // http_status > 0 (cloud responded with non-2xx) and http_status === null
        // (cloud responded 200 but body was unparseable) both mean "cloud was
        // reached, response not usable" — cloud_rejected. Only http_status === 0
        // is "cloud unreachable" (network/timeout).
        $notice_key    = ($http_status === 0)
            ? 'common.cloud.unreachable'
            : 'common.cloud.rejected';
        $host_suffix   = ' ' . FiftyOneDegreesStrings::get(
            'common.cloud.host_suffix',
            FiftyOneDegreesCloudMetadata::get_cloud_host_url()
        );
        ?>
        <div class="notice notice-error">
            <p> echo wp_kses_post(FiftyOneDegreesStrings::get($notice_key) . $host_suffix . $cache_suffix); ?></p>
        </div>
     else: ?>
         if (!$supports_iscrawler && !$supports_crawler_usage): ?>
            <div class="notice notice-warning">
                <p> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.no_crawler')); ?></p>
            </div>
         elseif (!$supports_iscrawler): ?>
            <div class="notice notice-warning">
                <p> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.no_iscrawler')); ?></p>
            </div>
         elseif (!$supports_crawler_usage): ?>
            <div class="notice notice-info">
                <p> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.no_crawler_usage')); ?></p>
            </div>
         endif; ?>

         if (!$supports_robots_txt): ?>
            <div class="notice notice-info">
                <p> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.no_robots_txt')); ?></p>
            </div>
         endif; ?>

         if (empty($crawler_categories) && $supports_crawler_usage): ?>
            <div class="notice notice-error">
                <p> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.categories_fetch_failed')); ?></p>
            </div>
         endif; ?>
     endif; ?>

    
    $generate_success = get_transient('fiftyonedegrees_robots_generate_success');
    if ($generate_success !== false):
        delete_transient('fiftyonedegrees_robots_generate_success');
    ?>
        <div class="notice notice-success is-dismissible">
            <p> echo esc_html(FiftyOneDegreesStrings::get('robots.notice.generate_success')); ?></p>
        </div>
     endif; ?>

    
    // Suppress the transient cloud-error notice when a metadata-failure
    // signal is already being rendered — same root cause, one notice.
    $robots_cloud_error = get_transient('fiftyonedegrees_robots_cloud_error');
    if ($robots_cloud_error !== false && $cloud_failure_signal === null): ?>
        <div class="notice notice-error">
            <p> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.cloud_api_error', esc_html($robots_cloud_error))); ?></p>
        </div>
     endif; ?>

     if (is_array($last_refresh) && isset($last_refresh['status'])):
        // Error-state deduped against $cloud_failure_signal; success still useful.
        if ($last_refresh['status'] === 'error' && $cloud_failure_signal === null): ?>
            <div class="notice notice-warning">
                <p> echo wp_kses_post(FiftyOneDegreesStrings::get(
                    'robots.notice.last_refresh_error',
                    esc_html(isset($last_refresh['message']) ? $last_refresh['message'] : ''),
                    esc_html(isset($last_refresh['timestamp']) ? gmdate('Y-m-d H:i:s', $last_refresh['timestamp']) . ' UTC' : '')
                )); ?></p>
            </div>
         elseif ($last_refresh['status'] === 'success'): ?>
            <p class="description">
                 echo esc_html(FiftyOneDegreesStrings::get(
                    'robots.notice.last_refresh_success',
                    isset($last_refresh['timestamp']) ? gmdate('Y-m-d H:i:s', $last_refresh['timestamp']) . ' UTC' : ''
                )); ?>
            </p>
         endif;
    endif; ?>

     if (file_exists(ABSPATH . 'robots.txt')): ?>
        <div class="notice notice-error">
            <p> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.notice.physical_file')); ?></p>
        </div>
     endif; ?>

    <form method="post" action="options.php">
         settings_fields(Options::ROBOTS_GROUP_KEY); ?>

        <input type="hidden" name=" echo Options::ROBOTS_ENABLE; ?>" value="off">
        <input type="hidden" name=" echo Options::ROBOTS_ENFORCE; ?>" value="off">

        <table class="form-table">
            <tr valign="top">
                <th scope="row"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.enable_label')); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name=" echo Options::ROBOTS_ENABLE; ?>" value="on"  checked('on', $robots_enable); ?> />
                         echo esc_html(FiftyOneDegreesStrings::get('robots.field.enable_checkbox')); ?>
                    </label>
                    <p class="description"> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.enable_description')); ?></p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.enforce_label')); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name=" echo Options::ROBOTS_ENFORCE; ?>" value="on"  checked('on', $robots_enforce); ?> />
                         echo esc_html(FiftyOneDegreesStrings::get('robots.field.enforce_checkbox')); ?>
                    </label>
                    <p class="description"> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.enforce_description')); ?></p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><label for=" echo Options::ROBOTS_REDIRECT_URL; ?>"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.redirect_url_label')); ?></label></th>
                <td>
                    <input type="url" name=" echo Options::ROBOTS_REDIRECT_URL; ?>" id=" echo esc_attr(Options::ROBOTS_REDIRECT_URL); ?>" value=" echo esc_attr($redirect_url); ?>" class="large-text" placeholder=" echo esc_attr(FiftyOneDegreesStrings::get('robots.field.redirect_url_placeholder')); ?>" />
                    <p class="description"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.redirect_url_description')); ?></p>
                    
                    fiftyonedegrees_render_page_picker(
                        Options::ROBOTS_REDIRECT_URL,
                        FiftyOneDegreesStrings::get('common.page_picker.placeholder')
                    );
                    ?>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.categories_label')); ?></th>
                <td>
                    <fieldset>
                        <p class="description"> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.categories_description')); ?></p>
                        <br>
                         foreach ($crawler_categories as $cat_name => $cat_desc): ?>
                            
                            if ($saved_allowed === null || $saved_allowed === false) {
                                $is_allowed = !in_array($cat_name, $default_denied, true);
                            } else {
                                $is_allowed = in_array($cat_name, $saved_allowed, true);
                            }
                            ?>
                            <label style="display:block; margin-bottom: 12px;">
                                <input type="checkbox"
                                    name=" echo esc_attr(Options::ROBOTS_ALLOWED_CATEGORIES); ?>[]"
                                    value=" echo esc_attr($cat_name); ?>"
                                     checked($is_allowed); ?>>
                                <strong> echo esc_html($cat_name); ?></strong>
                                 if (!empty($cat_desc)): ?>
                                    <span class="description" style="display:block; margin-left: 24px; margin-top: 2px;"> echo esc_html($cat_desc); ?></span>
                                 endif; ?>
                            </label>
                         endforeach; ?>
                    </fieldset>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.tdl_label')); ?></th>
                <td>
                    <fieldset>

                         if (!empty($standard_tdls)): ?>
                        <p><strong> echo esc_html(FiftyOneDegreesStrings::get('robots.field.tdl_standard_section_label')); ?></strong></p>
                        <p class="description"> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.tdl_standard_section_description')); ?></p>
                        <br>
                         foreach ($standard_tdls as $tdl_entry): ?>
                             $entry_id = $tdl_entry['id']; ?>
                            <label style="display:block; margin-bottom: 12px;">
                                <input type="checkbox"
                                    name=" echo esc_attr(Options::ROBOTS_STANDARD_TDL_SELECTED); ?>[]"
                                    value=" echo esc_attr($entry_id); ?>"
                                     checked(in_array($entry_id, $standard_selected, true)); ?>>
                                <strong> echo esc_html($tdl_entry['label']); ?></strong>
                                 if (!empty($tdl_entry['description'])): ?>
                                    <span class="description" style="display:block; margin-left: 24px; margin-top: 2px;"> echo esc_html($tdl_entry['description']); ?></span>
                                 endif; ?>
                            </label>
                         endforeach; ?>
                        <hr style="margin: 15px 0;">
                         endif; ?>

                        <p class="description"> echo wp_kses_post(FiftyOneDegreesStrings::get('robots.field.tdl_custom_description')); ?></p>
                        <br>
                        <textarea
                            name=" echo esc_attr(Options::ROBOTS_CUSTOM_TDL); ?>"
                            class="large-text code"
                            rows="5"
                            placeholder=" echo esc_attr(FiftyOneDegreesStrings::get('robots.field.tdl_custom_placeholder')); ?>"
                        > echo esc_textarea(implode("\n", $custom_tdl)); ?></textarea>
                    </fieldset>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><label for=" echo Options::ROBOTS_CUSTOM_TOP; ?>"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.custom_top_label')); ?></label></th>
                <td>
                    <textarea name=" echo Options::ROBOTS_CUSTOM_TOP; ?>" rows="5" class="large-text code" placeholder=" echo esc_attr(FiftyOneDegreesStrings::get('robots.field.custom_top_placeholder')); ?>"> echo esc_textarea($custom_top); ?></textarea>
                    <p class="description"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.custom_top_description')); ?></p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><label for=" echo Options::ROBOTS_CUSTOM_BOTTOM; ?>"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.custom_bottom_label')); ?></label></th>
                <td>
                    <textarea name=" echo Options::ROBOTS_CUSTOM_BOTTOM; ?>" rows="5" class="large-text code" placeholder=" echo esc_attr(FiftyOneDegreesStrings::get('robots.field.custom_bottom_placeholder')); ?>"> echo esc_textarea($custom_bottom); ?></textarea>
                    <p class="description"> echo esc_html(FiftyOneDegreesStrings::get('robots.field.custom_bottom_description')); ?></p>
                </td>
            </tr>
        </table>

         submit_button(FiftyOneDegreesStrings::get('robots.button.save')); ?>
    </form>

    <hr>

    <h3> echo esc_html(FiftyOneDegreesStrings::get('robots.preview.title')); ?></h3>
    <p> echo esc_html(FiftyOneDegreesStrings::get('robots.preview.description')); ?></p>
    <pre style="background: #f0f0f1; padding: 15px; border: 1px solid #ccc; max-height: 400px; overflow: auto; white-space: pre-wrap;">
        $output = FiftyOneDegreesRobotsTxt::generate_robots_txt_content(get_option('blog_public'));
        if (empty(trim($output))) {
            $output = FiftyOneDegreesStrings::get('robots.preview.empty');
        }
        echo esc_html($output);
    ?></pre>

    <p>
        <a href=" echo esc_url(home_url('/robots.txt')); ?>" target="_blank"> echo esc_html(FiftyOneDegreesStrings::get('robots.links.view')); ?></a>
    </p>
</div>

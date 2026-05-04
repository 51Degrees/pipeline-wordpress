<?php

require_once __DIR__ . '/../options.php';
require_once __DIR__ . '/pipeline.php';
require_once __DIR__ . '/cloud-metadata.php';
require_once __DIR__ . '/fiftyone-strings.php';
require_once __DIR__ . '/standard-tdls.php';

use fiftyone\pipeline\cloudrequestengine\CloudRequestEngine;
use fiftyone\pipeline\cloudrequestengine\CloudRequestException;
use fiftyone\pipeline\cloudrequestengine\Constants;
use fiftyone\pipeline\cloudrequestengine\HttpClient;

class FiftyOneDegreesRobotsTxt {

    const DEFAULT_DENIED_CATEGORIES = ['Index', 'Train', 'Input'];
    const DEFAULT_KNOWN_CATEGORIES = ['Index', 'Train', 'Input', 'Search', 'Monitor',
                                   'Archiving', 'Preview', 'Security', 'Analytics',
                                   'Feed', 'Discovery'];

    public static function init() {
        add_filter('robots_txt', [__CLASS__, 'generate_robots_txt'], 10, 2);
        add_action('init', [__CLASS__, 'enforce_crawler_redirect'], 15);
        add_action('fiftyonedegrees_refresh_robots_txt', [__CLASS__, 'refresh_robots_txt_cron']);
    }

    public static function refresh_robots_txt_cron() {
        if (get_option(Options::ROBOTS_ENABLE, 'off') !== 'on') {
            return;
        }
        if (!FiftyOneDegreesRobotsTxt::fetch_from_cloud()) {
            error_log('51Degrees: robots.txt cron refresh failed');
        }
    }

    public static function get_effective_tdl_values() {
        $selected = get_option(Options::ROBOTS_STANDARD_TDL_SELECTED, []);
        $config   = FiftyOneDegreesStandardTdls::load();
        $custom   = get_option(Options::ROBOTS_CUSTOM_TDL, []);

        if (!is_array($selected)) {
            $selected = [];
        }
        if (!is_array($custom)) {
            $custom = [];
        }

        $values = [];
        foreach ($selected as $id) {
            foreach ($config as $entry) {
                if ($entry['id'] === $id) {
                    $values[] = $entry['macro'];
                    break;
                }
            }
        }

        return array_merge($values, $custom);
    }

    private static function get_effective_denied_for_cloud(array $all_categories): array {
        $saved_allowed = get_option(Options::ROBOTS_ALLOWED_CATEGORIES, null);

        if (!empty($all_categories)) {
            if ($saved_allowed === null || $saved_allowed === false) {
                return self::DEFAULT_DENIED_CATEGORIES;
            }
            $allowed = is_array($saved_allowed) ? $saved_allowed : [];
            return array_diff(array_keys($all_categories), $allowed);
        } else {
            if ($saved_allowed === null || $saved_allowed === false) {
                return self::DEFAULT_DENIED_CATEGORIES;
            }
            $allowed = is_array($saved_allowed) ? $saved_allowed : [];
            return array_diff(self::DEFAULT_KNOWN_CATEGORIES, $allowed);
        }
    }

    /**
     * Records a durable last-refresh outcome — survives the daily
     * cron's 60-second error transient so admins see a stale-cache
     * indicator on the next admin-page load.
     */
    private static function record_last_refresh($status, $message, $http_status = null) {
        update_option(Options::ROBOTS_LAST_REFRESH, [
            'status' => $status,
            'timestamp' => time(),
            'message' => $message,
            'http_status' => $http_status,
        ]);
    }

    private static function http_status_from_exception(\Throwable $e) {
        if ($e instanceof CloudRequestException) {
            return $e->httpStatusCode;
        }
        return null;
    }

    public static function fetch_from_cloud() {
        $resource_key = get_option(Options::RESOURCE_KEY, '');
        if (empty($resource_key)) {
            return false;
        }

        $all_categories = FiftyOneDegreesCloudMetadata::fetch_crawler_usage_values();
        $effective_denied = self::get_effective_denied_for_cloud($all_categories);
        $params = ['resource' => $resource_key];

        if (!empty($all_categories)) {
            foreach (array_keys($all_categories) as $cat) {
                $val = in_array($cat, $effective_denied) ? 'disallow' : 'allow';
                $params['robotstxt.' . strtolower($cat)] = $val;
            }
        } else {
            foreach ($effective_denied as $cat) {
                $params['robotstxt.' . strtolower($cat)] = 'disallow';
            }
        }

        $tdl_values = self::get_effective_tdl_values();
        if (!empty($tdl_values)) {
            $params['robotstxt.tdl'] = implode(',', $tdl_values);
        }

        try {
            $engine = new CloudRequestEngine(['resourceKey' => $resource_key]);
            $url = rtrim($engine->baseURL, '/');
            if (!preg_match('#/json$#', $url)) {
                $url .= '/json';
            }
            $url .= '?' . http_build_query($params);
        } catch (\Throwable $e) {
            error_log('51Degrees robots.txt Cloud API request failed: ' . $e->getMessage());
            set_transient('fiftyonedegrees_robots_cloud_error', $e->getMessage(), 60);
            self::record_last_refresh('error', $e->getMessage(), self::http_status_from_exception($e));
            return false;
        }

        try {
            $body = self::do_cloud_request($url);
        } catch (\Throwable $e) {
            error_log('51Degrees robots.txt Cloud API request failed: ' . $e->getMessage());
            set_transient('fiftyonedegrees_robots_cloud_error', $e->getMessage(), 60);
            self::record_last_refresh('error', $e->getMessage(), self::http_status_from_exception($e));
            return false;
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            $msg = 'Invalid JSON response';
            error_log('51Degrees robots.txt Cloud API: ' . $msg);
            set_transient('fiftyonedegrees_robots_cloud_error', $msg, 60);
            self::record_last_refresh('error', $msg, null);
            return false;
        }

        // Discriminator: presence of the `robotstxt` section means the
        // engine ran server-side. Empty plaintext from a present section
        // is a legitimate "no Disallow lines" robots.txt; absence of the
        // section means the engine isn't advertised by the resource key.
        if (!isset($data['robotstxt'])) {
            $msg = 'Cloud response did not include robots.txt content — check resource key permissions';
            error_log('51Degrees robots.txt Cloud API: ' . $msg);
            set_transient('fiftyonedegrees_robots_cloud_error', $msg, 60);
            self::record_last_refresh('error', $msg, null);
            return false;
        }

        $plaintext = isset($data['robotstxt']['plaintext']) ? $data['robotstxt']['plaintext'] : '';
        $annotatedtext = isset($data['robotstxt']['annotatedtext']) ? $data['robotstxt']['annotatedtext'] : '';

        delete_transient('fiftyonedegrees_robots_cloud_error');
        update_option(Options::ROBOTS_PLAINTEXT_CACHE, $plaintext);
        update_option(Options::ROBOTS_ANNOTATEDTEXT_CACHE, $annotatedtext);
        self::record_last_refresh('success', 'Updated', null);

        return true;
    }

    protected static function do_cloud_request(string $url): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        if (!empty($ip)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'client-ip=' . urlencode($ip);
        }
        $httpClient = new HttpClient();
        return $httpClient->makeCloudRequest('GET', $url, null, null);
    }

    public static function generate_robots_txt_content($public, $annotated = false) {
        $result = '';
        $custom_top = get_option(Options::ROBOTS_CUSTOM_TOP, '');
        $custom_bottom = get_option(Options::ROBOTS_CUSTOM_BOTTOM, '');
        $cache_key = $annotated ? Options::ROBOTS_ANNOTATEDTEXT_CACHE : Options::ROBOTS_PLAINTEXT_CACHE;
        $cache = get_option($cache_key, '');

        if (!empty($custom_top)) {
            $result .= rtrim($custom_top) . "\n\n";
        }

        if (!empty($cache)) {
            $result .= $cache;
        }

        if (!empty($custom_bottom)) {
            $result .= rtrim($custom_bottom) . "\n";
        }

        return $result;
    }

    public static function generate_robots_txt($output, $public) {
        if (get_option(Options::ROBOTS_ENABLE, 'off') !== 'on') {
            return $output;
        }

        $content = self::generate_robots_txt_content($public);

        if (empty(trim($content)) && !$public) {
            return $output;
        }

        return $content;
    }

    public static function enforce_crawler_redirect() {
        if (get_option(Options::ROBOTS_ENFORCE, 'off') !== 'on') {
            return;
        }

        if (get_option(Options::PIPELINE_ENABLE, 'on') !== 'on') {
            return;
        }

        if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (function_exists('wp_doing_cron') && wp_doing_cron())) {
            return;
        }

        if (php_sapi_name() === 'cli') {
            return;
        }

        $is_crawler = Pipeline::get('device', 'iscrawler');
        if ($is_crawler !== true) {
            return;
        }

        $saved_allowed = get_option(Options::ROBOTS_ALLOWED_CATEGORIES, null);
        if ($saved_allowed === null || $saved_allowed === false) {
            return;
        }

        if (!FiftyOneDegreesCloudMetadata::supports_crawler_usage()) {
            return;
        }

        $crawler_usage = Pipeline::get('device', 'crawlerusage');
        if (!is_array($crawler_usage) || empty($crawler_usage)) {
            return;
        }

        $allowed = is_array($saved_allowed) ? $saved_allowed : [];
        if (empty(array_diff($crawler_usage, $allowed))) {
            return;
        }

        $redirect_url = get_option(Options::ROBOTS_REDIRECT_URL, '');
        if (!empty($redirect_url)) {
            $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
            $current_url = home_url($request_uri);
            if (trailingslashit($current_url) === trailingslashit($redirect_url)) {
                return;
            }
            if (function_exists('url_to_postid')) {
                $target_id = url_to_postid($redirect_url);
                if ($target_id > 0 && url_to_postid($current_url) === $target_id) {
                    return;
                }
            }
            wp_redirect($redirect_url, 302);
            exit;
        } else {
            nocache_headers();
            $title   = esc_html(FiftyOneDegreesStrings::get('robots.bot_denied.title'));
            $message = esc_html(FiftyOneDegreesStrings::get('robots.bot_denied.message'));
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . $title . '</title></head>'
               . '<body><p>' . $message . '</p></body></html>';
            exit;
        }
    }
}

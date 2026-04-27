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
        add_action('fiftyonedegrees_refresh_standard_tdls', [__CLASS__, 'refresh_standard_tdls_cron']);
    }

    public static function refresh_robots_txt_cron() {
        if (get_option(Options::ROBOTS_ENABLE, 'off') !== 'on') {
            return;
        }
        if (!FiftyOneDegreesRobotsTxt::fetch_from_cloud()) {
            error_log('51Degrees: robots.txt cron refresh failed');
        }
    }

    public static function refresh_standard_tdls_cron() {
        $entries = FiftyOneDegreesStandardTdls::load();
        if (empty($entries)) {
            return;
        }

        $urls    = get_option(Options::ROBOTS_STANDARD_TDL_URLS, []);
        $changed = false;

        foreach ($entries as $entry) {
            $id      = $entry['id'];
            $current = isset($urls[$id]) ? $urls[$id] : $entry['url'];

            // Extract current version (extension-agnostic)
            $current_version = self::extract_version($current);
            if ($current_version === null) {
                continue;
            }
            $next_version = $current_version + 1;

            $next_url = self::replace_version($current, $next_version);

            $response = wp_remote_head($next_url, ['timeout' => 10]);

            if (is_wp_error($response)) {
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 200 && $code < 300) {
                $urls[$id] = $next_url;
                $changed   = true;
            }
        }

        if ($changed) {
            update_option(Options::ROBOTS_STANDARD_TDL_URLS, $urls);
        }
    }

    private static function extract_version(string $url) {
        $path = parse_url($url, PHP_URL_PATH);

        if (!$path) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        $last = end($segments);

        // extract leading number from last segment
        if (preg_match('/^(\d+)/', $last, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private static function replace_version(string $url, int $nextVersion) {
        $parts = parse_url($url);

        if (!isset($parts['path'])) {
            return $url;
        }

        $segments = explode('/', trim($parts['path'], '/'));
        $lastIndex = count($segments) - 1;

        // replace only numeric prefix of last segment
        $segments[$lastIndex] = preg_replace(
            '/^\d+/',
            (string) $nextVersion,
            $segments[$lastIndex]
        );

        $newPath = '/' . implode('/', $segments);

        return
            ($parts['scheme'] ?? 'https') . '://' .
            ($parts['host'] ?? '') .
            $newPath;
    }

    public static function get_effective_tdl_urls() {
        $selected = get_option(Options::ROBOTS_STANDARD_TDL_SELECTED, []);
        $std_urls = get_option(Options::ROBOTS_STANDARD_TDL_URLS, []);
        $config   = FiftyOneDegreesStandardTdls::load();
        $custom   = get_option(Options::ROBOTS_CUSTOM_TDL, []);

        if (!is_array($selected)) {
            $selected = [];
        }
        if (!is_array($std_urls)) {
            $std_urls = [];
        }
        if (!is_array($custom)) {
            $custom = [];
        }

        $urls = [];
        foreach ($selected as $id) {
            if (isset($std_urls[$id])) {
                $urls[] = $std_urls[$id];
            } else {
                foreach ($config as $entry) {
                    if ($entry['id'] === $id) {
                        $urls[] = $entry['url'];
                        break;
                    }
                }
            }
        }

        return array_merge($urls, $custom);
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

        $tdl_urls = self::get_effective_tdl_urls();
        if (!empty($tdl_urls)) {
            $params['robotstxt.tdl'] = implode(',', $tdl_urls);
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
            return false;
        }

        try {
            $body = self::do_cloud_request($url);
        } catch (\Throwable $e) {
            error_log('51Degrees robots.txt Cloud API request failed: ' . $e->getMessage());
            set_transient('fiftyonedegrees_robots_cloud_error', $e->getMessage(), 60);
            return false;
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            error_log('51Degrees robots.txt Cloud API: invalid JSON response');
            set_transient('fiftyonedegrees_robots_cloud_error', 'Invalid JSON response', 60);
            return false;
        }

        $plaintext = isset($data['robotstxt']['plaintext']) ? $data['robotstxt']['plaintext'] : '';
        $annotatedtext = isset($data['robotstxt']['annotatedtext']) ? $data['robotstxt']['annotatedtext'] : '';

        delete_transient('fiftyonedegrees_robots_cloud_error');
        update_option(Options::ROBOTS_PLAINTEXT_CACHE, $plaintext);
        update_option(Options::ROBOTS_ANNOTATEDTEXT_CACHE, $annotatedtext);

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

    public static function parse_robots_txt_to_dict(string $content): array {
        if (empty(trim($content))) {
            return [];
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $blocks = preg_split('/\n\s*\n/', trim($content));
        $dict = [];

        foreach ($blocks as $block) {
            $lines = explode("\n", $block);
            $user_agents = [];
            $rules = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                if (stripos($line, 'User-agent:') === 0) {
                    $ua = trim(substr($line, strlen('User-agent:')));
                    if ($ua !== '') {
                        $user_agents[] = strtolower($ua);
                    }
                } elseif (stripos($line, 'Allow:') === 0) {
                    $path = trim(substr($line, strlen('Allow:')));
                    if ($path !== '') {
                        $rules[$path] = true;
                    }
                } elseif (stripos($line, 'Disallow:') === 0) {
                    $path = trim(substr($line, strlen('Disallow:')));
                    if ($path !== '') {
                        $rules[$path] = false;
                    }
                }
            }

            if (empty($user_agents) || empty($rules)) {
                continue;
            }

            foreach ($user_agents as $ua) {
                if (!isset($dict[$ua])) {
                    $dict[$ua] = [];
                }
                foreach ($rules as $path => $allowed) {
                    $dict[$ua][$path] = $allowed;
                }
            }
        }

        return $dict;
    }

    public static function build_enforcement_dict(): array {
        $cache = get_option(Options::ROBOTS_PLAINTEXT_CACHE, '');
        $dict = self::parse_robots_txt_to_dict($cache);

        $custom = trim(
            get_option(Options::ROBOTS_CUSTOM_TOP, '') . "\n\n" .
            get_option(Options::ROBOTS_CUSTOM_BOTTOM, '')
        );

        if (!empty($custom)) {
            $custom_dict = self::parse_robots_txt_to_dict($custom);
            foreach ($custom_dict as $ua => $rules) {
                if (!isset($dict[$ua])) {
                    $dict[$ua] = [];
                }
                foreach ($rules as $path => $allowed) {
                    $dict[$ua][$path] = $allowed;
                }
            }
        }

        return $dict;
    }

    public static function check_path_allowed(array $dict, string $ua, string $request_path): ?bool {
        if (empty($dict)) {
            return null;
        }

        $request_path = explode('?', $request_path, 2)[0];
        if ($request_path === '' || $request_path[0] !== '/') {
            $request_path = '/' . $request_path;
        }

        $ua_lower = strtolower($ua);
        $ua_key = '*';
        foreach (array_keys($dict) as $token) {
            if ($token !== '' && $token !== '*' && strpos($ua_lower, $token) !== false
                && ($ua_key === '*' || strlen($token) > strlen($ua_key))) {
                $ua_key = $token;
            }
        }
        if (!isset($dict[$ua_key])) {
            return null;
        }

        $best_len = -1;
        $best_result = null;

        foreach ($dict[$ua_key] as $rule_path => $allowed) {
            if (strpos($request_path, $rule_path) === 0) {
                $len = strlen($rule_path);
                if ($len > $best_len) {
                    $best_len = $len;
                    $best_result = $allowed;
                }
            }
        }

        return $best_result;
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

        $dict = self::build_enforcement_dict();
        if (empty($dict)) {
            return;
        }

        $saved_allowed = get_option(Options::ROBOTS_ALLOWED_CATEGORIES, null);
        if ($saved_allowed === null || $saved_allowed === false) {
            return;
        }

        $redirect_url = get_option(Options::ROBOTS_REDIRECT_URL, '');

        $supports_crawler_usage = FiftyOneDegreesCloudMetadata::supports_crawler_usage();

        if ($supports_crawler_usage) {
            $crawler_usage = Pipeline::get('device', 'crawlerusage');
            if (!is_array($crawler_usage)) {
                $crawler_usage = [];
            }
            // Allow crawlers with empty crawler_usage to pass through to dict check
            if (!empty($crawler_usage)) {
                $allowed = is_array($saved_allowed) ? $saved_allowed : [];
                $match = array_diff($crawler_usage, $allowed);
                if (empty($match)) {
                    return;
                }
            }
        }

        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $request_path = explode('?', $request_uri, 2)[0];
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';
        $path_result = self::check_path_allowed($dict, $ua, $request_path);
        if ($path_result !== false) {
            return;
        }

        if (!empty($redirect_url)) {
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

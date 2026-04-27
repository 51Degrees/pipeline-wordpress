<?php

require_once __DIR__ . '/../options.php';

use fiftyone\pipeline\cloudrequestengine\CloudRequestException;
use fiftyone\pipeline\cloudrequestengine\Constants;
use fiftyone\pipeline\cloudrequestengine\HttpClient;

class FiftyOneDegreesCloudMetadata {

    private static function get_cloud_host_url(): string {
        $apiUrl = getenv(Constants::FOD_CLOUD_API_URL);
        if (!empty($apiUrl)) {
            $parsed = parse_url(rtrim($apiUrl, '/'));
            $scheme = $parsed['scheme'] ?? 'https';
            $host   = $parsed['host']   ?? 'cloud.51degrees.com';
            $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            return $scheme . '://' . $host . $port;
        }
        return 'https://cloud.51degrees.com';
    }

    protected static function do_cloud_request(string $url): string {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        if (!empty($ip)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'client-ip=' . urlencode($ip);
        }
        $httpClient = new HttpClient();
        return $httpClient->makeCloudRequest('GET', $url, null, null);
    }

    private static function get_transient_key() {
        return 'fiftyonedegrees_metadata';
    }

    private static function get_failure_key() {
        return 'fiftyonedegrees_metadata_fail';
    }

    public static function invalidate() {
        delete_transient(self::get_transient_key());
        delete_transient(self::get_failure_key());
    }

    public static function fetch_accessible_properties() {
        $transient = get_transient(self::get_transient_key());
        if ($transient !== false) {
            return is_array($transient) ? $transient : [];
        }

        $failData = get_transient(self::get_failure_key());
        if ($failData !== false && is_array($failData)) {
            return [];
        }

        $resourceKey = get_option(Options::RESOURCE_KEY, '');
        if (empty($resourceKey)) {
            return [];
        }

        $url = self::get_cloud_host_url() . '/api/v4/accessibleProperties?resource=' . urlencode($resourceKey);

        try {
            $body = self::do_cloud_request($url);
        } catch (CloudRequestException $e) {
            usleep(150000);
            try {
                $body = self::do_cloud_request($url);
            } catch (CloudRequestException $e) {
                self::cache_failure();
                return [];
            }
        }

        $data = json_decode($body, true);

        if (!$data || !isset($data['Products'])) {
            self::cache_failure();
            return [];
        }

        $properties = [];
        foreach ($data['Products'] as $engineName => $engineData) {
            if (isset($engineData['Properties'])) {
                foreach ($engineData['Properties'] as $prop) {
                    $properties[] = $prop['Name'];
                }
            }
        }

        delete_transient(self::get_failure_key());
        set_transient(self::get_transient_key(), $properties, HOUR_IN_SECONDS);

        return $properties;
    }

    private static function cache_failure() {
        $failData = get_transient(self::get_failure_key());
        $attempts = 1;
        if (is_array($failData) && isset($failData['n'])) {
            $attempts = $failData['n'] + 1;
        }

        $ttl = min(60 * pow(2, $attempts - 1), 3600);
        set_transient(self::get_failure_key(), ['n' => $attempts], $ttl);
    }

    public static function supports_crawler() {
        $props = self::fetch_accessible_properties();
        return in_array('IsCrawler', $props) || in_array('CrawlerUsage', $props);
    }

    public static function supports_crawler_usage() {
        $props = self::fetch_accessible_properties();
        return in_array('CrawlerUsage', $props);
    }

    public static function supports_robots_txt() {
        $props = self::fetch_accessible_properties();
        return in_array('PlainText', $props) || in_array('AnnotatedText', $props);
    }

    private static function get_crawler_usage_transient_key() {
        return 'fiftyonedegrees_crawler_usage_values';
    }

    private static function get_crawler_usage_failure_key() {
        return 'fiftyonedegrees_crawler_usage_fail';
    }

    public static function invalidate_crawler_usage() {
        delete_transient(self::get_crawler_usage_transient_key());
        delete_transient(self::get_crawler_usage_failure_key());
    }

    public static function invalidate_all() {
        self::invalidate();
        self::invalidate_crawler_usage();
    }

    public static function fetch_crawler_usage_values() {
        $transient = get_transient(self::get_crawler_usage_transient_key());
        if ($transient !== false) {
            return is_array($transient) ? $transient : [];
        }

        $failData = get_transient(self::get_crawler_usage_failure_key());
        if ($failData !== false && is_array($failData)) {
            return [];
        }

        $url = self::get_cloud_host_url() . '/api/metadata/values?propertyName=CrawlerUsage';

        try {
            $body = self::do_cloud_request($url);
        } catch (CloudRequestException $e) {
            usleep(150000);
            try {
                $body = self::do_cloud_request($url);
            } catch (CloudRequestException $e) {
                self::cache_crawler_usage_failure();
                return [];
            }
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            self::cache_crawler_usage_failure();
            return [];
        }

        $values = [];
        foreach ($data as $item) {
            if (isset($item['Name']) && $item['Name'] !== '' && $item['Name'] !== 'N/A') {
                $values[$item['Name']] = isset($item['Description']) ? $item['Description'] : '';
            }
        }

        delete_transient(self::get_crawler_usage_failure_key());
        set_transient(self::get_crawler_usage_transient_key(), $values, DAY_IN_SECONDS);

        return $values;
    }

    private static function cache_crawler_usage_failure() {
        $failData = get_transient(self::get_crawler_usage_failure_key());
        $attempts = 1;
        if (is_array($failData) && isset($failData['n'])) {
            $attempts = $failData['n'] + 1;
        }

        $ttl = min(60 * pow(2, $attempts - 1), 3600);
        set_transient(self::get_crawler_usage_failure_key(), ['n' => $attempts], $ttl);
    }
}

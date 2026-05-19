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

require_once __DIR__ . '/../options.php';
require_once __DIR__ . '/client-ip.php';

/**
 * Suspicious activity detection engine.
 *
 * Tracks per-visitor request rates using WordPress transients and
 * redirects visitors who exceed a configured threshold.
 *

 */
class SuspiciousActivity
{
    /**
     * Registers the parse_request hook for suspicious activity checking.
     * Priority 11 ensures rest_api_loaded (priority 10) runs first: for
     * REST requests it defines REST_REQUEST and exits, so our hook never
     * fires for them. For non-REST requests REST_REQUEST remains
     * undefined, which the exclusion check handles.
     *
     * @access public
     *
     * @return void
     */
    public static function register()
    {
        add_action('parse_request', [__CLASS__, 'check_and_maybe_redirect'], 11);
    }

    /**
     * Main entry point called on parse_request at priority 11.
     * Checks exclusions, records the request, and redirects if threshold met.
     *
     * @access public
    
     * @return void
     */
    public static function check_and_maybe_redirect()
    {
        if (get_option(Options::SUSPICIOUS_ENABLE, 'off') !== 'on') {
            return;
        }

        if (self::is_excluded_context()) {
            return;
        }

        $window = (int) get_option(Options::SUSPICIOUS_WINDOW, 30);
        $threshold = (int) get_option(Options::SUSPICIOUS_REQUESTS, 5);

        if ($window <= 0 || $threshold <= 0) {
            return;
        }

        if (self::is_on_redirect_target()) {
            return;
        }

        if (headers_sent()) {
            error_log('Suspicious activity: cannot redirect, headers already sent');
            return;
        }

        $did = self::get_51did();
        $key = self::build_transient_key($did);
        $now = microtime(true);

        $timestamps = get_transient($key);
        if (!is_array($timestamps)) {
            $timestamps = [];
        }

        // Prune expired entries.
        $timestamps = array_values(array_filter(
            $timestamps,
            function ($t) use ($now, $window) {
                return $t >= $now - $window;
            }
        ));

        $timestamps[] = $now;
        set_transient($key, $timestamps, $window);

        if (count($timestamps) >= $threshold) {
            $target = get_option(Options::SUSPICIOUS_REDIRECT_URL);
            if (!$target) {
                return;
            }
            wp_safe_redirect($target, 302);
            exit;
        }
    }

    /**
     * Resolves the visitor identity.
     * Tries IdProbLic then IdProbGlobal from the pipeline, falling back
     * to a SHA-256 hash of IP + User-Agent.
     *
     * @access public
    
     * @return string visitor identifier (always returns a value)
     */
    public static function get_51did()
    {
        $properties = Pipeline::$data['properties'] ?? [];

        foreach (['idproblic', 'idprobglobal'] as $propName) {
            foreach ($properties as $engine => $props) {
                if (isset($props[$propName])) {
                    $value = Pipeline::get($engine, $propName);
                    if (is_string($value) && $value !== '') {
                        $identifier = self::extract_owid_identifier($value);
                        if ($identifier !== null) {
                            return $identifier;
                        }
                    }
                }
            }
        }

        $ip = ClientIpResolver::resolve() ?: '127.0.0.1';
        $ua = sanitize_text_field(
            wp_unslash(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '')
        );
        return hash('sha256', $ip . '|' . $ua);
    }

    /**
     * Extracts the 32-byte Identifier (bytes 21..52) from a base64-encoded
     * OWID v3 token and returns it as a 64-char hex string.
     *
     * The envelope's ECDSA signature is regenerated on every request
     * (random nonce), so hashing the full token would produce a new
     * tracking key per request. The Identifier is the SHA-256 hash the
     * cloud computed from visitor features and is stable per visitor.
     *
     * @access public
     *
     * @param  mixed       $token base64-encoded OWID token
     * @return string|null 64-char hex identifier, or null on malformed input
     */
    public static function extract_owid_identifier($token)
    {
        if (!is_string($token) || $token === '') {
            return null;
        }
        $normalized = strtr($token, '-_', '+/');
        $pad = (4 - strlen($normalized) % 4) % 4;
        $decoded = base64_decode($normalized . str_repeat('=', $pad), true);
        if (!is_string($decoded) || strlen($decoded) !== 117) {
            return null;
        }
        if (ord($decoded[0]) !== 3) {
            return null;
        }
        return bin2hex(substr($decoded, 21, 32));
    }

    /**
     * Finds the engine dataKey that exposes IdProbLic or IdProbGlobal.
     * Used by the admin UI to display the active tracking mode.
     *
     * @access public
    
     * @return string|null engine dataKey, or null if not available
     */
    public static function id_engine_datakey()
    {
        $properties = Pipeline::$data['properties'] ?? [];
        foreach ($properties as $engine => $props) {
            if (isset($props['idproblic']) || isset($props['idprobglobal'])) {
                return $engine;
            }
        }
        return null;
    }

    /**
     * Builds the transient key for a given visitor identity.
     *
     * @access public
    
     * @param  string $did visitor identifier
     * @return string transient key (47 chars: "51d_suspicious_" + md5)
     */
    public static function build_transient_key($did)
    {
        return '51d_suspicious_' . md5($did);
    }

    /**
     * Returns true if the current request context should be excluded
     * from suspicious activity tracking.
     *
     * @access private
    
     * @return bool
     */
    private static function is_excluded_context()
    {
        if (is_admin()) {
            return true;
        }

        if (wp_doing_ajax()) {
            return true;
        }

        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return true;
        }

        if (php_sapi_name() === 'cli') {
            return true;
        }

        $script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
        if (in_array($script, ['wp-login.php', 'wp-signup.php', 'wp-activate.php'], true)) {
            return true;
        }

        // /robots.txt is metadata for crawlers; counting it as suspicious
        // activity redirects the bot away from the policy it was asked
        // to fetch — defeating the paired robots-enforce feature.
        $path = wp_parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
        if (is_string($path) && strtolower(rtrim($path, '/')) === '/robots.txt') {
            return true;
        }

        return false;
    }

    /**
     * Returns true if the current request is for the suspicious redirect
     * target or the robots.txt bot redirect target. Skipping both prevents
     * loops with the suspicious feature itself and the robots.txt feature
     * (a bot redirected to its destination must not then be redirected
     * onward as suspicious).
     *
     * Resolves URLs to a WordPress post ID. This catches the case where
     * the target page is the static front page: WordPress canonicalises
     * /page-slug/ to / for the home request, and a plain path comparison
     * would treat them as different and loop.
     *
     * @access private
     *
     * @return bool
     */
    private static function is_on_redirect_target()
    {
        $targets = array_filter([
            get_option(Options::SUSPICIOUS_REDIRECT_URL),
            get_option(Options::ROBOTS_REDIRECT_URL),
        ]);
        if (empty($targets)) {
            return false;
        }

        $current_uri = $_SERVER['REQUEST_URI'] ?? '/';
        $current_path = trailingslashit(strtok($current_uri, '?'));
        $current_id = function_exists('url_to_postid') && function_exists('home_url')
            ? url_to_postid(home_url($current_uri))
            : 0;

        foreach ($targets as $target) {
            if ($current_id > 0) {
                $target_id = url_to_postid($target);
                if ($target_id > 0 && $current_id === $target_id) {
                    return true;
                }
            }
            $target_path = trailingslashit(wp_parse_url($target, PHP_URL_PATH) ?: '/');
            if ($current_path === $target_path) {
                return true;
            }
        }

        return false;
    }

    public static function delete_options() {
        delete_option(Options::SUSPICIOUS_ENABLE);
        delete_option(Options::SUSPICIOUS_REDIRECT_URL);
        delete_option(Options::SUSPICIOUS_REQUESTS);
        delete_option(Options::SUSPICIOUS_WINDOW);
    }
}

if (function_exists('add_action')) {
    SuspiciousActivity::register();
}

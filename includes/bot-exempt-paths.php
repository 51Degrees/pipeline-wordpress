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

if (!function_exists('fiftyonedegrees_is_bot_exempt_path')) {
    /**
     * Returns true if the request path is a bot-facing policy file that must
     * stay reachable to crawlers, so it is exempt from both suspicious-activity
     * throttling and robots.txt crawler-category enforcement (issue #69).
     *
     * Beyond /robots.txt itself: /ads.txt and /app-ads.txt (IAB authorized
     * digital sellers files) and everything under /.well-known/ (RFC 8615,
     * e.g. security.txt) are documents bots are meant to read; gating them
     * defeats the very policies they advertise.
     *
     * @param  string $request_uri raw request URI (path + optional query)
     * @return bool
     */
    function fiftyonedegrees_is_bot_exempt_path($request_uri)
    {
        $path = wp_parse_url(is_string($request_uri) ? $request_uri : '/', PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }
        $path = strtolower(rtrim($path, '/'));

        if (in_array($path, ['/robots.txt', '/ads.txt', '/app-ads.txt'], true)) {
            return true;
        }

        return $path === '/.well-known' || strpos($path, '/.well-known/') === 0;
    }
}

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

require_once __DIR__ . '/cloud-metadata.php';

/**
 * Thin client for the 51Degrees Google OAuth relay.
 *
 * The relay owns the single shared Google client and its secret. This plugin
 * never holds the client secret and never talks to Google's token endpoint
 * directly. It instead:
 *
 *   - sends the admin to {relay}/api/v4/oauth/start (carrying the PKCE
 *     code_challenge, the resource key, the site callback and the signed
 *     state) — the relay builds the Google consent URL and, after consent,
 *     forwards the code + state back to the site callback;
 *   - exchanges the forwarded code at {relay}/api/v4/oauth/exchange,
 *     presenting the PKCE code_verifier (the relay adds the secret);
 *   - refreshes an expired access token at {relay}/api/v4/oauth/refresh.
 *
 * Single source of truth for the relay base URL and the endpoint paths, so the
 * three call sites (start, callback, ga-service) cannot drift. The relay lives
 * on the same host as the cloud API, so its base is derived from the cloud API
 * URL the plugin already uses.
 */
class FiftyOneDegreesOauthRelayClient
{
    public const START_PATH    = '/api/v4/oauth/start';
    public const EXCHANGE_PATH = '/api/v4/oauth/exchange';
    public const REFRESH_PATH  = '/api/v4/oauth/refresh';

    /**
     * The relay origin, e.g. https://cloud.51degrees.com. The relay is part of
     * the cloud service and in production shares a host with the device-
     * detection API, so the default is taken from FOD_CLOUD_API_URL (same
     * source as cloud-metadata). FOD_OAUTH_RELAY_URL overrides only this base
     * for local development where the relay is rolled to a staging host
     * (e.g. an Azure app service) ahead of the rest of the v4 API.
     *
     * @return string
     */
    public static function base()
    {
        $override = getenv('FOD_OAUTH_RELAY_URL');
        if (is_string($override) && $override !== '') {
            $parsed = parse_url(rtrim($override, '/'));
            $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
            $host = isset($parsed['host']) ? $parsed['host'] : '';
            $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
            if ($host !== '') {
                return $scheme . '://' . $host . $port;
            }
        }
        return FiftyOneDegreesCloudMetadata::get_cloud_host_url();
    }

    /**
     * Builds the relay /start URL. The relay re-signs its own state around
     * our $state value (carried as `nonce`) and echoes it back verbatim on
     * the callback, so our own verify_state still sees the state we issued.
     *
     * @param string $resource     the site's 51Degrees resource key
     * @param string $redirect_uri the site callback the relay forwards to
     * @param string $scope        the Google scope(s) requested
     * @param string $state        our signed state string (echoed back)
     * @param string $challenge    the PKCE S256 code challenge
     * @return string
     */
    public static function start_url($resource, $redirect_uri, $scope, $state, $challenge)
    {
        $query = http_build_query([
            'resource'              => $resource,
            'redirect_uri'          => $redirect_uri,
            'scope'                 => $scope,
            'nonce'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return self::base() . self::START_PATH . '?' . $query;
    }

    /**
     * Exchanges a forwarded authorization code for a token set, presenting
     * the PKCE verifier. The relay adds the client secret.
     *
     * @param string      $code          the authorization code
     * @param string      $resource      the site's resource key
     * @param null|string $code_verifier the PKCE verifier (S256 pre-image)
     * @return array decoded token array on success, or ['error' => slug]
     */
    public static function exchange($code, $resource, $code_verifier)
    {
        return self::post(self::EXCHANGE_PATH, [
            'code'         => $code,
            'resource'     => $resource,
            'codeVerifier' => $code_verifier,
        ]);
    }

    /**
     * Mints a fresh access token from a stored refresh token. Google's
     * refresh grant does not return a new refresh token; the caller keeps
     * the existing one.
     *
     * @param string $refresh_token the stored refresh token
     * @param string $resource      the site's resource key
     * @return array decoded token array on success, or ['error' => slug]
     */
    public static function refresh($refresh_token, $resource)
    {
        return self::post(self::REFRESH_PATH, [
            'refreshToken' => $refresh_token,
            'resource'     => $resource,
        ]);
    }

    /**
     * POSTs a JSON body to a relay endpoint and decodes the JSON response.
     * Any transport error, non-2xx status, or malformed body collapses to
     * an ['error' => ...] array so callers can treat failures uniformly
     * (mirroring the Google client's error-array convention). Tokens are
     * never logged here.
     *
     * @param string $path the endpoint path (one of the *_PATH constants)
     * @param array  $body the request payload
     * @return array
     */
    protected static function post($path, array $body)
    {
        $response = wp_remote_post(self::base() . $path, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status < 200 || $status >= 300) {
            return ['error' => is_array($data) && isset($data['error'])
                ? (string) $data['error']
                : ('http_' . $status)];
        }

        if (!is_array($data) || !isset($data['access_token'])) {
            return ['error' => 'invalid_relay_response'];
        }

        return $data;
    }
}

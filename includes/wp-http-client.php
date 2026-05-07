<?php
/*
    This Original Work is copyright of 51 Degrees Mobile Experts Limited.
    Copyright 2026 51 Degrees Mobile Experts Limited, Davidson House,
    Forbury Square, Reading, Berkshire, United Kingdom RG1 3EU.

    This Original Work is licensed under the European Union Public Licence
    (EUPL) v.1.2 and is subject to its terms as set out below.

    If a copy of the EUPL was not distributed with this file, You can obtain
    one at https://opensource.org/licenses/EUPL-1.2.
*/

// We extend the upstream HttpClient class — make sure the composer
// autoloader is in scope before our subclass is parsed. The plugin's
// other entry points include this autoloader later (load_includes()),
// but cloud-metadata.php pulls us in earlier than that.
require_once __DIR__ . '/../lib/vendor/autoload.php';

use fiftyone\pipeline\cloudrequestengine\CloudRequestException;
use fiftyone\pipeline\cloudrequestengine\Constants;
use fiftyone\pipeline\cloudrequestengine\HttpClient;

/**
 * HttpClient subclass that routes cloud requests through the WordPress
 * HTTP API (wp_remote_request) instead of raw curl/file_get_contents.
 *
 * Why: the upstream HttpClient calls curl_init/file_get_contents directly,
 * which (a) bypasses WP_PROXY_HOST/WP_PROXY_PORT, the WP-bundled CA bundle,
 * http_request_args filters, and managed-host transports — so it fails on
 * WP Engine, Kinsta, corporate proxies, and stale-CA hosts; and (b) on
 * transport failure curl_exec returns false, which the upstream code feeds
 * into substr() and crashes with a TypeError instead of a useful message.
 *
 * Injected via CloudRequestEngine's `httpClient` settings key, and used
 * directly by the metadata/robots.txt cloud calls.
 */
class FiftyOneDegreesWpHttpClient extends HttpClient
{
    public function makeCloudRequest(string $type, string $url, ?string $content, ?string $originHeader): string
    {
        $args = [
            'method'  => strtoupper($type),
            'timeout' => 10,
            'headers' => [],
        ];
        if (!empty($originHeader)) {
            $args['headers']['Origin'] = $originHeader;
        }
        if ($content !== null && strcasecmp($type, 'POST') === 0) {
            $args['body'] = $content;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new CloudRequestException(
                sprintf(
                    "Transport error contacting cloud service at '%s': %s",
                    $url,
                    $response->get_error_message()
                ),
                0,
                []
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body       = (string) wp_remote_retrieve_body($response);
        $headersObj = wp_remote_retrieve_headers($response);

        // Normalise WP's headers (Requests_Utility_CaseInsensitiveDictionary
        // or array) to the "Name: value" string list shape that
        // parseHeaders() / the upstream contract expect.
        $headerLines = [];
        $iterable    = is_object($headersObj) && method_exists($headersObj, 'getAll')
            ? $headersObj->getAll()
            : (is_array($headersObj) ? $headersObj : []);
        foreach ($iterable as $k => $v) {
            $headerLines[] = $k . ': ' . (is_array($v) ? implode(', ', $v) : (string) $v);
        }

        // Mirror upstream HttpClient::validateResponse (it's private, so we
        // can't call it from a subclass — re-implement using the same
        // Constants for message parity).
        $message = null;
        if ($body !== '') {
            $json = json_decode($body, true);
            if (isset($json['errors']) && count($json['errors'])) {
                $message = implode(',', $json['errors']);
            } elseif ($statusCode !== 200) {
                $message = sprintf(Constants::MESSAGE_ERROR_CODE_RETURNED, $url, $statusCode, $body);
            }
        } else {
            $message = sprintf(Constants::MESSAGE_NO_DATA_IN_RESPONSE, $url);
        }

        if ($message !== null) {
            throw new CloudRequestException(
                sprintf(Constants::EXCEPTION_CLOUD_ERROR, $message),
                $statusCode,
                $this->parseHeaders($headerLines)
            );
        }

        return $body;
    }
}

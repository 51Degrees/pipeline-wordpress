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

require_once __DIR__ . '/ga4-auth-error.php';

/**
 * GA4 Admin API surface for property discovery + Measurement ID lookup.
 *
 * Two endpoints in use:
 *
 *   - accountSummaries.list — flat list of every account the admin
 *     can see, with embedded property summaries; one call returns
 *     enough to render the property dropdown end-to-end.
 *   - properties/{id}/dataStreams.list — called on submit once the
 *     admin picks a property, so we can resolve the property's
 *     first Web data stream and pull its Measurement ID (G-XXXXXXX)
 *     for the frontend gtag snippet.
 *
 * Both methods are static and take a configured
 * Google_Service_GoogleAnalyticsAdmin as their first parameter; the
 * caller is responsible for providing an authenticated admin service
 * (token + refresh-token wiring lives elsewhere). Tests stub the
 * admin service to avoid real HTTP.
 *
 * Error mapping:
 *   - 401 / 403 from the API -> throw FiftyOneDegreesGa4AuthError so
 *     the caller can surface a "reconnect Google Analytics" notice
 *   - any other error -> log + return empty / null so the dropdown
 *     renders without crashing the admin page
 */
class FiftyOneDegreesGa4PropertyService
{
    /**
     * Returns every GA4 property the authenticated admin can access,
     * flattened to one row per property with its parent account
     * context attached for display.
     *
     * Shape per element:
     *   - property_id   (string, numeric e.g. "123456789")
     *   - property_name (string, display name from GA4)
     *   - account_id    (string, numeric e.g. "12345")
     *   - account_name  (string, display name from GA4)
     *
     * Non-auth errors return an empty array (plus error_log). 401 /
     * 403 throws FiftyOneDegreesGa4AuthError — the caller decides
     * whether to surface "reconnect" copy or swallow silently
     * depending on the call context.
     *
     * @param Google_Service_GoogleAnalyticsAdmin $admin
     * @return array<int,array<string,string>>
     * @throws FiftyOneDegreesGa4AuthError on 401 / 403
     */
    public static function list_account_summaries($admin)
    {
        try {
            $response = $admin->accountSummaries->listAccountSummaries();
        }
        catch (Throwable $e) {
            if (FiftyOneDegreesGa4AuthError::matches($e)) {
                error_log(
                    '51Degrees GA4 accountSummaries.list rejected as unauthorized: '
                    . $e->getMessage()
                );
                throw new FiftyOneDegreesGa4AuthError($e->getMessage(), 0, $e);
            }
            error_log(
                '51Degrees GA4 accountSummaries.list failed: '
                . $e->getMessage()
            );
            return [];
        }

        $properties = [];
        $summaries = $response->getAccountSummaries();
        if (empty($summaries)) {
            return [];
        }

        foreach ($summaries as $accountSummary) {
            $accountId   = self::resource_id($accountSummary->getAccount());
            $accountName = (string) $accountSummary->getDisplayName();

            $propertySummaries = $accountSummary->getPropertySummaries();
            if (empty($propertySummaries)) {
                continue;
            }

            foreach ($propertySummaries as $propertySummary) {
                $properties[] = [
                    'property_id'   => self::resource_id($propertySummary->getProperty()),
                    'property_name' => (string) $propertySummary->getDisplayName(),
                    'account_id'    => $accountId,
                    'account_name'  => $accountName,
                ];
            }
        }

        return $properties;
    }

    /**
     * Resolves the first Web data stream on the given property and
     * returns its Measurement ID. Returns null when no Web stream is
     * configured (mobile-app-only property) or a non-auth API error
     * occurs. Throws on 401 / 403 so the caller can surface a
     * reconnect-account notice rather than the misleading
     * no-Web-stream copy.
     *
     * Plan-time decision: mobile-app and iOS streams are deliberately
     * excluded — WordPress is a web context, and an admin who
     * configured only mobile streams is using the wrong integration.
     *
     * @param Google_Service_GoogleAnalyticsAdmin $admin
     * @param string $propertyId numeric ("123456789"), not a resource name
     * @return string|null G-XXXXXXX or null on miss / non-auth error
     * @throws FiftyOneDegreesGa4AuthError on 401 / 403
     */
    public static function get_measurement_id($admin, $propertyId)
    {
        if ($propertyId === '' || $propertyId === null) {
            return null;
        }

        $parent = 'properties/' . $propertyId;

        try {
            $response = $admin->properties_dataStreams
                ->listPropertiesDataStreams($parent);
        }
        catch (Throwable $e) {
            if (FiftyOneDegreesGa4AuthError::matches($e)) {
                error_log(
                    '51Degrees GA4 dataStreams.list rejected as unauthorized for '
                    . $parent . ': ' . $e->getMessage()
                );
                throw new FiftyOneDegreesGa4AuthError($e->getMessage(), 0, $e);
            }
            error_log(
                '51Degrees GA4 dataStreams.list failed for '
                . $parent . ': ' . $e->getMessage()
            );
            return null;
        }

        $streams = $response->getDataStreams();
        if (empty($streams)) {
            error_log(
                '51Degrees GA4: property ' . $propertyId
                . ' has no data streams'
            );
            return null;
        }

        foreach ($streams as $stream) {
            if ($stream->getType() !== 'WEB_DATA_STREAM') {
                continue;
            }
            $webData = $stream->getWebStreamData();
            if ($webData === null) {
                continue;
            }
            $mid = (string) $webData->getMeasurementId();
            if ($mid !== '') {
                return $mid;
            }
        }

        error_log(
            '51Degrees GA4: property ' . $propertyId
            . ' has no Web data stream with a Measurement ID'
        );
        return null;
    }

    /**
     * Extracts the numeric tail of a GA4 resource name like
     * "accounts/12345" or "properties/123456789". Returns the empty
     * string when the input cannot be cleanly parsed (no slash, or
     * trailing slash) — defensive against API shape changes, lets
     * the dropdown render with a blank id rather than a misleading
     * full resource name or a stray empty segment.
     */
    private static function resource_id($resourceName)
    {
        if (!is_string($resourceName) || $resourceName === '') {
            return '';
        }
        $slash = strrpos($resourceName, '/');
        if ($slash === false) {
            return '';
        }
        $tail = substr($resourceName, $slash + 1);
        return ($tail === false) ? '' : $tail;
    }

}

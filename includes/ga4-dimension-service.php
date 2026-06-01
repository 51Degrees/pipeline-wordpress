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
 * GA4 Admin API surface for Custom Dimensions list + create.
 *
 * UA used a numeric `custom_dimension_index` to address a Custom
 * Dimension and a property-wide `customDimensions->insert` call. GA4
 * identifies a Custom Dimension by `parameter_name` (the key sent on
 * the event payload) and `displayName` (the human label shown in
 * reports). The integration creates dimensions with `scope=EVENT`
 * only — `USER` and `ITEM` scopes are out of scope for a
 * device-detection plugin emitting per-pageview event parameters.
 *
 * Created dimensions get a "51Degrees " prefix on their displayName
 * so they remain identifiable in the admin's GA4 console even when
 * the underlying parameter_name overlaps with something the admin
 * defined for their own analytics.
 *
 * GA4 enforces a hard per-property cap (50 in the free tier, 125 for
 * GA4 360). The plugin pre-checks against the free-tier limit before
 * starting any create loop so a property that is one dimension away
 * from the cap does not get a half-finished batch.
 *
 * Auth errors (401 / 403) are surfaced via the shared
 * FiftyOneDegreesGa4AuthError thrown by the property service so the
 * caller can route them to a single "reconnect Google Analytics"
 * notice rather than the misleading "no Web stream" / "create
 * failed" copy.
 */
class FiftyOneDegreesGa4DimensionService
{
    /**
     * Only event-scope dimensions make sense for per-pageview device
     * properties. User-scope would persist across sessions; item-scope
     * is for e-commerce items.
     */
    public const SCOPE = 'EVENT';

    /**
     * Prefix on the GA4 displayName. Keeps plugin-created dimensions
     * visually distinct from any the admin defined themselves and
     * survives the case where a parameter_name collision is benign
     * (admin opted to map their own dim onto the same key).
     */
    public const DISPLAY_NAME_PREFIX = '51Degrees ';

    /**
     * GA4 free-tier per-property limit. The 360 tier raises this to
     * 125 but we conservatively gate against the free-tier value —
     * over-creating would 4xx mid-loop on the 51st create and leave
     * the admin with a half-applied mapping.
     */
    public const MAX_DIMENSIONS_PER_PROPERTY = 50;

    /**
     * Page size requested on listPropertiesCustomDimensions. GA4's
     * documented maximum is 200; our working cap is 50 (free) / 125
     * (360-tier) so 200 fits both in one page and gives headroom
     * for admin-created dimensions that share the property. The
     * service still cross-checks for an unexpected nextPageToken
     * and logs a warning if one ever surfaces — defensive against
     * a future API change that lowers the default page size.
     */
    private const LIST_PAGE_SIZE = 200;

    /**
     * Returns the list of Custom Dimensions configured on the given
     * GA4 property as a flat array of associative rows.
     *
     * Shape per row:
     *   - parameter_name (string)
     *   - display_name   (string)
     *   - scope          (string, "EVENT" / "USER" / etc.)
     *
     * Non-auth API errors return []. Auth errors (401 / 403 / token
     * refresh failures / scope-insufficient — see
     * FiftyOneDegreesGa4AuthError::matches) throw so the caller can
     * surface a reconnect notice.
     *
     * @param Google_Service_GoogleAnalyticsAdmin $admin
     * @param string $propertyId numeric (e.g. "123456789")
     * @return array<int,array<string,string>>
     * @throws FiftyOneDegreesGa4AuthError on auth / scope failure
     */
    public static function list_custom_dimensions($admin, $propertyId)
    {
        if ($propertyId === '' || $propertyId === null) {
            return [];
        }

        $parent = 'properties/' . $propertyId;

        try {
            $response = $admin->properties_customDimensions
                ->listPropertiesCustomDimensions(
                    $parent,
                    ['pageSize' => self::LIST_PAGE_SIZE]
                );
        }
        catch (Throwable $e) {
            if (FiftyOneDegreesGa4AuthError::matches($e)) {
                error_log(
                    '51Degrees GA4 customDimensions.list rejected as unauthorized for '
                    . $parent . ': ' . $e->getMessage()
                );
                throw new FiftyOneDegreesGa4AuthError($e->getMessage(), 0, $e);
            }
            error_log(
                '51Degrees GA4 customDimensions.list failed for '
                . $parent . ': ' . $e->getMessage()
            );
            return [];
        }

        // Defensive: GA4 returning a nextPageToken at pageSize=200
        // would mean the property has 200+ dimensions, which is
        // beyond every known tier cap. Log so a future API-shape
        // change doesn't silently mask a missing-row condition in
        // dimension_exists().
        $token = (string) $response->getNextPageToken();
        if ($token !== '') {
            error_log(
                '51Degrees GA4 customDimensions.list returned '
                . 'a nextPageToken for ' . $parent . ' (page size '
                . self::LIST_PAGE_SIZE . '); idempotency check '
                . 'and pre-flight count may be incomplete'
            );
        }

        $dimensions = $response->getCustomDimensions();
        if (empty($dimensions)) {
            return [];
        }

        $rows = [];
        foreach ($dimensions as $dimension) {
            $rows[] = [
                'parameter_name' => (string) $dimension->getParameterName(),
                'display_name'   => (string) $dimension->getDisplayName(),
                'scope'          => (string) $dimension->getScope(),
            ];
        }
        return $rows;
    }

    /**
     * Creates a single GA4 Custom Dimension on the given property.
     * Idempotent in one specific sense: a 409 ALREADY_EXISTS that
     * matches the parameter_name + EVENT-scope we are creating is
     * treated as success (we re-fetch the list to verify). A 409
     * that does not match is treated as a real conflict (different
     * scope, or a sibling row with the same display name but a
     * different parameter_name) — logged and reported as failure.
     *
     * Known best-effort case: a concurrent delete by another admin
     * between this create's 409 and the recovery refetch will look
     * indistinguishable from a real non-matching conflict (the
     * dimension is no longer there). Re-running the create on the
     * next admin save resolves to a clean success in that case, so
     * the worst outcome is one transient false-failure notice.
     *
     * Input validation: all three string arguments are required to
     * be non-empty after trim; whitespace-only inputs would emit
     * either an invalid payload (e.g. displayName="51Degrees ") or
     * an opaque GA4 INVALID_ARGUMENT and are refused locally.
     *
     * @param Google_Service_GoogleAnalyticsAdmin $admin
     * @param string $propertyId numeric (e.g. "123456789")
     * @param string $parameterName GA4 event-parameter key, e.g. "device_type"
     * @param string $displayLabel human label that will be prefixed
     *                              with "51Degrees " in GA4, e.g.
     *                              "DeviceType" -> "51Degrees DeviceType"
     * @return bool true on success or idempotent re-create; false on
     *              real conflict or non-auth API failure
     * @throws FiftyOneDegreesGa4AuthError on auth / scope failure
     */
    public static function create_custom_dimension(
        $admin,
        $propertyId,
        $parameterName,
        $displayLabel
    ) {
        $propertyId    = is_scalar($propertyId)    ? trim((string) $propertyId)    : '';
        $parameterName = is_scalar($parameterName) ? trim((string) $parameterName) : '';
        $displayLabel  = is_scalar($displayLabel)  ? trim((string) $displayLabel)  : '';

        if ($propertyId === '' || $parameterName === '' || $displayLabel === '') {
            return false;
        }

        $parent  = 'properties/' . $propertyId;
        $payload = new Google_Service_GoogleAnalyticsAdmin_GoogleAnalyticsAdminV1betaCustomDimension();
        $payload->setParameterName($parameterName);
        $payload->setDisplayName(self::DISPLAY_NAME_PREFIX . $displayLabel);
        $payload->setScope(self::SCOPE);

        try {
            $admin->properties_customDimensions->create($parent, $payload);
            return true;
        }
        catch (Throwable $e) {
            if (FiftyOneDegreesGa4AuthError::matches($e)) {
                error_log(
                    '51Degrees GA4 customDimensions.create rejected as unauthorized for '
                    . $parent . ': ' . $e->getMessage()
                );
                throw new FiftyOneDegreesGa4AuthError($e->getMessage(), 0, $e);
            }

            if ($e->getCode() === 409) {
                // 409 from the GA4 Admin API can mean either:
                //   (a) the exact (parameter_name, EVENT) pair we
                //       just asked for already exists (idempotent
                //       re-create — admin re-saved the CD screen)
                //   (b) a different conflict — e.g. a USER-scope
                //       dimension with the same parameter_name, or
                //       a displayName collision under our prefix.
                // (a) is the common case; we re-fetch the list and
                // promote it to success if our (param, EVENT) pair
                // is now present. (b) we log and surface as failure
                // so the admin can resolve at the GA4 console.
                if (self::dimension_exists($admin, $propertyId, $parameterName)) {
                    return true;
                }
                error_log(
                    '51Degrees GA4 customDimensions.create 409 for '
                    . $parent . ' parameter ' . $parameterName
                    . ' did not resolve to an existing matching dimension: '
                    . $e->getMessage()
                );
                return false;
            }

            error_log(
                '51Degrees GA4 customDimensions.create failed for '
                . $parent . ' parameter ' . $parameterName
                . ': ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Pre-flight capacity check: returns true when creating
     * $new_count additional dimensions on a property that already
     * has $existing_count would exceed the per-property cap.
     *
     * Exposed so a caller orchestrating a batch create can refuse
     * to start the loop and surface a single "would exceed 50 CDs"
     * notice rather than failing mid-flight on the 51st create.
     */
    public static function would_exceed_limit($existing_count, $new_count)
    {
        return ($existing_count + $new_count) > self::MAX_DIMENSIONS_PER_PROPERTY;
    }

    /**
     * Verifies that a (parameter_name, EVENT-scope) dimension now
     * exists on the property — used to resolve a 409 from create()
     * as idempotent success rather than failure.
     *
     * Behaviour notes:
     *   - Auth errors (401 / 403 / refresh-failure / scope-insufficient)
     *     propagate as FiftyOneDegreesGa4AuthError because
     *     list_custom_dimensions re-throws them.
     *   - Non-auth list failures (transient 5xx, network timeout)
     *     return [] from list_custom_dimensions, which means this
     *     helper returns false and the calling create() reports a
     *     real conflict. That conflates "verification unavailable"
     *     with "definitely doesn't exist"; the false-failure is
     *     surfaced with an extra error_log marker at the call site
     *     so the admin can correlate a "create failed" notice with
     *     the underlying list-failed log line. Re-running the
     *     create on the next save resolves cleanly via the same
     *     409-idempotent path.
     */
    private static function dimension_exists($admin, $propertyId, $parameterName)
    {
        $existing = self::list_custom_dimensions($admin, $propertyId);
        foreach ($existing as $row) {
            if ($row['parameter_name'] === $parameterName
                && $row['scope'] === self::SCOPE
            ) {
                return true;
            }
        }
        return false;
    }

}

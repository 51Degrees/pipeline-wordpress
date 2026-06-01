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

/**
 * Shared "the OAuth token cannot satisfy this call" signal across
 * the GA4 service surface. PropertyService and DimensionService
 * both throw this when the underlying API rejects a call with what
 * we recognize as an authentication / authorization problem, so the
 * admin-facing layer can route every such case through a single
 * "reconnect Google Analytics" notice rather than the generic
 * "request failed" copy.
 *
 * Detection is centralized on the static matches() predicate so a
 * future tweak (a new marker the Google client surfaces under some
 * other HTTP code, an oauth library version bump) lands in one
 * place instead of drifting between services.
 */
class FiftyOneDegreesGa4AuthError extends RuntimeException
{
    /**
     * Returns true when the throwable looks like an auth/scope
     * problem from the Google API client. The Google client family
     * does not surface these consistently:
     *
     *   - Google\Service\Exception with HTTP 401 or 403 — typical
     *     case (expired access token, revoked scope, insufficient
     *     scope for the endpoint).
     *   - Google\Auth\Exception with HTTP code 0 and message text
     *     "invalid_grant" — refresh-token rotation failed.
     *   - HTTP 400 with body containing "scopeInsufficient" or
     *     "PERMISSION_DENIED" — Admin API surfaces some auth
     *     rejections at the 400 layer rather than 401/403.
     *
     * Catching all three keeps the admin-facing reconnect notice
     * accurate even when the underlying client library evolves its
     * error shape across versions.
     */
    public static function matches(\Throwable $e)
    {
        $code = $e->getCode();
        if ($code === 401 || $code === 403) {
            return true;
        }

        $message = $e->getMessage();
        if ($message === '') {
            return false;
        }

        // Substring markers — order doesn't matter, any hit is auth.
        // Case-insensitive because the Google client and the auth
        // library disagree on casing across versions.
        $markers = ['invalid_grant', 'scopeInsufficient', 'PERMISSION_DENIED'];
        foreach ($markers as $marker) {
            if (stripos($message, $marker) !== false) {
                return true;
            }
        }
        return false;
    }
}

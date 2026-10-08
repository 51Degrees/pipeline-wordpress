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

require_once __DIR__ . '/../includes/ga4-auth-error.php';

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Pin the auth-error detection contract shared by Ga4PropertyService
 * and Ga4DimensionService. Both services route 401/403 PLUS three
 * substring markers ('invalid_grant', 'scopeInsufficient',
 * 'PERMISSION_DENIED') through this matcher into a single typed
 * exception so the admin-facing layer can deliver one reconnect
 * notice instead of N service-specific generic-failure copies.
 */
class Ga4AuthErrorTests extends TestCase
{
    // ─── HTTP-status matches ────────────────────────────────────────────

    public function testMatches401()
    {
        $this->assertTrue(
            FiftyOneDegreesGa4AuthError::matches(new \Exception('unauthorized', 401))
        );
    }

    public function testMatches403()
    {
        $this->assertTrue(
            FiftyOneDegreesGa4AuthError::matches(new \Exception('forbidden', 403))
        );
    }

    // ─── Marker-string matches (auth errors that arrive with non-4xx codes) ─

    public function testMatchesInvalidGrantOnAnyCode()
    {
        // Token-refresh failure — Google\Auth\Exception with code 0
        // and "invalid_grant" payload.
        $this->assertTrue(
            FiftyOneDegreesGa4AuthError::matches(
                new \Exception('Error fetching OAuth2 access token: invalid_grant', 0)
            )
        );
    }

    public function testMatchesScopeInsufficient()
    {
        // Some Admin API endpoints surface scope mismatches at the
        // 400 layer rather than 403.
        $this->assertTrue(
            FiftyOneDegreesGa4AuthError::matches(
                new \Exception('Request had insufficient authentication scopes (scopeInsufficient)', 400)
            )
        );
    }

    public function testMatchesPermissionDenied()
    {
        // GA4 Admin API surfaces some authorization rejections as
        // status=PERMISSION_DENIED inside the response body.
        $this->assertTrue(
            FiftyOneDegreesGa4AuthError::matches(
                new \Exception('{"error":{"status":"PERMISSION_DENIED"}}', 400)
            )
        );
    }

    public function testMarkerMatchIsCaseInsensitive()
    {
        // The Google client and the auth library disagree on casing
        // across versions; the matcher must accept both.
        $this->assertTrue(
            FiftyOneDegreesGa4AuthError::matches(
                new \Exception('INVALID_GRANT received', 0)
            )
        );
    }

    // ─── Non-matches ────────────────────────────────────────────────────

    public function testDoesNotMatchGeneric500()
    {
        $this->assertFalse(
            FiftyOneDegreesGa4AuthError::matches(new \Exception('server explosion', 500))
        );
    }

    public function testDoesNotMatch404()
    {
        $this->assertFalse(
            FiftyOneDegreesGa4AuthError::matches(new \Exception('not found', 404))
        );
    }

    public function testDoesNotMatchEmptyMessage()
    {
        $this->assertFalse(
            FiftyOneDegreesGa4AuthError::matches(new \Exception('', 0))
        );
    }

    public function testDoesNotMatchUnrelated400()
    {
        $this->assertFalse(
            FiftyOneDegreesGa4AuthError::matches(
                new \Exception('invalid request body', 400)
            ),
            'a generic 400 with no auth marker must not trip the matcher'
        );
    }
}

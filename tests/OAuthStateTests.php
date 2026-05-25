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

require_once __DIR__ . '/../includes/oauth-state.php';

use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Test seam over FiftyOneDegreesOauthState: exposes the protected now()
 * override and lets tests force a clock reading without monkey-patching
 * the time() built-in. Subclassing is the documented mock seam.
 */
class TestableOauthState extends FiftyOneDegreesOauthState
{
    public static $frozen_time;

    protected static function now()
    {
        return self::$frozen_time === null ? time() : self::$frozen_time;
    }
}

class OAuthStateTests extends TestCase
{
    public const FAKE_SECRET = 'a-test-secret-value-of-sufficient-length-for-hmac-1234';
    public const FAKE_SITE_URL = 'https://example.test/wp-admin/options-general.php?page=51Degrees&tab=google-analytics&oauth=callback';
    public const FAKE_HOST = 'example.test';

    public function set_up()
    {
        parent::set_up();
        Brain\Monkey\setUp();
        TestableOauthState::$frozen_time = null;
    }

    public function tear_down()
    {
        TestableOauthState::$frozen_time = null;
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    // ─── create_state ───────────────────────────────────────────────────

    public function testCreateStatePayloadStructure()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();

        $state = TestableOauthState::create_state(42);
        $parts = explode('.', $state);

        $this->assertCount(2, $parts, 'state must be b64.b64 with single dot');
        $payload = json_decode($this->b64url_decode($parts[0]), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('v', $payload);
        $this->assertArrayHasKey('site_url', $payload);
        $this->assertArrayHasKey('nonce', $payload);
        $this->assertArrayHasKey('expiry', $payload);
    }

    public function testCreateStateSiteUrlMatchesAdminUrl()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();

        $state = TestableOauthState::create_state(42);
        $payload = $this->decode_payload($state);

        $this->assertSame(self::FAKE_SITE_URL, $payload['site_url']);
    }

    public function testCreateStateHmacMatchesManualComputation()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();

        $state = TestableOauthState::create_state(42);
        list($b64_payload, $b64_hmac) = explode('.', $state, 2);

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $b64_payload, self::FAKE_SECRET, true)
        ), '+/', '-_'), '=');

        $this->assertSame($expected, $b64_hmac);
    }

    public function testCreateStateStoresPkceVerifierInTransient()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $store = $this->stub_transient_store();

        $verifier = str_repeat('a', 64);
        $state = TestableOauthState::create_state(42, $verifier);
        $payload = $this->decode_payload($state);

        $key = FiftyOneDegreesOauthState::TRANSIENT_PREFIX . $payload['nonce'];
        $this->assertArrayHasKey($key, $store->data);
        $this->assertSame(42, $store->data[$key]['user_id']);
        $this->assertSame($verifier, $store->data[$key]['code_verifier']);
    }

    // ─── secret semantics ───────────────────────────────────────────────

    public function testSecretAutoCreatedThenReusedAddOptionSemantics()
    {
        // Mini-store: first call sees option absent, generate+persist;
        // second call reads the persisted value back.
        $persisted = ['v' => false];
        Functions\when('get_option')->alias(function ($key) use (&$persisted) {
            return $key === Options::OAUTH_STATE_SECRET ? $persisted['v'] : false;
        });
        Functions\expect('add_option')
            ->once()
            ->with(Options::OAUTH_STATE_SECRET, Mockery::type('string'), '', 'no')
            ->andReturnUsing(function ($k, $v) use (&$persisted) {
                $persisted['v'] = $v;

                return true;
            });

        $first = TestableOauthState::get_or_create_secret();
        $second = TestableOauthState::get_or_create_secret();

        $this->assertIsString($first);
        $this->assertGreaterThanOrEqual(FiftyOneDegreesOauthState::MIN_SECRET_LEN, strlen($first));
        $this->assertSame($first, $second, 'second call must reuse the persisted secret');
    }

    public function testSecretCorruptionEmptyStringRejected()
    {
        Functions\expect('get_option')
            ->with(Options::OAUTH_STATE_SECRET)
            ->andReturn('');

        $this->expectException(FiftyOneDegreesOauthStateException::class);

        try {
            TestableOauthState::get_or_create_secret();
        } catch (FiftyOneDegreesOauthStateException $e) {
            $this->assertSame('secret_corrupt', $e->reason);

            throw $e;
        }
    }

    public function testSecretCorruptionTooShortRejected()
    {
        Functions\expect('get_option')
            ->with(Options::OAUTH_STATE_SECRET)
            ->andReturn('short');

        $this->expectException(FiftyOneDegreesOauthStateException::class);

        try {
            TestableOauthState::get_or_create_secret();
        } catch (FiftyOneDegreesOauthStateException $e) {
            $this->assertSame('secret_corrupt', $e->reason);

            throw $e;
        }
    }

    // ─── verify_state ───────────────────────────────────────────────────

    public function testVerifyStateHappyPath()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();

        $state = TestableOauthState::create_state(42, 'verifier-xyz');
        $result = TestableOauthState::verify_state($state, 42, self::FAKE_HOST);

        $this->assertIsArray($result);
        $this->assertSame(42, $result['transient']['user_id']);
        $this->assertSame('verifier-xyz', $result['transient']['code_verifier']);
        $this->assertSame(self::FAKE_SITE_URL, $result['payload']['site_url']);
    }

    public function testVerifyStateDoesNotDeleteTransientOnSuccess()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $store = $this->stub_transient_store();

        $state = TestableOauthState::create_state(42);
        TestableOauthState::verify_state($state, 42, self::FAKE_HOST);

        $payload = $this->decode_payload($state);
        $key = FiftyOneDegreesOauthState::TRANSIENT_PREFIX . $payload['nonce'];
        $this->assertArrayHasKey(
            $key,
            $store->data,
            'verify_state must NOT delete transient — that is the callback\'s job after token save (GAP-4)'
        );
    }

    public function testVerifyStateTamperedHmacRejected()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();

        $state = TestableOauthState::create_state(42);
        // Flip a char in the middle of the HMAC portion. Tampering the
        // last char alone may only flip base64 padding bits and decode
        // back to identical bytes — middle bytes are unambiguous.
        $dot = strrpos($state, '.');
        $hmac_part = substr($state, $dot + 1);
        $mid = (int) (strlen($hmac_part) / 2);
        $orig_char = $hmac_part[$mid];
        $new_char = $orig_char === 'A' ? 'B' : 'A';
        $tampered = substr($state, 0, $dot + 1)
            . substr($hmac_part, 0, $mid)
            . $new_char
            . substr($hmac_part, $mid + 1);

        $this->assertVerifyRejects($tampered, 42, self::FAKE_HOST, 'bad_hmac');
    }

    public function testVerifyStateExpiredRejected()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();

        TestableOauthState::$frozen_time = 1000;
        $state = TestableOauthState::create_state(42);

        // Jump forward past the TTL.
        TestableOauthState::$frozen_time = 1000 + FiftyOneDegreesOauthState::STATE_TTL + 1;

        $this->assertVerifyRejects($state, 42, self::FAKE_HOST, 'expired_state');
    }

    public function testVerifyStateMalformedNoDot()
    {
        $this->stub_secret();
        $this->assertVerifyRejects('no-separator-here', 42, self::FAKE_HOST, 'malformed');
    }

    public function testVerifyStateMalformedBadBase64()
    {
        $this->stub_secret();
        $this->assertVerifyRejects('!!!.!!!', 42, self::FAKE_HOST, 'malformed');
    }

    public function testVerifyStateMalformedBadJson()
    {
        $this->stub_secret();
        $b64_payload = $this->b64url_encode('not-json');
        $hmac = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $b64_payload, self::FAKE_SECRET, true)
        ), '+/', '-_'), '=');

        $this->assertVerifyRejects($b64_payload . '.' . $hmac, 42, self::FAKE_HOST, 'malformed');
    }

    public function testVerifyStateMalformedMissingFields()
    {
        $this->stub_secret();
        $b64_payload = $this->b64url_encode(json_encode(['v' => 1]));
        $hmac = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $b64_payload, self::FAKE_SECRET, true)
        ), '+/', '-_'), '=');

        $this->assertVerifyRejects($b64_payload . '.' . $hmac, 42, self::FAKE_HOST, 'malformed');
    }

    public function testVerifyStateHostMismatch()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();

        $state = TestableOauthState::create_state(42);

        $this->assertVerifyRejects($state, 42, 'attacker.example', 'host_mismatch');
    }

    public function testVerifyStateMissingTransient()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $store = $this->stub_transient_store();

        $state = TestableOauthState::create_state(42);
        // Simulate transient TTL expiry / cleanup between create and verify.
        $store->data = [];

        $this->assertVerifyRejects($state, 42, self::FAKE_HOST, 'missing_transient');
    }

    public function testVerifyStateUserIdMismatch()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();

        $state = TestableOauthState::create_state(42);

        // Admin B opens the callback while admin A's state is still live.
        $this->assertVerifyRejects($state, 99, self::FAKE_HOST, 'user_mismatch');
    }

    public function testVerifyStateSecretMissingIsCorruptNotRecreated()
    {
        // Issue a state with one secret, then simulate the secret option
        // being absent on the verification path. verify_state must throw
        // secret_corrupt rather than lazily writing a new secret to the DB.
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();

        $state = TestableOauthState::create_state(42);

        // Drop the secret between create and verify.
        Functions\when('get_option')->alias(function ($key) {
            return $key === Options::OAUTH_STATE_SECRET ? false : false;
        });
        // add_option must NOT be called on the verification path.
        Functions\expect('add_option')->never();

        $this->assertVerifyRejects($state, 42, self::FAKE_HOST, 'secret_corrupt');
    }

    public function testCreateStateRejectsInvalidUserId()
    {
        $this->stub_admin_url();
        $this->stub_secret();
        $this->stub_transient_store();
        // No add_option / no set_transient should fire — fail fast.
        Functions\expect('add_option')->never();

        $this->expectException(FiftyOneDegreesOauthStateException::class);

        try {
            TestableOauthState::create_state(0);
        } catch (FiftyOneDegreesOauthStateException $e) {
            $this->assertSame('invalid_user_id', $e->reason);

            throw $e;
        }
    }

    public function testVerifyStateRejectsInvalidExpectedUserId()
    {
        // verify_state must reject user_id=0 before touching any state
        // material — this is the "unauthenticated request reached the
        // callback" defence-in-depth path.
        $state = 'irrelevant.because.we.fail.fast';

        $this->expectException(FiftyOneDegreesOauthStateException::class);

        try {
            TestableOauthState::verify_state($state, 0, self::FAKE_HOST);
        } catch (FiftyOneDegreesOauthStateException $e) {
            $this->assertSame('invalid_user_id', $e->reason);

            throw $e;
        }
    }

    // ─── PKCE primitives ────────────────────────────────────────────────

    public function testGenerateCodeVerifierShape()
    {
        $a = FiftyOneDegreesOauthState::generate_code_verifier();
        $b = FiftyOneDegreesOauthState::generate_code_verifier();

        $this->assertGreaterThanOrEqual(
            43,
            strlen($a),
            'RFC 7636 mandates >= 43 chars'
        );
        $this->assertMatchesRegularExpression(
            '/^[A-Za-z0-9._~-]+$/',
            $a,
            'verifier must use only the RFC 7636 unreserved alphabet'
        );
        $this->assertNotSame($a, $b, 'verifier must be random per call');
    }

    public function testCodeChallengeFromKnownVector()
    {
        // RFC 7636 Appendix B test vector.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expected = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $this->assertSame(
            $expected,
            FiftyOneDegreesOauthState::code_challenge_from($verifier)
        );
    }

    /**
     * Lightweight in-memory transient store used by tests that need to
     * round-trip set_transient -> get_transient through Brain Monkey.
     */
    private function stub_transient_store()
    {
        $store = new stdClass();
        $store->data = [];

        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use ($store) {
            $store->data[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(function ($key) use ($store) {
            return array_key_exists($key, $store->data) ? $store->data[$key] : false;
        });
        Functions\when('delete_transient')->alias(function ($key) use ($store) {
            unset($store->data[$key]);

            return true;
        });

        return $store;
    }

    private function stub_admin_url()
    {
        Functions\when('admin_url')->alias(function ($path) {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        });
        Functions\when('wp_json_encode')->alias(function ($v) {
            return json_encode($v);
        });
    }

    private function stub_secret($value = self::FAKE_SECRET)
    {
        Functions\when('get_option')->alias(function ($key) use ($value) {
            return $key === Options::OAUTH_STATE_SECRET ? $value : false;
        });
    }

    // ─── helpers ────────────────────────────────────────────────────────

    private function assertVerifyRejects($state, $user_id, $host, $expected_reason)
    {
        try {
            TestableOauthState::verify_state($state, $user_id, $host);
            $this->fail("Expected verify_state to reject with reason '{$expected_reason}', but it returned");
        } catch (FiftyOneDegreesOauthStateException $e) {
            $this->assertSame($expected_reason, $e->reason);
        }
    }

    private function decode_payload($state)
    {
        list($b64_payload) = explode('.', $state, 2);

        return json_decode($this->b64url_decode($b64_payload), true);
    }

    private function b64url_encode($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function b64url_decode($s)
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($s, true);
    }
}

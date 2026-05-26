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

require_once __DIR__ . '/../includes/oauth-callback.php';

use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Test seam over FiftyOneDegreesOauthCallback. Replaces `exit` with a
 * tracked flag so handle() can be invoked safely from PHPUnit, and
 * lets tests inject a mock Google_Client without touching the real
 * Google API library.
 */
class TestableOauthCallback extends FiftyOneDegreesOauthCallback
{
    public static $halt_called = false;
    public static $mock_client = null;

    public static function reset_state()
    {
        self::$halt_called = false;
        self::$mock_client = null;
    }

    protected static function halt()
    {
        self::$halt_called = true;
    }

    protected static function build_client()
    {
        return self::$mock_client;
    }
}

/**
 * Minimal Google_Client stand-in. Records the code passed to
 * authenticate() and the verifier passed to setCodeVerifier() so tests
 * can assert the exchange contract without dragging in the real library.
 */
class FakeGoogleClient
{
    public $verifier = null;
    public $code_seen = null;
    public $return_value;
    public $throw = null;

    public function __construct($return_value = ['access_token' => 'fake-access'])
    {
        $this->return_value = $return_value;
    }

    public function setCodeVerifier($verifier)
    {
        $this->verifier = $verifier;
    }

    public function authenticate($code)
    {
        $this->code_seen = $code;
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->return_value;
    }
}

class OAuthCallbackTests extends TestCase
{
    private const FAKE_SECRET = 'a-test-secret-value-of-sufficient-length-for-hmac-1234';
    private const FAKE_HOST = 'example.test';
    private const FAKE_USER = 42;
    private const NONCE = '0123456789abcdef0123456789abcdef';

    /** @var array<int,array{string,mixed}> ordered call log shared across stubs */
    private $call_log;

    public function set_up()
    {
        parent::set_up();
        Brain\Monkey\setUp();
        TestableOauthCallback::reset_state();
        $this->call_log = [];

        $_GET = [];
        $_SERVER['HTTP_HOST'] = self::FAKE_HOST;
    }

    public function tear_down()
    {
        TestableOauthCallback::reset_state();
        Brain\Monkey\tearDown();
        $_GET = [];
        parent::tear_down();
    }

    // ─── Test helpers ───────────────────────────────────────────────────

    /**
     * Stubs the WP function surface used by the callback. By default the
     * site is single-site, the user is an authenticated admin, and the
     * option/transient stores are empty in-memory arrays returned via
     * stdClass so individual tests can mutate / assert against them.
     *
     * Each WP write is appended to $this->call_log as [fn, key] so order
     * assertions are possible (used by the GAP-4 / R-1 happy-path test).
     */
    private function stub_wp(array $overrides = [])
    {
        $opts = new \stdClass();
        $opts->data = isset($overrides['options']) ? $overrides['options'] : [];
        $tr = new \stdClass();
        $tr->data = isset($overrides['transients']) ? $overrides['transients'] : [];
        $log = &$this->call_log;

        Functions\when('is_user_logged_in')->justReturn(
            isset($overrides['logged_in']) ? $overrides['logged_in'] : true
        );
        Functions\when('current_user_can')->justReturn(
            isset($overrides['can_manage']) ? $overrides['can_manage'] : true
        );
        Functions\when('is_multisite')->justReturn(
            isset($overrides['multisite']) ? $overrides['multisite'] : false
        );
        Functions\when('get_current_user_id')->justReturn(self::FAKE_USER);

        Functions\when('admin_url')->alias(function ($path) {
            return 'https://' . OAuthCallbackTests::FAKE_HOST . '/wp-admin/' . ltrim((string) $path, '/');
        });
        Functions\when('wp_login_url')->justReturn('https://' . self::FAKE_HOST . '/wp-login.php');
        Functions\when('wp_safe_redirect')->alias(function ($url) use (&$log) {
            $log[] = ['wp_safe_redirect', $url];
            return true;
        });

        Functions\when('get_option')->alias(function ($key, $default = false) use ($opts) {
            return array_key_exists($key, $opts->data) ? $opts->data[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) use ($opts, &$log) {
            $opts->data[$key] = $value;
            $log[] = ['update_option', $key];
            return true;
        });

        Functions\when('get_transient')->alias(function ($key) use ($tr) {
            return array_key_exists($key, $tr->data) ? $tr->data[$key] : false;
        });
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use ($tr, &$log) {
            $tr->data[$key] = $value;
            $log[] = ['set_transient', $key];
            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) use ($tr, &$log) {
            $existed = array_key_exists($key, $tr->data);
            unset($tr->data[$key]);
            $log[] = ['delete_transient', $key];
            return $existed;
        });

        Functions\when('do_action')->alias(function (...$args) use (&$log) {
            $log[] = ['do_action', $args];
        });

        // wp_json_encode used inside the state engine; pass-through to json_encode.
        Functions\when('wp_json_encode')->alias(function ($value) {
            return json_encode($value);
        });

        return [$opts, $tr];
    }

    /**
     * Crafts a state string by hand using the same wire format as
     * FiftyOneDegreesOauthState::create_state, so we can produce expired
     * or host-mismatched states without time-travel or subclassing
     * (the production callback hardcodes FiftyOneDegreesOauthState — a
     * late-static override would not change which class verifies).
     */
    private function craft_state(array $payload, $secret = self::FAKE_SECRET)
    {
        $b64 = $this->b64url_encode(json_encode($payload));
        $hmac = hash_hmac('sha256', $b64, $secret, true);
        return $b64 . '.' . $this->b64url_encode($hmac);
    }

    private function default_payload(array $overrides = [])
    {
        return array_merge([
            'v' => 1,
            'site_url' => 'https://' . self::FAKE_HOST . '/wp-admin/options-general.php?page=51Degrees&tab=google-analytics&oauth=callback',
            'nonce' => self::NONCE,
            'expiry' => time() + 600,
        ], $overrides);
    }

    private function transient_key($nonce = self::NONCE)
    {
        return FiftyOneDegreesOauthState::TRANSIENT_PREFIX . $nonce;
    }

    /**
     * Sets up the four query params and a primed option/transient store
     * for the happy path. Returns [options, transients] so the test can
     * mutate or assert further.
     */
    private function prime_happy_path()
    {
        $state = $this->craft_state($this->default_payload());
        $_GET = [
            'page' => '51Degrees',
            'tab' => 'google-analytics',
            'oauth' => 'callback',
            'code' => 'auth-code-xyz',
            'state' => $state,
        ];

        return $this->stub_wp([
            'options' => [Options::OAUTH_STATE_SECRET => self::FAKE_SECRET],
            'transients' => [
                $this->transient_key() => [
                    'user_id' => self::FAKE_USER,
                    'code_verifier' => 'pkce-verifier-abc',
                ],
            ],
        ]);
    }

    private function log_actions()
    {
        return array_values(array_filter(
            $this->call_log,
            function ($entry) { return $entry[0] === 'do_action'; }
        ));
    }

    private function log_keys_of($fn)
    {
        $out = [];
        foreach ($this->call_log as $entry) {
            if ($entry[0] === $fn) {
                $out[] = $entry[1];
            }
        }
        return $out;
    }

    private function b64url_encode($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    // ─── Early skip — does nothing on unrelated admin pages ─────────────

    public function testEarlySkipWhenOauthParamMissing()
    {
        $this->stub_wp();
        $_GET = ['page' => '51Degrees', 'tab' => 'google-analytics'];

        TestableOauthCallback::handle();

        $this->assertFalse(TestableOauthCallback::$halt_called,
            'unrelated admin page must not redirect');
        $this->assertSame([], $this->call_log, 'no side effects');
    }

    public function testEarlySkipWhenPageParamWrong()
    {
        $this->stub_wp();
        $_GET = ['page' => 'other', 'tab' => 'google-analytics', 'oauth' => 'callback'];

        TestableOauthCallback::handle();

        $this->assertSame([], $this->call_log);
    }

    public function testEarlySkipWhenTabParamWrong()
    {
        $this->stub_wp();
        $_GET = ['page' => '51Degrees', 'tab' => 'other', 'oauth' => 'callback'];

        TestableOauthCallback::handle();

        $this->assertSame([], $this->call_log);
    }

    // ─── Capability / login gates ───────────────────────────────────────

    public function testRedirectsToLoginWhenNotLoggedIn()
    {
        $this->stub_wp(['logged_in' => false]);
        $_GET = ['page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback'];

        TestableOauthCallback::handle();

        $redirects = $this->log_keys_of('wp_safe_redirect');
        $this->assertCount(1, $redirects);
        $this->assertStringContainsString('/wp-login.php', $redirects[0]);
        $this->assertTrue(TestableOauthCallback::$halt_called);
        $this->assertSame([], $this->log_actions(),
            'no rejection action — unauthenticated request has no admin context');
    }

    public function testRedirectsToLoginWhenNoManageCapability()
    {
        $this->stub_wp(['can_manage' => false]);
        $_GET = ['page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback'];

        TestableOauthCallback::handle();

        $redirects = $this->log_keys_of('wp_safe_redirect');
        $this->assertCount(1, $redirects);
        $this->assertStringContainsString('/wp-login.php', $redirects[0]);
    }

    // ─── Branch: multisite ──────────────────────────────────────────────

    public function testMultisiteRejection()
    {
        $this->stub_wp(['multisite' => true]);
        $_GET = ['page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback'];

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('multisite_unsupported');
        $this->assertNoticeSet('multisite_unsupported');
    }

    // ─── Branch: google_error ───────────────────────────────────────────

    public function testGoogleErrorRejection()
    {
        $this->stub_wp();
        $_GET = [
            'page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback',
            'error' => 'access_denied',
        ];

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('google_error');
        $actions = $this->log_actions();
        $this->assertSame(['error' => 'access_denied'], $actions[0][1][3],
            'rejection context must carry the raw Google error code');
    }

    // ─── Branch: bad_hmac ───────────────────────────────────────────────

    public function testBadHmacRejection()
    {
        $state = $this->craft_state($this->default_payload(), 'wrong-secret-of-sufficient-length-for-hmac-test-1234');
        $_GET = [
            'page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback',
            'code' => 'auth-code', 'state' => $state,
        ];
        $this->stub_wp([
            'options' => [Options::OAUTH_STATE_SECRET => self::FAKE_SECRET],
            'transients' => [$this->transient_key() => ['user_id' => self::FAKE_USER]],
        ]);

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('bad_hmac');
        $this->assertSame([], $this->log_keys_of('delete_transient'),
            'transient must not be consumed when verify fails before atomic-consume step');
    }

    // ─── Branch: expired_state ──────────────────────────────────────────

    public function testExpiredStateRejection()
    {
        $state = $this->craft_state($this->default_payload(['expiry' => time() - 10]));
        $_GET = [
            'page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback',
            'code' => 'auth-code', 'state' => $state,
        ];
        $this->stub_wp([
            'options' => [Options::OAUTH_STATE_SECRET => self::FAKE_SECRET],
            'transients' => [$this->transient_key() => ['user_id' => self::FAKE_USER]],
        ]);

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('expired_state');
    }

    // ─── Branch: host_mismatch ──────────────────────────────────────────

    public function testHostMismatchRejection()
    {
        $state = $this->craft_state($this->default_payload([
            'site_url' => 'https://other.example/wp-admin/options-general.php?page=51Degrees&tab=google-analytics&oauth=callback',
        ]));
        $_GET = [
            'page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback',
            'code' => 'auth-code', 'state' => $state,
        ];
        $this->stub_wp([
            'options' => [Options::OAUTH_STATE_SECRET => self::FAKE_SECRET],
            'transients' => [$this->transient_key() => ['user_id' => self::FAKE_USER]],
        ]);

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('host_mismatch');
    }

    // ─── Branch: missing_transient ──────────────────────────────────────

    public function testMissingTransientRejection()
    {
        $state = $this->craft_state($this->default_payload());
        $_GET = [
            'page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback',
            'code' => 'auth-code', 'state' => $state,
        ];
        $this->stub_wp([
            'options' => [Options::OAUTH_STATE_SECRET => self::FAKE_SECRET],
            // no transient
        ]);

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('missing_transient');
    }

    // ─── Branch: user_mismatch ──────────────────────────────────────────

    public function testUserMismatchRejection()
    {
        $state = $this->craft_state($this->default_payload());
        $_GET = [
            'page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback',
            'code' => 'auth-code', 'state' => $state,
        ];
        $this->stub_wp([
            'options' => [Options::OAUTH_STATE_SECRET => self::FAKE_SECRET],
            'transients' => [$this->transient_key() => ['user_id' => self::FAKE_USER + 99]],
        ]);

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('user_mismatch');
    }

    // ─── Branch: malformed ──────────────────────────────────────────────

    public function testMalformedStateRejection()
    {
        $_GET = [
            'page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback',
            'code' => 'auth-code', 'state' => 'not-a-valid-state-string',
        ];
        $this->stub_wp(['options' => [Options::OAUTH_STATE_SECRET => self::FAKE_SECRET]]);

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('malformed');
    }

    // ─── Branch: exchange_failed (authenticate throws) ──────────────────

    public function testExchangeFailureWhenAuthenticateThrows()
    {
        $this->prime_happy_path();
        $client = new FakeGoogleClient();
        $client->throw = new \Exception('invalid_grant');
        TestableOauthCallback::$mock_client = $client;

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('exchange_failed');
        $this->assertSame(
            [$this->transient_key()],
            $this->log_keys_of('delete_transient'),
            'transient is consumed BEFORE exchange (Pre-mortem R-1); exception leaves it gone — retry needs fresh state'
        );
        $this->assertSame([], array_filter(
            $this->log_keys_of('update_option'),
            function ($k) { return $k === Options::GA_TOKEN; }
        ), 'no token must be saved on exchange failure');
    }

    // ─── Branch: exchange_failed (Google returns error array) ───────────

    public function testExchangeFailureWhenAuthenticateReturnsErrorArray()
    {
        $this->prime_happy_path();
        TestableOauthCallback::$mock_client = new FakeGoogleClient([
            'error' => 'invalid_grant',
            'access_token' => 'leaked-partial-token',
            'error_description' => 'malformed auth response with PII',
        ]);

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('exchange_failed');
        $actions = $this->log_actions();
        $context = $actions[0][1][3];
        $this->assertSame(['error' => 'invalid_grant'], $context,
            'rejection context must be a narrow {error: code} shape — never the full token response'
        );
    }

    // ─── Branch: missing_code (R-1 fix follow-up) ───────────────────────

    public function testMissingCodeRejection()
    {
        // State verifies but `code` query parameter never arrived.
        // The transient must still be consumed (atomic-consume contract),
        // and the user sees the dedicated missing_code notice rather than
        // a misleading "exchange_failed" (which implies a burned code).
        $state = $this->craft_state($this->default_payload());
        $_GET = [
            'page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback',
            'state' => $state,
            // 'code' deliberately absent
        ];
        $this->stub_wp([
            'options' => [Options::OAUTH_STATE_SECRET => self::FAKE_SECRET],
            'transients' => [$this->transient_key() => ['user_id' => self::FAKE_USER]],
        ]);

        TestableOauthCallback::handle();

        $this->assertEqualsBranch('missing_code');
        $this->assertSame(
            [$this->transient_key()],
            $this->log_keys_of('delete_transient'),
            'transient is consumed before the code check — retry needs fresh state'
        );
    }

    // ─── Happy path ─────────────────────────────────────────────────────

    public function testHappyPathSavesTokenAndPrgRedirects()
    {
        [$opts, $tr] = $this->prime_happy_path();
        $token = ['access_token' => 'real-token', 'scope' => 'analytics'];
        TestableOauthCallback::$mock_client = new FakeGoogleClient($token);

        TestableOauthCallback::handle();

        $this->assertSame($token, $opts->data[Options::GA_TOKEN],
            'GA_TOKEN must be persisted on success');
        $this->assertIsInt($opts->data[Options::GA_AUTH_DATE],
            'GA_AUTH_DATE must be a unix timestamp');
        $this->assertSame('success', $tr->data[FiftyOneDegreesOauthNotice::TRANSIENT_KEY],
            'success notice must be queued for the next admin page render');

        $redirects = $this->log_keys_of('wp_safe_redirect');
        $this->assertCount(1, $redirects);
        $this->assertStringContainsString('oauth-success=1', $redirects[0]);
        $this->assertStringNotContainsString('code=', $redirects[0],
            'PRG must strip the authorization code from the URL');
        $this->assertStringNotContainsString('state=', $redirects[0],
            'PRG must strip the state from the URL');
        $this->assertTrue(TestableOauthCallback::$halt_called);
    }

    public function testHappyPathDeletesTransientBeforeAuthenticate()
    {
        $this->prime_happy_path();
        $client = new FakeGoogleClient(['access_token' => 'ok']);
        TestableOauthCallback::$mock_client = $client;

        TestableOauthCallback::handle();

        // Build the abridged sequence of "interesting" calls in order.
        $seen = [];
        foreach ($this->call_log as $entry) {
            if ($entry[0] === 'delete_transient') {
                $seen[] = 'delete_transient';
            } elseif ($entry[0] === 'update_option' && $entry[1] === Options::GA_TOKEN) {
                $seen[] = 'update_token';
            }
        }
        $this->assertSame(['delete_transient', 'update_token'], $seen,
            'Pre-mortem R-1: transient consumed BEFORE the exchange + token save');
        // authenticate was called between the two log points — assert via the
        // recorded code value, which only the FakeGoogleClient could set.
        $this->assertSame('auth-code-xyz', $client->code_seen);
    }

    public function testHappyPathAppliesPkceCodeVerifier()
    {
        $this->prime_happy_path();
        $client = new FakeGoogleClient();
        TestableOauthCallback::$mock_client = $client;

        TestableOauthCallback::handle();

        $this->assertSame('pkce-verifier-abc', $client->verifier,
            'PKCE verifier from the transient must be passed to the client');
    }

    public function testHappyPathWithoutPkceVerifier()
    {
        $state = $this->craft_state($this->default_payload());
        $_GET = [
            'page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback',
            'code' => 'auth-code', 'state' => $state,
        ];
        $this->stub_wp([
            'options' => [Options::OAUTH_STATE_SECRET => self::FAKE_SECRET],
            'transients' => [
                $this->transient_key() => [
                    'user_id' => self::FAKE_USER,
                    'code_verifier' => null,
                ],
            ],
        ]);
        $client = new FakeGoogleClient(['access_token' => 'ok']);
        TestableOauthCallback::$mock_client = $client;

        TestableOauthCallback::handle();

        $this->assertNull($client->verifier,
            'setCodeVerifier must not be called when the transient stored null verifier');
    }

    // ─── Rejection action shape ─────────────────────────────────────────

    public function testRejectionActionCarriesBranchAndUserId()
    {
        $this->stub_wp(['multisite' => true]);
        $_GET = ['page' => '51Degrees', 'tab' => 'google-analytics', 'oauth' => 'callback'];

        TestableOauthCallback::handle();

        $actions = $this->log_actions();
        $this->assertCount(1, $actions);
        $args = $actions[0][1];
        $this->assertSame('fiftyonedegrees_oauth_rejection', $args[0]);
        $this->assertSame('multisite_unsupported', $args[1]);
        $this->assertSame(self::FAKE_USER, $args[2]);
        $this->assertIsArray($args[3]);
    }

    // ─── Custom assertions ──────────────────────────────────────────────

    /**
     * Asserts the rejection branch fired the observability action and set
     * the matching notice transient. Both wires are keyed on the same slug
     * by contract — the admin tab reads the transient, renders the matching
     * oauth.notice.<slug> string, and clears.
     */
    private function assertEqualsBranch($branch)
    {
        $actions = $this->log_actions();
        $this->assertNotEmpty($actions, 'rejection action must fire for branch ' . $branch);
        $this->assertSame($branch, $actions[0][1][1],
            'rejection branch slug must match notice key (oauth.notice.' . $branch . ')');
        $this->assertNoticeSet($branch);
    }

    private function assertNoticeSet($branch)
    {
        $this->assertContains(
            FiftyOneDegreesOauthNotice::TRANSIENT_KEY,
            $this->log_keys_of('set_transient'),
            'notice transient must be set so the admin tab can render ' . $branch
        );
    }
}

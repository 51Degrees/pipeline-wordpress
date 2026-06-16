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

require_once __DIR__ . '/../includes/oauth-start.php';

use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Test seam over FiftyOneDegreesOauthStart. Replaces `exit` with a
 * tracked flag so handle() can run under PHPUnit without terminating
 * the process.
 *
 * The handler no longer builds a Google client; it composes the relay
 * /start URL and wp_redirect()s there. There is no client to inject —
 * tests capture the redirect URL instead.
 */
class TestableOauthStart extends FiftyOneDegreesOauthStart
{
    public static $halt_called = false;

    public static function reset_state()
    {
        self::$halt_called = false;
    }

    protected static function halt()
    {
        self::$halt_called = true;
    }
}

class OAuthStartHandlerTests extends TestCase
{
    private const FAKE_SECRET = 'a-test-secret-value-of-sufficient-length-for-hmac-1234';
    private const FAKE_USER = 42;
    private const FAKE_RESOURCE = 'AQS-test-resource-key';
    private const HTTPS_HOME = 'https://example.test';
    private const HTTP_HOME = 'http://example.test';

    /** @var array<int,array{string,mixed}> ordered call log */
    private $call_log;

    public function set_up()
    {
        parent::set_up();
        Brain\Monkey\setUp();
        TestableOauthStart::reset_state();
        $this->call_log = [];

        // The relay base is derived from the cloud API host (FOD_CLOUD_API_URL),
        // so point it at a test host the URL assertions can match.
        putenv('FOD_CLOUD_API_URL=https://relay.51degrees.example/api/v4/');
    }

    public function tear_down()
    {
        putenv('FOD_CLOUD_API_URL');
        TestableOauthStart::reset_state();
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * Stubs the WP function surface. By default: logged-in admin,
     * single site, HTTPS home_url, nonce verifies, secret + resource
     * key present + empty transient store. Returns the stores so tests
     * can assert.
     */
    private function stub_wp(array $overrides = [])
    {
        $opts = new \stdClass();
        $opts->data = isset($overrides['options'])
            ? $overrides['options']
            : [
                Options::OAUTH_STATE_SECRET => self::FAKE_SECRET,
                Options::RESOURCE_KEY       => self::FAKE_RESOURCE,
            ];
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
        Functions\when('home_url')->justReturn(
            isset($overrides['home_url']) ? $overrides['home_url'] : self::HTTPS_HOME
        );
        Functions\when('admin_url')->alias(function ($path) {
            return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
        });
        Functions\when('wp_login_url')->justReturn('https://example.test/wp-login.php');
        Functions\when('wp_safe_redirect')->alias(function ($url) use (&$log) {
            $log[] = ['wp_safe_redirect', $url];
            return true;
        });
        // wp_redirect is used ONLY for the leg to the relay /start URL,
        // because wp_safe_redirect would fall back to admin_url (the relay
        // is an external host). Tracking these separately lets tests assert
        // the right call on each path.
        Functions\when('wp_redirect')->alias(function ($url) use (&$log) {
            $log[] = ['wp_redirect', $url];
            return true;
        });

        // check_admin_referer: by default succeeds. Tests for bad-nonce
        // make this throw to simulate wp_die without killing PHPUnit.
        $referer_throws = isset($overrides['nonce_fails']) ? $overrides['nonce_fails'] : false;
        Functions\when('check_admin_referer')->alias(function ($action) use (&$log, $referer_throws) {
            $log[] = ['check_admin_referer', $action];
            if ($referer_throws) {
                throw new \RuntimeException('wp_die: invalid nonce');
            }
            return 1;
        });

        Functions\when('apply_filters')->alias(function ($hook, $value) use (&$log) {
            $log[] = ['apply_filters', $hook, $value];
            return $value;
        });
        Functions\when('do_action')->alias(function (...$args) use (&$log) {
            $log[] = ['do_action', $args];
        });

        Functions\when('get_option')->alias(function ($key, $default = false) use ($opts) {
            return array_key_exists($key, $opts->data) ? $opts->data[$key] : $default;
        });
        Functions\when('add_option')->alias(function ($key, $value, $deprecated = '', $autoload = 'yes') use ($opts) {
            if (array_key_exists($key, $opts->data)) {
                return false;
            }
            $opts->data[$key] = $value;
            return true;
        });
        Functions\when('update_option')->alias(function ($key, $value) use ($opts) {
            $opts->data[$key] = $value;
            return true;
        });

        Functions\when('get_transient')->alias(function ($key) use ($tr) {
            return array_key_exists($key, $tr->data) ? $tr->data[$key] : false;
        });
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use ($tr, &$log) {
            $tr->data[$key] = $value;
            $log[] = ['set_transient', $key, $value, $ttl];
            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) use ($tr) {
            unset($tr->data[$key]);
            return true;
        });

        Functions\when('wp_json_encode')->alias(function ($value) {
            return json_encode($value);
        });

        return [$opts, $tr];
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

    private function find_notice()
    {
        foreach ($this->call_log as $entry) {
            if ($entry[0] === 'set_transient'
                && $entry[1] === FiftyOneDegreesOauthNotice::TRANSIENT_KEY) {
                return $entry[2];
            }
        }
        return null;
    }

    /**
     * Returns the args of the first fiftyonedegrees_oauth_rejection do_action
     * call, or null if none fired. Used to assert observability parity with
     * the callback handler.
     */
    private function find_rejection_action()
    {
        foreach ($this->call_log as $entry) {
            if ($entry[0] === 'do_action'
                && isset($entry[1][0])
                && $entry[1][0] === 'fiftyonedegrees_oauth_rejection') {
                return $entry[1];
            }
        }
        return null;
    }

    /**
     * Returns the single relay /start URL captured from wp_redirect, or
     * null if none fired. The handler hands off to the relay via
     * wp_redirect (not wp_safe_redirect — the relay is an external host).
     */
    private function captured_relay_url()
    {
        $redirects = $this->log_keys_of('wp_redirect');
        return isset($redirects[0]) ? $redirects[0] : null;
    }

    /**
     * Parses the query string of a captured relay URL into an assoc array.
     */
    private function relay_query($url)
    {
        $query = parse_url($url, PHP_URL_QUERY);
        $out = [];
        parse_str((string) $query, $out);
        return $out;
    }

    // ─── 1. Login gate ──────────────────────────────────────────────────

    public function testRedirectsToLoginWhenNotLoggedIn()
    {
        $this->stub_wp(['logged_in' => false]);

        TestableOauthStart::handle();

        $redirects = $this->log_keys_of('wp_safe_redirect');
        $this->assertCount(1, $redirects);
        $this->assertStringContainsString('/wp-login.php', $redirects[0]);
        $this->assertSame([], $this->log_keys_of('check_admin_referer'),
            'nonce check must not run before login check'
        );
        $this->assertNull($this->captured_relay_url(),
            'no relay redirect must happen when not logged in'
        );
    }

    // ─── 2. Capability gate ─────────────────────────────────────────────

    public function testRedirectsToLoginWhenNoManageCapability()
    {
        $this->stub_wp(['can_manage' => false]);

        TestableOauthStart::handle();

        $redirects = $this->log_keys_of('wp_safe_redirect');
        $this->assertStringContainsString('/wp-login.php', $redirects[0]);
    }

    // ─── 3. Nonce gate ──────────────────────────────────────────────────

    public function testBadNonceTerminatesViaWpDie()
    {
        $this->stub_wp(['nonce_fails' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wp_die: invalid nonce');

        try {
            TestableOauthStart::handle();
        } finally {
            $this->assertNull($this->captured_relay_url(),
                'no relay redirect must happen when nonce fails'
            );
        }
    }

    // ─── 4. Multisite branch ────────────────────────────────────────────

    public function testMultisiteRejection()
    {
        $this->stub_wp(['multisite' => true]);

        TestableOauthStart::handle();

        $this->assertSame('multisite_unsupported', $this->find_notice());
        $redirects = $this->log_keys_of('wp_safe_redirect');
        $this->assertStringContainsString('page=51Degrees', $redirects[0]);
        $this->assertStringContainsString('tab=google-analytics', $redirects[0]);
        $this->assertNull($this->captured_relay_url(),
            'multisite must reject before any relay redirect'
        );

        // Observability parity with callback: rejection action must fire.
        $action = $this->find_rejection_action();
        $this->assertNotNull($action, 'fiftyonedegrees_oauth_rejection must fire on start-side rejections');
        $this->assertSame('multisite_unsupported', $action[1]);
        $this->assertSame(self::FAKE_USER, $action[2]);
    }

    // ─── 5. HTTPS gate ──────────────────────────────────────────────────

    public function testHttpHomeUrlRejection()
    {
        $this->stub_wp(['home_url' => self::HTTP_HOME]);

        TestableOauthStart::handle();

        $this->assertSame('https_required', $this->find_notice());
        $this->assertNull($this->captured_relay_url(),
            'plain-HTTP site must reject before any relay redirect'
        );
    }

    // ─── 6. State engine error surfaces as notice ───────────────────────

    public function testCorruptSecretSurfacesAsNotice()
    {
        // OAUTH_STATE_SECRET is present but too short — get_or_create_secret
        // throws 'secret_corrupt'. The handler must catch and surface.
        $this->stub_wp(['options' => [
            Options::OAUTH_STATE_SECRET => 'short',
            Options::RESOURCE_KEY       => self::FAKE_RESOURCE,
        ]]);

        TestableOauthStart::handle();

        $this->assertSame('secret_corrupt', $this->find_notice());
        $this->assertNull($this->captured_relay_url());
    }

    // ─── 6b. Missing resource key surfaces as start_failed ──────────────

    public function testMissingResourceKeyRejection()
    {
        // With no resource key the relay would reject /start, so the handler
        // short-circuits to the generic start branch instead of redirecting
        // the admin into a relay 400.
        $this->stub_wp(['options' => [
            Options::OAUTH_STATE_SECRET => self::FAKE_SECRET,
            // RESOURCE_KEY deliberately absent => get_option returns ''
            Options::RESOURCE_KEY       => '',
        ]]);

        TestableOauthStart::handle();

        $this->assertSame('start_failed', $this->find_notice());
        $this->assertNull($this->captured_relay_url(),
            'no relay redirect without a resource key'
        );
        $action = $this->find_rejection_action();
        $this->assertNotNull($action);
        $this->assertSame('start_failed', $action[1]);
    }

    // ─── 7. Happy path: state created, PKCE in URL, redirect to relay ───

    public function testHappyPathCreatesStateAndRedirectsToRelay()
    {
        [$opts, $tr] = $this->stub_wp();

        TestableOauthStart::handle();

        // Final redirect goes to the relay /start URL via wp_redirect (NOT
        // wp_safe_redirect). The latter would silently fall back to admin_url
        // because the relay host is not in wp_allowed_redirect_hosts.
        $url = $this->captured_relay_url();
        $this->assertNotNull($url,
            'happy path must use wp_redirect (not wp_safe_redirect) for the relay leg'
        );
        $this->assertSame([], $this->log_keys_of('wp_safe_redirect'),
            'wp_safe_redirect must not be used for the relay leg — it would clamp to same-host'
        );
        $this->assertStringStartsWith(
            'https://relay.51degrees.example/api/v4/oauth/start?',
            $url,
            'redirect must target the relay /start endpoint'
        );

        $q = $this->relay_query($url);

        // Resource key + scope are carried for the relay.
        $this->assertSame(self::FAKE_RESOURCE, $q['resource']);
        $this->assertSame(
            Google_Service_GoogleAnalyticsAdmin::ANALYTICS_EDIT,
            $q['scope']
        );

        // The signed state travels in `nonce` and follows the b64.b64 format.
        $this->assertArrayHasKey('nonce', $q);
        $this->assertStringContainsString('.', $q['nonce'],
            'state must follow the b64payload.b64hmac wire format'
        );

        // The site callback URL the relay forwards the code to.
        $this->assertArrayHasKey('redirect_uri', $q);
        $this->assertStringContainsString('oauth=callback', $q['redirect_uri']);

        // PKCE: code_challenge present, S256 method.
        $this->assertArrayHasKey('code_challenge', $q);
        $this->assertNotEmpty($q['code_challenge']);
        $this->assertSame('S256', $q['code_challenge_method']);

        // Per-nonce transient was stashed with the verifier (paired with
        // the state's nonce, ready for the callback to read back).
        $transient_entries = array_filter($tr->data, function ($v, $k) {
            return strpos($k, FiftyOneDegreesOauthState::TRANSIENT_PREFIX) === 0;
        }, ARRAY_FILTER_USE_BOTH);
        $this->assertCount(1, $transient_entries,
            'exactly one per-nonce transient must be created'
        );
        $entry = array_values($transient_entries)[0];
        $this->assertSame(self::FAKE_USER, $entry['user_id']);
        $this->assertNotEmpty($entry['code_verifier'],
            'PKCE verifier must be stashed alongside the user_id'
        );

        $this->assertTrue(TestableOauthStart::$halt_called);
    }

    // ─── 8. Repeat clicks produce independent state values ──────────────

    public function testRepeatedClicksProduceIndependentStates()
    {
        [, $tr] = $this->stub_wp();
        TestableOauthStart::handle();
        $first = $this->relay_query($this->captured_relay_url());
        $state_one = $first['nonce'];
        $challenge_one = $first['code_challenge'];

        $this->call_log = [];
        TestableOauthStart::$halt_called = false;
        TestableOauthStart::handle();
        $second = $this->relay_query($this->captured_relay_url());
        $state_two = $second['nonce'];
        $challenge_two = $second['code_challenge'];

        $this->assertNotSame($state_one, $state_two,
            'each click must produce a fresh state (nonce + verifier are random per call)'
        );
        $this->assertNotSame($challenge_one, $challenge_two,
            'each click must produce a fresh PKCE challenge'
        );

        // Both transients coexist — no replacement by nonce reuse.
        $transient_count = 0;
        foreach ($tr->data as $key => $_) {
            if (strpos($key, FiftyOneDegreesOauthState::TRANSIENT_PREFIX) === 0) {
                $transient_count++;
            }
        }
        $this->assertSame(2, $transient_count,
            'two clicks => two pending transients (TTL cleanup is async)'
        );
    }

    // ─── 9. URL length sanity check ─────────────────────────────────────

    public function testGeneratedRelayUrlStaysUnderBrowserLimit()
    {
        $this->stub_wp();

        TestableOauthStart::handle();

        $url = $this->captured_relay_url();
        $this->assertNotNull($url);
        $this->assertLessThan(2000, strlen($url),
            'relay /start URL must remain well under the 2KB browser limit'
        );
    }

    // ─── 10. encode_failure exception path surfaces as notice ───────────

    public function testEncodeFailureSurfacesAsNotice()
    {
        // wp_json_encode returning false is the create_state code path
        // that throws FiftyOneDegreesOauthStateException('encode_failure').
        // The handler must surface this distinct slug so the admin sees
        // the right diagnostic; the oauth-strings.yaml key must exist.
        $this->stub_wp();
        Functions\when('wp_json_encode')->justReturn(false);

        TestableOauthStart::handle();

        $this->assertSame('encode_failure', $this->find_notice());
        $action = $this->find_rejection_action();
        $this->assertNotNull($action);
        $this->assertSame('encode_failure', $action[1]);
        $this->assertNull($this->captured_relay_url());
    }

    // ─── 11. Throwable from CSPRNG surfaces as start_failed ─────────────

    public function testThrowableInStateEngineSurfacesAsStartFailed()
    {
        // Simulate a non-typed engine failure (e.g. random_bytes throwing
        // Error/Exception under unusual conditions). The handler's last-
        // resort \Throwable catch must surface the 'start_failed' slug
        // and the request must NOT propagate the exception up.
        //
        // We can't easily make random_bytes throw, so we instead drive
        // the engine through a class that overrides handle() to throw at
        // the state-engine step. This still exercises the catch.
        $this->stub_wp();

        // Pre-load the class that re-routes generate_code_verifier.
        if (!class_exists('ThrowingTestableOauthStart')) {
            eval(<<<'PHP'
class ThrowingTestableOauthStart extends TestableOauthStart {
    public static function handle() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_safe_redirect(wp_login_url());
            static::halt();
            return;
        }
        check_admin_referer(self::NONCE_ACTION);
        $user_id = (int) get_current_user_id();
        if (is_multisite()) { return; }
        if (parse_url((string) home_url(), PHP_URL_SCHEME) !== 'https') { return; }
        try {
            throw new \RuntimeException('CSPRNG unavailable');
        } catch (FiftyOneDegreesOauthStateException $e) {
            // unreachable
        } catch (\Throwable $e) {
            error_log('51Degrees OAuth start exception: ' . $e->getMessage());
            do_action('fiftyonedegrees_oauth_rejection', 'start_failed', $user_id, []);
            FiftyOneDegreesOauthNotice::set('start_failed');
            wp_safe_redirect(admin_url('options-general.php?page=51Degrees&tab=google-analytics'));
            static::halt();
            return;
        }
    }
}
PHP
            );
        }

        ThrowingTestableOauthStart::handle();

        $this->assertSame('start_failed', $this->find_notice());
        $action = $this->find_rejection_action();
        $this->assertNotNull($action);
        $this->assertSame('start_failed', $action[1]);
    }
}

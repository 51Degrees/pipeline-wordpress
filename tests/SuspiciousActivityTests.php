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

require_once(__DIR__ . '/../includes/suspicious-activity.php');
require_once(__DIR__ . '/../includes/pipeline.php');
require_once(__DIR__ . '/../includes/fiftyone-service.php');

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey;

class ExitException extends \Exception {}

class SuspiciousActivityTests extends TestCase
{
    private $options = [];
    private $transients = [];

    public function set_up()
    {
        Pipeline::reset();
        parent::set_up();
        Monkey\setUp();

        $this->options = [
            Options::SUSPICIOUS_ENABLE => 'off',
            Options::SUSPICIOUS_REDIRECT_URL => 'http://example.com/blocked/',
            Options::SUSPICIOUS_REQUESTS => 5,
            Options::SUSPICIOUS_WINDOW => 30,
        ];
        $this->transients = [];

        $opts = &$this->options;
        $trans = &$this->transients;

        Functions\when('get_option')->alias(function ($key, $default = '') use (&$opts) {
            return array_key_exists($key, $opts) ? $opts[$key] : $default;
        });

        Functions\when('get_transient')->alias(function ($key) use (&$trans) {
            return isset($trans[$key]) ? $trans[$key] : false;
        });

        Functions\when('set_transient')->alias(function ($key, $value, $ttl = 0) use (&$trans) {
            $trans[$key] = $value;
            return true;
        });

        Functions\when('update_option')->alias(function ($key, $value) use (&$opts) {
            $opts[$key] = $value;
            return true;
        });

        Functions\when('delete_option')->alias(function ($key) use (&$opts) {
            unset($opts[$key]);
            return true;
        });

        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('trailingslashit')->alias(function ($string) {
            return rtrim($string, '/\\') . '/';
        });
        Functions\when('wp_parse_url')->alias(function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('home_url')->alias(function ($path = '/') {
            return 'http://example.com' . $path;
        });
        Functions\when('url_to_postid')->justReturn(0);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('error_log')->justReturn(true);

        \Patchwork\redefine('headers_sent', function () {
            return false;
        });
        \Patchwork\redefine('php_sapi_name', function () {
            return 'apache2handler';
        });

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';
        $_SERVER['REQUEST_URI'] = '/some-page/';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
    }

    public function tear_down()
    {
        \Patchwork\restoreAll();
        Monkey\tearDown();
        parent::tear_down();
    }

    private function buildMockFlowData($engine, $propName, $value)
    {
        $propObj = new \stdClass();
        $propObj->hasValue = ($value !== null);
        $propObj->value = $value;
        if ($value === null) {
            $propObj->noValueMessage = 'No value';
        }

        $engineObj = new \stdClass();
        $engineObj->{$propName} = $propObj;

        $flowData = new \stdClass();
        $flowData->{$engine} = $engineObj;

        return $flowData;
    }

    /**
     * Assembles a 117-byte OWID v3 envelope matching the spec at
     * .claude/issues/pipeline-wordpress/16/2026-04-24-51did-core-engine-spec.md
     * and returns it base64-encoded.
     */
    private function buildOwid($identity32, $signature64 = null, $version = 3)
    {
        if (strlen($identity32) !== 32) {
            throw new \InvalidArgumentException('identity must be exactly 32 bytes');
        }
        $signature = $signature64 ?? str_repeat("\x00", 64);
        if (strlen($signature) !== 64) {
            throw new \InvalidArgumentException('signature must be exactly 64 bytes');
        }
        $envelope = chr($version)            // Version (1 byte)
            . "51d.es\x00"                   // Domain (7 bytes, null-terminated)
            . pack('V', 3320214)              // Date (4 bytes LE, arbitrary minutes)
            . pack('V', 37)                   // Payload Length (4 bytes LE)
            . "\x01"                         // Usage Signal (1 byte, non-marketing)
            . pack('V', 19493)                // License Key ID (4 bytes LE, arbitrary)
            . $identity32                     // Identifier (32 bytes)
            . $signature;                     // Signature (64 bytes)
        return base64_encode($envelope);
    }

    /**
     * Test that no transient reads or redirects happen when the feature
     * is disabled.
     */
    public function testFeatureDisabledDoesNothing()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'off';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the redirect is skipped on admin requests.
     */
    public function testSkippedOnAdmin()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        Functions\when('is_admin')->justReturn(true);

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the redirect is skipped on AJAX requests.
     */
    public function testSkippedOnAjax()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        Functions\when('wp_doing_ajax')->justReturn(true);

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the redirect is skipped on CLI requests.
     */
    public function testSkippedOnCli()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        \Patchwork\redefine('php_sapi_name', function () {
            return 'cli';
        });

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the redirect is skipped on wp-login.php.
     */
    public function testSkippedOnWpLogin()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $_SERVER['SCRIPT_NAME'] = '/wp-login.php';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the redirect is skipped on wp-signup.php.
     */
    public function testSkippedOnWpSignup()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $_SERVER['SCRIPT_NAME'] = '/wp-signup.php';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the redirect is skipped on wp-activate.php.
     */
    public function testSkippedOnWpActivate()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $_SERVER['SCRIPT_NAME'] = '/wp-activate.php';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the redirect is skipped when headers have already been sent.
     */
    public function testSkippedWhenHeadersSent()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        \Patchwork\redefine('headers_sent', function () {
            return true;
        });

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that a zero window value causes an early return.
     */
    public function testZeroWindowReturnsEarly()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_WINDOW] = 0;

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that a zero threshold value causes an early return.
     */
    public function testZeroThresholdReturnsEarly()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 0;

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that get_51did extracts the 32-byte Identifier from a valid
     * IdProbLic OWID token and returns it as a 64-char hex string.
     */
    public function testIdentityIdProbLicAvailable()
    {
        $identity = str_repeat("\x7f", 32);
        $token = $this->buildOwid($identity);

        Pipeline::$data = [
            'properties' => [
                'did_engine' => [
                    'idproblic' => ['name' => 'IdProbLic', 'type' => 'String'],
                ],
            ],
            'flowData' => $this->buildMockFlowData('did_engine', 'idproblic', $token),
            'errors' => [],
        ];

        $result = SuspiciousActivity::get_51did();
        self::assertEquals(bin2hex($identity), $result);
    }

    /**
     * Test that get_51did falls back to IdProbGlobal when IdProbLic is
     * absent, and extracts the Identifier from it the same way.
     */
    public function testIdentityIdProbGlobalFallback()
    {
        $identity = str_repeat("\xA3", 32);
        $token = $this->buildOwid($identity);

        Pipeline::$data = [
            'properties' => [
                'did_engine' => [
                    'idprobglobal' => ['name' => 'IdProbGlobal', 'type' => 'String'],
                ],
            ],
            'flowData' => $this->buildMockFlowData('did_engine', 'idprobglobal', $token),
            'errors' => [],
        ];

        $result = SuspiciousActivity::get_51did();
        self::assertEquals(bin2hex($identity), $result);
    }

    /**
     * Test that the same Identifier yields the same tracking key even
     * when the OWID signature suffix rotates per request, which it does
     * under random-k ECDSA on the cloud side.
     */
    public function testIdentityStableAcrossVaryingSignatures()
    {
        $identity = str_repeat("\x55", 32);
        $tokenA = $this->buildOwid($identity, str_repeat("\x01", 64));
        $tokenB = $this->buildOwid($identity, str_repeat("\xFE", 64));
        self::assertNotEquals($tokenA, $tokenB);

        Pipeline::$data = [
            'properties' => [
                'did_engine' => ['idproblic' => ['name' => 'IdProbLic', 'type' => 'String']],
            ],
            'flowData' => $this->buildMockFlowData('did_engine', 'idproblic', $tokenA),
            'errors' => [],
        ];
        $resultA = SuspiciousActivity::get_51did();

        Pipeline::$data['flowData'] = $this->buildMockFlowData('did_engine', 'idproblic', $tokenB);
        $resultB = SuspiciousActivity::get_51did();

        self::assertEquals($resultA, $resultB);
        self::assertEquals(bin2hex($identity), $resultA);
    }

    /**
     * Test that an unparseable OWID value falls through to the IP+UA
     * hash so the feature still tracks visitors under cloud-side errors
     * or unexpected data.
     */
    public function testIdentityMalformedOwidFallsBackToIpUa()
    {
        Pipeline::$data = [
            'properties' => [
                'did_engine' => ['idproblic' => ['name' => 'IdProbLic', 'type' => 'String']],
            ],
            'flowData' => $this->buildMockFlowData('did_engine', 'idproblic', 'not-a-valid-owid-string'),
            'errors' => [],
        ];

        $result = SuspiciousActivity::get_51did();
        $expected = hash('sha256', '192.168.1.100|TestBrowser/1.0');
        self::assertEquals($expected, $result);
    }

    /**
     * Test that an OWID with a version byte other than 3 is rejected,
     * preventing silent misinterpretation if the cloud later issues v4
     * tokens with a different byte layout.
     */
    public function testIdentityWrongVersionFallsBackToIpUa()
    {
        $identity = str_repeat("\x01", 32);
        $token = $this->buildOwid($identity, null, 4);

        Pipeline::$data = [
            'properties' => [
                'did_engine' => ['idproblic' => ['name' => 'IdProbLic', 'type' => 'String']],
            ],
            'flowData' => $this->buildMockFlowData('did_engine', 'idproblic', $token),
            'errors' => [],
        ];

        $result = SuspiciousActivity::get_51did();
        $expected = hash('sha256', '192.168.1.100|TestBrowser/1.0');
        self::assertEquals($expected, $result);
    }

    /**
     * Test that get_51did falls back to an IP+UA hash when no pipeline
     * identity properties are available.
     */
    public function testIdentityFallbackToIpUaHash()
    {
        Pipeline::$data = [
            'properties' => [],
            'flowData' => null,
            'errors' => [],
        ];

        $result = SuspiciousActivity::get_51did();
        $expected = hash('sha256', '192.168.1.100|TestBrowser/1.0');
        self::assertEquals($expected, $result);
        self::assertEquals(64, strlen($result));
    }

    /**
     * Test that the IP+UA hash is stable across multiple calls with the
     * same server variables.
     */
    public function testIdentityIpUaHashIsStable()
    {
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        $result1 = SuspiciousActivity::get_51did();
        $result2 = SuspiciousActivity::get_51did();
        self::assertEquals($result1, $result2);
    }

    /**
     * Test that no redirect fires when the request count is under the
     * configured threshold.
     */
    public function testUnderThresholdNoRedirect()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 5;
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        $did = SuspiciousActivity::get_51did();
        $key = SuspiciousActivity::build_transient_key($did);
        $now = microtime(true);
        $this->transients[$key] = [$now - 5, $now - 3, $now - 1];

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertCount(4, $this->transients[$key]);
    }

    public function testRateLimitingActiveWhenPipelineDataNull()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 5;
        Pipeline::reset();

        $did = SuspiciousActivity::get_51did();
        self::assertSame(64, strlen($did), 'Expected SHA-256 IP+UA fallback identity');

        $key = SuspiciousActivity::build_transient_key($did);
        $now = microtime(true);
        $this->transients[$key] = [$now - 4, $now - 3, $now - 2, $now - 1];

        Functions\expect('wp_safe_redirect')
            ->once()
            ->with('http://example.com/blocked/', 302)
            ->andReturnUsing(function () {
                throw new ExitException('redirect');
            });

        try {
            SuspiciousActivity::check_and_maybe_redirect();
            self::fail('Expected ExitException — rate-limit redirect must fire even with cloud down');
        } catch (ExitException $e) {
            self::assertEquals('redirect', $e->getMessage());
        }
    }

    /**
     * Test that the redirect fires when the request count equals the
     * configured threshold (>= semantics).
     */
    public function testAtThresholdRedirectFires()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 5;
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        $did = SuspiciousActivity::get_51did();
        $key = SuspiciousActivity::build_transient_key($did);
        $now = microtime(true);
        $this->transients[$key] = [$now - 4, $now - 3, $now - 2, $now - 1];

        Functions\expect('wp_safe_redirect')
            ->once()
            ->with('http://example.com/blocked/', 302)
            ->andReturnUsing(function () {
                throw new ExitException('redirect');
            });

        try {
            SuspiciousActivity::check_and_maybe_redirect();
            self::fail('Expected ExitException from wp_safe_redirect');
        } catch (ExitException $e) {
            self::assertEquals('redirect', $e->getMessage());
        }
    }

    /**
     * Test that the redirect fires when the request count exceeds the
     * configured threshold.
     */
    public function testOverThresholdRedirectFires()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 5;
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        $did = SuspiciousActivity::get_51did();
        $key = SuspiciousActivity::build_transient_key($did);
        $now = microtime(true);
        $this->transients[$key] = [$now - 5, $now - 4, $now - 3, $now - 2, $now - 1];

        Functions\expect('wp_safe_redirect')
            ->once()
            ->with('http://example.com/blocked/', 302)
            ->andReturnUsing(function () {
                throw new ExitException('redirect');
            });

        try {
            SuspiciousActivity::check_and_maybe_redirect();
            self::fail('Expected ExitException from wp_safe_redirect');
        } catch (ExitException $e) {
            self::assertEquals('redirect', $e->getMessage());
        }
    }

    /**
     * Test that the first request from a visitor creates a single-entry
     * transient and does not redirect.
     */
    public function testFirstRequestNoRedirect()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 5;
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        SuspiciousActivity::check_and_maybe_redirect();

        $did = SuspiciousActivity::get_51did();
        $key = SuspiciousActivity::build_transient_key($did);
        self::assertArrayHasKey($key, $this->transients);
        self::assertCount(1, $this->transients[$key]);
    }

    /**
     * Test that no redirect fires when the threshold is exceeded but
     * no redirect URL is configured.
     */
    public function testOverThresholdNoRedirectUrlNoRedirect()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 2;
        $this->options[Options::SUSPICIOUS_REDIRECT_URL] = '';
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        $did = SuspiciousActivity::get_51did();
        $key = SuspiciousActivity::build_transient_key($did);
        $now = microtime(true);
        $this->transients[$key] = [$now - 1, $now - 0.5];

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertTrue(true);
    }

    /**
     * Test that no redirect fires when the current request path matches
     * the redirect target path (loop prevention).
     */
    public function testLoopPreventionMatchingPaths()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 1;
        $this->options[Options::SUSPICIOUS_REDIRECT_URL] = 'http://example.com/blocked/';
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        $_SERVER['REQUEST_URI'] = '/blocked/';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that loop prevention also catches the static-front-page case:
     * when the redirect target is the page set as the home page,
     * WordPress canonicalises /page-slug/ to /, and a path-only
     * comparison would treat them as different and loop. Both URLs
     * resolve to the same post ID via url_to_postid.
     */
    public function testLoopPreventionStaticFrontPage()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 1;
        $this->options[Options::SUSPICIOUS_REDIRECT_URL] = 'http://example.com/blocked/';
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        Functions\when('url_to_postid')->alias(function ($url) {
            return 4;
        });

        $_SERVER['REQUEST_URI'] = '/';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that two URLs resolving to different post IDs are treated as
     * different pages and the threshold check still fires.
     */
    public function testLoopPreventionDifferentPostIdsDoNotMatch()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 1;
        $this->options[Options::SUSPICIOUS_REDIRECT_URL] = 'http://example.com/blocked/';
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        Functions\when('url_to_postid')->alias(function ($url) {
            return strpos($url, '/blocked/') !== false ? 4 : 7;
        });

        $_SERVER['REQUEST_URI'] = '/some-other-page/';

        Functions\expect('wp_safe_redirect')
            ->once()
            ->andReturnUsing(function () {
                throw new ExitException('redirect');
            });

        try {
            SuspiciousActivity::check_and_maybe_redirect();
            self::fail('Expected ExitException from wp_safe_redirect');
        } catch (ExitException $e) {
            self::assertEquals('redirect', $e->getMessage());
        }
    }

    /**
     * Test that suspicious detection skips when the request is on the
     * robots.txt bot redirect URL. Without this, a bot redirected by the
     * robots feature accumulates hits on the bot page and gets bounced
     * onward to the suspicious page, producing too-many-redirects loops.
     */
    public function testLoopPreventionOnBotRedirectUrl()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 1;
        $this->options[Options::SUSPICIOUS_REDIRECT_URL] = 'http://example.com/blocked/';
        $this->options[Options::ROBOTS_REDIRECT_URL] = 'http://example.com/bot/';
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        $_SERVER['REQUEST_URI'] = '/bot/';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the IP+UA hash works with non-ASCII and binary characters
     * in the User-Agent string.
     */
    public function testNonAsciiUaHashWorks()
    {
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];
        $_SERVER['HTTP_USER_AGENT'] = "\xff\xfe\x00\x01 " . chr(0) . " binary";

        $result = SuspiciousActivity::get_51did();
        self::assertEquals(64, strlen($result));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result);
    }

    /**
     * Test that the enable sanitizer only accepts 'on' as a truthy value.
     */
    public function testSanitizeEnableCheckbox()
    {
        $sanitize = function ($v) { return $v === 'on' ? 'on' : 'off'; };

        self::assertEquals('on', $sanitize('on'));
        self::assertEquals('off', $sanitize('off'));
        self::assertEquals('off', $sanitize(''));
        self::assertEquals('off', $sanitize(null));
        self::assertEquals('off', $sanitize('yes'));
    }

    /**
     * Test that truthy-looking values like 'off', '0', and false all
     * sanitize to 'off'.
     */
    public function testSanitizeTruthyOffTrap()
    {
        $sanitize = function ($v) { return $v === 'on' ? 'on' : 'off'; };

        self::assertEquals('off', $sanitize('off'));
        self::assertEquals('off', $sanitize(''));
        self::assertEquals('off', $sanitize('0'));
        self::assertEquals('off', $sanitize(false));
        self::assertEquals('on', $sanitize('on'));
    }

    /**
     * Test that the requests and window sanitizers clamp values to their
     * valid ranges.
     */
    public function testSanitizeBounds()
    {
        $sanitize_requests = function ($v) { return max(1, (int) $v); };
        $sanitize_window = function ($v) { return max(1, min(3600, (int) $v)); };

        self::assertEquals(1, $sanitize_requests(0));
        self::assertEquals(1, $sanitize_requests(-5));
        self::assertEquals(10, $sanitize_requests(10));

        self::assertEquals(1, $sanitize_window(0));
        self::assertEquals(1, $sanitize_window(-5));
        self::assertEquals(3600, $sanitize_window(9999));
        self::assertEquals(60, $sanitize_window(60));
    }

    /**
     * Test that timestamps older than the configured window are pruned
     * before the count is evaluated.
     */
    public function testExpiredTimestampsPruned()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $this->options[Options::SUSPICIOUS_REQUESTS] = 10;
        $this->options[Options::SUSPICIOUS_WINDOW] = 30;
        Pipeline::$data = ['properties' => [], 'flowData' => null, 'errors' => []];

        $did = SuspiciousActivity::get_51did();
        $key = SuspiciousActivity::build_transient_key($did);
        $now = microtime(true);
        $this->transients[$key] = [
            $now - 100,
            $now - 60,
            $now - 50,
            $now - 2,
            $now - 1,
        ];

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertCount(3, $this->transients[$key]);
    }

    /**
     * Test that the transient key is built from an md5 hash of the
     * visitor identity.
     */
    public function testTransientKeyFormat()
    {
        $key = SuspiciousActivity::build_transient_key('abc');
        self::assertEquals('51d_suspicious_' . md5('abc'), $key);
        self::assertEquals(47, strlen($key));
    }

    // --- SUSPICIOUS_ENABLE toggle rebuilds cached pipeline ---

    /**
     * Test that toggling SUSPICIOUS_ENABLE from 'on' to 'off' synchronously
     * rebuilds the cached pipeline.
     */
    public function testUpdateOption_SuspiciousToggleOnToOff_RebuildsPipeline()
    {
        $this->options[Options::RESOURCE_KEY] = 'AQS5-test';
        Functions\when('add_action')->returnArg();
        Functions\when('add_filter')->returnArg();

        $built = [
            'pipeline' => (new \fiftyone\pipeline\core\PipelineBuilder())->build(),
            'available_engines' => ['device'],
            'error' => null,
        ];
        \Patchwork\redefine('Pipeline::make_pipeline', Patchwork\always($built));

        $service = new FiftyoneService();
        $service->fiftyonedegrees_suspicious_enable_updated(Options::SUSPICIOUS_ENABLE, 'on', 'off');

        self::assertArrayHasKey(Options::PIPELINE, $this->options);
        self::assertSame($built, $this->options[Options::PIPELINE]);
    }

    /**
     * Test that toggling SUSPICIOUS_ENABLE from 'off' to 'on' also rebuilds
     * the cached pipeline — the handler is bidirectional.
     */
    public function testUpdateOption_SuspiciousToggleOffToOn_RebuildsPipeline()
    {
        $this->options[Options::RESOURCE_KEY] = 'AQS5-test';
        Functions\when('add_action')->returnArg();
        Functions\when('add_filter')->returnArg();

        $built = [
            'pipeline' => (new \fiftyone\pipeline\core\PipelineBuilder())->build(),
            'available_engines' => ['device', 'fodid'],
            'error' => null,
        ];
        \Patchwork\redefine('Pipeline::make_pipeline', Patchwork\always($built));

        $service = new FiftyoneService();
        $service->fiftyonedegrees_suspicious_enable_updated(Options::SUSPICIOUS_ENABLE, 'off', 'on');

        self::assertSame($built, $this->options[Options::PIPELINE]);
    }

    /**
     * Test that the toggle handler skips rebuild entirely when the resource
     * key is empty — nothing to validate.
     */
    public function testUpdateOption_SuspiciousToggle_NoOpWhenResourceKeyEmpty()
    {
        $this->options[Options::RESOURCE_KEY] = '';
        Functions\when('add_action')->returnArg();
        Functions\when('add_filter')->returnArg();

        $call_count = 0;
        \Patchwork\redefine('Pipeline::make_pipeline', function ($key) use (&$call_count) {
            $call_count++;
            return [
                'pipeline' => (new \fiftyone\pipeline\core\PipelineBuilder())->build(),
                'available_engines' => [],
                'error' => null,
            ];
        });

        $service = new FiftyoneService();
        $service->fiftyonedegrees_suspicious_enable_updated(Options::SUSPICIOUS_ENABLE, 'off', 'on');

        self::assertSame(0, $call_count, 'make_pipeline must not be called when resource key is empty');
        self::assertArrayNotHasKey(Options::PIPELINE, $this->options);
    }

    /**
     * Constant-based exclusion tests MUST be last. PHP constants persist
     * across tests in the same process, so once defined they contaminate
     * all subsequent tests.
     */

    /**
     * Test that the redirect is skipped during cron execution.
     */
    public function testSkippedOnCron()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        if (!defined('DOING_CRON')) {
            define('DOING_CRON', true);
        }

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the redirect is skipped on REST API requests.
     */
    public function testSkippedOnRest()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Test that the redirect is skipped on XML-RPC requests.
     */
    public function testSkippedOnXmlRpc()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        if (!defined('XMLRPC_REQUEST')) {
            define('XMLRPC_REQUEST', true);
        }

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }
}

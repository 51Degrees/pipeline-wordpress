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
     * Assembles an OWID v3 envelope around a payload and returns it
     * base64-encoded. The envelope is a version byte, the creator domain
     * as ASCII with a zero terminator, four date bytes, the payload
     * length as four little-endian bytes, the payload and then the
     * 64-byte signature.
     */
    private function buildEnvelope(
        $payload,
        $signature64 = null,
        $version = 3,
        $domain = '51d.es'
    ) {
        $signature = $signature64 ?? str_repeat("\x00", 64);
        if (strlen($signature) !== 64) {
            throw new \InvalidArgumentException(
                'signature must be exactly 64 bytes'
            );
        }
        $envelope = chr($version)            // Version (1 byte)
            . $domain . "\x00"               // Domain (null-terminated)
            . pack('V', 3320214)             // Date (4 bytes LE, arbitrary)
            . pack('V', strlen($payload))    // Payload Length (4 bytes LE)
            . $payload
            . $signature;
        return base64_encode($envelope);
    }

    /**
     * Assembles a 51Did payload, being the flags byte, the four-byte
     * licence field, the value and an optional creator context section.
     * Bits 6 and 7 of the flags carry the identifier type, which the
     * default of 0x01 leaves at Probabilistic.
     */
    private function buildPayload($value, $flags = 0x01, $context = '')
    {
        return chr($flags)                   // Flags (1 byte)
            . pack('V', 19493)               // License Key ID (4 bytes LE)
            . $value
            . $context;
    }

    /**
     * Assembles a base64-encoded OWID v3 envelope carrying a
     * Probabilistic 51Did with the given 32-byte identity.
     */
    private function buildOwid(
        $identity32,
        $signature64 = null,
        $version = 3,
        $context = '',
        $domain = '51d.es'
    ) {
        if (strlen($identity32) !== 32) {
            throw new \InvalidArgumentException(
                'identity must be exactly 32 bytes'
            );
        }
        return $this->buildEnvelope(
            $this->buildPayload($identity32, 0x01, $context),
            $signature64,
            $version,
            $domain
        );
    }

    /**
     * Builds a creator context section of the given length. The plugin
     * must not care what is inside it, so the bytes are arbitrary.
     */
    private function buildContext($length)
    {
        return str_repeat("\xAB", $length);
    }

    /**
     * Puts a token on the pipeline as IdProbLic and returns the identity
     * the suspicious activity feature derives from it.
     */
    private function identityFromToken($token)
    {
        Pipeline::$data = [
            'properties' => [
                'did_engine' => [
                    'idproblic' => ['name' => 'IdProbLic', 'type' => 'String'],
                ],
            ],
            'flowData' => $this->buildMockFlowData(
                'did_engine',
                'idproblic',
                $token
            ),
            'errors' => [],
        ];
        return SuspiciousActivity::get_51did();
    }

    /**
     * The identity the feature falls back to when nothing parses, which
     * is a SHA-256 of the client IP and the User-Agent.
     */
    private function ipUaFallback()
    {
        return hash('sha256', '192.168.1.100|TestBrowser/1.0');
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
     * /robots.txt is crawler metadata; counting it would redirect bots
     * away from the policy they were asked to fetch and break the paired
     * robots-enforce feature.
     */
    public function testSkippedOnRobotsTxt()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $_SERVER['REQUEST_URI'] = '/robots.txt';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    /**
     * Issue #69: ads.txt / app-ads.txt / /.well-known/* are bot-facing
     * policy files and must be exempt from throttling, like robots.txt.
     */
    public function testSkippedOnAdsTxt()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $_SERVER['REQUEST_URI'] = '/ads.txt';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    public function testSkippedOnAppAdsTxt()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $_SERVER['REQUEST_URI'] = '/app-ads.txt';

        SuspiciousActivity::check_and_maybe_redirect();

        self::assertEmpty($this->transients);
    }

    public function testSkippedOnWellKnownPath()
    {
        $this->options[Options::SUSPICIOUS_ENABLE] = 'on';
        $_SERVER['REQUEST_URI'] = '/.well-known/security.txt';

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
     * Test that an identifier carrying a creator context gives the same
     * tracking key as the same identifier without one. The cloud starts
     * issuing the context section with the creator context release, and
     * an exact length check on the envelope silently dropped every
     * identifier from that day, which sent every site back to the IP and
     * User-Agent fallback.
     *
     * The 136 bytes asserted here are what the cloud issues with its own
     * 51d.es domain, being the previous 117-byte envelope plus a 19-byte
     * version 0 context section. The number is pinned in the test on
     * purpose, so a regression to an exact length check in the reader
     * fails here. The reader itself must not know it.
     */
    public function testIdentityWithCreatorContextMatchesWithout()
    {
        $identity = str_repeat("\x7f", 32);
        $withoutContext = $this->buildOwid($identity);
        $withContext = $this->buildOwid(
            $identity,
            null,
            3,
            $this->buildContext(19)
        );

        self::assertEquals(117, strlen(base64_decode($withoutContext)));
        self::assertEquals(136, strlen(base64_decode($withContext)));

        $before = $this->identityFromToken($withoutContext);
        $after = $this->identityFromToken($withContext);

        self::assertEquals(bin2hex($identity), $after);
        self::assertEquals($before, $after);
    }

    /**
     * Test that a context section of some other length is read the same
     * way, so the plugin keeps working if the section ever grows. The
     * plugin must not assume any context length at all.
     */
    public function testIdentityWithLongerCreatorContextReadsValue()
    {
        $identity = str_repeat("\x31", 32);
        $token = $this->buildOwid(
            $identity,
            null,
            3,
            $this->buildContext(64)
        );

        self::assertEquals(
            bin2hex($identity),
            $this->identityFromToken($token)
        );
    }

    /**
     * Test that a self-hosted deployment signing with its own creator
     * domain is read correctly. The domain sits between the version byte
     * and the payload, so a longer domain moves the value and a fixed
     * offset would read the wrong bytes.
     */
    public function testIdentitySelfHostedDomainReadsValue()
    {
        $identity = str_repeat("\x66", 32);
        $token = $this->buildOwid(
            $identity,
            null,
            3,
            $this->buildContext(19),
            'id.self-hosted.example.com'
        );

        self::assertEquals(
            bin2hex($identity),
            $this->identityFromToken($token)
        );
    }

    /**
     * Test that a HashedEmail identifier, which sets bit 7 of the flags
     * and carries a 32-byte value like the Probabilistic type, gives the
     * same 64-character key.
     */
    public function testIdentityHashedEmailTypeReadsValue()
    {
        $identity = str_repeat("\x0c", 32);
        $token = $this->buildEnvelope(
            $this->buildPayload($identity, 0x80, $this->buildContext(19))
        );

        $result = $this->identityFromToken($token);
        self::assertEquals(bin2hex($identity), $result);
        self::assertEquals(64, strlen($result));
    }

    /**
     * Test that a Random identifier, whose value is a 16-byte GUID
     * rather than a 32-byte hash, gives the hex of those 16 bytes. That
     * is 32 characters rather than 64. The result is only ever used as a
     * per-visitor key, so a shorter string is still a complete and
     * stable identity, and inventing padding or hashing the GUID would
     * produce a key no other 51Did reader agrees with.
     */
    public function testIdentityRandomTypeReadsGuid()
    {
        $guid = str_repeat("\x22", 16);
        $token = $this->buildEnvelope($this->buildPayload($guid, 0x41));

        $result = $this->identityFromToken($token);
        self::assertEquals(bin2hex($guid), $result);
        self::assertEquals(32, strlen($result));
    }

    /**
     * Test that a Random identifier carrying a creator context gives the
     * same key as the same GUID without one, so the context section is
     * never read as part of the value.
     */
    public function testIdentityRandomWithCreatorContextMatchesWithout()
    {
        $guid = str_repeat("\x9e", 16);
        $plain = $this->buildEnvelope($this->buildPayload($guid, 0x41));
        $withContext = $this->buildEnvelope(
            $this->buildPayload($guid, 0x41, $this->buildContext(19))
        );

        self::assertEquals(
            $this->identityFromToken($plain),
            $this->identityFromToken($withContext)
        );
    }

    /**
     * Test that the reserved identifier type falls back to the IP and
     * User-Agent hash. Its value has no assigned length, so guessing one
     * would give a key that is not stable.
     */
    public function testIdentityReservedTypeFallsBackToIpUa()
    {
        $token = $this->buildEnvelope(
            $this->buildPayload(str_repeat("\x44", 32), 0xC1)
        );

        self::assertNull(SuspiciousActivity::extract_owid_identifier($token));
        self::assertEquals(
            $this->ipUaFallback(),
            $this->identityFromToken($token)
        );
    }

    /**
     * Test that an envelope cut short after the payload, so that the
     * signature is incomplete, is rejected rather than read.
     */
    public function testIdentityTruncatedSignatureFallsBackToIpUa()
    {
        $full = base64_decode($this->buildOwid(str_repeat("\x12", 32)));
        $token = base64_encode(substr($full, 0, strlen($full) - 1));

        self::assertNull(SuspiciousActivity::extract_owid_identifier($token));
        self::assertEquals(
            $this->ipUaFallback(),
            $this->identityFromToken($token)
        );
    }

    /**
     * Test that a payload holding only the flags and licence header,
     * with no value after it, is rejected rather than read as an empty
     * or partial key.
     */
    public function testIdentityPayloadWithoutValueFallsBackToIpUa()
    {
        $token = $this->buildEnvelope($this->buildPayload(''));

        self::assertNull(SuspiciousActivity::extract_owid_identifier($token));
        self::assertEquals(
            $this->ipUaFallback(),
            $this->identityFromToken($token)
        );
    }

    /**
     * Test that a payload holding only part of the value is rejected.
     * Reading it would give a short key that changes shape.
     */
    public function testIdentityPartialValueFallsBackToIpUa()
    {
        $token = $this->buildEnvelope(
            $this->buildPayload(str_repeat("\x77", 20))
        );

        self::assertNull(SuspiciousActivity::extract_owid_identifier($token));
        self::assertEquals(
            $this->ipUaFallback(),
            $this->identityFromToken($token)
        );
    }

    /**
     * Test that an envelope whose payload length field asks for more
     * bytes than the envelope holds is rejected.
     */
    public function testIdentityOverstatedPayloadLengthFallsBackToIpUa()
    {
        $identity = str_repeat("\x5a", 32);
        $payload = $this->buildPayload($identity);
        $envelope = chr(3)
            . "51d.es\x00"
            . pack('V', 3320214)
            . pack('V', strlen($payload) + 32)
            . $payload
            . str_repeat("\x00", 64);

        $token = base64_encode($envelope);
        self::assertNull(SuspiciousActivity::extract_owid_identifier($token));
        self::assertEquals(
            $this->ipUaFallback(),
            $this->identityFromToken($token)
        );
    }

    /**
     * Test that an envelope with no zero terminator after the domain is
     * rejected, since the payload cannot be located without it.
     */
    public function testIdentityUnterminatedDomainFallsBackToIpUa()
    {
        $token = base64_encode(chr(3) . str_repeat("\x41", 116));

        self::assertNull(SuspiciousActivity::extract_owid_identifier($token));
        self::assertEquals(
            $this->ipUaFallback(),
            $this->identityFromToken($token)
        );
    }

    /**
     * Test that an empty string and a value that is not a string are
     * rejected without a warning.
     */
    public function testIdentityEmptyAndNonStringTokensReturnNull()
    {
        self::assertNull(SuspiciousActivity::extract_owid_identifier(''));
        self::assertNull(SuspiciousActivity::extract_owid_identifier(null));
        self::assertNull(SuspiciousActivity::extract_owid_identifier(42));
        self::assertNull(SuspiciousActivity::extract_owid_identifier('===='));
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
     * Test that when the rebuild fails, SUSPICIOUS_ENABLE is reverted to
     * the old value, PIPELINE_VALIDATION_ERROR is cleared after the
     * revert, and a dismissible admin-notice transient is set.
     */
    public function testUpdateOption_SuspiciousToggle_RevertsOnRebuildFailure()
    {
        $this->options[Options::RESOURCE_KEY] = 'AQS5-test';
        $this->options[Options::SUSPICIOUS_ENABLE] = 'off';
        Functions\when('add_action')->returnArg();
        Functions\when('add_filter')->returnArg();

        \Patchwork\redefine('Pipeline::make_pipeline', Patchwork\always([
            'pipeline' => null,
            'available_engines' => null,
            'error' => 'cloud unreachable',
        ]));

        $service = new FiftyoneService();
        $service->fiftyonedegrees_suspicious_enable_updated(Options::SUSPICIOUS_ENABLE, 'on', 'off');

        self::assertSame('on', $this->options[Options::SUSPICIOUS_ENABLE], 'SUSPICIOUS_ENABLE must be reverted');
        self::assertArrayNotHasKey(Options::PIPELINE_VALIDATION_ERROR, $this->options, 'PIPELINE_VALIDATION_ERROR must be cleared after revert');
        self::assertArrayHasKey('fiftyonedegrees_suspicious_toggle_failed', $this->transients);
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

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

require_once(__DIR__ . "/../includes/client-ip.php");

use Yoast\PHPUnitPolyfills\TestCases\TestCase;


class ClientIpResolverTests extends TestCase {

    /**
     * Test that when no proxy headers are present the resolver
     * falls back to REMOTE_ADDR.
     */
    public function testNoHeadersFallsBackToRemoteAddr() {
        $this->assertSame('203.0.113.1', ClientIpResolver::resolve([
            'REMOTE_ADDR' => '203.0.113.1',
        ]));
    }

    /**
     * Test that CF-Connecting-IP wins over every lower-priority
     * header when more than one is present.
     */
    public function testCloudflareBeatsAllOtherHeaders() {
        $this->assertSame('198.51.100.5', ClientIpResolver::resolve([
            'HTTP_CF_CONNECTING_IP' => '198.51.100.5',
            'HTTP_TRUE_CLIENT_IP'   => '198.51.100.6',
            'HTTP_X_REAL_IP'        => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR'  => '198.51.100.8',
            'HTTP_CLIENT_IP'        => '198.51.100.9',
            'REMOTE_ADDR'           => '203.0.113.1',
        ]));
    }

    /**
     * Test that True-Client-IP wins when CF-Connecting-IP is
     * absent.
     */
    public function testTrueClientBeatsLowerPriority() {
        $this->assertSame('198.51.100.6', ClientIpResolver::resolve([
            'HTTP_TRUE_CLIENT_IP'  => '198.51.100.6',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.8',
            'HTTP_CLIENT_IP'       => '198.51.100.9',
            'REMOTE_ADDR'          => '203.0.113.1',
        ]));
    }

    /**
     * Test that X-Real-IP wins when higher-priority headers are
     * absent.
     */
    public function testXRealIpBeatsLowerPriority() {
        $this->assertSame('198.51.100.7', ClientIpResolver::resolve([
            'HTTP_X_REAL_IP'       => '198.51.100.7',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.8',
            'REMOTE_ADDR'          => '203.0.113.1',
        ]));
    }

    /**
     * Test that X-Forwarded-For wins over the legacy Client-IP
     * header.
     */
    public function testXForwardedBeatsClientIp() {
        $this->assertSame('198.51.100.8', ClientIpResolver::resolve([
            'HTTP_X_FORWARDED_FOR' => '198.51.100.8',
            'HTTP_CLIENT_IP'       => '198.51.100.9',
            'REMOTE_ADDR'          => '203.0.113.1',
        ]));
    }

    /**
     * Test that Client-IP is used when it is the only proxy header
     * present.
     */
    public function testClientIpUsedWhenOnlyHeaderPresent() {
        $this->assertSame('198.51.100.9', ClientIpResolver::resolve([
            'HTTP_CLIENT_IP' => '198.51.100.9',
            'REMOTE_ADDR'    => '203.0.113.1',
        ]));
    }

    /**
     * Test that X-Forwarded-For takes the first entry from the
     * comma-separated list.
     */
    public function testXForwardedTakesFirstEntry() {
        $this->assertSame('203.0.113.1', ClientIpResolver::resolve([
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1, 10.0.0.1, 10.0.0.2',
            'REMOTE_ADDR'          => '127.0.0.1',
        ]));
    }

    /**
     * Test that whitespace around X-Forwarded-For entries is
     * trimmed.
     */
    public function testXForwardedTrimsWhitespace() {
        $this->assertSame('203.0.113.1', ClientIpResolver::resolve([
            'HTTP_X_FORWARDED_FOR' => '  203.0.113.1  , 10.0.0.1',
            'REMOTE_ADDR'          => '127.0.0.1',
        ]));
    }

    /**
     * Test that a malformed higher-priority header is skipped and
     * the walk continues to the next header.
     */
    public function testMalformedHigherPriorityHeaderSkipsToNext() {
        $this->assertSame('5.6.7.8', ClientIpResolver::resolve([
            'HTTP_CF_CONNECTING_IP' => 'garbage',
            'HTTP_X_FORWARDED_FOR'  => '5.6.7.8',
            'REMOTE_ADDR'           => '127.0.0.1',
        ]));
    }

    /**
     * Test that when every proxy header is present but syntactically
     * invalid, the resolver falls back to REMOTE_ADDR.
     */
    public function testAllHeadersMalformedFallsBackToRemoteAddr() {
        $this->assertSame('203.0.113.1', ClientIpResolver::resolve([
            'HTTP_CF_CONNECTING_IP' => 'garbage-1',
            'HTTP_TRUE_CLIENT_IP'   => 'garbage-2',
            'HTTP_X_REAL_IP'        => 'garbage-3',
            'HTTP_X_FORWARDED_FOR'  => 'garbage-4',
            'HTTP_CLIENT_IP'        => 'garbage-5',
            'REMOTE_ADDR'           => '203.0.113.1',
        ]));
    }

    /**
     * Test that when X-Forwarded-For's first entry is malformed
     * the resolver does not fall through to later entries of the
     * same header (those are proxies, not the client).
     */
    public function testXForwardedFirstEntryMalformedDoesNotUseLaterEntries() {
        $this->assertSame('203.0.113.1', ClientIpResolver::resolve([
            'HTTP_X_FORWARDED_FOR' => 'garbage, 5.6.7.8',
            'REMOTE_ADDR'          => '203.0.113.1',
        ]));
    }

    /**
     * Test that a valid IPv6 address in a proxy header is
     * returned as-is.
     */
    public function testIpv6Accepted() {
        $this->assertSame('2001:4860:4860::8888', ClientIpResolver::resolve([
            'HTTP_CF_CONNECTING_IP' => '2001:4860:4860::8888',
            'REMOTE_ADDR'           => '203.0.113.1',
        ]));
    }

    /**
     * Test that a private-range IP in a proxy header is
     * accepted without filtering.
     */
    public function testPrivateIpAcceptedWhenInHeader() {
        $this->assertSame('10.0.0.1', ClientIpResolver::resolve([
            'HTTP_CF_CONNECTING_IP' => '10.0.0.1',
            'REMOTE_ADDR'           => '203.0.113.1',
        ]));
    }

    /**
     * Test that when neither proxy headers nor REMOTE_ADDR are
     * present the resolver returns an empty string.
     */
    public function testEmptyServerReturnsEmptyString() {
        $this->assertSame('', ClientIpResolver::resolve([]));
    }

    /**
     * Test that REMOTE_ADDR is returned without FILTER_VALIDATE_IP
     * (it is server-controlled, not client-forgeable).
     */
    public function testRemoteAddrNotValidated() {
        $this->assertSame('anything-goes', ClientIpResolver::resolve([
            'REMOTE_ADDR' => 'anything-goes',
        ]));
    }
}

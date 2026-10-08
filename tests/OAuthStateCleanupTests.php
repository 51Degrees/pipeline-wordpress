<?php

require_once __DIR__ . '/../includes/oauth-state.php';

use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Time-freezing subclass used to assert that cleanup binds the current
 * cutoff timestamp (and only that timestamp) into the prepared %d slot.
 */
class TestableOauthStateForCleanup extends FiftyOneDegreesOauthState
{
    public static $frozen_time = 1700000000;

    protected static function now()
    {
        return self::$frozen_time;
    }
}

/**
 * Stand-in for $wpdb that captures the prepare/get_col arguments and
 * returns a scripted result set. esc_like mirrors the production
 * behavior just enough for the LIKE pattern assertion to be meaningful.
 */
class FakeWpdbForCleanup
{
    public $options = 'wp_options';
    public $prepared = [];
    public $result = [];

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, ...$args)
    {
        $this->prepared[] = ['query' => $query, 'args' => $args];
        return $query;
    }

    public function get_col($prepared)
    {
        return $this->result;
    }
}

class OAuthStateCleanupTests extends TestCase
{
    private $deleted = [];

    public function set_up()
    {
        parent::set_up();
        Brain\Monkey\setUp();
        $this->deleted = [];
        Functions\when('delete_transient')->alias(function ($key) {
            $this->deleted[] = $key;
            return true;
        });
    }

    public function tear_down()
    {
        unset($GLOBALS['wpdb']);
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    private function withFakeWpdb(array $rows): FakeWpdbForCleanup
    {
        $wpdb = new FakeWpdbForCleanup();
        $wpdb->result = $rows;
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    public function testDeletesEachReturnedNonceKey()
    {
        $this->withFakeWpdb([
            'fiftyonedegrees_oauth_pending_aaaa',
            'fiftyonedegrees_oauth_pending_bbbb',
        ]);

        $deleted = FiftyOneDegreesOauthState::cleanup_expired_pending();

        $this->assertSame(2, $deleted);
        $this->assertSame([
            'fiftyonedegrees_oauth_pending_aaaa',
            'fiftyonedegrees_oauth_pending_bbbb',
        ], $this->deleted);
    }

    public function testReturnsZeroWhenNoRows()
    {
        $this->withFakeWpdb([]);

        $deleted = FiftyOneDegreesOauthState::cleanup_expired_pending();

        $this->assertSame(0, $deleted);
        $this->assertSame([], $this->deleted);
    }

    public function testIgnoresNonStringRowsSafely()
    {
        $this->withFakeWpdb([
            'fiftyonedegrees_oauth_pending_good',
            null,
            123,
            '',
        ]);

        $deleted = FiftyOneDegreesOauthState::cleanup_expired_pending();

        $this->assertSame(1, $deleted);
        $this->assertSame(['fiftyonedegrees_oauth_pending_good'], $this->deleted);
    }

    public function testNoOpWhenWpdbMissing()
    {
        unset($GLOBALS['wpdb']);

        $deleted = FiftyOneDegreesOauthState::cleanup_expired_pending();

        $this->assertSame(0, $deleted);
        $this->assertSame([], $this->deleted);
    }

    public function testQueryUsesPreparedLikePatternBoundToPrefix()
    {
        $wpdb = $this->withFakeWpdb([]);

        FiftyOneDegreesOauthState::cleanup_expired_pending();

        $this->assertCount(1, $wpdb->prepared);
        $call = $wpdb->prepared[0];
        $this->assertStringContainsString('SELECT', $call['query']);
        $this->assertStringContainsString('SUBSTRING(option_name', $call['query']);
        $this->assertStringContainsString('option_name LIKE %s', $call['query']);
        $this->assertStringContainsString('option_value < %d', $call['query']);

        $this->assertCount(2, $call['args']);
        // Pattern is run through esc_like, which backslash-escapes the
        // literal underscores; strip those before comparing to the prefix.
        $unescaped = stripcslashes($call['args'][0]);
        $this->assertStringStartsWith(
            '_transient_timeout_' . FiftyOneDegreesOauthState::TRANSIENT_PREFIX,
            $unescaped
        );
        $this->assertStringEndsWith('%', $call['args'][0]);
        $this->assertIsInt($call['args'][1]);
        $this->assertGreaterThan(0, $call['args'][1]);
    }

    /**
     * Locks in the cutoff semantics — only rows whose timeout is
     * strictly less than `now()` are eligible. We freeze the clock via
     * TestableOauthStateForCleanup and confirm the same value is bound
     * into the prepared %d slot, so a regression in the cutoff (e.g.
     * accidentally using a static literal, or off-by-TTL) surfaces here.
     */
    public function testPreparedCutoffEqualsFrozenNow()
    {
        $wpdb = $this->withFakeWpdb([]);
        TestableOauthStateForCleanup::$frozen_time = 1700000000;

        TestableOauthStateForCleanup::cleanup_expired_pending();

        $this->assertCount(1, $wpdb->prepared);
        $this->assertSame(1700000000, $wpdb->prepared[0]['args'][1]);
    }

    /**
     * The LIKE pattern must be anchored on the OAuth prefix and not
     * match unrelated plugins' transients. Pre-anchor check + simulated
     * MySQL LIKE evaluation against a handful of foreign keys.
     */
    public function testLikePatternDoesNotMatchOtherPluginTransients()
    {
        $wpdb = $this->withFakeWpdb([]);

        FiftyOneDegreesOauthState::cleanup_expired_pending();

        $pattern = stripcslashes($wpdb->prepared[0]['args'][0]);

        // Convert MySQL LIKE pattern to regex: % -> .*, _ -> . (no _
        // wildcards survive because esc_like escapes them, then stripcslashes
        // restored them as literals — convert remaining literal underscores
        // back to literal matches, only `%` stays a wildcard).
        $regex = '/^' . str_replace(
            ['%', '/'],
            ['.*', '\\/'],
            preg_quote($pattern, '/')
        ) . '$/';

        $this->assertSame(1, preg_match($regex, '_transient_timeout_fiftyonedegrees_oauth_pending_abcd1234'));
        $this->assertSame(0, preg_match($regex, '_transient_timeout_some_other_plugin_token'));
        $this->assertSame(0, preg_match($regex, '_transient_timeout_fiftyonedegrees_robots_cloud_error'));
        $this->assertSame(0, preg_match($regex, '_transient_fiftyonedegrees_oauth_pending_abcd'));
        $this->assertSame(0, preg_match($regex, 'fiftyonedegrees_oauth_pending_abcd'));
    }

    /**
     * cron_cleanup is the cron-facing wrapper around cleanup_expired_pending.
     * Swallows Throwable and logs the count when non-zero. These tests do
     * not assert on error_log output (would couple to host behavior) — they
     * verify the wrapper does not propagate exceptions and that the
     * underlying delete loop still runs.
     */
    public function testCronCleanupRunsUnderlyingDeletion()
    {
        $this->withFakeWpdb([
            'fiftyonedegrees_oauth_pending_xyz',
        ]);

        FiftyOneDegreesOauthState::cron_cleanup();

        $this->assertSame(['fiftyonedegrees_oauth_pending_xyz'], $this->deleted);
    }

    public function testDeleteOptionsRemovesStateSecret()
    {
        $deletedOptions = [];
        Functions\when('delete_option')->alias(function ($key) use (&$deletedOptions) {
            $deletedOptions[] = $key;
            return true;
        });

        FiftyOneDegreesOauthState::delete_options();

        $this->assertSame([Options::OAUTH_STATE_SECRET], $deletedOptions);
    }

    public function testCronCleanupSwallowsThrowableFromInner()
    {
        // No $wpdb installed and a delete_transient stub that throws would
        // not be reached. Force a Throwable from inside the loop instead:
        // a fake $wpdb whose get_col throws.
        $throwing = new class extends FakeWpdbForCleanup {
            public function get_col($prepared)
            {
                throw new \RuntimeException('simulated DB failure');
            }
        };
        $GLOBALS['wpdb'] = $throwing;

        // Must not throw.
        FiftyOneDegreesOauthState::cron_cleanup();

        $this->assertSame([], $this->deleted);
    }
}

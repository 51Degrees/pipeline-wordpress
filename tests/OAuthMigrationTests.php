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

require_once __DIR__ . '/../includes/oauth-migration.php';

use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class OAuthMigrationTests extends TestCase
{
    public function set_up()
    {
        parent::set_up();
        Brain\Monkey\setUp();
    }

    public function tear_down()
    {
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * In-memory option store so a single test can observe the full
     * delete + update sequence without juggling individual expect() calls.
     * Returns the closures so the test can also assert against the final
     * state.
     */
    private function stub_option_store(array $initial)
    {
        $store = new \stdClass();
        $store->data = $initial;

        Functions\when('get_option')->alias(function ($key, $default = false) use ($store) {
            return array_key_exists($key, $store->data) ? $store->data[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) use ($store) {
            $store->data[$key] = $value;

            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use ($store) {
            $existed = array_key_exists($key, $store->data);
            unset($store->data[$key]);

            return $existed;
        });

        return $store;
    }

    private function stub_transients()
    {
        $store = new \stdClass();
        $store->data = [];
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use ($store) {
            $store->data[$key] = $value;

            return true;
        });
        Functions\when('get_transient')->alias(function ($key) use ($store) {
            return array_key_exists($key, $store->data) ? $store->data[$key] : false;
        });

        return $store;
    }

    // ─── Case 1: OOB-active install → wipe + notice + mark migrated ─────

    public function testWipesOobTokenWhenAuthCodePresent()
    {
        $options = $this->stub_option_store([
            Options::GA_AUTH_CODE => 'legacy-oob-code',
            Options::GA_TOKEN     => '{"access_token":"legacy"}',
            Options::GA_AUTH_DATE => 1700000000,
            // GA_OAUTH_VERSION absent
        ]);
        $transients = $this->stub_transients();

        FiftyOneDegreesOauthMigration::run();

        $this->assertArrayNotHasKey(Options::GA_TOKEN, $options->data,
            'OOB-era access token must be wiped so the user reconnects');
        $this->assertArrayNotHasKey(Options::GA_AUTH_DATE, $options->data,
            'auth date must be wiped together with the token');
        $this->assertArrayNotHasKey(Options::GA_AUTH_CODE, $options->data,
            'GA_AUTH_CODE has no successor in the new flow; remove unconditionally');
        $this->assertSame('2', $options->data[Options::GA_OAUTH_VERSION],
            'version marker must be set so the migration is idempotent');
        $this->assertArrayHasKey('fiftyonedegrees_oauth_migration_notice', $transients->data,
            'admin must see a notice explaining the reconnect requirement');
    }

    // ─── Case 2: token-shape gate — no AUTH_CODE → no wipe ──────────────

    public function testDoesNotWipeWhenAuthCodeAbsent()
    {
        // GA_TOKEN may be present (e.g. backup-restored DB without the
        // matching AUTH_CODE row), but if the token-shape gate fails we
        // must NOT wipe — the documented assumption is that no working
        // OOB clients exist, but we don't want a backup-restore to also
        // burn a freshly-issued post-migration token.
        $options = $this->stub_option_store([
            Options::GA_TOKEN => '{"access_token":"new-shape"}',
            // GA_AUTH_CODE absent, GA_OAUTH_VERSION absent
        ]);
        $transients = $this->stub_transients();

        FiftyOneDegreesOauthMigration::run();

        $this->assertSame('{"access_token":"new-shape"}', $options->data[Options::GA_TOKEN],
            'token must be preserved when GA_AUTH_CODE is absent (token-shape gate)');
        $this->assertSame('2', $options->data[Options::GA_OAUTH_VERSION],
            'version marker still gets set so we do not re-evaluate next request');
        $this->assertArrayNotHasKey('fiftyonedegrees_oauth_migration_notice', $transients->data,
            'no migration happened — do not show a misleading reconnect notice');
    }

    // ─── Case 2a: empty-string GA_AUTH_CODE row → still no wipe ─────────

    public function testDoesNotWipeWhenAuthCodeIsEmptyString()
    {
        // ga-service.php writes GA_AUTH_CODE straight from a form submit,
        // so an admin who opened the OAuth tab and submitted blank input
        // leaves '' (or '0') in the row. That is NOT an OOB-era marker —
        // no real authorization ever happened. The gate must distinguish
        // 'row absent / empty-row noise' from 'row holds a real code'.
        $options = $this->stub_option_store([
            Options::GA_AUTH_CODE => '',
            Options::GA_TOKEN     => '{"access_token":"keepme"}',
            Options::GA_AUTH_DATE => 1700000000,
        ]);
        $transients = $this->stub_transients();

        FiftyOneDegreesOauthMigration::run();

        $this->assertSame(
            '{"access_token":"keepme"}',
            $options->data[Options::GA_TOKEN],
            'empty-string AUTH_CODE must not trigger the OOB wipe path'
        );
        $this->assertSame(
            1700000000,
            $options->data[Options::GA_AUTH_DATE],
            'AUTH_DATE must also be preserved when AUTH_CODE is empty'
        );
        $this->assertArrayNotHasKey(Options::GA_AUTH_CODE, $options->data,
            'empty AUTH_CODE row is still cleaned up — there is no successor for it');
        $this->assertSame('2', $options->data[Options::GA_OAUTH_VERSION]);
        $this->assertArrayNotHasKey(
            'fiftyonedegrees_oauth_migration_notice',
            $transients->data,
            'no wipe happened — no reconnect notice'
        );
    }

    // ─── Case 3: already migrated → fully no-op ─────────────────────────

    public function testNoOpWhenAlreadyMigrated()
    {
        $options = $this->stub_option_store([
            Options::GA_OAUTH_VERSION => '2',
            Options::GA_TOKEN         => '{"access_token":"current"}',
            // GA_AUTH_CODE could in theory still be lingering — make sure
            // we don't touch ANYTHING once version=='2', not even cleanup.
            Options::GA_AUTH_CODE     => 'stale',
        ]);
        $transients = $this->stub_transients();
        Functions\expect('update_option')->never();
        Functions\expect('delete_option')->never();

        FiftyOneDegreesOauthMigration::run();

        $this->assertSame('{"access_token":"current"}', $options->data[Options::GA_TOKEN]);
        $this->assertSame('stale', $options->data[Options::GA_AUTH_CODE]);
        $this->assertArrayNotHasKey('fiftyonedegrees_oauth_migration_notice', $transients->data);
    }

    // ─── Case 4: idempotency — run twice, second call is a no-op ───────

    public function testIdempotentSecondInvocation()
    {
        $options = $this->stub_option_store([
            Options::GA_AUTH_CODE => 'legacy-oob-code',
            Options::GA_TOKEN     => '{"access_token":"legacy"}',
        ]);
        $this->stub_transients();

        FiftyOneDegreesOauthMigration::run();
        // After the first run, version is '2', AUTH_CODE/TOKEN are gone.
        $snapshot = $options->data;

        // Re-issue the notice transient so we can tell whether the second
        // run incorrectly fires again.
        unset($options->data['fiftyonedegrees_oauth_migration_notice']);

        FiftyOneDegreesOauthMigration::run();

        $this->assertSame($snapshot, $options->data,
            'second run must not change anything once version is "2"');
    }
}

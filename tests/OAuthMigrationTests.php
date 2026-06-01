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

    /**
     * Realistic v2 install snapshot — what an admin who connected GA
     * on 1.0.12 would have in wp_options. Every key here is part of
     * the v3 sweep list and must be gone after migration.
     */
    private function v2_install_state(): array
    {
        return [
            Options::GA_OAUTH_VERSION         => '2',
            Options::GA_TOKEN                 => '{"access_token":"ua-token","refresh_token":"r"}',
            Options::GA_AUTH_DATE             => 1700000000,
            Options::GA_TRACKING_ID           => 'UA-12345-1',
            Options::GA_ACCOUNT_ID            => '12345',
            Options::GA_PROPERTIES            => [['accountId' => '12345', 'id' => 'UA-12345-1']],
            Options::GA_CUSTOM_DIMENSIONS_MAP => [['custom_dimension_index' => 1]],
            Options::GA_MAX_DIMENSIONS        => 200,
        ];
    }

    // ─── Case 1: v2 install → full sweep + reconnect notice ─────────────

    public function testV2InstallIsFullySweptAndStampedV3()
    {
        $options = $this->stub_option_store($this->v2_install_state());
        $transients = $this->stub_transients();

        FiftyOneDegreesOauthMigration::run();

        // Every UA-era / shape-changed option must be gone.
        $sweptKeys = [
            Options::GA_TOKEN,
            Options::GA_AUTH_DATE,
            Options::GA_TRACKING_ID,
            Options::GA_ACCOUNT_ID,
            Options::GA_PROPERTIES,
            Options::GA_CUSTOM_DIMENSIONS_MAP,
            Options::GA_MAX_DIMENSIONS,
        ];
        foreach ($sweptKeys as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $options->data,
                "$key must be deleted on v2 -> v3 sweep"
            );
        }

        $this->assertSame(
            '3',
            $options->data[Options::GA_OAUTH_VERSION],
            'version marker must be bumped to 3 after a successful sweep'
        );
        $this->assertArrayHasKey(
            FiftyOneDegreesOauthMigration::NOTICE_TRANSIENT,
            $transients->data,
            'admin must see a reconnect notice — token is gone, scope expanded'
        );
    }

    // ─── Case 2: pre-v2 (OOB-era) install upgrading directly to v3 ─────

    public function testPreV2InstallIsAlsoSweptToV3()
    {
        // Install that never received the 1.0.12 v2 migration — still
        // has a GA_AUTH_CODE around. v3 sweeps it together with the
        // rest of the GA state regardless of v2-era logic.
        $options = $this->stub_option_store([
            Options::GA_AUTH_CODE   => 'legacy-oob-code',
            Options::GA_TOKEN       => '{"access_token":"oob-token"}',
            Options::GA_TRACKING_ID => 'UA-99-1',
        ]);
        $transients = $this->stub_transients();

        FiftyOneDegreesOauthMigration::run();

        $this->assertArrayNotHasKey(Options::GA_AUTH_CODE, $options->data);
        $this->assertArrayNotHasKey(Options::GA_TOKEN, $options->data);
        $this->assertArrayNotHasKey(Options::GA_TRACKING_ID, $options->data);
        $this->assertSame('3', $options->data[Options::GA_OAUTH_VERSION]);
        $this->assertArrayHasKey(
            FiftyOneDegreesOauthMigration::NOTICE_TRANSIENT,
            $transients->data
        );
    }

    // ─── Case 3: fresh install (no GA state) → stamp only, no notice ───

    public function testFreshInstallStampsVersionWithoutBotheringTheAdmin()
    {
        // Plugin activated but GA never configured. Bumping the
        // version stamp is still required (so we don't re-evaluate
        // every request), but firing a "please reconnect" notice
        // would be misleading.
        $options = $this->stub_option_store([]);
        $transients = $this->stub_transients();

        FiftyOneDegreesOauthMigration::run();

        $this->assertSame('3', $options->data[Options::GA_OAUTH_VERSION]);
        $this->assertArrayNotHasKey(
            FiftyOneDegreesOauthMigration::NOTICE_TRANSIENT,
            $transients->data,
            'no state to migrate -> no reconnect notice'
        );
    }

    // ─── Case 4: already at v3 → fully no-op ───────────────────────────

    public function testNoOpWhenAlreadyAtV3()
    {
        $options = $this->stub_option_store([
            Options::GA_OAUTH_VERSION => '3',
            Options::GA_TOKEN         => '{"access_token":"ga4-token"}',
            Options::GA_MEASUREMENT_ID => 'G-XXXXXXX',
            Options::GA_PROPERTY_ID   => '123456789',
        ]);
        $transients = $this->stub_transients();
        Functions\expect('update_option')->never();
        Functions\expect('delete_option')->never();

        FiftyOneDegreesOauthMigration::run();

        $this->assertSame('{"access_token":"ga4-token"}', $options->data[Options::GA_TOKEN]);
        $this->assertSame('G-XXXXXXX', $options->data[Options::GA_MEASUREMENT_ID]);
        $this->assertArrayNotHasKey(
            FiftyOneDegreesOauthMigration::NOTICE_TRANSIENT,
            $transients->data
        );
    }

    // ─── Case 5: idempotency — second invocation no-ops ────────────────

    public function testIdempotentSecondInvocation()
    {
        $options = $this->stub_option_store($this->v2_install_state());
        $this->stub_transients();

        FiftyOneDegreesOauthMigration::run();
        $snapshot = $options->data;

        // Re-issue the notice transient so we can tell whether the
        // second run incorrectly fires again.
        unset($options->data[FiftyOneDegreesOauthMigration::NOTICE_TRANSIENT]);

        // Second run must not just produce the same end-state — it
        // must do zero DB work. Parity with testNoOpWhenAlreadyAtV3.
        Functions\expect('update_option')->never();
        Functions\expect('delete_option')->never();

        FiftyOneDegreesOauthMigration::run();

        $this->assertSame(
            $snapshot,
            $options->data,
            'second run must not change anything once version is "3"'
        );
    }

    // ─── update_option failure path leaves version unstamped ───────────

    public function testUpdateOptionFailureLeavesVersionUnstampedForRetry()
    {
        // A DB outage that breaks the version-stamp write (but lets
        // the sweep delete_option calls succeed) must NOT mark the
        // install as migrated. The next request's run() should
        // re-attempt the migration rather than locking the install
        // at the pre-stamp half-state.
        $store = new \stdClass();
        $store->data = $this->v2_install_state();
        Functions\when('get_option')->alias(function ($key, $default = false) use ($store) {
            return array_key_exists($key, $store->data) ? $store->data[$key] : $default;
        });
        Functions\when('delete_option')->alias(function ($key) use ($store) {
            $existed = array_key_exists($key, $store->data);
            unset($store->data[$key]);
            return $existed;
        });
        // Simulate a DB write failure for the version stamp only.
        Functions\when('update_option')->alias(function ($key, $value) {
            return false;
        });
        $this->stub_transients();

        FiftyOneDegreesOauthMigration::run();

        // The original v2 marker still reads from the stubbed store
        // because update_option silently no-op'd. Next page load
        // will re-enter the sweep — already-deleted rows are
        // no-ops, version stamp retries.
        $this->assertSame(
            '2',
            $store->data[Options::GA_OAUTH_VERSION],
            'failed update_option must leave version at the pre-migration value so the next request retries'
        );
    }

    // ─── delete_options (uninstall hook contribution) ──────────────────

    public function testDeleteOptionsRemovesVersionAndNoticeTransient()
    {
        $deletedOptions  = [];
        $deletedTransients = [];

        Functions\when('delete_option')->alias(function ($key) use (&$deletedOptions) {
            $deletedOptions[] = $key;
            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) use (&$deletedTransients) {
            $deletedTransients[] = $key;
            return true;
        });

        FiftyOneDegreesOauthMigration::delete_options();

        $this->assertSame([Options::GA_OAUTH_VERSION], $deletedOptions);
        $this->assertSame([FiftyOneDegreesOauthMigration::NOTICE_TRANSIENT], $deletedTransients);
    }
}

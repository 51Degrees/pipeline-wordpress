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

require_once __DIR__ . '/../options.php';

/**
 * One-shot, idempotent generation gate for the GA-side schema. Each
 * release that requires the admin to reconnect Google Analytics bumps
 * the target version and wipes the relevant rows here, then surfaces
 * a single "please reconnect" admin notice.
 *
 * Generations:
 *
 *   - v2 — HMAC+PKCE OAuth, Universal Analytics. Shipped in 1.0.12,
 *     itself a migration off the deprecated OOB flow (Google sunset
 *     Jan 2023).
 *   - v3 — GA4 Admin API + analytics.edit scope + new option keys
 *     (GA_MEASUREMENT_ID, GA_PROPERTY_ID; UA-only fields removed).
 *     Existing tokens cannot satisfy the broader scope so they have
 *     to be reissued, and the UA Management API the v2 token was
 *     paired with has been shut down since 2024-07-01 anyway. v3
 *     therefore wipes every GA-related option unconditionally.
 *
 * Wired on plugins_loaded at priority 10 — earlier than admin_init
 * so a frontend page view served between the upgrade and the first
 * wp-admin visit does not emit a stale UA snippet built from
 * pre-migration option values.
 */
class FiftyOneDegreesOauthMigration
{
    public const VERSION             = '3';
    public const NOTICE_TRANSIENT    = 'fiftyonedegrees_oauth_migration_notice';

    /**
     * One month, chosen so admins of infrequently-visited installs
     * still see the reconnect prompt the next time they log in. A
     * shorter window (e.g. a day) silently expires on sites where
     * wp-admin is touched only occasionally, leaving the user to
     * discover the wiped token via missing analytics — exactly the
     * outcome the notice is meant to prevent.
     */
    public const NOTICE_TTL_SECONDS  = MONTH_IN_SECONDS;

    /**
     * GA options removed or with changed shape between v2 (UA) and
     * v3 (GA4). Anything keyed here is deleted unconditionally on
     * upgrade. Keep in sync with the GA delete sweep in
     * Fiftyonedegrees_Google_Analytics::delete_ga_options — both
     * lists encode "this is GA state owned by the integration".
     *
     * New GA4-only keys (GA_MEASUREMENT_ID, GA_PROPERTY_ID) are
     * deliberately excluded — they cannot exist on v2 installs, and
     * sweeping them on the v2 -> v3 path would only matter to beta
     * testers who ran a pre-release v3 build and then downgraded.
     *
     * The constant is named for the current target version rather
     * than version-generically because the contents are specific to
     * what a v2 -> v3 upgrade needs to clear. A future v4 will swap
     * the list rather than amend it.
     */
    private const SWEEP_KEYS = [
        // auth artifacts — token cannot satisfy the new scope set
        Options::GA_TOKEN,
        Options::GA_AUTH_DATE,
        Options::GA_AUTH_CODE, // OOB-era leftover; v3 sweeps it just in case

        // property / account context — UA shapes; GA4 stores
        // GA_MEASUREMENT_ID + GA_PROPERTY_ID instead.
        'fiftyonedegrees_ga_tracking_id', // UA-only Options::* constant removed in v3; literal preserved so the v2 wp_options row still gets swept
        Options::GA_ACCOUNT_ID,
        Options::GA_PROPERTIES,

        // dimensions — UA used a numeric `custom_dimension_index`;
        // GA4 keys by parameter_name. Map shape is incompatible.
        Options::GA_CUSTOM_DIMENSIONS_MAP,
        'fiftyonedegrees_ga_max_cust_dim_index', // UA-only Options::* constant removed in v3; literal preserved so the v2 wp_options row still gets swept
        Options::GA_DIMENSIONS,
        Options::GA_DIMENSIONS_UPDATED,

        // UA tracking-id-derived JavaScript snippet cache.
        Options::GA_JS,

        // UI / error flags tied to the UA tracking-id text input
        Options::GA_TRACKING_ID_ERROR,
        Options::GA_ID_UPDATED,
        Options::GA_CUSTOM_DIMENSIONS_SCREEN,
        Options::GA_ERROR,
    ];

    /**
     * Idempotent entry point. Safe to call on every plugins_loaded —
     * early-returns once the install is marked at the current
     * VERSION.
     */
    public static function run()
    {
        if (get_option(Options::GA_OAUTH_VERSION) === self::VERSION) {
            return;
        }

        $had_state = false;
        foreach (self::SWEEP_KEYS as $key) {
            if (delete_option($key)) {
                $had_state = true;
            }
        }

        // Best-effort sweep + unconditional version stamp. Per-row
        // delete_option failures are silent and indistinguishable
        // from "row didn't exist", so we don't gate the stamp on
        // them — a half-cleaned install lands in a state the admin
        // diagnoses via the reconnect notice and the GA settings
        // screen rather than via repeated migration retries. A
        // total DB outage that breaks update_option below leaves
        // the version stamp untouched, which DOES retry the
        // migration on the next request as intended.
        if ($had_state) {
            // Surface the reconnect notice only if there was real
            // state to wipe. Fresh installs (no GA configured yet)
            // silently bump the version stamp without bothering the
            // admin.
            set_transient(
                self::NOTICE_TRANSIENT,
                '1',
                self::NOTICE_TTL_SECONDS
            );
        }

        update_option(Options::GA_OAUTH_VERSION, self::VERSION);
    }

    /**
     * Uninstall hook contribution — removes the migration version
     * stamp and any leftover one-shot notice transient. Symmetric
     * with FiftyOneDegreesOauthState::delete_options.
     *
     * Single-blog scope on multisite — same precedent as
     * cleanup_expired_pending; multisite cleanup deferred.
     */
    public static function delete_options()
    {
        delete_option(Options::GA_OAUTH_VERSION);
        delete_transient(self::NOTICE_TRANSIENT);
    }
}

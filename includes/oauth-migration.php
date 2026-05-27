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
 * One-time migration off the deprecated OOB OAuth flow (Google sunset
 * Jan 2023) onto the new HMAC + PKCE state engine.
 *
 * Wired on admin_init at priority 10 (after the OAuth callback at
 * priority 5, so a successful callback in the same request gets its
 * token saved before this code looks at GA_AUTH_CODE).
 *
 * Behaviour is gated on a token-shape check rather than blanket-wiping
 * every install:
 *
 *   - If GA_OAUTH_VERSION is already '2', the migration has run; do
 *     nothing on this and every subsequent request.
 *   - Otherwise, GA_AUTH_CODE is the marker of an OOB-era install. If
 *     present, wipe the access token and auth date and surface a notice
 *     telling the admin to reconnect Google Analytics. If absent,
 *     preserve GA_TOKEN as-is — the documented assumption is that no
 *     working OOB-authenticated clients exist (OOB sunset > 3 years ago),
 *     and this gate prevents a backup-restored DB without the matching
 *     AUTH_CODE row from burning a freshly-issued post-migration token.
 *   - In both branches, drop GA_AUTH_CODE (the new flow has no successor
 *     for it) and stamp GA_OAUTH_VERSION = '2'.
 */
class FiftyOneDegreesOauthMigration
{
    public const VERSION             = '2';
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
     * Idempotent entry point for the migration. Safe to call on every
     * admin_init — early-returns once the install is marked as v2.
     */
    public static function run()
    {
        if (get_option(Options::GA_OAUTH_VERSION) === self::VERSION) {
            return;
        }

        // Non-empty string gate. A plain `!== false` check would also fire
        // for rows holding '' / '0' / null — and ga-service.php writes
        // GA_AUTH_CODE straight from user input, so an admin who opened
        // the OAuth screen and submitted a blank code can leave an empty
        // row behind. Treating that as an OOB-era marker would wipe a
        // perfectly good (possibly post-migration) GA_TOKEN for no reason.
        $auth_code = get_option(Options::GA_AUTH_CODE, '');
        if (is_string($auth_code) && $auth_code !== '') {
            // OOB-era install detected via a non-empty legacy auth-code.
            // Wipe the paired token state so the admin is forced through
            // the new flow, and queue a notice so they know why.
            delete_option(Options::GA_TOKEN);
            delete_option(Options::GA_AUTH_DATE);
            set_transient(
                self::NOTICE_TRANSIENT,
                '1',
                self::NOTICE_TTL_SECONDS
            );
        }

        // Stamp the version marker even if one of the deletes above failed
        // silently — retrying every admin_init forever is a worse failure
        // mode than leaving one stale row. A failed delete leaves the row
        // visible to the GA settings screen, which is where the admin
        // would diagnose the problem anyway.
        delete_option(Options::GA_AUTH_CODE);
        update_option(Options::GA_OAUTH_VERSION, self::VERSION);
    }

    /**
     * Uninstall hook contribution — removes the migration version stamp
     * and any leftover one-shot notice transient. Symmetric with
     * FiftyOneDegreesOauthState::delete_options.
     *
     * Single-blog scope on multisite — same precedent as
     * cleanup_expired_pending; multisite cleanup deferred to 1.0.13.
     */
    public static function delete_options()
    {
        delete_option(Options::GA_OAUTH_VERSION);
        delete_transient(self::NOTICE_TRANSIENT);
    }
}

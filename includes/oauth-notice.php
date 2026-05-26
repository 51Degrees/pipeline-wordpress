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

/**
 * Cross-handler OAuth notice transient.
 *
 * The OAuth flow has two server-side entry points (start and callback)
 * that both need to surface a short-lived notice to the next admin page
 * render in the Google Analytics tab. This class owns the transient
 * name + TTL so neither handler depends on the other and the UI render
 * layer (S-9) has one canonical location to read from.
 *
 * Stored value is a branch slug ('success', 'multisite_unsupported',
 * 'exchange_failed', etc.) matching a leaf key under oauth.notice.* in
 * languages/oauth-strings.yaml. The render layer reads-and-deletes the
 * transient so each notice is shown exactly once.
 */
class FiftyOneDegreesOauthNotice
{
    /**
     * Single transient key used by start + callback handlers. Not keyed
     * per user — admins on the same site share a single pending notice.
     * Acceptable trade-off in v1 (slugs are generic); per-user keying is
     * tracked as a cleanup item.
     */
    public const TRANSIENT_KEY = 'fiftyonedegrees_oauth_notice';

    /**
     * Notice survives one admin page render. 30 seconds is enough cover
     * for the PRG redirect + initial GA tab load, and short enough that
     * a stale notice never surfaces on a later visit.
     */
    public const TTL = 30;

    /**
     * Sets the notice slug. Defined here so callers don't repeat the
     * transient name + TTL combo at every emission site.
     */
    public static function set($slug)
    {
        set_transient(self::TRANSIENT_KEY, (string) $slug, self::TTL);
    }
}

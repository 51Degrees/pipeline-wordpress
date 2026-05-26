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
require_once __DIR__ . '/../includes/oauth-notice.php';
require_once __DIR__ . '/../includes/oauth-start.php';
require_once __DIR__ . '/../includes/fiftyone-strings.php';

use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Render-side tests for the Settings > 51Degrees > Google Analytics tab
 * (google-analytics.php in the plugin root). The file is included
 * procedurally by admin.php; tests capture the rendered HTML via
 * output buffering and assert on substrings.
 */
class GoogleAnalyticsTabRenderTests extends TestCase
{
    /** @var array<string,mixed> */
    private $options;
    /** @var array<string,mixed> */
    private $transients;

    public function set_up()
    {
        parent::set_up();
        Brain\Monkey\setUp();

        $this->options = [];
        $this->transients = [];
        $_GET = [];

        $opts = &$this->options;
        $tr = &$this->transients;

        Functions\when('get_option')->alias(function ($key, $default = false) use (&$opts) {
            return array_key_exists($key, $opts) ? $opts[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) use (&$opts) {
            $opts[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) use (&$opts) {
            unset($opts[$key]);
            return true;
        });
        Functions\when('get_transient')->alias(function ($key) use (&$tr) {
            return array_key_exists($key, $tr) ? $tr[$key] : false;
        });
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use (&$tr) {
            $tr[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) use (&$tr) {
            unset($tr[$key]);
            return true;
        });
        Functions\when('is_multisite')->justReturn(false);

        Functions\when('admin_url')->alias(function ($path = '') {
            return 'https://example.test/wp-admin/' . ltrim((string) $path, '/');
        });
        Functions\when('wp_nonce_url')->alias(function ($url, $action) {
            $sep = (strpos($url, '?') === false) ? '?' : '&';
            return $url . $sep . '_wpnonce=test-nonce-' . rawurlencode((string) $action);
        });
        Functions\when('plugin_dir_path')->alias(function ($file) {
            return dirname($file) . DIRECTORY_SEPARATOR;
        });

        // esc_* / __ are no-ops for the render path; we assert raw output.
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_url')->returnArg(1);
        Functions\when('esc_html__')->returnArg(1);
        Functions\when('esc_html_e')->alias(function ($s) { echo $s; });
        Functions\when('submit_button')->alias(function ($name = null) {
            $name = $name ?: 'Save Changes';
            echo '<input type="submit" value="' . $name . '" />';
        });

        // Reset the strings cache so each test sees the real yaml content.
        FiftyOneDegreesStrings::reset();
        if (!defined('FIFTYONEDEGREES_PLUGIN_DIR')) {
            define(
                'FIFTYONEDEGREES_PLUGIN_DIR',
                dirname(__DIR__) . DIRECTORY_SEPARATOR
            );
        }
    }

    public function tear_down()
    {
        Brain\Monkey\tearDown();
        $_GET = [];
        parent::tear_down();
    }

    /**
     * Includes google-analytics.php and returns the rendered output.
     * The file is a procedural include; we capture stdout.
     */
    private function render()
    {
        ob_start();
        include __DIR__ . '/../google-analytics.php';
        return ob_get_clean();
    }

    // ─── Not-connected state ────────────────────────────────────────────

    public function testConnectButtonIsRenderedWhenNotConnected()
    {
        $html = $this->render();

        $this->assertStringContainsString('Connect Google Analytics', $html);
        $this->assertStringContainsString('admin-post.php?action=fiftyonedegrees_oauth_start', $html);
        $this->assertStringContainsString('_wpnonce=', $html,
            'connect URL must carry a WP nonce to satisfy check_admin_referer'
        );
        $this->assertStringContainsString('button button-primary', $html);
    }

    public function testConnectButtonHasNoBlankTarget()
    {
        // OAuth flow must stay in the same browser context so the callback
        // lands on a window holding the admin session cookie.
        $html = $this->render();

        $this->assertStringNotContainsString('target="_blank"', $this->extract_connect_anchor($html));
    }

    public function testLegacyAccessCodeInputIsGone()
    {
        $html = $this->render();

        $this->assertStringNotContainsString('Access Code', $html);
        $this->assertStringNotContainsString('fiftyonedegrees_ga_code', $html);
        $this->assertStringNotContainsString('Log in with Google Analytics Account', $html);
    }

    // ─── Multisite UI ───────────────────────────────────────────────────

    public function testMultisiteShowsNoticeAndHidesConnectButton()
    {
        Functions\when('is_multisite')->justReturn(true);

        $html = $this->render();

        $this->assertStringContainsString('multisite', strtolower($html),
            'multisite-unsupported notice copy must include the keyword'
        );
        $this->assertStringNotContainsString('Connect Google Analytics', $html,
            'Connect button must not render on multisite'
        );
        $this->assertStringNotContainsString('admin-post.php?action=fiftyonedegrees_oauth_start', $html);
    }

    public function testMultisiteNoticeRendersAlsoOnConnectedState()
    {
        // Pre-existing GA_TOKEN from a build that predates the multisite
        // guard. The connected-state branch must still surface the multisite
        // caveat so the user understands re-auth will refuse — otherwise
        // they hit Logout and then face a Connect button that issues a
        // multisite_unsupported rejection with no prior context.
        Functions\when('is_multisite')->justReturn(true);
        $this->options[Options::GA_TOKEN] = ['access_token' => 'legacy-token'];
        $this->options[Options::GA_PROPERTIES] = [];
        $this->options[Options::GA_TRACKING_ID] = '';

        $html = $this->render();

        $this->assertStringContainsString('multisite', strtolower($html),
            'multisite notice must surface even when a token already exists'
        );
        // Connected-state surface still renders below the notice.
        $this->assertStringContainsString('name="ga_log_out"', $html);
    }

    // ─── Notice channel ─────────────────────────────────────────────────

    public function testRejectionNoticeIsRenderedAndCleared()
    {
        $this->transients[FiftyOneDegreesOauthNotice::TRANSIENT_KEY] = 'bad_hmac';

        $html = $this->render();

        $this->assertStringContainsString('notice notice-error', $html,
            'non-success notice must use the error CSS class'
        );
        $this->assertStringContainsString(
            'could not be verified',
            $html,
            'bad_hmac notice copy must surface'
        );
        $this->assertArrayNotHasKey(
            FiftyOneDegreesOauthNotice::TRANSIENT_KEY,
            $this->transients,
            'notice transient must be cleared after first render (one-shot)'
        );
    }

    public function testSuccessNoticeFromTransientUsesSuccessClass()
    {
        $this->transients[FiftyOneDegreesOauthNotice::TRANSIENT_KEY] = 'success';

        $html = $this->render();

        $this->assertStringContainsString('notice notice-success', $html);
        $this->assertStringContainsString('Connected to Google Analytics', $html);
    }

    public function testSuccessQueryMarkerRendersSuccessNoticeWhenTransientEmpty()
    {
        // Belt-and-braces path: callback PRG appends ?oauth-success=1 in
        // addition to setting the transient. If the transient was already
        // consumed by another reader (e.g. observability plugin), this
        // marker keeps the user informed — but only when there's a real
        // token to back it up (see testSuccessQueryMarkerIgnoredWithoutToken).
        $_GET = ['oauth-success' => '1'];
        $this->options[Options::GA_TOKEN] = ['access_token' => 'real-token'];
        $this->options[Options::GA_PROPERTIES] = [];
        $this->options[Options::GA_TRACKING_ID] = '';

        $html = $this->render();

        $this->assertStringContainsString('notice notice-success', $html);
        $this->assertStringContainsString('Connected to Google Analytics', $html);
    }

    public function testSuccessQueryMarkerIgnoredWithoutToken()
    {
        // Defense against URL spoofing: visiting the admin page with
        // ?oauth-success=1 but no real GA_TOKEN would otherwise show a
        // "Connected to Google Analytics" banner above the Connect button.
        // Internally contradictory and misleading.
        $_GET = ['oauth-success' => '1'];
        // GA_TOKEN intentionally absent — not-connected branch.

        $html = $this->render();

        $this->assertStringNotContainsString('notice notice-success', $html,
            'success banner must not render without an actual token'
        );
        // Not-connected surface (Connect button) still visible.
        $this->assertStringContainsString('Connect Google Analytics', $html);
    }

    public function testSuccessQueryMarkerSuppressedWhenTransientNoticeAlreadyRendered()
    {
        // Avoid double-banner: if the transient produced a notice, the
        // ?oauth-success=1 fallback must not render a second one.
        $this->transients[FiftyOneDegreesOauthNotice::TRANSIENT_KEY] = 'success';
        $_GET = ['oauth-success' => '1'];

        $html = $this->render();

        // Exactly one notice-success div.
        $this->assertSame(1, substr_count($html, 'notice notice-success'));
    }

    // ─── Connected state (regression) ───────────────────────────────────

    public function testConnectedStateRendersPropertySelectorAndLogout()
    {
        $this->options[Options::GA_TOKEN] = ['access_token' => 'real-token'];
        $this->options[Options::GA_PROPERTIES] = [];
        $this->options[Options::GA_TRACKING_ID] = '';

        $html = $this->render();

        $this->assertStringContainsString('name="ga_log_out"', $html,
            'logout submit input must remain on the connected screen'
        );
        $this->assertStringContainsString('Select Analytics Property', $html);
        $this->assertStringNotContainsString('Connect Google Analytics', $html,
            'Connect button must not appear once GA_TOKEN is set'
        );
    }

    public function testGaErrorIsDisplayedAndCleared()
    {
        $this->options[Options::GA_ERROR] = 'Some error from a previous flow';

        $html = $this->render();

        $this->assertStringContainsString('Some error from a previous flow', $html);
        $this->assertArrayNotHasKey(Options::GA_ERROR, $this->options,
            'GA_ERROR option must be cleared after display (one-shot)'
        );
        // GA_ERROR forces the not-connected branch even if GA_TOKEN exists,
        // so the Connect button surface is still visible.
        $this->assertStringContainsString('Connect Google Analytics', $html);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    /**
     * Extracts the <a> tag carrying the Connect button so target=_blank
     * assertions don't trip on unrelated anchors (e.g. the "Set up an
     * account" external link above the button intentionally uses
     * target="_blank").
     */
    private function extract_connect_anchor($html)
    {
        if (preg_match('/<a[^>]*Connect Google Analytics[^<]*<\/a>/s', $html, $m)) {
            return $m[0];
        }
        if (preg_match('/<a[^>]*class="button button-primary"[^>]*>[^<]*<\/a>/s', $html, $m)) {
            return $m[0];
        }
        return '';
    }
}

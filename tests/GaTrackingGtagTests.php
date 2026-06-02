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

require_once __DIR__ . '/../includes/ga-tracking-gtag.php';
require_once __DIR__ . '/../options.php';

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Brain\Monkey\Functions;

/**
 * GA4 gtag emission. UA-era tests compared output to fixture files at
 * tests/outputs/*.js — that pattern was retired together with the
 * server-side file regeneration the fixtures verified. Tests now
 * assert structural properties of the inline JS instead so a copy
 * tweak doesn't drag a string-equality fixture along with it.
 */
class GaTrackingGtagTests extends TestCase
{
    /** @var array<string,mixed> */
    private $options;

    public function set_up()
    {
        parent::set_up();
        Brain\Monkey\setUp();

        $this->options = [];
        $opts = &$this->options;
        Functions\when('get_option')->alias(function ($key, $default = false) use (&$opts) {
            return array_key_exists($key, $opts) ? $opts[$key] : $default;
        });
        Functions\when('esc_html')->returnArg(1);
        Functions\when('esc_attr')->returnArg(1);
        Functions\when('esc_js')->returnArg(1);
    }

    public function tear_down()
    {
        Brain\Monkey\tearDown();
        parent::tear_down();
    }

    // ─── get_event_parameters ───────────────────────────────────────────

    public function testEventParametersUsesExplicitParameterNameWhenPresent()
    {
        // Post-CD-UI-migration CD map shape: each row carries a
        // dedicated `parameter_name` (the GA4 event-parameter key the
        // admin registered on the property). The emission must use
        // that exact field as the event payload key.
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            [
                'parameter_name'           => 'device_type',
                'property_name'            => 'DeviceType',
                'custom_dimension_datakey' => 'device',
            ],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->get_event_parameters();

        $this->assertSame(['device_type' => '(data.device || {}).devicetype'], $result['parameters']);
        $this->assertFalse($result['delayed_evidence']);
    }

    public function testEventParametersDropsRowsWithoutParameterName()
    {
        // After the CD-UI rewrite, every row in the saved map
        // carries an explicit parameter_name. A row missing one is
        // a corrupted write rather than a routine state, and the
        // emitter drops it rather than synthesising a fallback key
        // that the GA4 property does not know about.
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            [
                'property_name'            => 'HardwareName',
                'custom_dimension_datakey' => 'device',
            ],
            [
                'parameter_name'           => 'device_type',
                'property_name'            => 'DeviceType',
                'custom_dimension_datakey' => 'device',
            ],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->get_event_parameters();

        $this->assertSame(
            ['device_type' => '(data.device || {}).devicetype'],
            $result['parameters']
        );
    }

    public function testEventParametersDetectsLocationAsDelayed()
    {
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            [
                'parameter_name'           => 'country',
                'property_name'            => 'Country',
                'custom_dimension_datakey' => 'location',
            ],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->get_event_parameters();

        $this->assertTrue(
            $result['delayed_evidence'],
            'location datakey must mark the emission as delayed-evidence so fod.complete waits for it'
        );
    }

    public function testEventParametersSkipsRowsMissingRequiredFields()
    {
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            ['property_name' => 'OnlyProperty'],                           // no datakey
            ['custom_dimension_datakey' => 'device'],                      // no property_name
            ['parameter_name' => '', 'property_name' => '', 'custom_dimension_datakey' => 'device'], // both empty
            ['parameter_name' => 'good', 'property_name' => 'Good', 'custom_dimension_datakey' => 'device'],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->get_event_parameters();

        $this->assertSame(['good' => '(data.device || {}).good'], $result['parameters']);
    }

    public function testEventParametersSkipsNonScalarFieldsWithoutWarning()
    {
        // Corrupted DB rows must not emit "Array" as a parameter key
        // or trigger PHP warnings. A non-scalar parameter_name is
        // treated as missing and the row is dropped — the CD-UI
        // rewrite no longer permits a property_name fallback, so a
        // bad field cannot leak through under a derived key.
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            ['parameter_name' => 'good',     'property_name' => 'Good',       'custom_dimension_datakey' => 'device'],
            ['parameter_name' => ['nested'], 'property_name' => 'Fallback',   'custom_dimension_datakey' => 'device'], // non-scalar param_name -> dropped (no fallback)
            ['property_name' => ['nested'],  'custom_dimension_datakey' => 'device'], // missing param_name + non-scalar property -> dropped
            ['parameter_name' => 'noseg',    'property_name' => ['nested'],   'custom_dimension_datakey' => 'device'], // property_name non-scalar segment -> dropped
            'not-an-array',
        ];

        $errorBefore = error_get_last();
        $result = (new Fiftyonedegrees_Tracking_Gtag())->get_event_parameters();
        $errorAfter = error_get_last();

        $this->assertSame(
            ['good' => '(data.device || {}).good'],
            $result['parameters'],
            'rows with non-scalar or missing parameter_name must be dropped, not fallback-derived'
        );
        $this->assertEquals(
            $errorBefore,
            $errorAfter,
            'no new PHP warning should be raised by corrupted rows'
        );
    }

    public function testEventParametersSkipsRowsWithNonAlphanumericSegments()
    {
        // Defense-in-depth against a malformed CD-map row escaping
        // the JS expression context. The datakey and property_name
        // build an unquoted `data.<datakey>.<propName>` path; any
        // char outside [a-z0-9_] forces the row to be dropped.
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            ['parameter_name' => 'evil',  'property_name' => 'foo); alert(1); //', 'custom_dimension_datakey' => 'device'],
            ['parameter_name' => 'evil2', 'property_name' => 'foo',                'custom_dimension_datakey' => 'dev; alert(1);'],
            ['parameter_name' => 'ok',    'property_name' => 'devicetype',         'custom_dimension_datakey' => 'device'],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->get_event_parameters();

        $this->assertSame(['ok' => '(data.device || {}).devicetype'], $result['parameters'],
            'rows with non-[a-z0-9_] datakey/property_name segments must be dropped'
        );
    }

    public function testEventParametersReturnsEmptyOnNonArrayOption()
    {
        // get_option default-false for a missing row. Must not error.
        $result = (new Fiftyonedegrees_Tracking_Gtag())->get_event_parameters();

        $this->assertSame([], $result['parameters']);
        $this->assertFalse($result['delayed_evidence']);
    }

    // ─── output_ga_tracking_code ────────────────────────────────────────

    public function testOutputGaTrackingCodeReturnsEmptyWhenNoMeasurementId()
    {
        // Don't surface an empty <script> tag in the page <head> when
        // GA is not configured — the wp_head action echoes whatever
        // we return.
        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_ga_tracking_code();

        $this->assertSame('', $result);
    }

    public function testOutputGaTrackingCodeEmitsLoaderScriptAndBothBranches()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_ga_tracking_code();

        $this->assertStringContainsString(
            'https://www.googletagmanager.com/gtag/js?id=G-ABC123',
            $result,
            'loader script must point at the configured measurement id'
        );
        $this->assertStringContainsString("gtag('config', fodMeasurementId", $result,
            'fresh-branch IIFE must be present'
        );
        $this->assertStringContainsString("gtag('event', 'fod'", $result,
            'fod event must be emitted'
        );
    }

    // ─── output_gtag_code (fresh / not-tagged branch) ───────────────────

    public function testFreshBranchEmitsConfigWithSendPageViewTrue()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';
        $this->options[Options::GA_SEND_PAGE_VIEW] = 'true';

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();

        $this->assertStringContainsString("gtag('config', fodMeasurementId", $result);
        $this->assertMatchesRegularExpression(
            "/'send_page_view':\s*true/",
            $result
        );
    }

    public function testFreshBranchEmitsSendPageViewFalseWhenOptionAbsent()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';
        // GA_SEND_PAGE_VIEW intentionally absent

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();

        $this->assertMatchesRegularExpression(
            "/'send_page_view':\s*false/",
            $result,
            'absent send-page-view option must become literal false in the config'
        );
    }

    public function testFreshBranchEmitsFodEventWithParameters()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            [
                'parameter_name'           => 'device_type',
                'property_name'            => 'DeviceType',
                'custom_dimension_datakey' => 'device',
            ],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();

        $this->assertStringContainsString("gtag('event', 'fod'", $result);
        $this->assertStringContainsString("'send_to': fodMeasurementId", $result);
        $this->assertStringContainsString("'device_type': (data.device || {}).devicetype", $result);
    }

    public function testFreshBranchOmitsRowWithoutParameterName()
    {
        // Mirror of testEventParametersDropsRowsWithoutParameterName
        // at the emitted-JS layer: a malformed CD-map row produces
        // no event-parameter line. The fod event still fires under
        // send_to so analytics receives the pageview, just without
        // the bogus row's data.
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            [
                'property_name'            => 'HardwareName',
                'custom_dimension_datakey' => 'device',
            ],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();

        $this->assertStringContainsString("gtag('event', 'fod'", $result);
        $this->assertStringNotContainsString(
            "'hardwarename':",
            $result,
            'no fallback key should be synthesised for a row missing parameter_name'
        );
    }

    public function testFreshBranchStampsOwnershipSentinelAfterConfig()
    {
        // Pins the R-1 fix: after the fresh branch issues its own
        // gtag('config', ...) call it must stamp window-scoped
        // ownership so the tagged-property IIFE can distinguish
        // "we tagged ourselves" from "a sibling tagged us" and bail
        // in the former case.
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();

        $this->assertStringContainsString(
            "window['" . Fiftyonedegrees_Tracking_Gtag::OWNERSHIP_SENTINEL . "'] = true",
            $result,
            'fresh branch must claim ownership of the config it just pushed'
        );
        // Order matters — sentinel must follow the config call, not
        // precede it. A pre-config stamp would have the tagged
        // branch bail before our own dataLayer entry exists.
        $pos_config   = strpos($result, "gtag('config', fodMeasurementId");
        $pos_sentinel = strpos($result, Fiftyonedegrees_Tracking_Gtag::OWNERSHIP_SENTINEL . "'] = true");
        $this->assertNotFalse($pos_config);
        $this->assertNotFalse($pos_sentinel);
        $this->assertGreaterThan(
            $pos_config,
            $pos_sentinel,
            'ownership sentinel must be stamped AFTER the config call'
        );
    }

    public function testFreshBranchWithEmptyMapEmitsBareEvent()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';
        // No CD map

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();

        $this->assertStringContainsString("gtag('event', 'fod'", $result);
        $this->assertStringContainsString("'send_to': fodMeasurementId", $result);
        // No stray parameter lines — the params block was empty so
        // only send_to lands in the event payload.
        $this->assertDoesNotMatchRegularExpression(
            "/'send_to':\s*fodMeasurementId\s*,\s*\n/",
            $result,
            'event must not carry a trailing comma when there are no parameters'
        );
    }

    public function testFreshBranchHasNoCustomMapField()
    {
        // GA4 sends parameters directly on the event — the UA-era
        // `custom_map` translation layer is gone.
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            [
                'parameter_name'           => 'device_type',
                'property_name'            => 'DeviceType',
                'custom_dimension_datakey' => 'device',
            ],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();

        $this->assertStringNotContainsString('custom_map', $result);
    }

    public function testFreshBranchIsGatedByConfigCheck()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();

        // Self-gates: bails if another plugin already configured the
        // same measurement id, so both IIFEs can sit in the same page
        // without double-firing.
        $this->assertStringContainsString('fodIsAlreadyConfigured', $result);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*fodIsAlreadyConfigured\s*\(\s*\)\s*\)\s*\{\s*return;/',
            $result,
            'fresh branch must return early when the property is already tagged'
        );
    }

    public function testFreshBranchUsesDelayedFodCompleteForLocation()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            [
                'parameter_name'           => 'country',
                'property_name'            => 'Country',
                'custom_dimension_datakey' => 'location',
            ],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();

        $this->assertStringContainsString('fod.complete(update, "location")', $result);
    }

    // ─── output_gtag_code_tagged_property (tagged branch) ───────────────

    public function testTaggedBranchUsesGtagSet()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            [
                'parameter_name'           => 'device_type',
                'property_name'            => 'DeviceType',
                'custom_dimension_datakey' => 'device',
            ],
        ];

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code_tagged_property();

        // GA4 `set(measurementId, {...})` merges defaults into the
        // sibling plugin's already-emitted config instead of
        // redefining it.
        $this->assertStringContainsString("gtag('set', fodMeasurementId", $result);
        $this->assertStringContainsString("'device_type': (data.device || {}).devicetype", $result);
        // Tagged branch must never call config — that would override
        // the sibling's settings.
        $this->assertStringNotContainsString("gtag('config'", $result);
    }

    public function testTaggedBranchSkipsSetWhenNoParameters()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';
        // No CD map -> nothing to set.

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code_tagged_property();

        $this->assertStringNotContainsString("gtag('set'", $result,
            'empty parameters means no set call — avoids polluting the sibling config'
        );
        // Event still fires, with only send_to.
        $this->assertStringContainsString("gtag('event', 'fod'", $result);
    }

    public function testTaggedBranchIsGatedByConfigCheck()
    {
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code_tagged_property();

        // Inverse gate of the fresh branch: tagged branch only runs
        // when a sibling config is already present.
        $this->assertStringContainsString('fodIsAlreadyConfigured', $result);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*fodIsAlreadyConfigured\s*\(\s*\)\s*\)\s*\{\s*return;/',
            $result,
            'tagged branch must return early when no sibling has tagged the property yet'
        );
    }

    public function testTaggedBranchBailsWhenFreshBranchOwnedConfig()
    {
        // Mutual-exclusion gate that prevents the tagged branch from
        // firing on a fresh page where the sibling IIFE in the same
        // <script> just pushed a config entry. Must be the FIRST
        // statement inside the IIFE — running anything beforehand
        // would let the duplicate-event bug back in.
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code_tagged_property();

        $sentinel = Fiftyonedegrees_Tracking_Gtag::OWNERSHIP_SENTINEL;
        $this->assertStringContainsString(
            "window['" . $sentinel . "']",
            $result,
            'tagged branch must check the ownership sentinel'
        );

        // Sentinel check must precede the dataLayer-walking check.
        $pos_sentinel_check = strpos(
            $result,
            "window['" . $sentinel . "'] === true"
        );
        $pos_dl_check = strpos($result, 'fodIsAlreadyConfigured');
        $this->assertNotFalse($pos_sentinel_check);
        $this->assertNotFalse($pos_dl_check);
        $this->assertLessThan(
            $pos_dl_check,
            $pos_sentinel_check,
            'sentinel guard must run before the dataLayer scan (cheaper, and protects against the fresh-branch self-tag)'
        );
    }

    public function testCombinedOutputContainsMutuallyExclusiveGates()
    {
        // The whole point of the two-IIFE design is that exactly one
        // branch fires per render. Pin the full set of gating
        // expressions in the combined head fragment so a future
        // refactor cannot quietly drop one half and let both
        // branches execute again.
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';

        $result = (new Fiftyonedegrees_Tracking_Gtag())->output_ga_tracking_code();

        $sentinel = Fiftyonedegrees_Tracking_Gtag::OWNERSHIP_SENTINEL;
        // Fresh branch: positive check + sentinel set.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*fodIsAlreadyConfigured\s*\(\s*\)\s*\)\s*\{\s*return;/',
            $result
        );
        $this->assertStringContainsString(
            "window['" . $sentinel . "'] = true",
            $result
        );
        // Tagged branch: sentinel check + inverse dataLayer check.
        $this->assertStringContainsString(
            "window['" . $sentinel . "'] === true",
            $result
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*fodIsAlreadyConfigured\s*\(\s*\)\s*\)\s*\{\s*return;/',
            $result
        );
        // Exactly one config call (fresh) and exactly one set call
        // (tagged) for the typical case with parameters present.
        $this->options[Options::GA_CUSTOM_DIMENSIONS_MAP] = [
            ['parameter_name' => 'p', 'property_name' => 'P', 'custom_dimension_datakey' => 'device'],
        ];
        $result_with_params = (new Fiftyonedegrees_Tracking_Gtag())->output_ga_tracking_code();
        $this->assertSame(1, substr_count($result_with_params, "gtag('config', fodMeasurementId"));
        $this->assertSame(1, substr_count($result_with_params, "gtag('set', fodMeasurementId"));
    }

    // ─── fod library hard-dependency guard ──────────────────────────────

    public function testBothBranchesGuardAgainstMissingFodLibrary()
    {
        // If assets/js/fod.js ever fails to load or is removed from
        // the enqueue, the load handler must not throw — it should
        // fire a bare fod event so analytics still sees the
        // pageview, just without device properties.
        $this->options[Options::GA_MEASUREMENT_ID] = 'G-ABC123';

        $fresh  = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code();
        $tagged = (new Fiftyonedegrees_Tracking_Gtag())->output_gtag_code_tagged_property();

        foreach ([$fresh, $tagged] as $branch) {
            $this->assertStringContainsString(
                "typeof fod === 'undefined'",
                $branch,
                'each branch must feature-detect fod before calling fod.complete'
            );
        }
    }

    // ─── No file regeneration ───────────────────────────────────────────

    public function testNoCallToFopenOrFwriteInSourceFile()
    {
        // The UA-era emission generated assets/js/*.js on every render
        // via fopen('w') + fwrite. The GA4 emission is fully inline;
        // pin the absence so a future "convenient cache file"
        // refactor cannot quietly resurrect the race.
        //
        // token_get_all rather than substring search so an
        // incidental mention in a docblock ("removed the fopen
        // call") does not falsely fail the test.
        $source = file_get_contents(__DIR__ . '/../includes/ga-tracking-gtag.php');
        $tokens = token_get_all($source);
        $forbidden = ['fopen', 'fwrite'];
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] === T_STRING && in_array($token[1], $forbidden, true)) {
                $this->fail(
                    'Unexpected ' . $token[1]
                    . '() call site in ga-tracking-gtag.php — '
                    . 'file regeneration was removed and must not be reintroduced.'
                );
            }
        }
        $this->assertTrue(true);
    }
}

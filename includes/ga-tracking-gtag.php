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
 * GA4 gtag emission for the frontend.
 *
 * The previous Universal Analytics emission produced two server-side
 * generated JavaScript files (`assets/js/ga-51d-tracking.js` and
 * `ga-integration-tracking.js`) via PHP filesystem writes on every
 * render — race-prone under concurrent admin requests and brittle
 * against filesystem permissions. GA4 emission is entirely inline:
 * `output_ga_tracking_code` returns the head fragment as a string
 * and the caller echoes it into wp_head.
 *
 * GA4 carries Custom Dimension values as event-level parameters
 * directly on the event call, not through a `custom_map` translation
 * layer like UA had. The Custom Dimension resources themselves are
 * created on the property via the GA4 Admin API (handled elsewhere)
 * — this file is the value pipeline only.
 *
 * Two emission methods are kept for testability and to cover the
 * already-tagged-property case where another plugin or theme has
 * already issued a `gtag('config', G-XXX, ...)` for the same
 * Measurement ID:
 *
 *   - output_gtag_code()                  -> config + event (fresh)
 *   - output_gtag_code_tagged_property()  -> set + event (coexist)
 *
 * Each method emits a self-contained IIFE that runtime-checks
 * window.dataLayer for an existing config call and self-gates so
 * exactly one branch fires per page render even though both blocks
 * are present in the document. The fresh branch additionally stamps
 * a window-scoped ownership sentinel after issuing its own config
 * so the tagged branch — which would otherwise observe the just-
 * pushed config and execute as well — bails on a fresh page.
 *
 * TODO: migrate to wp_add_inline_script attached to the fod.js
 * handle so this inline body benefits from WordPress's CSP-nonce
 * support and inherits dependency ordering with the fod.js
 * runtime. Out of scope for this commit (would require touching
 * the script-enqueue path in fiftyone-service).
 */
class Fiftyonedegrees_Tracking_Gtag {

    /**
     * Window-scoped ownership sentinel. Stamped by the fresh-branch
     * IIFE after its own `gtag('config', ...)` call so the tagged
     * branch — which sees the just-pushed config entry in
     * window.dataLayer — can distinguish "a sibling plugin tagged
     * us first" from "we just tagged ourselves" and bail in the
     * latter case. Externalized to a constant so the tests can
     * pin both the setter site (fresh branch) and the checker
     * site (tagged branch) by exact string.
     */
    public const OWNERSHIP_SENTINEL = '__fod51d_owns_config';

    public $name = 'gtag';
    public $version = '2.0.0';

    public function __construct() {}

    /**
     * @return array{parameters: array<string,string>, delayed_evidence: bool}
     *
     * Walks Options::GA_CUSTOM_DIMENSIONS_MAP and yields one row per
     * property->parameter mapping for the GA4 event payload, plus a
     * delayed-evidence flag that controls whether the JS load
     * handler calls `fod.complete(update, "location")` (waits for
     * the location engine) or `fod.complete(update)` (fires
     * immediately).
     *
     * Shape of each returned parameter:
     *   - key   = the GA4 parameter_name (event parameter the admin
     *             registered as a Custom Dimension on the property)
     *   - value = a JS expression that pulls the value out of the
     *             FOD library's `data` callback argument, e.g.
     *             "data.device.devicetype"
     *
     * Hardening:
     *   - is_scalar() guard on each option field so a corrupted DB
     *     row (e.g. array stored where a string was expected) does
     *     not trigger PHP warnings or emit "Array" as the literal
     *     parameter key.
     *   - regex whitelist on the datakey and property_name segments
     *     that build the unquoted JS expression: only `[a-z0-9_]+`
     *     accepted, anything else is skipped. The CD-map is admin-
     *     controlled and Pipeline-sourced today, but a single bad
     *     row would otherwise become executable JS in wp_head.
     *
     * Backward-compat shim: pre-CD-UI-migration CD-map rows do not
     * carry an explicit `parameter_name` field. We fall back to the
     * lowercased `property_name` slug so a half-populated install
     * (the GA schema migration ran but the CD admin UI has not yet
     * been rewritten) still emits a sensible payload. The fallback
     * is transitional — TODO: remove once the GA4 CD admin UI ships
     * and backfills `parameter_name` for every map row.
     */
    public function get_event_parameters() {
        $custom_dimensions = get_option(Options::GA_CUSTOM_DIMENSIONS_MAP);
        if (!is_array($custom_dimensions)) {
            return ['parameters' => [], 'delayed_evidence' => false];
        }

        $parameters = [];
        $delayed_evidence = false;

        foreach ($custom_dimensions as $dimension) {
            if (!is_array($dimension)) {
                continue;
            }

            $param = $this->extract_parameter_name($dimension);
            if ($param === '') {
                continue;
            }

            $datakey  = $this->normalize_segment($dimension, 'custom_dimension_datakey');
            $propName = $this->normalize_segment($dimension, 'property_name');
            if ($datakey === '' || $propName === '') {
                continue;
            }

            $parameters[$param] = 'data.' . $datakey . '.' . $propName;

            if ($datakey === 'location') {
                // The location engine resolves asynchronously; the
                // FOD library has a per-key `complete(callback, key)`
                // overload that waits for it.
                $delayed_evidence = true;
            }
        }

        return [
            'parameters'       => $parameters,
            'delayed_evidence' => $delayed_evidence,
        ];
    }

    /**
     * Resolves the GA4 parameter name for a single CD-map row.
     * Returns '' when the row carries neither an explicit
     * `parameter_name` (post-CD-UI-migration shape) nor a fallback-
     * derivable `property_name` (pre-CD-UI-migration shape).
     */
    private function extract_parameter_name(array $dimension)
    {
        if (isset($dimension['parameter_name'])
            && is_scalar($dimension['parameter_name'])
            && (string) $dimension['parameter_name'] !== ''
        ) {
            return (string) $dimension['parameter_name'];
        }
        // TODO: remove once the CD-UI rewrite backfills parameter_name.
        if (isset($dimension['property_name'])
            && is_scalar($dimension['property_name'])
            && (string) $dimension['property_name'] !== ''
        ) {
            return strtolower((string) $dimension['property_name']);
        }
        return '';
    }

    /**
     * Lowercases a CD-map field for use as a segment of the unquoted
     * `data.<datakey>.<propName>` JS expression and rejects anything
     * outside the `[a-z0-9_]+` whitelist. Belt-and-braces against a
     * corrupted or maliciously-edited option row escaping the JS
     * expression context.
     */
    private function normalize_segment(array $dimension, $field)
    {
        if (!isset($dimension[$field]) || !is_scalar($dimension[$field])) {
            return '';
        }
        $value = strtolower((string) $dimension[$field]);
        if ($value === '' || !preg_match('/^[a-z0-9_]+$/', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * Top-of-page entry: returns the full head fragment containing
     * the GA4 loader script + the two self-gating IIFEs. Returns the
     * empty string when GA_MEASUREMENT_ID is not set so the wp_head
     * action emits nothing and the page is not littered with a
     * useless empty <script> tag.
     */
    public function output_ga_tracking_code() {
        $measurement_id = get_option(Options::GA_MEASUREMENT_ID);
        if (empty($measurement_id)) {
            return '';
        }

        ob_start();
        echo "\n\t<!-- 51Degrees WordPress Plugin (GA4 emitter v"
            . esc_html($this->version) . ") -->\n";
        echo "\t" . '<script async src="https://www.googletagmanager.com/gtag/js?id='
            . esc_attr($measurement_id) . '"></script>' . "\n";
        echo "\t<script>\n";
        echo $this->output_gtag_code();
        echo $this->output_gtag_code_tagged_property();
        echo "\t</script>\n";
        echo "\t<!-- End 51Degrees WordPress Plugin -->\n\n";
        return ob_get_clean();
    }

    /**
     * GA4 emission for the case where the page does NOT already have
     * a `gtag('config', $measurement_id, ...)` from another plugin.
     * Emits the config (so page-view fires) and the fod event with
     * 51Degrees device properties attached as event parameters.
     *
     * Self-gated: at runtime the IIFE checks window.dataLayer and
     * bails when a sibling plugin has already configured the same
     * Measurement ID — the tagged-property method below handles
     * that case instead. After issuing its own config call, this
     * IIFE stamps an ownership sentinel on window so the tagged
     * branch (which runs immediately after, in the same <script>)
     * can distinguish "we just tagged ourselves" from "a sibling
     * tagged us" and bail in the former case.
     *
     * Returns the JS body (no enclosing <script>); the caller wraps.
     */
    public function output_gtag_code() {
        $measurement_id = get_option(Options::GA_MEASUREMENT_ID);
        $send_page_view = get_option(Options::GA_SEND_PAGE_VIEW)
            ? 'true'
            : 'false';
        $params = $this->get_event_parameters();
        $event_lines = $this->build_event_parameter_lines($params['parameters']);
        $event_block = $event_lines === ''
            ? "                'send_to': fodMeasurementId"
            : "                'send_to': fodMeasurementId,\n" . $event_lines;
        $delayed = $params['delayed_evidence'] ? 'true' : 'false';

        ob_start();
        ?>
        (function () {
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }

            var fodMeasurementId = '<?php echo esc_js($measurement_id); ?>';

            function fodIsAlreadyConfigured() {
                var dl = window.dataLayer || [];
                for (var i = 0; i < dl.length; i++) {
                    if (dl[i][0] === 'config' && dl[i][1] === fodMeasurementId) {
                        return true;
                    }
                }
                return false;
            }
            if (fodIsAlreadyConfigured()) { return; }

            gtag('js', new Date());
            gtag('config', fodMeasurementId, {
                'send_page_view': <?php echo $send_page_view; ?>
            });
            // Stamp ownership so the tagged-property IIFE below
            // does not also execute on top of the config we just
            // pushed into dataLayer.
            window['<?php echo self::OWNERSHIP_SENTINEL; ?>'] = true;

            window.addEventListener('load', function () {
                var update = function (data) {
                    gtag('event', 'fod', {
<?php echo $event_block; ?>

                    });
                };
                if (typeof fod === 'undefined') {
                    if (typeof console !== 'undefined' && console.warn) {
                        console.warn('51Degrees: fod library not loaded; firing bare fod event without device properties.');
                    }
                    update({});
                    return;
                }
                if (<?php echo $delayed; ?>) {
                    fod.complete(update, "location");
                } else {
                    fod.complete(update);
                }
            });
        })();
        <?php
        return ob_get_clean();
    }

    /**
     * GA4 emission for the case where another plugin has already
     * called `gtag('config', $measurement_id, ...)` for the same
     * Measurement ID. We must not redefine that config (would
     * override the sibling's options). Instead, push our parameters
     * via `gtag('set', $measurement_id, {...})` so subsequent events
     * to that measurement carry them as defaults, then fire the fod
     * event with only `send_to` — the parameters are already merged.
     *
     * Two gates, both required to fire:
     *   1. The fresh-branch IIFE above did NOT just issue its own
     *      config call (ownership sentinel absent). Without this
     *      gate the fresh branch's freshly-pushed config entry
     *      would trip our `fodIsAlreadyConfigured` check and we
     *      would emit a duplicate event payload.
     *   2. A sibling plugin HAS configured the measurement id
     *      (positive dataLayer match). Without this gate the
     *      tagged branch would attempt to set defaults on a
     *      measurement that has never been configured.
     */
    public function output_gtag_code_tagged_property() {
        $measurement_id = get_option(Options::GA_MEASUREMENT_ID);
        $params = $this->get_event_parameters();
        $set_lines = $this->build_event_parameter_lines($params['parameters']);
        $delayed = $params['delayed_evidence'] ? 'true' : 'false';

        ob_start();
        ?>
        (function () {
            // Gate 1: skip when the fresh-branch IIFE just claimed
            // ownership of the config call — its dataLayer entry
            // would otherwise satisfy the positive check below.
            if (window['<?php echo self::OWNERSHIP_SENTINEL; ?>'] === true) { return; }

            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }

            var fodMeasurementId = '<?php echo esc_js($measurement_id); ?>';

            function fodIsAlreadyConfigured() {
                var dl = window.dataLayer || [];
                for (var i = 0; i < dl.length; i++) {
                    if (dl[i][0] === 'config' && dl[i][1] === fodMeasurementId) {
                        return true;
                    }
                }
                return false;
            }
            // Gate 2: only fire when a sibling has already tagged
            // this measurement id.
            if (!fodIsAlreadyConfigured()) { return; }

            window.addEventListener('load', function () {
                var update = function (data) {
                    <?php if ($set_lines !== '') { ?>
                    gtag('set', fodMeasurementId, {
<?php echo $set_lines; ?>

                    });
                    <?php } ?>
                    gtag('event', 'fod', {
                        'send_to': fodMeasurementId
                    });
                };
                if (typeof fod === 'undefined') {
                    if (typeof console !== 'undefined' && console.warn) {
                        console.warn('51Degrees: fod library not loaded; firing bare fod event without device properties.');
                    }
                    update({});
                    return;
                }
                if (<?php echo $delayed; ?>) {
                    fod.complete(update, "location");
                } else {
                    fod.complete(update);
                }
            });
        })();
        <?php
        return ob_get_clean();
    }

    /**
     * Returns the indented comma-separated `'<key>': <value-expr>`
     * lines for the parameters block. Single canonical builder so
     * the fresh-branch event block and the tagged-branch set block
     * cannot drift on whitespace, trailing-comma handling, or
     * quoting. Empty params -> empty string (caller emits the bare
     * object on its own).
     */
    private function build_event_parameter_lines(array $parameters)
    {
        if (empty($parameters)) {
            return '';
        }
        $lines = [];
        foreach ($parameters as $param => $valueExpr) {
            $lines[] = "                        '" . esc_js($param) . "': " . $valueExpr;
        }
        return implode(",\n", $lines);
    }
}

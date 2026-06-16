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

// Custom Dimensions table

if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/screen.php');
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the GA4 Custom Dimensions table on the plugin's CD admin
 * screen and writes the GA_CUSTOM_DIMENSIONS_MAP option that the
 * frontend gtag emission consumes.
 *
 * Differences from the UA-era table:
 *   - The numeric "Index" column is gone — GA4 addresses Custom
 *     Dimensions by `parameter_name` (event-parameter key) and
 *     `displayName`, not by numeric position.
 *   - Existing Custom Dimensions are fetched from the GA4 property
 *     via Ga4DimensionService and offered as dropdown options
 *     alongside an auto-derived default, so the admin can map a
 *     51Degrees property either onto a CD they already created or
 *     onto a new one that gets auto-created on Enable Tracking.
 *   - Auto-create on Enable happens via Ga4DimensionService against
 *     the GA4 Admin API; the stored option carries the parameter
 *     name + display name + the data-path expression for emission.
 *
 * Map row shape written to Options::GA_CUSTOM_DIMENSIONS_MAP:
 *   - parameter_name           (GA4 event-parameter key)
 *   - display_name             (GA4 displayName the admin sees)
 *   - property_name            (51Degrees property, lowercased)
 *   - custom_dimension_datakey (51Degrees engine key, lowercased)
 */
class Fiftyonedegrees_Custom_Dimensions extends WP_List_Table
{
    /**
     * Per-property cache of the GA4 Custom Dimensions list. Stored
     * as a transient so it expires automatically; key is suffixed
     * with the property_id so switching properties does not serve
     * stale data from the previous selection. Invalidated
     * explicitly by ga-service after a successful Enable Tracking
     * (the just-created dimensions must show up on the next CD tab
     * render) and on Logout.
     */
    public const CACHE_TRANSIENT_PREFIX = 'fiftyonedegrees_ga_cd_cache_';

    /**
     * Five minutes. Short enough that a CD created directly in the
     * GA4 console shows up on the next CD-tab reload, long enough
     * that the multi-render burst from opening the tab and
     * tweaking dropdowns does not hit the Admin API repeatedly.
     */
    public const CACHE_TTL_SECONDS = 300;

    /**
     * GA4 parameter_name validation (per the Admin API):
     *   - 1 - 40 characters
     *   - must start with [A-Za-z]
     *   - rest from [A-Za-z0-9_]
     * The plugin lowercases everything; the regex below enforces
     * the same rule case-insensitively but the runtime check uses
     * lowercase strings.
     */
    private const PARAMETER_NAME_MAX_LENGTH = 40;

    /**
     * @var Fiftyonedegrees_Google_Analytics|null Optional injected
     * service for tests / advanced wiring. Production code path
     * defaults to a freshly-constructed instance via the
     * resolve_ga_service() lazy seam — see fetch_existing_dimensions.
     */
    private $ga_service = null;

    public function __construct($args = [])
    {
        parent::__construct($args);
        if (isset($args['ga_service'])) {
            $this->ga_service = $args['ga_service'];
        }
    }

    public function get_columns()
    {
        // The 'include' header carries an HTML <input> rather than a
        // plain label so the column heading itself is the master
        // toggle. WP_List_Table emits column display names without
        // escaping, so the markup survives intact; 51D.js wires the
        // toggle to the per-row checkboxes by id.
        return [
            'property_name'         => __('Property Name'),
            'custom_dimension_name' => __('Custom Dimension'),
            'include'               => '<input type="checkbox" class="51D-include-master" aria-label="'
                . esc_attr__('Include all 51Degrees properties as Custom Dimensions', 'fiftyonedegrees')
                . '" />'
                . '<span style="margin-left:6px;">'
                . esc_html__('Include', 'fiftyonedegrees')
                . '</span>',
        ];
    }

    public function get_sortable_columns()
    {
        return [
            'property_name' => ['property_name', true],
        ];
    }

    public function prepare_items()
    {
        $result = Pipeline::$data;
        if (!$result) {
            return;
        }

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];

        $existing_dimensions = $this->fetch_existing_dimensions();
        $passedDims          = get_option(Options::GA_DIMENSIONS);

        $results    = [];
        $ga_results = [];

        foreach ($result['properties'] as $dataKey => $properties) {
            foreach ($properties as $property) {
                $name = strtolower((string) $property['name']);
                if (strpos($name, 'javascript') !== false
                    || strpos($name, 'setheader') !== false
                ) {
                    continue;
                }

                $datakey      = strtolower((string) $dataKey);
                $defaultParam = $this->derive_parameter_name($datakey, $name);

                // The admin's stored mapping may already point this
                // 51Degrees property at a non-default parameter
                // name (e.g. one they already created on GA4 and
                // selected from the dropdown last time).
                $selectedParam = isset($passedDims[$name])
                    ? (string) $passedDims[$name]
                    : $defaultParam;

                $listbox = $this->get_custom_dimension_listbox(
                    $existing_dimensions,
                    $defaultParam,
                    $selectedParam
                );

                $matchedParam = $this->get_ga_custom_dimension_parameter_name(
                    $existing_dimensions,
                    $selectedParam
                );

                // NOTE: `custom_dimension_options` carries the
                // listbox option list (an array of strings), not a
                // single name. display_rows iterates it as a JS
                // array. The legacy key `custom_dimension_name`
                // suggested a scalar; the rename eliminates the
                // confusion.
                $results[] = [
                    'property_name'            => $name,
                    'custom_dimension_options' => $listbox,
                ];

                $ga_results[] = [
                    'parameter_name'           => $matchedParam !== ''
                        ? $matchedParam
                        : $defaultParam,
                    'display_name'             => $this->derive_display_name($property['name']),
                    'property_name'            => $name,
                    'custom_dimension_datakey' => $datakey,
                ];
            }
        }

        usort($results, function ($a, $b) {
            $orderby = !empty($_GET['orderby'])
                ? sanitize_text_field($_GET['orderby'])
                : 'property_name';
            $order = !empty($_GET['order'])
                ? sanitize_text_field($_GET['order'])
                : 'asc';
            $cmp = strcmp((string) $a[$orderby], (string) $b[$orderby]);
            return $order === 'asc' ? $cmp : -$cmp;
        });

        update_option(Options::GA_CUSTOM_DIMENSIONS_MAP, $ga_results);

        $this->items = $results;
    }

    public function display_rows()
    {
        $passedDims = get_option(Options::GA_DIMENSIONS);
        // Inclusion set (property_name => true) of ticked checkboxes.
        // Absent option (option === false) means "fresh, never submitted"
        // -> default everything to ticked so an onboarding admin sees the
        // familiar all-properties view. Present option (array, possibly
        // empty) means "the admin has interacted" -> property ticked iff
        // it appears as a key. Unticked properties are not stored, they
        // are simply absent from the set.
        $includedMap = get_option(Options::GA_DIMENSIONS_INCLUDED);
        $hasIncludedMap = is_array($includedMap);

        foreach ($this->items as $i => $rec) {
            $listBoxId = '51D_' . $rec['property_name'];
            $selectedPHP = $passedDims[$rec['property_name']] ?? '';
            $includeName = '51D_include_' . $rec['property_name'];
            $isIncluded = $hasIncludedMap
                ? isset($includedMap[$rec['property_name']])
                : true;

            echo '<tr id="record_' . esc_attr((string) $i) . '">';
            echo '<td>' . esc_html($rec['property_name']) . '</td>';
            echo '<td>';
            echo '<div class="51DPropertiesList">';
            ?>
            <select id="<?php echo esc_attr($listBoxId); ?>" name="<?php echo esc_attr($listBoxId); ?>">
                <script>
                    var custDimsList = <?php echo sprintf(esc_html('%1$s'), json_encode($rec['custom_dimension_options'])); ?>;
                    var selectedParam = "<?php echo esc_html((string) $selectedPHP); ?>";
                    if (Array.isArray(custDimsList)) {
                        for (var i = 0, len = custDimsList.length; i < len; i++) {
                            var v = custDimsList[i];
                            if (typeof v !== 'string' || v === '') {
                                continue;
                            }
                            if (selectedParam === v) {
                                document.write('<option value="' + v + '" selected>' + v + '</option>');
                            } else {
                                document.write('<option value="' + v + '">' + v + '</option>');
                            }
                        }
                    }
                </script>
            </select>
            <?php
            echo "</div>\n</td>\n";
            printf(
                '<td><input type="checkbox" class="51D-include-cb" name="%s" value="1" %s aria-label="%s" /></td>',
                esc_attr($includeName),
                checked($isIncluded, true, false),
                esc_attr(sprintf(
                    /* translators: %s is the 51Degrees property name. */
                    __('Include %s as a Custom Dimension', 'fiftyonedegrees'),
                    $rec['property_name']
                ))
            );
            echo "</tr>\n";
        }
    }

    /**
     * Derives the default GA4 parameter_name for a 51Degrees
     * property and coerces the result into the GA4 Admin API's
     * `[A-Za-z][A-Za-z0-9_]{0,39}` shape so a long property name
     * or one starting with a digit cannot leak through to the
     * create() call and bounce as INVALID_ARGUMENT mid-batch.
     *
     * Coercion rules:
     *   - Lowercase both segments and join with underscore.
     *   - Replace any character outside [a-z0-9_] with underscore.
     *   - If the first character is not a letter (e.g. "5g" /
     *     digit-leading), prefix with "p_" so the GA4 leading-letter
     *     rule is satisfied without losing information.
     *   - If the result exceeds 40 characters, truncate and append
     *     a 4-char hex hash of the original so collisions between
     *     two overlong names that share a prefix do not merge.
     */
    public function derive_parameter_name($datakey, $propertyName)
    {
        $datakey      = strtolower((string) $datakey);
        $propertyName = strtolower((string) $propertyName);
        if ($datakey === '' || $propertyName === '') {
            return '';
        }

        $candidate = $datakey . '_' . $propertyName;
        // Coerce any out-of-spec character to underscore.
        $candidate = preg_replace('/[^a-z0-9_]/', '_', $candidate);
        if ($candidate === null || $candidate === '') {
            return '';
        }

        // Leading char must be [A-Za-z]; we are all-lowercase by
        // construction, so the test reduces to ctype_alpha on the
        // first character only.
        if (!ctype_alpha($candidate[0])) {
            $candidate = 'p_' . $candidate;
        }

        if (strlen($candidate) > self::PARAMETER_NAME_MAX_LENGTH) {
            // Reserve 5 chars for `_` + 4-char hash suffix so the
            // truncated body keeps as much of the human-readable
            // prefix as possible.
            $suffix = substr(md5($candidate), 0, 4);
            $candidate = substr($candidate, 0, self::PARAMETER_NAME_MAX_LENGTH - 5)
                . '_' . $suffix;
        }

        return $candidate;
    }

    /**
     * Auto-prefixed GA4 displayName the admin sees in the GA4
     * console. Matches the prefix the Ga4DimensionService uses on
     * create.
     */
    public function derive_display_name($propertyName)
    {
        return FiftyOneDegreesGa4DimensionService::DISPLAY_NAME_PREFIX
            . (string) $propertyName;
    }

    /**
     * Returns the matched parameter_name from $existing_dimensions
     * (the list fetched from the GA4 property) when $selectedParam
     * is one of the existing rows. Returns '' when not present —
     * caller treats that as "auto-create on Enable Tracking".
     *
     * @param array<int,array<string,string>> $existing_dimensions
     * @param string $selectedParam
     * @return string parameter_name when matched, '' otherwise
     */
    public function get_ga_custom_dimension_parameter_name(
        array $existing_dimensions,
        $selectedParam
    ) {
        $selectedParam = (string) $selectedParam;
        if ($selectedParam === '') {
            return '';
        }
        foreach ($existing_dimensions as $row) {
            if (isset($row['parameter_name'])
                && $row['parameter_name'] === $selectedParam
            ) {
                return $selectedParam;
            }
        }
        return '';
    }

    /**
     * Builds the dropdown options for a single property row. The
     * derived default goes first (so a fresh property auto-selects
     * a sensible name) followed by every existing CD on the
     * property — admin can pick "create new under default name"
     * or "map onto an existing CD".
     *
     * @param array<int,array<string,string>> $existing_dimensions
     * @param string $defaultParam
     * @param string $selectedParam
     * @return array<int,string>
     */
    public function get_custom_dimension_listbox(
        array $existing_dimensions,
        $defaultParam,
        $selectedParam
    ) {
        $options = [];
        if ($defaultParam !== '') {
            $options[] = $defaultParam;
        }

        foreach ($existing_dimensions as $row) {
            if (!isset($row['parameter_name'])) {
                continue;
            }
            $param = (string) $row['parameter_name'];
            if ($param === '' || in_array($param, $options, true)) {
                continue;
            }
            $options[] = $param;
        }

        if ($selectedParam !== '' && !in_array($selectedParam, $options, true)) {
            $options[] = $selectedParam;
        }

        return $options;
    }

    /**
     * Pulls the GA4 property's existing Custom Dimensions via the
     * shared Ga4DimensionService. Three failure modes are handled
     * explicitly:
     *
     *   - Auth-error path (token revoked / scope insufficient):
     *     surface a reconnect notice via the shared OauthNotice
     *     channel so the admin sees a banner on the next render,
     *     and return [] so the table still draws with the
     *     auto-derived defaults.
     *   - Other API errors (5xx, network timeout): swallow + log
     *     so the render path does not white-screen on a transient
     *     blip. Caller still draws with the defaults.
     *   - Missing property / unauthenticated: return [] silently
     *     (handled upstream).
     *
     * Results are cached in a per-property transient with a short
     * TTL so opening + tweaking the CD tab does not re-hit the
     * Admin API on every render. Invalidated explicitly by
     * ga-service after Enable Tracking succeeds (so just-created
     * dimensions show up immediately) and on Logout.
     *
     * @return array<int,array<string,string>>
     */
    protected function fetch_existing_dimensions()
    {
        $property_id = get_option(Options::GA_PROPERTY_ID);
        if (empty($property_id)) {
            return [];
        }

        $cache_key = self::CACHE_TRANSIENT_PREFIX . $property_id;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $ga_service = $this->resolve_ga_service();
        $client = $ga_service->authenticate();
        if (!$client) {
            return [];
        }

        $admin = $ga_service->get_ga4_admin_service($client);

        try {
            $rows = FiftyOneDegreesGa4DimensionService::list_custom_dimensions(
                $admin,
                $property_id
            );
        }
        catch (FiftyOneDegreesGa4AuthError $e) {
            // Hand off to the OauthNotice channel — surfaces on the
            // next render of any GA tab and routes through the same
            // copy lookup as the OAuth callback handlers.
            if (class_exists('FiftyOneDegreesOauthNotice')) {
                FiftyOneDegreesOauthNotice::set('reconnect_required');
            }
            return [];
        }
        catch (\Throwable $e) {
            error_log(
                '51Degrees: failed to fetch GA4 Custom Dimensions for '
                . 'property ' . $property_id . ': ' . $e->getMessage()
            );
            return [];
        }

        set_transient($cache_key, $rows, self::CACHE_TTL_SECONDS);
        return $rows;
    }

    /**
     * Service seam: tests inject a stub via the constructor `args`;
     * production paths get a freshly-constructed
     * Fiftyonedegrees_Google_Analytics.
     */
    private function resolve_ga_service()
    {
        if ($this->ga_service !== null) {
            return $this->ga_service;
        }
        return new Fiftyonedegrees_Google_Analytics();
    }
}

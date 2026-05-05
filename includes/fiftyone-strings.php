<?php

/**
 * Loads and exposes UI string resources from the languages/*-strings.yaml files.
 *
 * Usage:
 *   FiftyOneDegreesStrings::get('robots.page.title')
 *   FiftyOneDegreesStrings::get('robots.notice.cloud_api_error', esc_html($error))
 *
 * For locale overrides, place a {file}.{locale}.yaml file next to the base
 * file (e.g. languages/robots-strings.fr_FR.yaml). Each *-strings file is
 * localised independently — translating one file does not require copying
 * the others; missing locale files fall back to the English base.
 *
 * Keys use dot notation with up to three levels: section.subsection.key.
 * Values that contain HTML are safe to output via wp_kses_post(); plain-text
 * values should be output via esc_html().
 */
class FiftyOneDegreesStrings {

    // File order matters: common loads first, feature files override on collision
    // via array_replace_recursive. Top-level namespaces (common:, robots:,
    // suspicious:) are unique per file by convention — feature files must NOT
    // add a `common:` namespace or they'll silently override common-strings.
    private const FILES = ['common-strings', 'robots-strings', 'suspicious-strings'];

    /** @var array<string,mixed>|null */
    private static $data = null;

    /**
     * Returns the string for the given dot-notation key.
     *
     * Extra arguments are passed to vsprintf() for %s placeholder substitution.
     * Returns the bare key string if the key is not found.
     */
    public static function get(string $key, ...$args): string {
        if (self::$data === null) {
            self::$data = self::load();
        }
        $value = self::lookup($key);
        if ($value === null) {
            return $key;
        }
        return empty($args) ? $value : vsprintf($value, $args);
    }

    /** Clears the in-memory cache. */
    public static function reset(): void {
        self::$data = null;
    }

    /** @return array<string,mixed> */
    private static function load(): array {
        $dir = defined('FIFTYONEDEGREES_PLUGIN_DIR')
            ? FIFTYONEDEGREES_PLUGIN_DIR
            : dirname(__DIR__) . DIRECTORY_SEPARATOR;

        $base       = $dir . 'languages' . DIRECTORY_SEPARATOR;
        $locale     = function_exists('get_locale') ? get_locale() : '';
        $use_locale = $locale !== '' && $locale !== 'en_US' && $locale !== 'en';

        $merged = [];
        foreach (self::FILES as $name) {
            $file = $base . $name . '.yaml';
            if ($use_locale) {
                $candidate = $base . $name . '.' . $locale . '.yaml';
                if (is_readable($candidate)) {
                    $file = $candidate;
                }
            }
            $merged = array_replace_recursive($merged, self::parse_yaml($file));
        }
        return $merged;
    }

    /** @return array<string,mixed> */
    private static function parse_yaml(string $file): array {
        if (!is_readable($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $result = [];
        $path   = [];

        foreach ($lines as $line) {
            $stripped = ltrim($line, ' ');

            if ($stripped === '' || $stripped[0] === '#') {
                continue;
            }

            $indent = strlen($line) - strlen($stripped);
            $level  = (int) ($indent / 2);

            $colon = strpos($stripped, ':');
            if ($colon === false) {
                continue;
            }

            $key  = rtrim(substr($stripped, 0, $colon));
            $rest = ltrim(substr($stripped, $colon + 1));

            // Strip any trailing inline comment on the rest part.
            // Only safe for unquoted values; quoted values handled in parse_scalar.
            if ($rest !== '' && $rest[0] !== '"' && $rest[0] !== "'" && ($hash = strpos($rest, ' #')) !== false) {
                $rest = rtrim(substr($rest, 0, $hash));
            }

            $path = array_slice($path, 0, $level);
            $path[$level] = $key;

            if ($rest === '' || $rest[0] === '#') {
                self::node_set($result, $path, []);
            } else {
                self::node_set($result, $path, self::parse_scalar($rest));
            }
        }

        return $result;
    }

    /**
     * Sets a value at the given path in the nested array.
     * Skips overwriting an already-populated array with an empty one so that
     * re-encountering a container key does not erase its children.
     *
     * @param array<string,mixed> $root
     * @param array<int,string>   $path
     * @param mixed               $value
     */
    private static function node_set(array &$root, array $path, $value): void {
        $ref  = &$root;
        $last = (string) array_pop($path);
        foreach ($path as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        if (!is_array($value) || !isset($ref[$last]) || !is_array($ref[$last])) {
            $ref[$last] = $value;
        }
    }

    /** Traverses the nested array using dot-notation key parts. */
    private static function lookup(string $key): ?string {
        $parts = explode('.', $key, 3);
        $node  = self::$data;
        foreach ($parts as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }
        return is_string($node) ? $node : null;
    }

    /**
     * Parses a YAML scalar value (single-quoted, double-quoted, or unquoted).
     * Supports the subset needed for single-line string resources:
     *   - Single-quoted  'value'  — '' is an escaped apostrophe.
     *   - Double-quoted  "value"  — \" \\ \n \t \r escape sequences.
     *   - Unquoted       value    — trailing whitespace stripped.
     */
    private static function parse_scalar(string $s): string {
        if ($s === '') {
            return '';
        }

        if ($s[0] === "'") {
            return self::parse_single_quoted($s);
        }

        if ($s[0] === '"') {
            return self::parse_double_quoted($s);
        }

        return rtrim($s);
    }

    private static function parse_single_quoted(string $s): string {
        $len  = strlen($s);
        $buf  = '';
        $i    = 1;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === "'") {
                if ($i + 1 < $len && $s[$i + 1] === "'") {
                    $buf .= "'";
                    $i   += 2;
                    continue;
                }
                break;
            }
            $buf .= $c;
            $i++;
        }
        return $buf;
    }

    private static function parse_double_quoted(string $s): string {
        $len  = strlen($s);
        $buf  = '';
        $i    = 1;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $next = $s[$i + 1];
                switch ($next) {
                    case 'n':  $buf .= "\n"; break;
                    case 't':  $buf .= "\t"; break;
                    case 'r':  $buf .= "\r"; break;
                    case '"':  $buf .= '"';  break;
                    case '\\': $buf .= '\\'; break;
                    default:   $buf .= $next; break;
                }
                $i += 2;
                continue;
            }
            if ($c === '"') {
                break;
            }
            $buf .= $c;
            $i++;
        }
        return $buf;
    }
}

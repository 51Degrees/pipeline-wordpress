<?php

/**
 * Loads and exposes standard TDL (Terms Document Locator) entries from
 * config/robots-standard-tdls.json.
 *
 * Each entry has: id, label, description, macro. The macro is sent to
 * 51Degrees Cloud as-is in the robotstxt.tdl= parameter; cloud resolves
 * it to the current canonical URL.
 */
class FiftyOneDegreesStandardTdls {

    /** @var array<int,array<string,string>>|null */
    private static $cache = null;

    /**
     * Returns all standard TDL config entries.
     * Returns an empty array if the config file is missing or unparseable.
     *
     * @return array<int,array<string,string>>
     */
    public static function load(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $dir  = defined('FIFTYONEDEGREES_PLUGIN_DIR')
            ? FIFTYONEDEGREES_PLUGIN_DIR
            : dirname(__DIR__) . DIRECTORY_SEPARATOR;

        $file = $dir . 'config' . DIRECTORY_SEPARATOR . 'robots-standard-tdls.json';

        if (!is_readable($file)) {
            self::$cache = [];
            return self::$cache;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            self::$cache = [];
            return self::$cache;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::$cache = [];
            return self::$cache;
        }

        $entries = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (empty($item['id']) || empty($item['macro'])) {
                continue;
            }
            $entries[] = [
                'id'          => (string) $item['id'],
                'label'       => isset($item['label'])       ? (string) $item['label']       : (string) $item['id'],
                'description' => isset($item['description']) ? (string) $item['description'] : '',
                'macro'       => (string) $item['macro'],
            ];
        }

        self::$cache = $entries;
        return self::$cache;
    }

    /**
     * Returns the config entry for the given id, or null if not found.
     *
     * @return array<string,string>|null
     */
    public static function get_by_id(string $id): ?array {
        foreach (self::load() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Clears the in-memory cache. Used in tests.
     */
    public static function reset(): void {
        self::$cache = null;
    }
}

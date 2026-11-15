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

require_once __DIR__ . '/../includes/fiftyone-strings.php';

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Checks that the README, the WordPress.org description and the admin
 * text match the code on where PMP keeps the choice, when robots.txt
 * hosting calls the cloud, what enforcement does without CrawlerUsage
 * and where the plugin's zip comes from.
 */
class DocumentationTests extends TestCase
{
    // get_locale is left undefined, so the strings load from the base
    // English files. Stubbing it here would define it for every later
    // test, and the string loader calls it whenever it exists.
    public function set_up()
    {
        parent::set_up();
        FiftyOneDegreesStrings::reset();
    }

    public function tear_down()
    {
        FiftyOneDegreesStrings::reset();
        parent::tear_down();
    }

    private static function read(string $file): string
    {
        $text = file_get_contents(dirname(__DIR__) . '/' . $file);
        self::assertIsString($text, "$file must be readable");
        return $text;
    }

    /**
     * Robots.txt hosting fetches the file from the cloud with the
     * Resource Key when the tab is saved and once a day.
     */
    public function testRobotsHostingSaysWhenItCallsTheCloud()
    {
        $text = FiftyOneDegreesStrings::get('robots.field.enable_description');

        self::assertStringNotContainsString('does not consume', $text);
        self::assertStringContainsString('Resource Key', $text);
        self::assertStringContainsString('once a day', $text);
    }

    /**
     * Enforcement has no path-based branch, so without CrawlerUsage it
     * blocks and redirects nothing.
     */
    public function testNoPathBasedFallbackIsDescribed()
    {
        $notice = FiftyOneDegreesStrings::get('robots.notice.no_crawler_usage');

        self::assertStringNotContainsString('path-based', $notice);
        self::assertStringContainsString('redirects nothing', $notice);
        self::assertStringNotContainsString('path-based', self::read('README.md'));
    }

    /**
     * PMP keeps the choice in the __mtm_pref and __51d_pmp_share
     * cookies, and uses no localStorage.
     */
    public function testPmpStorageIsDescribedAsCookies()
    {
        foreach (['README.md', 'readme.txt'] as $file) {
            $text = self::read($file);

            self::assertStringNotContainsString('localStorage', $text, $file);
            self::assertStringNotContainsString('__51d_pmp_pref', $text, $file);
            self::assertStringNotContainsString('writes no cookies', $text, $file);
            self::assertStringContainsString('__mtm_pref', $text, $file);
            self::assertStringContainsString('__51d_pmp_share', $text, $file);
        }
    }

    /**
     * Manual installation points at this plugin's WordPress.org page and
     * the folder its zip unpacks to.
     */
    public function testManualInstallationNamesThisPlugin()
    {
        $readme = self::read('README.md');

        self::assertStringNotContainsString('wp-plugin-manager', $readme);
        self::assertStringContainsString(
            'upload the `51degrees-optimize-by-device-location` folder',
            $readme);
    }
}

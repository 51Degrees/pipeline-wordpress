<?php

/**
 * Release-gate tests. NOT part of the default Unit suite — they
 * intentionally fail until pre-release work is complete, so a regular
 * dev run stays green.
 *
 * Run explicitly before tagging a release:
 *
 *     php lib/vendor/bin/phpunit --testsuite ReleaseGate
 *
 * Each test guards one ship-blocker condition documented inline.
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class ReleaseReadinessTests extends TestCase
{
    private const PLUGIN_ROOT = __DIR__ . '/..';

    /**
     * The OAuth redirect URI is hardcoded as the placeholder
     * 'https://TODO-relay-url' until 51Degrees provisions the production
     * relay (Ship Gate 2). Shipping with the placeholder hard-disables
     * Google Analytics for every install via the bootstrap guard, so this
     * test is the explicit pre-release blocker for that condition.
     */
    public function testBootstrapHasNoPlaceholderRedirectUrl()
    {
        $bootstrap = file_get_contents(self::PLUGIN_ROOT . '/fiftyonedegrees.php');
        $this->assertIsString($bootstrap, 'fiftyonedegrees.php must be readable');
        $this->assertStringNotContainsString(
            'TODO-relay-url',
            $bootstrap,
            'Placeholder OAuth redirect URL still present in fiftyonedegrees.php — '
            . 'OAuth is hard-disabled until the production relay URL is wired in. '
            . 'Resolve Ship Gate 2 with Eugene/James before releasing.'
        );
    }

    /**
     * readme.txt Stable tag follows the convention "main branch = dev
     * snapshot": Stable tag stays at the last released version until the
     * release PR (S-15) bumps it. Pre-release we expect it to match the
     * Version: header in fiftyonedegrees.php. This test guards that the
     * Stable tag has been bumped to the new version before tagging.
     *
     * Implemented as a placeholder for now — refines in S-15 once the
     * target version (1.0.12) is committed to fiftyonedegrees.php's
     * Version: header.
     */
    public function testReadmeStableTagMatchesPluginVersion()
    {
        $readme = file_get_contents(self::PLUGIN_ROOT . '/readme.txt');
        $bootstrap = file_get_contents(self::PLUGIN_ROOT . '/fiftyonedegrees.php');

        $this->assertIsString($readme, 'readme.txt must be readable');
        $this->assertIsString($bootstrap, 'fiftyonedegrees.php must be readable');

        $stable = null;
        if (preg_match('/^Stable tag:\s*(\S+)/m', $readme, $m)) {
            $stable = $m[1];
        }
        $version = null;
        if (preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $bootstrap, $m)) {
            $version = $m[1];
        }

        $this->assertNotNull($stable, 'readme.txt must declare "Stable tag:"');
        $this->assertNotNull($version, 'fiftyonedegrees.php must declare "Version:" header');
        $this->assertSame(
            $version,
            $stable,
            sprintf(
                'readme.txt Stable tag (%s) must match fiftyonedegrees.php Version (%s). '
                . 'Bump Stable tag in the release PR (S-15) before tagging.',
                $stable,
                $version
            )
        );
    }
}

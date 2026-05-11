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

require_once(__DIR__ . '/../includes/fiftyone-service.php');

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey;

class PmpTests extends TestCase
{
    private $options = [];
    private $postBackup;
    private $settingsErrors = [];

    public function set_up()
    {
        parent::set_up();
        Monkey\setUp();

        $this->postBackup = $_POST;
        $_POST = [];
        $this->settingsErrors = [];

        $this->options = [
            Options::PMP_ENABLE             => 'off',
            Options::PMP_SCRIPT_URL         => '//cdn.51degrees.com/pmp/pmp-en-us.js',
            Options::PMP_TCF_VENDOR_ID      => 51,
            Options::PMP_TCF_VENDOR_STRING  => '',
            Options::PMP_ALT_LABEL          => '',
            Options::PMP_ALT_URL            => '',
            Options::PMP_BRAND_NAME         => '',
            Options::PMP_BRAND_LOGO_URL     => '',
            Options::PMP_BRAND_TERMS_URL    => '',
            Options::PMP_SHOW_STANDARD      => 'off',
            Options::RESOURCE_KEY           => 'test-key',
        ];

        $opts = &$this->options;
        Functions\when('get_option')->alias(function ($key, $default = '') use (&$opts) {
            return array_key_exists($key, $opts) ? $opts[$key] : $default;
        });
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        $errors = &$this->settingsErrors;
        Functions\when('add_settings_error')->alias(function ($setting, $code, $message) use (&$errors) {
            $errors[] = ['setting' => $setting, 'code' => $code, 'message' => $message];
            return true;
        });
        Functions\when('__')->returnArg();
    }

    public function tear_down()
    {
        $_POST = $this->postBackup;
        Monkey\tearDown();
        parent::tear_down();
    }

    // -------- sanitize_pmp_enable --------

    /**
     * Off value short-circuits validation and saves as 'off'.
     */
    public function testSanitizePmpEnableOff()
    {
        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('off'));
    }

    /**
     * Anything that is not the literal 'on' resolves to 'off'.
     */
    public function testSanitizePmpEnableInvalidValueIsOff()
    {
        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable(''));
        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('yes'));
        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable(null));
    }

    /**
     * With every required field present in POST the enable flag passes
     * validation and is stored as 'on'.
     */
    public function testSanitizePmpEnableOnAllRequiredPresent()
    {
        $_POST = [
            Options::PMP_TCF_VENDOR_STRING => 'CPYBSvo',
            Options::PMP_BRAND_NAME        => 'Brand',
            Options::PMP_BRAND_TERMS_URL   => 'https://example.com/privacy',
            Options::PMP_ALT_LABEL         => 'Pay',
            Options::PMP_ALT_URL           => 'https://example.com/pay',
        ];

        self::assertEquals('on', FiftyoneService::sanitize_pmp_enable('on'));
    }

    /**
     * A single missing required field forces the option back to 'off' and
     * registers a settings error.
     */
    public function testSanitizePmpEnableOnMissingOneFieldRegistersError()
    {
        $_POST = [
            Options::PMP_TCF_VENDOR_STRING => '',
            Options::PMP_BRAND_NAME        => 'Brand',
            Options::PMP_BRAND_TERMS_URL   => 'https://example.com/privacy',
            Options::PMP_ALT_LABEL         => 'Pay',
            Options::PMP_ALT_URL           => 'https://example.com/pay',
        ];

        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('on'));
        self::assertCount(1, $this->settingsErrors);
        self::assertStringContainsString('TCF Vendor String', $this->settingsErrors[0]['message']);
    }

    /**
     * Whitespace-only values are treated as missing.
     */
    public function testSanitizePmpEnableOnWhitespaceCountsAsMissing()
    {
        $_POST = [
            Options::PMP_TCF_VENDOR_STRING => '   ',
            Options::PMP_BRAND_NAME        => 'Brand',
            Options::PMP_BRAND_TERMS_URL   => 'https://example.com/privacy',
            Options::PMP_ALT_LABEL         => 'Pay',
            Options::PMP_ALT_URL           => 'https://example.com/pay',
        ];

        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('on'));
        self::assertCount(1, $this->settingsErrors);
    }

    /**
     * When no required fields are supplied the validation still produces
     * a single, aggregated error message rather than one per field.
     */
    public function testSanitizePmpEnableOnNoFieldsRegistersOneError()
    {
        $_POST = [];

        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('on'));
        self::assertCount(1, $this->settingsErrors);
        // Single aggregated message lists all five missing fields.
        $message = $this->settingsErrors[0]['message'];
        self::assertStringContainsString('TCF Vendor String', $message);
        self::assertStringContainsString('Brand Name', $message);
        self::assertStringContainsString('Terms / Privacy URL', $message);
        self::assertStringContainsString('Alternative Button Label', $message);
        self::assertStringContainsString('Alternative Button URL', $message);
    }

    // -------- pmp_map_locale --------

    /**
     * en_US maps to the 'en-us' bundle suffix.
     */
    public function testPmpMapLocaleEnUs()
    {
        self::assertEquals('en-us', FiftyoneService::pmp_map_locale('en_US'));
    }

    /**
     * de_DE maps to 'de-de'.
     */
    public function testPmpMapLocaleDeDe()
    {
        self::assertEquals('de-de', FiftyoneService::pmp_map_locale('de_DE'));
    }

    /**
     * fr_FR maps to 'fr-fr'.
     */
    public function testPmpMapLocaleFrFr()
    {
        self::assertEquals('fr-fr', FiftyoneService::pmp_map_locale('fr_FR'));
    }

    /**
     * Locales PMP does not ship a bundle for fall through to null so the
     * URL resolver leaves the configured URL untouched.
     */
    public function testPmpMapLocaleUnsupportedReturnsNull()
    {
        self::assertNull(FiftyoneService::pmp_map_locale('uk_UA'));
        self::assertNull(FiftyoneService::pmp_map_locale(''));
    }

    // -------- pmp_replace_locale_in_url --------

    /**
     * en-us suffix is treated as a no-op; the URL is returned unchanged
     * because the bundle filename already matches.
     */
    public function testPmpReplaceLocaleInUrlEnUsNoOp()
    {
        self::assertEquals(
            '//cdn.51degrees.com/pmp/pmp-en-us.js',
            FiftyoneService::pmp_replace_locale_in_url('//cdn.51degrees.com/pmp/pmp-en-us.js', 'en-us')
        );
    }

    /**
     * Locale change swaps the 'en-us' token in the CDN URL.
     */
    public function testPmpReplaceLocaleInUrlDeDeSwapsCdn()
    {
        self::assertEquals(
            '//cdn.51degrees.com/pmp/pmp-de-de.js',
            FiftyoneService::pmp_replace_locale_in_url('//cdn.51degrees.com/pmp/pmp-en-us.js', 'de-de')
        );
    }

    /**
     * The same swap works against the local cloud URL used during
     * development.
     */
    public function testPmpReplaceLocaleInUrlLocalCloudFrFr()
    {
        self::assertEquals(
            'https://localhost:5001/pmp/KEY/pmp-fr-fr.js',
            FiftyoneService::pmp_replace_locale_in_url('https://localhost:5001/pmp/KEY/pmp-en-us.js', 'fr-fr')
        );
    }

    /**
     * Null suffix (locale PMP does not ship a bundle for) leaves the URL
     * pointing at the en-us bundle.
     */
    public function testPmpReplaceLocaleInUrlNullSuffixKeepsUrl()
    {
        self::assertEquals(
            '//cdn.51degrees.com/pmp/pmp-en-us.js',
            FiftyoneService::pmp_replace_locale_in_url('//cdn.51degrees.com/pmp/pmp-en-us.js', null)
        );
    }

    /**
     * Empty URL yields an empty result regardless of suffix.
     */
    public function testPmpReplaceLocaleInUrlEmptyUrl()
    {
        self::assertEquals('', FiftyoneService::pmp_replace_locale_in_url('', 'de-de'));
        self::assertEquals('', FiftyoneService::pmp_replace_locale_in_url('', null));
    }

    // -------- pmp_add_data_attributes --------

    /**
     * The filter is registered globally for script_loader_tag and runs
     * for every script. It must leave non-PMP handles alone.
     */
    public function testPmpAddDataAttributesUnrelatedHandlePassThrough()
    {
        $service = new FiftyoneService();
        $tag = '<script src="x"></script>';
        self::assertEquals(
            $tag,
            $service->pmp_add_data_attributes($tag, 'jquery', 'x')
        );
    }

    /**
     * With every option filled in, all relevant data-* attributes are
     * injected before the src attribute.
     */
    public function testPmpAddDataAttributesIncludesAllFilled()
    {
        $this->options[Options::PMP_TCF_VENDOR_STRING] = 'CPYBS';
        $this->options[Options::PMP_BRAND_NAME]        = 'Brand';
        $this->options[Options::PMP_BRAND_LOGO_URL]    = 'https://example.com/logo.png';
        $this->options[Options::PMP_BRAND_TERMS_URL]   = 'https://example.com/privacy';
        $this->options[Options::PMP_ALT_LABEL]         = 'Pay';
        $this->options[Options::PMP_ALT_URL]           = 'https://example.com/pay';

        $service = new FiftyoneService();
        $result = $service->pmp_add_data_attributes(
            '<script src="x"></script>',
            'fiftyonedegrees-pmp',
            'x'
        );

        self::assertStringContainsString('data-tcf-vendor="CPYBS"', $result);
        self::assertStringContainsString('data-tcf-vendor-id="51"', $result);
        self::assertStringContainsString('data-brand-name="Brand"', $result);
        self::assertStringContainsString('data-brand-logo="https://example.com/logo.png"', $result);
        self::assertStringContainsString('data-brand-terms-url="https://example.com/privacy"', $result);
        self::assertStringContainsString('data-alt-name="Pay"', $result);
        self::assertStringContainsString('data-alt-url="https://example.com/pay"', $result);
        self::assertStringContainsString(
            "data-action-url=\"javascript:window.fiftyoneDegreesPmpOnChoice('{preference}')\"",
            $result
        );
    }

    /**
     * Empty option values are skipped so PMP does not see attributes
     * like data-brand-name="".
     */
    public function testPmpAddDataAttributesSkipsEmptyOptions()
    {
        $service = new FiftyoneService();
        $result = $service->pmp_add_data_attributes(
            '<script src="x"></script>',
            'fiftyonedegrees-pmp',
            'x'
        );

        self::assertStringNotContainsString('data-brand-name=""', $result);
        self::assertStringNotContainsString('data-alt-name=""', $result);
        self::assertStringNotContainsString('data-brand-logo=""', $result);
        self::assertStringContainsString('data-tcf-vendor-id="51"', $result);
        self::assertStringContainsString('data-action-url=', $result);
    }

    /**
     * PMP_SHOW_STANDARD = 'on' adds the data-show-standard="true" hint.
     */
    public function testPmpAddDataAttributesShowStandardOn()
    {
        $this->options[Options::PMP_SHOW_STANDARD] = 'on';
        $service = new FiftyoneService();
        $result = $service->pmp_add_data_attributes(
            '<script src="x"></script>',
            'fiftyonedegrees-pmp',
            'x'
        );

        self::assertStringContainsString('data-show-standard="true"', $result);
    }

    /**
     * With PMP_SHOW_STANDARD off the attribute is absent entirely
     * (PMP defaults to hiding the Standard button).
     */
    public function testPmpAddDataAttributesShowStandardOffOmitsAttribute()
    {
        $service = new FiftyoneService();
        $result = $service->pmp_add_data_attributes(
            '<script src="x"></script>',
            'fiftyonedegrees-pmp',
            'x'
        );

        self::assertStringNotContainsString('data-show-standard', $result);
    }

}

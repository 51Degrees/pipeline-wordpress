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
     * Terms / Privacy URL plus both alternative-button fields are the
     * required ones; the rest fall back to defaults at runtime.
     */
    public function testSanitizePmpEnableOnAllRequiredPresent()
    {
        $_POST = [
            Options::PMP_BRAND_TERMS_URL => 'https://example.com/privacy',
            Options::PMP_ALT_LABEL       => 'Pay',
            Options::PMP_ALT_URL         => 'https://example.com',
        ];

        self::assertEquals('on', FiftyoneService::sanitize_pmp_enable('on'));
    }

    /**
     * Missing Terms / Privacy URL forces enable back to 'off' and
     * registers a settings error naming that field.
     */
    public function testSanitizePmpEnableOnMissingTermsUrlRegistersError()
    {
        $_POST = [
            Options::PMP_BRAND_TERMS_URL => '',
            Options::PMP_ALT_LABEL       => 'Pay',
            Options::PMP_ALT_URL         => 'https://example.com',
        ];

        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('on'));
        self::assertCount(1, $this->settingsErrors);
        self::assertStringContainsString('Terms / Privacy URL', $this->settingsErrors[0]['message']);
    }

    /**
     * Missing Alternative Button Label fails validation.
     */
    public function testSanitizePmpEnableOnMissingAltLabelRegistersError()
    {
        $_POST = [
            Options::PMP_BRAND_TERMS_URL => 'https://example.com/privacy',
            Options::PMP_ALT_LABEL       => '',
            Options::PMP_ALT_URL         => 'https://example.com',
        ];

        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('on'));
        self::assertStringContainsString('Alternative Button Label', $this->settingsErrors[0]['message']);
    }

    /**
     * Missing Alternative Button URL fails validation.
     */
    public function testSanitizePmpEnableOnMissingAltUrlRegistersError()
    {
        $_POST = [
            Options::PMP_BRAND_TERMS_URL => 'https://example.com/privacy',
            Options::PMP_ALT_LABEL       => 'Pay',
            Options::PMP_ALT_URL         => '',
        ];

        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('on'));
        self::assertStringContainsString('Alternative Button URL', $this->settingsErrors[0]['message']);
    }

    /**
     * Whitespace-only required field is treated as missing.
     */
    public function testSanitizePmpEnableOnWhitespaceRequiredIsMissing()
    {
        $_POST = [
            Options::PMP_BRAND_TERMS_URL => '   ',
            Options::PMP_ALT_LABEL       => 'Pay',
            Options::PMP_ALT_URL         => 'https://example.com',
        ];

        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('on'));
        self::assertCount(1, $this->settingsErrors);
    }

    /**
     * Empty POST aggregates all three required-field names into one
     * settings error message.
     */
    public function testSanitizePmpEnableOnNoFieldsRegistersOneError()
    {
        $_POST = [];

        self::assertEquals('off', FiftyoneService::sanitize_pmp_enable('on'));
        self::assertCount(1, $this->settingsErrors);
        $message = $this->settingsErrors[0]['message'];
        self::assertStringContainsString('Terms / Privacy URL', $message);
        self::assertStringContainsString('Alternative Button Label', $message);
        self::assertStringContainsString('Alternative Button URL', $message);
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
            "data-action-url=\"javascript:window.onPMPCompletion('{preference}')\"",
            $result
        );
    }

    /**
     * Empty options fall back to runtime defaults: '51Degrees' for the
     * brand, the bundled plugin logo for the brand logo, 'Remove ads' for
     * the alt label, 'https://example.com' for the alt URL, and the
     * built-in all-vendors TCF Vendor String. Terms URL has no fallback
     * and stays absent from the tag.
     */
    public function testPmpAddDataAttributesUsesFallbacksWhenOptionsEmpty()
    {
        $service = new FiftyoneService();
        $result = $service->pmp_add_data_attributes(
            '<script src="x"></script>',
            'fiftyonedegrees-pmp',
            'x'
        );

        self::assertStringContainsString('data-brand-name="51Degrees"', $result);
        self::assertStringContainsString(
            'data-brand-logo="' . FIFTYONEDEGREES_PLUGIN_URL . 'assets/images/logo.png"',
            $result
        );
        self::assertStringContainsString('data-alt-name="Remove ads"', $result);
        self::assertStringContainsString('data-alt-url="https://example.com"', $result);
        self::assertStringContainsString(
            'data-tcf-vendor="' . FiftyoneService::PMP_DEFAULT_TCF_VENDOR_STRING . '"',
            $result
        );
        self::assertStringContainsString('data-tcf-vendor-id="51"', $result);
        self::assertStringContainsString('data-action-url=', $result);
        // Terms URL has no fallback and is skipped when empty.
        self::assertStringNotContainsString('data-brand-terms-url=""', $result);
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

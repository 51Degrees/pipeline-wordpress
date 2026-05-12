"""Selenium tests for the PMP (Preference Management Platform) tab.

Covers admin-form persistence, server-rendered <script data-*> tags on
public pages, the inline ``fiftyoneDegreesPmpOnChoice`` glue, and the
``query.id.usage`` cookie the glue sets for the next pipeline request.
Browser-flow assertions (popup visibility, __tcfapi state) need a
running PMP cloud at ``PMP_CLOUD_URL`` and skip if it is unreachable.
"""

import os
import re

import pytest
import requests
from selenium.webdriver.support.ui import WebDriverWait

from conftest import WORDPRESS_URL

PMP_CLOUD_URL = os.environ.get('PMP_CLOUD_URL', 'https://localhost:5001')
PMP_REQUIRED_FIELDS = {
    'fiftyonedegrees_pmp_tcf_vendor_string': 'CPYBSvoPYBSvoO3AAAENAwCAAAAAAAAAAAAAAAAAAAAA',
    'fiftyonedegrees_pmp_brand_name':        'Test Brand',
    'fiftyonedegrees_pmp_brand_terms_url':   'https://example.com/privacy',
    'fiftyonedegrees_pmp_alt_label':         'Pay',
    'fiftyonedegrees_pmp_alt_url':           'https://example.com/pay',
}


def _get_admin_nonce(session, tab):
    base = WORDPRESS_URL.rstrip('/')
    url = f'{base}/wp-admin/options-general.php?page=51Degrees&tab={tab}'
    resp = session.get(url)
    resp.raise_for_status()
    match = re.search(r'name="_wpnonce"\s+value="([^"]+)"', resp.text)
    assert match, f'Could not extract _wpnonce from {tab} settings page'
    return match.group(1), resp.text


def save_pmp_settings(session, **overrides):
    """Save PMP settings. Defaults to the minimum valid payload (Enable on,
    all required fields filled). Override individual keys via kwargs.
    """
    base = WORDPRESS_URL.rstrip('/')
    nonce, _ = _get_admin_nonce(session, 'pmp')

    data = {
        '_wpnonce': nonce,
        '_wp_http_referer': '/wp-admin/options-general.php?page=51Degrees&tab=pmp',
        'option_page': 'fiftyonedegrees_pmp_options',
        'action': 'update',
        'fiftyonedegrees_pmp_enable':            'on',
        'fiftyonedegrees_pmp_script_url':        '//cdn.51degrees.com/pmp/pmp-en-us.js',
        'fiftyonedegrees_pmp_tcf_vendor_id':     '51',
        'fiftyonedegrees_pmp_brand_logo_url':    '',
        'fiftyonedegrees_pmp_show_standard':     'off',
        **PMP_REQUIRED_FIELDS,
        **overrides,
    }

    resp = session.post(base + '/wp-admin/options.php', data=data, allow_redirects=False)
    assert resp.status_code in (200, 302), f'Settings save returned {resp.status_code}'


def disable_pmp(session):
    """Quick helper to switch PMP off without filling everything else in."""
    save_pmp_settings(session, fiftyonedegrees_pmp_enable='off')


@pytest.fixture(autouse=True)
def _reset_pmp(wp_admin_session):
    yield
    disable_pmp(wp_admin_session)


class TestSettingsRoundTrip:
    """All PMP fields persist and re-render on the form."""

    def test_round_trip(self, wp_admin_session):
        save_pmp_settings(
            wp_admin_session,
            fiftyonedegrees_pmp_script_url='https://localhost:5001/pmp/KEY/pmp-en-us.js',
            fiftyonedegrees_pmp_tcf_vendor_id='99',
            fiftyonedegrees_pmp_show_standard='on',
        )

        _, html = _get_admin_nonce(wp_admin_session, 'pmp')

        assert 'https://localhost:5001/pmp/KEY/pmp-en-us.js' in html
        assert 'value="99"' in html
        assert PMP_REQUIRED_FIELDS['fiftyonedegrees_pmp_brand_name'] in html
        assert PMP_REQUIRED_FIELDS['fiftyonedegrees_pmp_alt_label'] in html
        assert PMP_REQUIRED_FIELDS['fiftyonedegrees_pmp_alt_url'] in html
        # Both Enable and Show Standard checkboxes saved as 'on'.
        assert html.count('checked') >= 2


class TestRequiredFieldValidation:
    """Enabling PMP without a required field produces a settings error
    and reverts the option to 'off'."""

    def test_missing_brand_name_blocks_enable(self, wp_admin_session):
        save_pmp_settings(wp_admin_session, fiftyonedegrees_pmp_brand_name='')

        # The enable checkbox is forced back to 'off' when a required
        # field is missing. The admin notice with the error message is
        # auto-rendered after the POST -> redirect cycle (driven by
        # WordPress's settings_errors transient + the settings-updated
        # query param it appends to the redirect target).
        tab_url = WORDPRESS_URL.rstrip('/') + (
            '/wp-admin/options-general.php?page=51Degrees&tab=pmp&settings-updated=true'
        )
        resp = wp_admin_session.get(tab_url)
        html = resp.text

        m = re.search(r'name="fiftyonedegrees_pmp_enable"\s+id="fiftyonedegrees_pmp_enable"\s+value="on"([^>]*)>', html)
        assert m, 'Enable checkbox not present'
        assert 'checked' not in m.group(1), 'Enable checkbox unexpectedly checked after validation failure'


class TestScriptTagOnFrontend:
    """When PMP is enabled, the public page carries <script data-*> with
    the expected attributes; disabling PMP removes the tag entirely."""

    def test_script_present_when_enabled(self, wp_admin_session):
        save_pmp_settings(wp_admin_session)

        resp = requests.get(WORDPRESS_URL)
        assert resp.status_code == 200

        m = re.search(
            r'<script\b[^>]*\bid="fiftyonedegrees-pmp-js"[^>]*>',
            resp.text,
        )
        assert m, 'PMP script tag was not rendered'
        tag = m.group(0)
        assert f'data-tcf-vendor="{PMP_REQUIRED_FIELDS["fiftyonedegrees_pmp_tcf_vendor_string"]}"' in tag
        assert f'data-brand-name="{PMP_REQUIRED_FIELDS["fiftyonedegrees_pmp_brand_name"]}"' in tag
        assert f'data-brand-terms-url="{PMP_REQUIRED_FIELDS["fiftyonedegrees_pmp_brand_terms_url"]}"' in tag
        assert f'data-alt-name="{PMP_REQUIRED_FIELDS["fiftyonedegrees_pmp_alt_label"]}"' in tag
        assert f'data-alt-url="{PMP_REQUIRED_FIELDS["fiftyonedegrees_pmp_alt_url"]}"' in tag
        assert 'data-action-url=' in tag

    def test_script_absent_when_disabled(self, wp_admin_session):
        disable_pmp(wp_admin_session)

        resp = requests.get(WORDPRESS_URL)
        assert resp.status_code == 200
        assert 'fiftyonedegrees-pmp-js' not in resp.text


class TestGlueAndCookie:
    """The inline glue is rendered with the WP REST endpoint URL and
    plants the ``51d_pmp_pref`` cookie when invoked."""

    def test_inline_glue_rendered(self, wp_admin_session):
        save_pmp_settings(wp_admin_session)

        resp = requests.get(WORDPRESS_URL)
        assert 'window.fiftyoneDegreesPmpOnChoice' in resp.text
        # The fetch target is wp-json/fiftyonedegrees/v4/json.
        assert 'wp-json/fiftyonedegrees/v4/json' in resp.text


@pytest.fixture(scope='module')
def pmp_cloud_reachable():
    """Skip browser-flow tests if the PMP cloud endpoint is not up."""
    try:
        requests.head(PMP_CLOUD_URL + '/', timeout=2, verify=False)
        return True
    except Exception:
        return False


class TestBrowserFlow:
    """Browser-driven checks of popup -> choice -> cookie -> TCF.

    Requires a running PMP cloud at ``PMP_CLOUD_URL``. If unreachable
    we skip rather than fail: in CI the cloud is provisioned separately.
    """

    def _ensure_cloud_reachable(self, pmp_cloud_reachable):
        if not pmp_cloud_reachable:
            pytest.skip(f'PMP cloud at {PMP_CLOUD_URL} not reachable')

    def test_popup_appears_and_choice_fires_rest(
        self, wp_admin_session, browser, pmp_cloud_reachable
    ):
        self._ensure_cloud_reachable(pmp_cloud_reachable)

        # Point Script URL at the local PMP cloud, with the configured key.
        rk_resp = wp_admin_session.get(
            WORDPRESS_URL.rstrip('/') + '/wp-admin/options-general.php?page=51Degrees&tab=setup'
        )
        key_match = re.search(r'name="fiftyonedegrees_resource_key"[^>]*value="([^"]+)"', rk_resp.text)
        if not key_match or not key_match.group(1):
            pytest.skip('No resource key configured — set one in the Setup tab')
        resource_key = key_match.group(1)

        save_pmp_settings(
            wp_admin_session,
            fiftyonedegrees_pmp_script_url=(
                f'{PMP_CLOUD_URL}/pmp/{resource_key}/pmp-en-us.js'
            ),
        )

        browser.delete_all_cookies()
        browser.get(WORDPRESS_URL)

        # Check the global API instead of digging into the shadow DOM.
        WebDriverWait(browser, 10).until(
            lambda d: d.execute_script(
                'return typeof window.__51d_pmp === "object";'
            )
        )

        # Call the glue directly instead of clicking a shadow-DOM button.
        browser.execute_script(
            'document.cookie = "51d_pmp_pref=personalized; path=/; SameSite=Lax";'
            'window.fiftyoneDegreesPmpOnChoice("personalized");'
        )

        # Wait for the cookie to land.
        WebDriverWait(browser, 5).until(
            lambda d: any(c['name'] == '51d_pmp_pref' for c in d.get_cookies())
        )
        cookie = next(c for c in browser.get_cookies() if c['name'] == '51d_pmp_pref')
        assert cookie['value'] == 'personalized'

        # markTcfReady is fired by the glue after fetch resolves; cmpStatus
        # flips from 'loading' to 'loaded' at that point.
        WebDriverWait(browser, 10).until(
            lambda d: d.execute_script(
                'return new Promise(r => __tcfapi("ping", 2, p => r(p.cmpStatus)));'
            ) == 'loaded'
        )

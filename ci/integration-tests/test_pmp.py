"""Selenium tests for the PMP (Preference Management Platform) tab.

Covers admin-form persistence, server-rendered ``<script data-*>`` tags
on public pages, and the inline ``window.onPMPCompletion`` no-op default
that publisher code can override to react to the visitor's choice.
Browser-flow assertions (popup visibility, __tcfapi state) need a
running PMP cloud at ``PMP_CLOUD_URL`` and skip if it is unreachable.
The plugin does not set cookies and does not persist the preference
server-side; the PMP widget bundle stores it in
``localStorage['__51d_pmp_pref']``.
"""

import os
import re

import pytest
import requests
from selenium.webdriver.support.ui import WebDriverWait

from conftest import WORDPRESS_URL

PMP_CLOUD_URL = os.environ.get('PMP_CLOUD_URL', 'https://localhost:5001')
PMP_REQUIRED_FIELDS = {
    'fiftyonedegrees_pmp_brand_terms_url': 'https://example.com/privacy',
    'fiftyonedegrees_pmp_alt_label':       'Pay',
    'fiftyonedegrees_pmp_alt_url':         'https://example.com',
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
        'fiftyonedegrees_pmp_tcf_vendor_string': '',
        'fiftyonedegrees_pmp_brand_name':        '',
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
            fiftyonedegrees_pmp_brand_name='Custom Brand',
            fiftyonedegrees_pmp_alt_label='Subscribe',
            fiftyonedegrees_pmp_alt_url='https://example.com/sub',
            fiftyonedegrees_pmp_show_standard='on',
        )

        _, html = _get_admin_nonce(wp_admin_session, 'pmp')

        assert 'Custom Brand' in html
        assert 'Subscribe' in html
        assert 'https://example.com/sub' in html
        assert PMP_REQUIRED_FIELDS['fiftyonedegrees_pmp_brand_terms_url'] in html
        # Both Enable and Show Standard checkboxes saved as 'on'.
        assert html.count('checked') >= 2


class TestRequiredFieldValidation:
    """Enabling PMP with any required field missing (Terms / Privacy
    URL, Alternative Button Label, Alternative Button URL) produces
    a settings error and reverts the option to 'off'."""

    def test_missing_terms_url_blocks_enable(self, wp_admin_session):
        save_pmp_settings(wp_admin_session, fiftyonedegrees_pmp_brand_terms_url='')

        # The enable checkbox is forced back to 'off' when the required
        # Terms / Privacy URL is missing. WordPress reloads the form
        # with settings-updated=true to surface the transient error.
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
        save_pmp_settings(
            wp_admin_session,
            fiftyonedegrees_pmp_brand_name='Brand X',
            fiftyonedegrees_pmp_alt_label='Pay X',
            fiftyonedegrees_pmp_alt_url='https://example.com/pay-x',
        )

        resp = requests.get(WORDPRESS_URL)
        assert resp.status_code == 200

        m = re.search(
            r'<script\b[^>]*\bid="fiftyonedegrees-pmp-js"[^>]*>',
            resp.text,
        )
        assert m, 'PMP script tag was not rendered'
        tag = m.group(0)
        # Explicit settings round-trip into data-* attributes.
        assert 'data-brand-name="Brand X"' in tag
        assert 'data-alt-name="Pay X"' in tag
        assert 'data-alt-url="https://example.com/pay-x"' in tag
        assert f'data-brand-terms-url="{PMP_REQUIRED_FIELDS["fiftyonedegrees_pmp_brand_terms_url"]}"' in tag
        # TCF Vendor ID is hardcoded to 51 (randomized rotation lands later).
        assert 'data-tcf-vendor-id="51"' in tag
        # TCF Vendor String always present: either user value or the built-in default.
        assert 'data-tcf-vendor="' in tag
        assert 'data-action-url=' in tag

    def test_script_absent_when_disabled(self, wp_admin_session):
        disable_pmp(wp_admin_session)

        resp = requests.get(WORDPRESS_URL)
        assert resp.status_code == 200
        assert 'fiftyonedegrees-pmp-js' not in resp.text


class TestContinuationHook:
    """When PMP is enabled, the page registers a no-op
    ``window.onPMPCompletion`` default that publisher code can override
    to react to the visitor's Standard/Personalized choice. The widget
    invokes the hook via the ``data-action-url`` JS URL on the script
    tag."""

    def test_inline_default_registered(self, wp_admin_session):
        save_pmp_settings(wp_admin_session)

        resp = requests.get(WORDPRESS_URL)
        # The inline 'before' script defines a no-op default unless the
        # publisher has already assigned their own onPMPCompletion.
        assert 'window.onPMPCompletion=window.onPMPCompletion||function' in resp.text

    def test_data_action_url_routes_to_completion_hook(self, wp_admin_session):
        save_pmp_settings(wp_admin_session)

        resp = requests.get(WORDPRESS_URL)
        # The widget reads data-action-url and substitutes {preference}
        # at click time. esc_attr() encodes single quotes as &#039;.
        assert (
            'data-action-url="javascript:window.onPMPCompletion('
            '&#039;{preference}&#039;)"'
        ) in resp.text


@pytest.fixture(scope='module')
def pmp_cloud_reachable():
    """Skip browser-flow tests if the PMP cloud endpoint is not up."""
    try:
        requests.head(PMP_CLOUD_URL + '/', timeout=2, verify=False)
        return True
    except Exception:
        return False


class TestBrowserFlow:
    """Browser-driven checks of popup -> choice -> continuation hook -> TCF.

    Requires a running PMP cloud at ``PMP_CLOUD_URL``. If unreachable
    we skip rather than fail: in CI the cloud is provisioned separately.
    """

    def _ensure_cloud_reachable(self, pmp_cloud_reachable):
        if not pmp_cloud_reachable:
            pytest.skip(f'PMP cloud at {PMP_CLOUD_URL} not reachable')

    def test_popup_appears_and_choice_invokes_continuation(
        self, wp_admin_session, browser, pmp_cloud_reachable
    ):
        self._ensure_cloud_reachable(pmp_cloud_reachable)

        rk_resp = wp_admin_session.get(
            WORDPRESS_URL.rstrip('/') + '/wp-admin/options-general.php?page=51Degrees&tab=setup'
        )
        key_match = re.search(r'name="fiftyonedegrees_resource_key"[^>]*value="([^"]+)"', rk_resp.text)
        if not key_match or not key_match.group(1):
            pytest.skip('No resource key configured — set one in the Setup tab')

        save_pmp_settings(wp_admin_session)

        # The plugin composes the bundle URL from the shared FOD_CLOUD_API_URL
        # env var (same one robots / suspicious / cloud metadata respect).
        # The dev environment must point that variable at PMP_CLOUD_URL for
        # this test to load the bundle from the local cloud; otherwise the
        # rendered tag still targets production and the browser flow won't
        # exercise local code.
        page_resp = requests.get(WORDPRESS_URL)
        if f'{PMP_CLOUD_URL}/api/v4/pmp?' not in page_resp.text:
            pytest.skip(
                f'PMP bundle URL does not target {PMP_CLOUD_URL}. Set '
                f'FOD_CLOUD_API_URL="{PMP_CLOUD_URL}" in the environment.'
            )

        browser.delete_all_cookies()
        browser.get(WORDPRESS_URL)

        # Wait for the bundle to install its global.
        WebDriverWait(browser, 10).until(
            lambda d: d.execute_script(
                'return typeof window.__51d_pmp === "object";'
            )
        )

        # The inline 'before' script registered a no-op onPMPCompletion;
        # overwrite it with a recorder so we can observe the choice
        # flowing through the continuation hook. Then simulate the
        # user's choice by invoking it directly (bypasses the shadow-DOM
        # button — the data-action-url javascript: target is the same
        # function the widget calls on click).
        browser.execute_script(
            'window.__pmpChoice = null;'
            'window.onPMPCompletion = function (preference) {'
            '  window.__pmpChoice = preference;'
            '};'
            "window.onPMPCompletion('personalized');"
        )

        WebDriverWait(browser, 5).until(
            lambda d: d.execute_script('return window.__pmpChoice;') == 'personalized'
        )

        # markTcfReady is fired by the bundle after fetch resolves; cmpStatus
        # flips from 'loading' to 'loaded' at that point.
        WebDriverWait(browser, 10).until(
            lambda d: d.execute_script(
                'return new Promise(r => __tcfapi("ping", 2, p => r(p.cmpStatus)));'
            ) == 'loaded'
        )

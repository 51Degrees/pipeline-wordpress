"""HTTP-based E2E for the Setup tab save flow.

Verifies the user-visible scenarios for the 2026-05-07 client-demo bug:
1. Bad key save -> red box appears.
2. Good key save after a bad key save -> red gone, green appears.
3. With no key configured (cleared from a previously-set state),
   no status boxes render.

Pure HTTP - no Selenium. Mirrors test_robots_txt.py.
"""
import os
import re
import time

import pytest
import requests

from conftest import WORDPRESS_URL

VALID_RESOURCE_KEY = os.environ.get('RESOURCE_KEY', '')
SETUP_TAB_PATH    = '/wp-admin/admin.php?page=51Degrees&tab=setup'
OPTIONS_GROUP_KEY = 'fiftyonedegrees_options'
RED_BOX_RE        = re.compile(r'class="fod-pipeline-status\s+error"')
GREEN_BOX_RE      = re.compile(r'class="fod-pipeline-status\s+good"')

pytestmark = pytest.mark.skipif(
    not VALID_RESOURCE_KEY,
    reason='RESOURCE_KEY env var required.',
)


def _base() -> str:
    return WORDPRESS_URL.rstrip('/')


def _get_setup_nonce(session: requests.Session) -> str:
    resp = session.get(_base() + SETUP_TAB_PATH)
    resp.raise_for_status()
    m = re.search(r'name="_wpnonce"\s+value="([^"]+)"', resp.text)
    assert m, 'Could not find _wpnonce on setup tab'
    return m.group(1)


def _save_resource_key(session: requests.Session, key: str) -> None:
    nonce = _get_setup_nonce(session)
    fields = [
        ('option_page', OPTIONS_GROUP_KEY),
        ('action', 'update'),
        ('_wpnonce', nonce),
        ('_wp_http_referer', SETUP_TAB_PATH),
        ('fiftyonedegrees_resource_key', key),
        ('fiftyonedegrees_pipeline_enable', 'off'),
        ('fiftyonedegrees_pipeline_enable', 'on'),
    ]
    resp = session.post(_base() + '/wp-admin/options.php', data=fields, allow_redirects=False)
    assert resp.status_code in (200, 302), f'Save returned {resp.status_code}'


def _fetch_setup_html(session: requests.Session) -> str:
    resp = session.get(_base() + SETUP_TAB_PATH)
    resp.raise_for_status()
    return resp.text


@pytest.fixture(autouse=True)
def _restore_valid_key_after(wp_admin_session):
    """Restore the valid resource key after each test.

    Tests in this module legitimately push the plugin into bad and empty
    key states; without restoration, downstream tests (e.g. permalink JS
    injection) fail because the pipeline can't build.
    """
    yield
    _save_resource_key(wp_admin_session, VALID_RESOURCE_KEY)


def test_red_box_clears_after_successful_save(wp_admin_session):
    """Bug 1: bad key -> red box; valid key after -> red gone, green present."""
    _save_resource_key(wp_admin_session, 'DEFINITELY-INVALID-KEY-12345')
    time.sleep(1)
    html = _fetch_setup_html(wp_admin_session)
    assert RED_BOX_RE.search(html), 'Bad key save should produce red box'

    _save_resource_key(wp_admin_session, VALID_RESOURCE_KEY)
    time.sleep(1)
    html = _fetch_setup_html(wp_admin_session)
    assert GREEN_BOX_RE.search(html), 'Valid save should produce green box'
    assert not RED_BOX_RE.search(html), 'Valid save must clear red box'


def test_no_status_boxes_when_resource_key_empty(wp_admin_session):
    """Bugs 2 + 3: clearing the key removes all status boxes."""
    _save_resource_key(wp_admin_session, VALID_RESOURCE_KEY)
    time.sleep(1)
    _save_resource_key(wp_admin_session, '')
    time.sleep(1)
    html = _fetch_setup_html(wp_admin_session)
    assert not RED_BOX_RE.search(html), 'Empty key must not show a red box'
    assert not GREEN_BOX_RE.search(html), 'Empty key must not show a green box'

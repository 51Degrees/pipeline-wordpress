"""Selenium tests: verify 51Degrees robots.txt crawler enforcement.

Verifies crawler spoofing via unauthenticated HTTP requests, testing
enforcement toggle states, unlisted agent transparency, and
specificity-based precedence for overlapping Allow/Deny directives.
"""

import os
import re
import time
import uuid

import pytest
import requests

from conftest import WORDPRESS_URL

RESOURCE_KEY = os.environ.get('RESOURCE_KEY', '')
GOOGLEBOT_UA = 'Googlebot/2.1 (+http://www.google.com/bot.html)'
BINGBOT_UA = 'bingbot/2.0 (+http://www.bing.com/bingbot.htm)'
ROBOTS_GROUP_KEY = 'fiftyonedegrees_options_robots'
ALL_CATEGORIES = [
    'Index', 'Search', 'Train', 'Analytics', 'Monitor',
    'Archiving', 'Preview', 'Security', 'Feed', 'Discovery',
]

pytestmark = pytest.mark.skipif(
    not RESOURCE_KEY,
    reason=(
        'RESOURCE_KEY environment variable is required. '
        'Set it to a 51Degrees key that includes the IsCrawler property.'
    ),
)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _base() -> str:
    return WORDPRESS_URL.rstrip('/')


def _get_robots_nonce(session: requests.Session) -> str:
    """Load the Robots.txt admin tab and extract the WordPress form nonce."""
    url = _base() + '/wp-admin/admin.php?page=51Degrees&tab=robots'
    resp = session.get(url)
    resp.raise_for_status()
    m = re.search(r'name="_wpnonce"\s+value="([^"]+)"', resp.text)
    assert m, f'Could not find _wpnonce on robots settings page ({url})'
    return m.group(1)


def _get_rest_nonce(session: requests.Session) -> str:
    """Get the WP REST API nonce from the admin dashboard."""
    resp = session.get(_base() + '/wp-admin/')
    resp.raise_for_status()
    # WordPress outputs: "nonce":"<hex>" inside a JSON object on the admin page
    m = re.search(r'"nonce"\s*:\s*"([a-f0-9]+)"', resp.text)
    assert m, 'Could not find REST nonce in wp-admin page'
    return m.group(1)


def save_robots_settings(
    session: requests.Session,
    *,
    enable: str = 'off',
    enforce: str = 'off',
    custom_top: str = '',
    custom_bottom: str = '',
    redirect_url: str = '',
    allowed_categories: list | None = None,
) -> None:
    """POST robots.txt settings to wp-admin/options.php.

    Replicates the HTML form in robots.php exactly — including the hidden
    'off' fallback fields that handle unchecked checkboxes.
    """
    nonce = _get_robots_nonce(session)

    # List of (name, value) tuples so we can include duplicate field names,
    # which is how WordPress handles the hidden-fallback / checkbox pattern.
    fields = [
        ('option_page', ROBOTS_GROUP_KEY),
        ('action', 'update'),
        ('_wpnonce', nonce),
        ('_wp_http_referer', '/wp-admin/admin.php?page=51Degrees&tab=robots'),
        # Hidden 'off' fallbacks that are always present in the HTML form
        ('fiftyonedegrees_robots_enable', 'off'),
        ('fiftyonedegrees_robots_enforce', 'off'),
    ]

    # Checkbox 'on' values — PHP will see the *last* occurrence for each name
    if enable == 'on':
        fields.append(('fiftyonedegrees_robots_enable', 'on'))
    if enforce == 'on':
        fields.append(('fiftyonedegrees_robots_enforce', 'on'))

    fields += [
        ('fiftyonedegrees_robots_custom_top', custom_top),
        ('fiftyonedegrees_robots_custom_bottom', custom_bottom),
        ('fiftyonedegrees_robots_redirect_url', redirect_url),
    ]

    # Array fields use the '[]' suffix convention
    for cat in (allowed_categories or []):
        fields.append(('fiftyonedegrees_robots_allowed_categories[]', cat))

    resp = session.post(
        _base() + '/wp-admin/options.php',
        data=fields,
        allow_redirects=False,
    )
    assert resp.status_code in (200, 302), (
        f'robots settings save returned unexpected status {resp.status_code}'
    )


def _create_page(session: requests.Session, title: str, slug: str) -> tuple[int, str]:
    """Create a published WordPress page via REST API; return (id, link)."""
    nonce = _get_rest_nonce(session)
    resp = session.post(
        _base() + '/wp-json/wp/v2/pages',
        json={'title': title, 'slug': slug, 'status': 'publish', 'content': title},
        headers={'X-WP-Nonce': nonce},
    )
    assert resp.status_code in (200, 201), (
        f'Page creation failed ({resp.status_code}): {resp.text[:300]}'
    )
    data = resp.json()
    return data['id'], data['link']


def _delete_page(session: requests.Session, page_id: int) -> None:
    """Permanently delete a WordPress page via REST API."""
    nonce = _get_rest_nonce(session)
    session.delete(
        _base() + f'/wp-json/wp/v2/pages/{page_id}',
        params={'force': 'true'},
        headers={'X-WP-Nonce': nonce},
    )


def _crawler_get(path: str = '/', user_agent: str = GOOGLEBOT_UA) -> requests.Response:
    """Make a single unauthenticated GET as a crawler.

    Redirects are intentionally NOT followed so callers can inspect the 302
    status code and Location header that the enforcement layer emits.
    """
    url = _base() + path
    return requests.get(
        url,
        headers={'User-Agent': user_agent},
        allow_redirects=False,
        timeout=15,
    )


def assert_blocked(resp: requests.Response, redirect_url: str) -> None:
    """Assert the crawler was blocked — expects a 302 to redirect_url."""
    assert resp.status_code == 302, (
        f'Expected 302 (crawler blocked) but got {resp.status_code}. '
        f'Body: {resp.text[:200]!r}'
    )
    location = resp.headers.get('Location', '').rstrip('/')
    expected = redirect_url.rstrip('/')
    assert expected in location or location in expected, (
        f'302 Location {location!r} does not reference the expected '
        f'redirect target {expected!r}'
    )


def assert_not_blocked(resp: requests.Response, redirect_url: str) -> None:
    """Assert the crawler was NOT blocked by the enforcement layer."""
    is_enforcement_redirect = (
        resp.status_code in (301, 302)
        and redirect_url.rstrip('/') in resp.headers.get('Location', '').rstrip('/')
    )
    assert not is_enforcement_redirect, (
        f'Crawler was unexpectedly blocked and redirected to '
        f'{resp.headers.get("Location", "")!r}'
    )
    assert 'Access denied for crawlers' not in resp.text, (
        'Unexpected "Access denied for crawlers" body when crawler should be allowed'
    )


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------

@pytest.fixture(scope='module')
def redirect_page(wp_admin_session):
    """Create a WP page to serve as the enforcement redirect target.

    Yields the full page URL; the page is permanently deleted after all
    tests in this module have run.
    """
    slug = f'bot-blocked-{uuid.uuid4().hex[:8]}'
    page_id, page_url = _create_page(wp_admin_session, 'Bot Blocked', slug)
    yield page_url
    _delete_page(wp_admin_session, page_id)


@pytest.fixture(autouse=True)
def reset_robots_settings(wp_admin_session):
    """Reset robots.txt settings to safe defaults before and after each test.

    Running the reset both before and after prevents state leakage even when
    a test is interrupted mid-execution.
    """
    _clean(wp_admin_session)
    yield
    _clean(wp_admin_session)


def _clean(session):
    save_robots_settings(
        session,
        enable='off',
        enforce='off',
        custom_top='',
        custom_bottom='',
        redirect_url='',
    )


# ---------------------------------------------------------------------------
# Scenario 1 — Enforcement ON: crawler is blocked
# ---------------------------------------------------------------------------

class TestCrawlerEnforcementOn:
    """Spoofing a crawler UA with enforcement enabled must trigger a 302."""

    def test_googlebot_is_redirected(self, wp_admin_session, redirect_page):
        """A Googlebot request to / must be redirected when enforcement is on.

        Setup:
          - ROBOTS_ENFORCE = on
          - Custom rule: User-agent: *  Disallow: /
          - All crawler categories denied (ensures CrawlerUsage check passes
            regardless of which categories the Resource Key exposes)
        """
        save_robots_settings(
            wp_admin_session,
            enable='on',
            enforce='on',
            custom_top='User-agent: *\nDisallow: /',
            redirect_url=redirect_page,
            allowed_categories=[],
        )
        # Allow WordPress option cache to settle
        time.sleep(1)

        resp = _crawler_get('/', GOOGLEBOT_UA)
        assert_blocked(resp, redirect_page)


# ---------------------------------------------------------------------------
# Scenario 2 — Enforcement OFF: crawler is allowed
# ---------------------------------------------------------------------------

class TestCrawlerEnforcementOff:
    """Identical setup but ROBOTS_ENFORCE=off — crawler must NOT be blocked."""

    def test_googlebot_is_allowed_when_enforcement_off(
        self, wp_admin_session, redirect_page
    ):
        """Disabling enforcement must let the crawler through even with Disallow: /."""
        save_robots_settings(
            wp_admin_session,
            enable='on',
            enforce='off',          # enforcement is OFF
            custom_top='User-agent: *\nDisallow: /',
            redirect_url=redirect_page,
            allowed_categories=[],
        )
        time.sleep(1)

        resp = _crawler_get('/', GOOGLEBOT_UA)
        assert_not_blocked(resp, redirect_page)


# ---------------------------------------------------------------------------
# Scenario 3 — UA not listed in robots.txt: crawler is allowed
# ---------------------------------------------------------------------------

class TestCrawlerUANotOnList:
    """A crawler whose full UA string is not in the enforcement dict must be allowed.

    The enforcement dict is built by parsing robots.txt custom entries.
    The dict key is the exact lowercased User-agent value from each
    'User-agent:' line.  If the request UA does not match any key (and
    there is no '*' wildcard), check_path_allowed returns null, which the
    enforcement layer treats as 'allowed'.

    Setup: custom entry lists Bingbot's full UA string only (no wildcard).
    The Googlebot request UA will not match that key — null → allowed.
    """

    def test_unlisted_ua_is_allowed(self, wp_admin_session, redirect_page):
        # BINGBOT_UA is the exact User-agent string used as the dict key.
        # Googlebot's full UA will not match it, and there is no '*', so
        # check_path_allowed returns null (allowed).
        custom_top = f'User-agent: {BINGBOT_UA}\nDisallow: /'
        save_robots_settings(
            wp_admin_session,
            enable='on',
            enforce='on',
            custom_top=custom_top,
            redirect_url=redirect_page,
            allowed_categories=[],
        )
        time.sleep(1)

        resp = _crawler_get('/', GOOGLEBOT_UA)
        assert_not_blocked(resp, redirect_page)


# ---------------------------------------------------------------------------
# Scenario 4 — More-specific Allow overrides less-specific Deny
# ---------------------------------------------------------------------------

class TestMoreSpecificAllowPath:
    """Allow: /specific-path/ must beat a broader Disallow: /.

    The plugin uses longest-prefix matching (check_path_allowed).
    '/allowed-path/' (length > 1) wins over '/' → result is true (Allow)
    → crawler is NOT blocked on that specific path.
    """

    def test_specific_allow_overrides_broad_deny(
        self, wp_admin_session, redirect_page
    ):
        slug = f'allowed-path-{uuid.uuid4().hex[:8]}'
        page_id, _ = _create_page(wp_admin_session, 'Allowed Path', slug)
        allow_path = f'/{slug}/'

        try:
            save_robots_settings(
                wp_admin_session,
                enable='on',
                enforce='on',
                custom_top=(
                    'User-agent: *\n'
                    'Disallow: /\n'
                    f'Allow: {allow_path}'
                ),
                redirect_url=redirect_page,
                allowed_categories=[],
            )
            time.sleep(1)

            # Sanity check: the broad '/' Disallow IS enforced on the root
            baseline = _crawler_get('/', GOOGLEBOT_UA)
            assert_blocked(baseline, redirect_page)

            # The more-specific Allow path must NOT be blocked
            resp = _crawler_get(allow_path, GOOGLEBOT_UA)
            assert_not_blocked(resp, redirect_page)
        finally:
            _delete_page(wp_admin_session, page_id)


# ---------------------------------------------------------------------------
# Scenario 5 — More-specific Deny overrides less-specific Allow
# ---------------------------------------------------------------------------

class TestMoreSpecificDenyPath:
    """Disallow: /blocked-path/ must beat a broader Allow: /.

    '/blocked-path/' (longer prefix) wins over '/' → result is false (Deny)
    → crawler IS blocked on that specific path even though '/' is Allowed.
    """

    def test_specific_deny_overrides_broad_allow(
        self, wp_admin_session, redirect_page
    ):
        slug = f'blocked-path-{uuid.uuid4().hex[:8]}'
        page_id, _ = _create_page(wp_admin_session, 'Blocked Path', slug)
        deny_path = f'/{slug}/'

        try:
            save_robots_settings(
                wp_admin_session,
                enable='on',
                enforce='on',
                custom_top=(
                    'User-agent: *\n'
                    'Allow: /\n'
                    f'Disallow: {deny_path}'
                ),
                redirect_url=redirect_page,
                allowed_categories=[],
            )
            time.sleep(1)

            # Sanity check: the broad Allow: / does NOT block the root
            baseline = _crawler_get('/', GOOGLEBOT_UA)
            assert_not_blocked(baseline, redirect_page)

            # The more-specific Deny path MUST be blocked
            resp = _crawler_get(deny_path, GOOGLEBOT_UA)
            assert_blocked(resp, redirect_page)
        finally:
            _delete_page(wp_admin_session, page_id)

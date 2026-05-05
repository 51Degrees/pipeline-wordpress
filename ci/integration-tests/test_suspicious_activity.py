"""Selenium tests: verify suspicious activity detection feature.

Tests the admin UI settings form, checkbox state persistence,
threshold-based redirect, disabled-feature behavior, and admin
lockout prevention.
"""

import re
import time

import pytest
import requests

from conftest import WORDPRESS_URL, ADMIN_USER, ADMIN_PASS


def get_admin_nonce(session, tab_url):
    """Extract the _wpnonce from a 51Degrees settings tab."""
    resp = session.get(tab_url)
    resp.raise_for_status()
    match = re.search(r'name="_wpnonce"\s+value="([^"]+)"', resp.text)
    assert match, 'Could not extract _wpnonce from settings page'
    return match.group(1), resp.text


def save_suspicious_settings(session, enable='off', redirect_url='',
                             requests_count=5, window=30):
    """Save suspicious activity settings via the admin form."""
    base = WORDPRESS_URL.rstrip('/')
    tab_url = base + '/wp-admin/options-general.php?page=51Degrees&tab=suspicious'
    nonce, _ = get_admin_nonce(session, tab_url)

    data = {
        '_wpnonce': nonce,
        '_wp_http_referer': '/wp-admin/options-general.php?page=51Degrees&tab=suspicious',
        'option_page': 'fiftyonedegrees_suspicious_options',
        'action': 'update',
        'fiftyonedegrees_suspicious_enable': enable,
        'fiftyonedegrees_suspicious_redirect_url': redirect_url,
        'fiftyonedegrees_suspicious_requests': str(requests_count),
        'fiftyonedegrees_suspicious_window': str(window),
    }

    resp = session.post(base + '/wp-admin/options.php', data=data,
                        allow_redirects=False)
    assert resp.status_code in (200, 302), (
        f'Settings save returned {resp.status_code}'
    )


def create_page(session, title, slug):
    """Create a WordPress page via the REST API and return its permalink."""
    base = WORDPRESS_URL.rstrip('/')

    # Get a REST nonce from the admin page
    resp = session.get(base + '/wp-admin/')
    resp.raise_for_status()
    match = re.search(r'"_wpnonce":"([^"]+)"', resp.text)
    if not match:
        match = re.search(r'wpApiSettings.*?"nonce":"([^"]+)"', resp.text)
    assert match, 'Could not extract REST nonce'
    rest_nonce = match.group(1)

    resp = session.post(
        base + '/wp-json/wp/v2/pages',
        json={'title': title, 'slug': slug, 'status': 'publish'},
        headers={'X-WP-Nonce': rest_nonce},
    )
    assert resp.status_code == 201, (
        f'Page creation returned {resp.status_code}: {resp.text[:200]}'
    )
    page_data = resp.json()
    return page_data['id'], page_data['link']


def delete_page(session, page_id):
    """Delete a WordPress page via the REST API."""
    base = WORDPRESS_URL.rstrip('/')

    resp = session.get(base + '/wp-admin/')
    match = re.search(r'"_wpnonce":"([^"]+)"', resp.text)
    if not match:
        match = re.search(r'wpApiSettings.*?"nonce":"([^"]+)"', resp.text)
    if match:
        rest_nonce = match.group(1)
        session.delete(
            base + f'/wp-json/wp/v2/pages/{page_id}?force=true',
            headers={'X-WP-Nonce': rest_nonce},
        )


class TestSettingsFormRoundTrip:
    """Test #1: Settings form saves and restores all four values."""

    def test_settings_round_trip(self, wp_admin_session):
        session = wp_admin_session
        base = WORDPRESS_URL.rstrip('/')
        tab_url = base + '/wp-admin/options-general.php?page=51Degrees&tab=suspicious'

        try:
            save_suspicious_settings(
                session, enable='on',
                redirect_url='http://localhost:8080/blocked/',
                requests_count=7, window=45,
            )

            _, html = get_admin_nonce(session, tab_url)

            assert 'value="on"' in html and 'checked' in html
            assert 'http://localhost:8080/blocked/' in html
            assert 'value="7"' in html
            assert 'value="45"' in html
        finally:
            save_suspicious_settings(session)


class TestCheckboxUncheckedState:
    """Test #2: Unchecking the checkbox persists as 'off'."""

    def test_checkbox_unchecked_disables(self, wp_admin_session):
        session = wp_admin_session
        base = WORDPRESS_URL.rstrip('/')
        tab_url = base + '/wp-admin/options-general.php?page=51Degrees&tab=suspicious'

        try:
            save_suspicious_settings(session, enable='on')
            _, html = get_admin_nonce(session, tab_url)
            assert 'checked' in html

            save_suspicious_settings(session, enable='off')
            _, html = get_admin_nonce(session, tab_url)
            assert 'checked=\'checked\'' not in html
            assert 'checked="checked"' not in html
        finally:
            save_suspicious_settings(session)


class TestThresholdRedirect:
    """Test #3: Visitors are redirected after reaching the threshold."""

    def test_threshold_redirect(self, wp_admin_session, browser):
        session = wp_admin_session
        base = WORDPRESS_URL.rstrip('/')
        page_id = None

        try:
            page_id, permalink = create_page(session, 'Blocked', 'blocked')

            save_suspicious_settings(
                session, enable='on', redirect_url=permalink,
                requests_count=3, window=60,
            )

            browser.delete_all_cookies()

            for i in range(3):
                browser.get(base + '/')
                time.sleep(0.5)

            current = browser.current_url.rstrip('/')
            blocked_path = permalink.rstrip('/')
            assert blocked_path in current or 'blocked' in current.lower(), (
                f'Expected redirect to {permalink}, got {browser.current_url}'
            )
        finally:
            save_suspicious_settings(session)
            if page_id:
                delete_page(session, page_id)


class TestNoRedirectWhenDisabled:
    """Test #4: No redirect when the feature is disabled."""

    def test_no_redirect_when_disabled(self, wp_admin_session, browser):
        session = wp_admin_session
        base = WORDPRESS_URL.rstrip('/')

        try:
            save_suspicious_settings(session, enable='off')

            browser.delete_all_cookies()

            for _ in range(10):
                browser.get(base + '/')
                time.sleep(0.3)

            assert '/wp-login' not in browser.current_url
            assert 'blocked' not in browser.current_url.lower()
        finally:
            save_suspicious_settings(session)


class TestAdminLockoutPrevention:
    """Test #5: Admin pages are never redirected."""

    def test_admin_not_locked_out(self, wp_admin_session, browser):
        session = wp_admin_session
        base = WORDPRESS_URL.rstrip('/')
        page_id = None

        try:
            page_id, permalink = create_page(
                session, 'Blocked Admin', 'blocked-admin'
            )

            save_suspicious_settings(
                session, enable='on', redirect_url=permalink,
                requests_count=3, window=60,
            )

            # Log in via the browser
            browser.delete_all_cookies()
            browser.get(base + '/wp-login.php')
            browser.find_element('id', 'user_login').send_keys(ADMIN_USER)
            browser.find_element('id', 'user_pass').send_keys(ADMIN_PASS)
            browser.find_element('id', 'wp-submit').click()
            time.sleep(2)

            # Trip the counter on the frontend
            for _ in range(4):
                browser.get(base + '/')
                time.sleep(0.3)

            # Navigate to admin — should NOT be redirected
            browser.get(base + '/wp-admin/')
            time.sleep(1)

            assert '/wp-admin' in browser.current_url, (
                f'Admin was redirected to {browser.current_url}'
            )
        finally:
            save_suspicious_settings(session)
            if page_id:
                delete_page(session, page_id)

"""Fixtures for 51Degrees WordPress Selenium tests.

Infrastructure-agnostic: receives a WordPress URL and works with just that.
Rewrite rule management (.htaccess, server config) is the responsibility
of whoever sets up the test environment, not the tests.
"""

import os
import re

import pytest
import requests
from selenium import webdriver
from selenium.webdriver.chrome.options import Options

# Configuration from environment
WORDPRESS_URL = os.environ.get('WORDPRESS_URL', 'http://localhost:8080')
ADMIN_USER = os.environ.get('WP_ADMIN_USER', 'admin')
ADMIN_PASS = os.environ.get('WP_ADMIN_PASS', 'admin')
SELENIUM_URL = os.environ.get('SELENIUM_URL', 'http://localhost:4444')


@pytest.fixture(scope='module')
def wp_admin_session():
    """Return a requests.Session authenticated to the WordPress admin."""
    session = requests.Session()

    # Log in via the standard WordPress login form.
    # Don't follow redirects: WordPress may redirect to siteurl which
    # differs from the host-accessible URL.
    login_url = WORDPRESS_URL.rstrip('/') + '/wp-login.php'
    resp = session.post(login_url, data={
        'log': ADMIN_USER,
        'pwd': ADMIN_PASS,
        'wp-submit': 'Log In',
        'redirect_to': WORDPRESS_URL.rstrip('/') + '/wp-admin/',
        'testcookie': '1',
    }, allow_redirects=False)
    # A 302 with Set-Cookie means login succeeded
    assert resp.status_code in (200, 302), f'WP login returned {resp.status_code}'
    assert 'wordpress_logged_in' in '|'.join(
        c.name for c in session.cookies
    ), 'WP admin login failed — no wordpress_logged_in cookie'

    yield session

    session.close()


def set_permalink(session, structure):
    """Change the WordPress permalink structure via the admin settings page.

    GETs the permalink page to extract the _wpnonce, then POSTs the form
    data. WordPress fires update_option() internally, which triggers the
    plugin's pipeline rebuild hook.
    """
    base = WORDPRESS_URL.rstrip('/')
    permalink_url = base + '/wp-admin/options-permalink.php'

    # GET the form to extract the nonce
    resp = session.get(permalink_url)
    resp.raise_for_status()

    match = re.search(r'name="_wpnonce"\s+value="([^"]+)"', resp.text)
    assert match, 'Could not extract _wpnonce from permalink settings page'
    nonce = match.group(1)

    # Determine the 'selection' radio value WordPress expects
    if structure == '':
        selection = ''
    elif structure == '/%year%/%monthnum%/%day%/%postname%/':
        selection = '/%year%/%monthnum%/%day%/%postname%/'
    elif structure == '/%year%/%monthnum%/%postname%/':
        selection = '/%year%/%monthnum%/%postname%/'
    elif structure == '/archives/%post_id%':
        selection = '/archives/%post_id%'
    elif structure == '/%postname%/':
        selection = '/%postname%/'
    else:
        selection = 'custom'

    data = {
        '_wpnonce': nonce,
        '_wp_http_referer': '/wp-admin/options-permalink.php',
        'selection': selection,
        'permalink_structure': structure,
        'submit': 'Save Changes',
    }

    # Don't follow redirects — WordPress may redirect to siteurl which
    # differs from the host-accessible URL. A 302 means the save succeeded.
    resp = session.post(permalink_url, data=data, allow_redirects=False)
    assert resp.status_code in (200, 302), (
        f'Permalink save returned {resp.status_code}'
    )


@pytest.fixture(scope='module')
def browser():
    """Create a Selenium WebDriver connected to the remote Selenium instance."""
    options = webdriver.ChromeOptions()
    options.add_argument("--headless=new")
    driver = webdriver.Chrome(options=options)
    driver.set_script_timeout(20)
    driver.implicitly_wait(10)

    yield driver

    driver.quit()

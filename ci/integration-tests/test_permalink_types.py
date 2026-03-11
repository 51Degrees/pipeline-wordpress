"""Selenium tests: verify 51Degrees JavaScript works with all permalink types.

Issue #34: Plain permalinks caused the /json endpoint to 404 because the
endpoint was hardcoded as /wp-json/... instead of using rest_url().
"""

import time

import pytest

from conftest import WORDPRESS_URL, set_permalink

PERMALINK_TYPES = [
    ('plain', ''),
    ('day_and_name', '/%year%/%monthnum%/%day%/%postname%/'),
    ('month_and_name', '/%year%/%monthnum%/%postname%/'),
    ('numeric', '/archives/%post_id%'),
    ('post_name', '/%postname%/'),
]

# JavaScript executed in the browser to wait for fod.complete()
FOD_COMPLETE_SCRIPT = """
var done = arguments[arguments.length - 1];
var timer = setTimeout(function() {
    done({
        error: 'timeout waiting for fod.complete (15s)',
        fodDefined: typeof fod !== 'undefined'
    });
}, 15000);

if (typeof fod === 'undefined') {
    clearTimeout(timer);
    done({error: 'fod object not defined on page'});
    return;
}

fod.complete(function(data) {
    clearTimeout(timer);
    var keys = data ? Object.keys(data) : [];
    var hasDevice = data && typeof data.device !== 'undefined';
    done({
        success: true,
        hasDevice: hasDevice,
        dataKeys: keys.slice(0, 10)
    });
});
"""


@pytest.mark.parametrize('name,structure', PERMALINK_TYPES)
def test_javascript_endpoint(wp_admin_session, browser, name, structure):
    """Verify the 51Degrees fod.complete() callback fires with device data.

    For each permalink type, the JavaScript should successfully POST to
    the /json endpoint and receive enriched device properties back.
    On main (unfixed), plain permalinks cause a 404 on /wp-json/... path.
    On fix/issue-34, rest_url() returns the correct path for all types.
    """
    set_permalink(wp_admin_session, structure)

    # Allow time for the pipeline rebuild triggered by permalink change
    time.sleep(1)

    # Clear browser state
    browser.delete_all_cookies()

    # Navigate to the WordPress homepage — this triggers JS injection
    browser.get(WORDPRESS_URL.rstrip('/') + '/')

    # Wait for page to fully load
    time.sleep(2)

    # Execute async script that registers fod.complete() callback
    # and waits for it to fire (up to 15s timeout)
    result = browser.execute_async_script(FOD_COMPLETE_SCRIPT)

    # Assert fod.complete() fired successfully
    assert result.get('success'), (
        f"fod.complete() did not succeed for permalink type '{name}': "
        f"{result.get('error', 'unknown error')}"
    )

    # Assert device data was received (proves /json endpoint returned 200)
    assert result.get('hasDevice'), (
        f"No device data received for permalink type '{name}'. "
        f"Data keys: {result.get('dataKeys', [])}. "
        f"This likely means the /json endpoint returned an error."
    )

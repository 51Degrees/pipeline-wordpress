# Selenium Tests — 51Degrees WordPress Plugin

End-to-end tests verifying the 51Degrees JavaScript integration works across all
WordPress permalink types. Covers the fix for [issue #34](https://github.com/51Degrees/pipeline-wordpress/issues/34)
where plain permalinks caused the `/json` endpoint to 404.

## Prerequisites

- WordPress installation on port 8080 with the 51Degrees plugin installed, a resource key configured, and the pipeline built
- Selenium Chrome instance (e.g. `docker run -d --network host --shm-size=2g selenium/standalone-chrome:latest`)
- Python 3.8+ with pip

**Note:** Rewrite rule management (.htaccess, server config) is the responsibility of whoever sets up the test environment. The tests only change the permalink setting via the WordPress admin UI.

## Running

```bash
pip install -r requirements.txt
python -m pytest test_permalink_types.py -v
```

## Configuration

| Environment Variable | Default | Description |
|---|---|---|
| `WORDPRESS_URL` | `http://localhost:8080` | WordPress URL as seen from both the test runner and the Selenium browser |
| `WP_ADMIN_USER` | `admin` | WordPress admin username |
| `WP_ADMIN_PASS` | `admin` | WordPress admin password |
| `SELENIUM_URL` | `http://localhost:4444` | Selenium WebDriver URL |

All 5 permalink types are tested: plain, day_and_name, month_and_name, numeric, post_name.

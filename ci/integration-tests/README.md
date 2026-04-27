# Selenium Tests — 51Degrees WordPress Plugin

End-to-end tests verifying plugin behaviour from a browser / HTTP-client perspective.

## Prerequisites

- WordPress installation on port 8080 with the 51Degrees plugin installed, a resource key configured, and the pipeline built
- Selenium Chrome instance (e.g. `docker run -d --network host --shm-size=2g selenium/standalone-chrome:latest`)
- Python 3.8+ with pip

**Note:** Rewrite rule management (.htaccess, server config) is the responsibility of whoever sets up the test environment. The tests only change the permalink setting via the WordPress admin UI.

## Running

Install dependencies once:

```bash
pip install -r requirements.txt
```

### Permalink tests

Covers the fix for [issue #34](https://github.com/51Degrees/pipeline-wordpress/issues/34)
where plain permalinks caused the `/json` endpoint to 404.

```bash
python -m pytest test_permalink_types.py -v
```

All 5 permalink types are tested: plain, day_and_name, month_and_name, numeric, post_name.

### Robots.txt enforcement tests

```bash
RESOURCE_KEY=<your-key> python -m pytest test_robots_txt.py -v
```

These tests use `requests` directly (no browser needed) to spoof
`User-Agent` headers, so **no Selenium instance is required** for this suite.

Verifies five enforcement scenarios:

1. Spoofed crawler UA → blocked when enforcement is **on**
2. Spoofed crawler UA → allowed when enforcement is **off**
3. Crawler UA not listed in robots.txt → allowed (no wildcard match)
4. More-specific `Allow` path overrides less-specific `Disallow`
5. More-specific `Disallow` path overrides less-specific `Allow`

## Configuration

| Environment Variable | Default                    | Description                                                  |
|----------------------|----------------------------|--------------------------------------------------------------|
| `WORDPRESS_URL`      | `http://localhost:8080`    | WordPress URL as seen from the test runner                   |
| `WP_ADMIN_USER`      | `admin`                    | WordPress admin username                                     |
| `WP_ADMIN_PASS`      | `admin`                    | WordPress admin password                                     |
| `SELENIUM_URL`       | `http://localhost:4444`    | Selenium WebDriver URL (permalink tests only)                |
| `RESOURCE_KEY`       | *(required for robots.txt)*| 51Degrees key — must include the **IsCrawler** property      |

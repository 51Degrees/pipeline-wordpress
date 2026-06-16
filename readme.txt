=== 51Degrees ===

Contributors: 51Degrees
Donate link: https://51degrees.com/?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.txt&utm_term=51degrees
Tags: 51degrees, device detection, location, Google Analytics, device, detect, device type, smartphone, tablet, desktop, mobile, optimize, detection, customizable, personalized, tailored, targeting, responsive, mobile website, mobile friendly, user experience, ecommerce, OpenStreetMap, Digital element, Geolocation
Requires at least: 4.7
Tested up to: 6.9.1
Requires PHP: 8.2
Stable tag: 1.0.13
License: EUPL

The best plugin for WordPress to send Device properties as Custom Dimensions to Google Analytics to get richer insights of device specifications and capabilities.

== Description ==

Integrating 51Degrees Device Detection with your website will allow you to make informed decisions about what content a user engages with and how it is displayed. Combining the information learned from your analytics data with real-time enhanced device data on your website will empower you to produce a page built for that specific device’s needs. Taking this one step further, you have an additional 280+ device properties available to enhance your user's user experience. The possibilities are endless as to what you can do with the information - it’s remarkably powerful.

This plugin makes use of the 51Degrees Pipeline API to deliver various data intelligence [services](https://51degrees.com/services?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.txt&utm_term=description). You can also add custom dimensions to your Google Analytics solution which will enhance your analytical data. With 51Degrees you can capture data that Google Analytics doesn't readily collect, such as detailed information on specific device hardware.

== Features ==

## Integration With Google Analytics

51Degrees plugin allows you to add the Device Data Properties as Custom Dimensions to Google Analytics in a seamless and useful manner. The integration is super simple and does not require the help of a developer to set up the integration. Once you integrate Google Analytics in WordPress using 51Degrees, you will be able to fetch the Custom Dimensions in the Google Analytics Custom Reports to get the useful insights.

## Suspicious Activity Detection

Detect and redirect visitors who make too many requests in a short time window. Configure the threshold, time window, and redirect URL from the Suspicious tab in Settings > 51Degrees.

## Dynamic Robots.txt with Crawler Detection

Use 51Degrees device detection to intelligently manage your robots.txt file and control crawler access. The plugin automatically detects crawlers and bad bots, then enforces access policies based on crawler type. Protect your site from unwanted scraping and DDoS attempts while allowing legitimate search engines and analytics crawlers to function normally.

## Preference Management Platform (PMP)

Add a 51Degrees consent popup to your site. Visitors choose Standard, Personalized, or a publisher-defined alternative (e.g. "Remove ads"). The choice is stored client-side in `localStorage` — no cookies, no extra server round-trips. Configure the brand, the alternative button, and the terms/privacy URL from the `PMP` tab.

Publishers can react to the visitor's choice by overriding a single global callback on their page:

`window.onPMPCompletion = function (preference) { /* preference is 'standard' or 'personalized' */ };`

The plugin ships a no-op default, so the popup works out of the box without any custom JavaScript.

## Advanced Features and Developer Info

For advanced feature usage, including in-page value replacement and shortcodes, conditional display based on property values, and access to 51Degrees property data from PHP and JavaScript, see the project [GitHub](https://github.com/51Degrees/pipeline-wordpress/) repository.


## Reporting

Please submit any issues or problems to the [GitHub](https://github.com/51Degrees/pipeline-wordpress/) repository issues page.


== Installation ==

Following way can be used to install 51Degrees WordPress Plugin.

= Installation from within WordPress =

1. Visit `Plugins > Add New`.
2. Search for `51Degrees`.
3. Install and activate the 51Degrees plugin.

For instructions on how to install the plugin manually or by uploading a zip file, please see the plugin [GitHub](https://github.com/51Degrees/pipeline-wordpress/) repository.

= After activation =

1. Visit the new `51Degrees` Settings menu.
2. To start using this plugin, you will need to create a `Resource Key`. This enables access to the data you need via the 51Degrees cloud service. You can create a `Resource Key` for free, using the [configurator](https://configure.51degrees.com/?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.txt&utm_term=after-activation) to select the properties you want.

For a demo video on how to use our configurator, [click here](https://51degrees.com/documentation/_concepts__configurator.html?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.txt&utm_term=after-activation).

= Integration with Google Analytics (GA4) =

The plugin uses Google Analytics 4. If your property still uses the deprecated Universal Analytics, create a GA4 property and a Web data stream in Google Analytics first; Universal Analytics was retired by Google on 2024-07-01 and is no longer reachable by this plugin.

1. Open the `Google Analytics` tab in `Settings > 51Degrees` and click `Connect Google Analytics`. You will be redirected to Google's consent screen — accept the requested permissions to give the 51Degrees plugin access to your GA4 properties (read + the ability to create Custom Dimensions).
2. On return, select your GA4 property from the `Analytics Account/Property` dropdown. The plugin automatically resolves your property's first Web data stream and stores both the Property ID and the Measurement ID (`G-XXXXXXX`).
3. Check `Send Page View` if you want a default Page View event to fire on each page along with the Custom Dimensions event. Skip this if another plugin already sends page views for the same property — otherwise events will duplicate.
4. Click `Save Changes`. The Custom Dimensions screen appears.
5. Review the mapping. Each 51Degrees property gets a default GA4 parameter name (`<engine>_<property>`) plus a dropdown of Custom Dimensions that already exist on the property — pick the default to auto-create, or pick an existing dimension to map onto it.
6. Click `Enable Google Analytics Tracking`. The plugin creates the missing GA4 Custom Dimensions on the property (up to GA4's per-property cap of 50 in the free tier) and starts emitting the inline gtag snippet on every page render. Custom Dimension values arrive as parameters on the `fod` event.

The OAuth redirect is single-site only in this release. Multisite OAuth support is on the roadmap.


== Screenshots ==

1. Google Analytics - Connect with Google Analytics
2. Google Analytics - Permissions Screen
3. Google Analytics - Access Code & Authenticate
4. Google Analytics - Select Property
5. Google Analytics - Enable Tracking

== Frequently Asked Questions ==

= Is the 51Degrees plugin free? =

The 51Degrees plugin is free and open source. However Our [Cloud Configurator](https://configure.51degrees.com/?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.txt&utm_term=is-the-51degrees-plugin-free) contains both FREE and PAID properties. The properties you will need to pay for are shown with a dollar icon. You can buy what you need on our [Pricing page](https://51degrees.com/pricing?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.txt&utm_term=is-the-51degrees-plugin-free).

= What happens if I already use another plugin to integrate Google Analytics? =

You can continue using your existing installed plugins to send Custom Dimensions or view Analytics Data along with the 51Degrees plugin.

= Where should I submit my support request? =

If you're experiencing any issues, use the WordPress.org [support forums](https://wordpress.org/support/plugin/51degrees-optimize-by-device-location/). If you have a technical issue with the plugin where you already have more insight on how to fix it, you can also open an issue on [GitHub](https://github.com/51Degrees/pipeline-wordpress/issues).

== Changelog ==

= 1.0.13 =
* Migrated the Google Analytics integration from Universal Analytics to Google Analytics 4. Universal Analytics was retired by Google on 2024-07-01 and the previous integration could no longer reach the underlying API; this release replaces it with GA4 Admin API + GA4 gtag.
* OAuth scope expanded from `analytics.readonly` to `analytics.edit` so the plugin can create the required Custom Dimensions on your GA4 property automatically. After upgrade, reconnect Google Analytics from the Google Analytics tab — the consent screen will ask you to authorize the new permission.
* Custom Dimensions admin screen rebuilt around the GA4 model. The numeric "Index" column is gone; each 51Degrees property maps onto a GA4 event-parameter name. Existing Custom Dimensions on the selected property are listed so you can map onto them or auto-create new ones.
* Frontend tracking is now emitted entirely inline in `<head>`. The previous release wrote two JavaScript files to `assets/js/` on every page render, which was race-prone under concurrent admin activity; that filesystem write is gone.
* GA4's per-property limit of 50 Custom Dimensions (free tier) is enforced upfront. The Enable Tracking action refuses to start a batch that would exceed it, with a notice explaining which properties to deselect.

= 1.0.12 =
* Replaced the deprecated Google "Out-of-Band" (OOB) OAuth flow (Google sunset January 2023) with a HMAC + PKCE-signed redirect through the 51Degrees relay.
* Existing Google Analytics connections must be reconnected after this upgrade. The plugin will surface an admin notice on first wp-admin load post-update.
* The new OAuth flow is single-site only in this release. Multisite OAuth support is on the roadmap.
* Hardened plugin uninstall: the OAuth state secret, migration version stamp, and the Google Analytics auth-date row are now removed on uninstall. Plugin deactivation is reversible and no longer wipes persistent OAuth data.
* Added a daily cleanup of orphan OAuth pending transients to keep wp_options small on sites with abandoned authorization attempts.
* FIX: /robots.txt is now always reachable to crawlers regardless of the configured robots-enforce policy. Previously, disallowed crawlers were 302-redirected when fetching /robots.txt itself, defeating the policy the file advertises (issue #60).

= 1.0.0 =
* Initial Release.

== Upgrade Notice ==

= 1.0.13 =
This release migrates the plugin from Universal Analytics to GA4. After updating, reconnect Google Analytics from Settings > 51Degrees > Google Analytics — the consent screen will request a broader scope so the plugin can create GA4 Custom Dimensions on your behalf. Required to restore tracking; Universal Analytics is no longer reachable.

= 1.0.12 =
After updating, reconnect Google Analytics from Settings > 51Degrees > Google Analytics. Required to restore tracking — Google has retired the previous OAuth flow.

= 1.0.0 =
* Install 51Degrees Plugin.

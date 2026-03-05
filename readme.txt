=== 51Degrees - Optimize by Device & Location===

Contributors: 51Degrees
Donate link: https://51degrees.com/
Tags: 51degrees, device detection, location, Google Analytics, device, detect, device type, smartphone, tablet, desktop, mobile, optimize, detection, customizable, personalized, tailored, targeting, responsive, mobile website, mobile friendly, user experience, ecommerce, OpenStreetMap, Digital element, Geolocation
Requires at least: 4.7
Tested up to: 6.9.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: EUPL

The best plugin for WordPress to send Device properties as Custom Dimensions to Google Analytics to get richer insights of device specifications and capabilities.

== Description ==

Integrating 51Degrees Device Detection with your website will allow you to make informed decisions about what content a user engages with and how it is displayed. Combining the information learned from your analytics data with real-time enhanced device data on your website will empower you to produce a page built for that specific device’s needs. Taking this one step further, you have an additional 280+ device properties available to enhance your user's user experience. The possibilities are endless as to what you can do with the information - it’s remarkably powerful.

This plugin makes use of the 51Degrees Pipeline API to deliver various data intelligence [services](https://51degrees.com/services). You can also add custom dimensions to your Google Analytics solution which will enhance your analytical data. With 51Degrees you can capture data that Google Analytics doesn't readily collect, such as detailed information on specific device hardware.

== Features ==

## Integration With Google Analytics

51Degrees plugin allows you to add the Device Data Properties as Custom Dimensions to Google Analytics in a seamless and useful manner. The integration is super simple and does not require the help of a developer to set up the integration. Once you integrate Google Analytics in WordPress using 51Degrees, you will be able to fetch the Custom Dimensions in the Google Analytics Custom Reports to get the useful insights.

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
2. To start using this plugin, you will need to create a `Resource Key`. This enables access to the data you need via the 51Degrees cloud service. You can create a `Resource Key` for free, using the [configurator](https://configure.51degrees.com/) to select the properties you want.

For a demo video on how to use our configurator, [click here](https://51degrees.com/documentation/_concepts__configurator.html).

= Integration with Google Analytics =

1. To integrate with Google Analytics go to the `Google Analytics` tab and click `Log in with Google Analytics Account` button, then follow the steps to give the 51Degrees plugin the required permissions. Copy the provided Google Analytics `Access Code’.
2. Enter the copied Code in the `Access Code` text field and click `Authenticate`. This will connect your Google Analytics Account to the 51Degrees Plugin.
3. After authentication, select your preferred profiles for which you want to enable Custom Dimensions Tracking via the `Google Analytics Property` dropdown.
4. Check `Send Page View` if you want to send Default Page View hit along with Custom Dimensions. It is only recommended if you have not already integrated with any other Google Analytics plugin to avoid data duplication.
5. Click `Save Changes`. This will prompt to the new Custom Dimensions Screen where you can find all the Custom Dimensions available with your Resource Key.
6. Click on `Enable Google Analytics Tracking` to enable tracking of all the Device Data Properties as Custom Dimensions.


== Screenshots ==

1. Google Analytics - Connect with Google Analytics
2. Google Analytics - Permissions Screen
3. Google Analytics - Access Code & Authenticate
4. Google Analytics - Select Property
5. Google Analytics - Enable Tracking

== Frequently Asked Questions ==

= Is the 51Degrees plugin free? =

The 51Degrees plugin is free and open source. However Our [Cloud Configurator](https://configure.51degrees.com/) contains both FREE and PAID properties. The properties you will need to pay for are shown with a dollar icon. You can buy what you need on our [Pricing page](https://51degrees.com/pricing).

= What happens if I already use another plugin to integrate Google Analytics? =

You can continue using your existing installed plugins to send Custom Dimensions or view Analytics Data along with the 51Degrees plugin.

= Where should I submit my support request? =

If you're experiencing any issues, use the WordPress.org [support forums](https://wordpress.org/support/plugin/51degrees-optimize-by-device-location/). If you have a technical issue with the plugin where you already have more insight on how to fix it, you can also open an issue on [GitHub](https://github.com/51Degrees/pipeline-wordpress/issues).

== Changelog ==

= 1.0.0 =
* Initial Release.

== Upgrade Notice ==

= 1.0.0 =
* Install 51Degrees Plugin.

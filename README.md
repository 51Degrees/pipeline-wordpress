# 51Degrees Device Detection and User-Agent parser for WordPress

<img src=".github/images/logo.png" alt="51Degrees" title="Data rewards the curious" width="120">

**Pipeline API - WordPress plugin**

Our User-Agent parser for WordPress supports Device Detection, browser detection, mobile detection, and crawler detection through the cloud service.

It reads User-Agent and User-Agent Client Hints, HTTP request headers that carry browser and device information. The plugin also provides location services, a dynamic robots.txt with crawler detection, and redirects for suspicious activity.

## Install

Install from the [WordPress plugin directory](https://wordpress.org/plugins/51degrees-optimize-by-device-location/), or search for `51Degrees` in the WordPress plugin manager. See the manual installation sections below.

To install a package built with the manual GitHub installation steps below:

```sh
wp plugin install --activate /path/to/your/zip
```

Try the [User-Agent tester](https://51degrees.com/developers/user-agent-tester?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.md&utm_term=install). For cloud use, [create a Resource Key](https://configure.51degrees.com/?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.md&utm_term=after-activation).

[Developer Documentation](https://51degrees.com/device-detection-php/md__home_vsts_work_1_s_apis_device-detection-php_readme.html?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.md&utm_term=51degrees-pipeline-api "Developer Documentation")

# Introduction
Use device type and location to personalize content and add device properties to your analytics reports.

# After activation

1. Visit the new `51Degrees` Settings menu.
2. To start using this plugin, you will need to create a `Resource Key`.
This enables access to the data you need via the 51Degrees cloud service.
You can create a `Resource Key` for free, using the
[property selection page](https://configure.51degrees.com/?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.md&utm_term=after-activation) to select the properties
you want.


## Integration with Google Analytics

1. To integrate with Google Analytics go to the `Google Analytics` tab
and click `Log in with Google Analytics Account` button, then follow the steps
to give the 51Degrees plugin the required permissions. Copy the provided
Google Analytics `Access Code’.

2. Enter the copied Access Code in the `Access Code` text field and click
`Authenticate`. This will connect your Google Analytics account to the
51Degrees Plugin.

3. After authentication, select your preferred profiles for which you want to
enable Custom Dimensions Tracking via the `Google Analytics Property` dropdown.

4. Check `Send Page View` if you want to send Default Page View hit along with
Custom Dimensions. It is only recommended if you have not already integrated
with any other Google Analytics plugin to avoid data duplication.

5. Click `Save Changes`. This will prompt to new Custom Dimensions Screen where
you can find all the Custom Dimensions available with Resource Key.

6. Click on `Enable Google Analytics Tracking` to enable tracking of all the
Device Data Properties as Custom Dimensions.


## Dynamic robots.txt with crawler detection

Use the `Robots.txt` tab in `Settings > 51Degrees` to manage your site's
`robots.txt` from the admin UI. The plugin fetches a robots.txt body from the
51Degrees Cloud based on the AI / search / analytics crawler categories you
allow or disallow, optionally bookended with your own custom top and bottom
sections. The cached body is refreshed daily and re-served by WordPress at
`/robots.txt`.

When `Enforce` is enabled, requests identified as crawlers via 51Degrees
Device Detection are redirected to a configurable URL (or shown a default
"access denied" page) when their crawler category is not on the allow list.
This requires a Resource Key with the `IsCrawler` and `CrawlerUsage`
properties. Without `CrawlerUsage`, enforcement falls back to path-based
rules from the generated robots.txt.

## Suspicious activity detection

The `Suspicious` tab in `Settings > 51Degrees` lets you redirect visitors
that exceed a request threshold within a sliding time window. Configure the
maximum requests, window length (seconds), and the redirect URL. The plugin
tracks per-IP request counts and redirects offenders once the threshold is
crossed. Useful as a low-friction first line of defense against scraping and
brute-force traffic.

## PMP (Preference Management Platform)

The `PMP` tab adds the 51Degrees consent popup to public pages. Visitors
choose Standard, Personalized, or an alternative (e.g. Remove ads)
experience. The choice is persisted in the browser's `localStorage` and
exposed to publisher code via the `window.onPMPCompletion` callback.
The plugin itself writes no cookies and stores no preference on the
server. PMP fetches the rest of itself from the cloud once the loader
has run, and it adds the 51Degrees client script to the page when no
client script object is there already.

PMP activates as soon as a Resource Key is configured. The popup
remains useful even on keys that don't include the 51Did identifier
properties (`IdProbGlobal` / `IdProbLic`). Those drive 51Did gating
elsewhere in the pipeline but are not required by the consent flow.

### Settings

Three fields are required when Enable PMP is on. These fields are Terms / Privacy URL,
Alternative Button Label, Alternative Button URL. The remaining
fields are optional.

- **Enable PMP**. Turn the popup on for public pages.
- **TCF Vendor String**. An IAB TCF v2 consent string carrying the
  vendor consents the site signals, generated with a TCF tool. It must
  hold a Core TC String followed by a Disclosed Vendors segment, joined
  by a dot, as version 2.3 and later of the
  [TCF consent string format](https://github.com/InteractiveAdvertisingBureau/GDPR-Transparency-and-Consent-Framework/blob/778c8c4272ce664f3f7f3be140b63508f1e02e8b/TCFv2/IAB%20Tech%20Lab%20-%20Consent%20string%20and%20vendor%20list%20formats%20v2.md#tc-string-format)
  require, and saving the tab refuses anything else with a message
  saying what to enter. It is empty by default, and the plugin ships
  no string of its own. While it is empty the plugin leaves
  `data-tcf-vendor` off the tag, and PMP reports an empty TC string.
  With a string set, PMP rewrites the
  purpose consents, the dates, the CMP ID and the policy and vendor
  list versions for each Standard or Personalized choice and keeps
  every other field as entered, as
  [Configuration attributes](https://51degrees.com/documentation/_identifiers__p_m_p__configuration.html?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.md&utm_term=tcf-vendor-string)
  describes. Versions 4.5.15 to 4.5.30 showed a built-in string in
  this box when it was empty, so saving the tab stored that string.
  Upgrading deletes a stored copy of that string and keeps any other.
- **Alternative Button Label** `*`. Label of the alternative button.
  Defaults to `Remove ads`.
- **Alternative Button URL** `*`. Destination of the alternative
  button. Defaults to `https://example.com`. A page-picker dropdown
  lets you select a published page instead.
- **Brand Name**. Defaults to `51Degrees` when empty.
- **Brand Logo URL**. Defaults to the 51Degrees logo bundled with
  the plugin (`assets/images/logo.png`) when empty.
- **Terms / Privacy URL** `*`. Required. A page-picker dropdown lets
  you select a published page.
- **Show Standard Marketing Option**. Show the Standard button
  alongside Personalized and the alternative (off by default).

The plugin puts a `data-tcf-vendor-id="51"` attribute on the tag.
PMP does not read that attribute, so the value has no effect today.

The loader address is built as
`{base}/api/v4/pmp/{resource-key}.js`, where `{base}` comes
from the same `FOD_CLOUD_API_URL` env var the rest of the plugin
honors (robots, suspicious, cloud metadata) and defaults to
`https://cloud.51degrees.com`. Point it at a staging or local
server when running the cloud yourself, because production
deployments need no configuration.

The Resource Key has to be a path segment and the address has to end
in `.js`. The small loader served from that address works out where
the rest of PMP lives by reading its own `src` attribute, dropping
any query string and any `.js` ending, and appending the locale it
picked, so a query-string form leaves it with no key to carry
forward. The locale is chosen in the browser from
`navigator.languages`, matched against the locales the loader was
built with and falling back to `en-us`, so no allowlist lives in the
plugin.

The settings tab also surfaces the visitor's current preference (read
from `localStorage['__51d_pmp_pref']` on the admin's own browser) and
a **Clear Preference** button that wipes it so the popup re-appears on
the next public-page visit.

### Continuation hook for `window.onPMPCompletion`

When the visitor makes a choice, the PMP widget invokes
`window.onPMPCompletion(preference)`. The plugin registers a no-op
default:

```js
window.onPMPCompletion = window.onPMPCompletion || function (preference) {};
```

Override it on your page to bootstrap anything that depends on the
visitor's preference, such as analytics, Prebid.js initialization, lazy ad
stacks, or any other publisher-side logic. PMP also gives scripts on
the page the TCF API, `__tcfapi`, with a TC string built from the TCF
Vendor String setting, which is empty while that setting is empty.
PMP follows the TCF's technical format without being a consent
management platform registered with IAB Europe, as
[Compared with a consent management platform](https://51degrees.com/documentation/_identifiers__p_m_p__cmp_comparison.html?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.md&utm_term=cmp-comparison)
explains. On a page where another consent management platform has
already defined `__tcfapi`, PMP writes a console warning and shows
nothing. An override that passes the choice to a data layer looks
like this.

```html
<script>
window.onPMPCompletion = function (preference) {
    // preference is 'standard', 'personalized' or 'non-marketing'
    dataLayer.push({ event: 'pmp_choice', pmp_preference: preference });
};
</script>
```

`non-marketing` is the value the alternative button stores, so a
visitor who clicks "Remove ads" arrives here as `non-marketing` and
not as `standard`. PMP suppresses `__tcfapi` for that choice.

The override may run before or after the plugin's PMP script. The last
assignment wins, and the widget only invokes the function when the
visitor actually clicks a button.

### Flow

1. The browser loads the PMP widget from the composed bundle URL.
2. On first visit the popup is shown. The visitor's choice is persisted
   in `localStorage` under `__51d_pmp_pref`.
3. PMP invokes `window.onPMPCompletion(preference)` and dismisses the
   dialog. The plugin's default is a no-op. Publisher code overrides
   it to react to the choice.
4. For a Standard or Personalized choice, PMP gives its `__tcfapi`
   listeners a TC string built from the TCF Vendor String, which is
   empty while that setting is empty.

When PMP is disabled the plugin emits no `<script>` tag.


## Developer information and advanced features

### Value replacement

You can insert snippets into your pages that will be replaced with the
corresponding value. For example, the text
`{Pipeline::get("device", "browsername")}` would be replaced with
`Chrome`, `Safari` and `Firefox`, etc. Depending on the browser being
used by the person visiting your site. To set this up, take the text from
the 'Usage in Content' column on the 'Properties' tab of the plugin.

### Conditional blocks



This feature allows you to show/hide content based on the property values
supplied by the Pipeline API. To start, click add a new block and select the
`51Degrees conditional group block`. Select the block to display the
configuration UI on the right-hand side. For example, you can configure the
block to only appear if the hardware vendor property is 'Apple'.


## Accessing properties in PHP code

To get a specific property, look it up on the available properties list and
use the get() method specified.

```Pipeline::get("device", "ismobile")```

You can also get a list of properties by category as an array:

```Pipeline::getCategory("Supported Media"))```


## JavaScript integration

The 51Degrees library exposes the same property values in JavaScript.
These are accessed through the global 'fod' object

```
<script type="text/javascript" >
	window.onload = function() {
	  fod.complete(function(data){
	  // console.log(data.device.screenpixelswidth);
	  });
	}
</script>
```

In some cases, additional evidence needs to be gathered by running JavaScript
on the client. This is mostly handled automatically by the plugin and the fod
object. For specific examples, see the 'Location' and 'Apple device models'
sections below.

## Location

Location works slightly differently to other properties. Currently, the address
is determined from the location provided by the client device. When this data
is requested, a confirmation pop-up will appear. It is good practice to delay
the appearance of this pop-up until the location is really needed. Otherwise,
the user may not know why they are being asked for the information and is more
likely to refuse. To facilitate this, the location data needs to be explicitly
requested by adding some additional JavaScript. There are many ways to do this
but for an example, we have gone with the simplest approach.

Firstly, add a button to your page. Make sure to set a css class that we can use
to identify this button and add an event to it.

Next, add an HTML element and paste the following snippet of code into it:

```
<script type="text/javascript" >
	window.onload = function() {
	  var elements = document.getElementsByClassName('get-user-location');

	  for(var i = 0; i < elements.length; i++) {
		elements[i].addEventListener('click', function() {
		  fod.complete(function(data) {
		    /* use values here if needed e.g. data.location.country
			   will contain country the user is in */
		    },
			'location');
		});
	  }
	};
</script>
```

Now, when the user clicks on the 'Use my location' button, the JavaScript that
we pasted in will execute. This lets the global `fod` object know that we want
access to the location data, which in turn causes the 'wants to know your
location' confirmation pop-up to be displayed.

<b>Note:</b> On the first request, the server will not have the location
information so the location properties will not have values. After the button
is clicked, we need to make another request to the server for the location
values to be populated.  The content on the page can also be updated by using
JavaScript, rather than waiting for the user to make a second request. This
involves editing the JavaScript snippet above to update the page within the
callback function that is passed to fod.complete.

## Apple device models

Determining the exact model of Apple devices is more difficult than others.
This is because Apple include only very limited information about the device
hardware in the 'User-Agent' HTTP header that is sent to the webserver. To get
around this problem, device detection uses JavaScript that runs directly on the
client to gather some additional information. This can usually be used to
determine the exact model of device and will at least narrow down the
possibilities.

The WordPress plugin will handle this for you automatically. However, be aware
that, due to having to get additional data from the client, the model may be
less clear on the first request than on subsequent requests. After the JavaScript
runs on the client, a second request is made and the array of values would be
significantly narrowed down.

<b>Note:</b> The content on the page can also be updated by using JavaScript,
rather than waiting for the user to make a second request. The global `fod`
object can be used to pass a callback that is executed when the updated values
are available. For example:

```
<script type="text/javascript" >
	window.onload = function() {
	  fod.complete(function(data) {
	    /* access values here. e.g. data.device.hardwarename */
	  });
	};
</script>
```

## Manual installation using WordPress plugin manager

1. Download `fiftyonedegrees` zip package from
[WordPress Plugin Manager](https://wordpress.org/plugins/wp-plugin-manager/).

2. Upload the entire `fiftyonedegrees` zip folder to the
`/wp-content/plugins/` directory.

3. Visit `Plugins`.

4. Activate the 51Degrees plugin.

## Manual installation using GitHub repository

If you want to build the plugin yourself and install locally,
you will need to follow these steps:

1.  Clone 51Degrees plugin GitHub repository from
[here](https://github.com/51Degrees/pipeline-wordpress/).

2.  Run the [ci/build-project.ps1](ci/build-project.ps1) script.

3.  Zip up the resulting `package/fiftyonedegrees` directory as `fiftyonedegrees.zip` (just the contents, not the dir itself). Delete the `package` directory.

5. Install [wp-cli](https://wp-cli.org/) and  run `wp plugin install --activate /path/to/your/zip` in the WordPress directory.

# Plugin tests

Tests for this plugin are located in the `tests` folder.
They make use of the [Brain Monkey](https://brain-wp.github.io/BrainMonkey/)
package to facilitate WordPress specific tests via it's mocking capabilities.
Also the [PHPUnit Polyfills](https://github.com/Yoast/PHPUnit-Polyfills)
package to ensure tests which are backwards compatible with PHPUnit versions.
For more info see the recommendation on [PHPUnit versions page](https://phpunit.de/supported-versions.html).

## Find out more

- [Device Detection](https://51degrees.com/device-detection?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.md&utm_term=find-out-more)
- [Bots and humans](https://51degrees.com/bots-and-humans?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.md&utm_term=find-out-more)
- [Documentation](https://51degrees.com/documentation/index.html?utm_source=github&utm_medium=readme&utm_campaign=pipeline-wordpress&utm_content=readme.md&utm_term=find-out-more)
- https://github.com/51Degrees/51degrees-cxx

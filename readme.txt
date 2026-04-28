=== PERMABOUND Sample Relay ===
Contributors: permabound
Tags: samples, zoho, crm, books, forms
Requires at least: 6.0
Tested up to: 6.5
Stable tag: 1.7.12
Requires PHP: 7.4
License: GPLv2 or later

Relays PERMABOUND sample requests from the website form to Zoho Books and Zoho CRM.

== Description ==

PERMABOUND Sample Relay provides the sample request shortcode, product sample selection, request validation, logging, email notifications, and Zoho Books/CRM relay handling.

The plugin settings screen includes controls for Zoho credentials, notification settings, source filtering, sample availability, repeat request limits, Google Places address autocomplete, and first-page form field layout.

== Changelog ==

= 1.7.12 - 28/04/2026 =
* Switched product sample availability to the ACF product_availability field.
* Added support for available, unavailable, and discontinued product states.
* Kept unavailable products visible in the sample grid while disabling and visually greying/crossing them out.
* Hid discontinued products from the sample grid.
* Added product page notices for unavailable and discontinued products.
* Added server-side validation to prevent unavailable or discontinued products being submitted.

= 1.7.11 - 20/04/2026 =
* Added admin controls for first-page form field width, with 1/2 width and full width options.
* Added admin controls for making each first-page form field mandatory or optional.
* Updated frontend and server-side validation to follow the saved field mandatory settings.

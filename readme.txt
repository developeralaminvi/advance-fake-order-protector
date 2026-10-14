=== Sarkar IT Fake Order Protector ===
Contributors: sarkarit
Tags: woocommerce, fraud prevention, phone validation, blocklist, checkout
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.9.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Phone validation, local blocklists, duplicate order checks and optional checkout recovery for WooCommerce stores in Bangladesh.

== Description ==

Sarkar IT Fake Order Protector helps merchants review and reduce unwanted orders on the WooCommerce classic checkout and Checkout Block.

* Validate Bangladeshi phone formats and normalize Bengali digits and country prefixes.
* Optionally reject repetitive or sequential number patterns. This heuristic does not verify ownership and can reject real numbers. It is disabled by default for new installations.
* Manage local phone and IP blocklists, including CSV import and export.
* Prevent repeat orders containing the same product within a configurable interval, using phone or IP matches. Shared IP addresses can affect different customers; staff should review disputed blocks.
* Offer WhatsApp support links when customers click them.
* Optionally save incomplete checkout details after the customer checks a separate consent box. Capture is disabled by default.
* View optional delivery history from configured courier providers after enabling external requests.
* Display product and security information in WooCommerce order screens with HPOS support.

Results do not prove that a customer is fraudulent or guarantee fraud prevention. Review results before making decisions.

Developed by [Sarkar IT](https://www.sarkarit.com/).
Source: https://github.com/developeralaminvi/advance-fake-order-protector
Scripts, styles and Unicode icons are included locally. No build step is required.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin ZIP using Plugins > Add New > Upload Plugin and activate it.
3. Use WooCommerce 8.9 or newer with either the classic [woocommerce_checkout] shortcode or the WooCommerce Checkout Block. For block checkout recovery, keep its Additional information block so the optional consent checkbox can appear.
4. Open Sarkar IT Protection > Settings and configure the relevant tabs.
5. Before enabling checkout recovery, update your privacy policy and choose a retention period. Customer consent is required before capture.
6. Before enabling courier checks, review the service terms and privacy policies, obtain credentials and explicitly enable external requests in Courier API Settings.

== External services ==

The plugin does not send customer information to Sarkar IT. Courier integrations are optional and disabled until a merchant enables external requests and configures credentials. An administrator's courier lookup can contact all configured providers, sending the customer's normalized phone number with API credentials. Connection tests use test phone 01712345678; Pathao connection tests send authentication credentials instead. Responses are cached in the site's own database.

= BDCourier =
Purpose: delivery and return history.
Endpoint: https://api.bdcourier.com/courier-check
Data: phone number and API key, on administrator lookup or connection test.
Service: https://bdcourier.com/
Privacy: https://www.bdcourier.com/privacy
Review the current service agreement in your provider account before use.

= Steadfast =
Purpose: fraud and delivery history.
Endpoint: https://api.steadfast.com.bd/api/v1/fraud_check/{phone}
Data: phone number, API key and secret, on administrator lookup or connection test.
Service: https://steadfast.com.bd/
Terms: https://steadfast.com.bd/terms-and-condition
Privacy: https://steadfast.com.bd/privacy

= FraudBD =
Purpose: multi-courier delivery history.
Endpoint: https://fraudbd.com/api/check-courier-info
Data: phone number and API key, on administrator lookup or connection test.
Documentation: https://fraudbd.com/api-documentation
Privacy: https://fraudbd.com/privacy
Review the current service agreement in your provider account before use.

= Pathao =
Purpose: merchant authentication and customer delivery ratings.
Endpoints: /aladdin/api/v1/issue-token and /aladdin/api/v1/user/success-rate on https://api-hermes.pathao.com or https://courier-api-sandbox.pathao.com.
Data: merchant client ID, secret, username, password or refresh token for authentication; customer phone and access token for administrator lookups.
Service: https://pathao.com/courier/
Terms: https://pathao.com/terms-of-service-merchant/
Privacy: https://pathao.com/privacy/
Sandbox-only credentials do not provide live customer history; production access is needed.

= WhatsApp =
Purpose: open a support conversation only after clicking a link.
Data: support phone number and prefilled message, sent to https://wa.me/ after a click.
Terms: https://www.whatsapp.com/legal/terms-of-service
Privacy: https://www.whatsapp.com/legal/privacy-policy

== Privacy and data retention ==

Local blocklists retain phone or IP, reason, administrative username and timestamps until manually removed. Separate order protection history stores order ID, phone, IP, product IDs, total and creation time, and is deleted after two days. Optional incomplete checkout records store name, phone, email, address, city, IP, cart contents, total, status and timestamps only after checkout recovery consent. Leads are retained for the configured 1-365 days (default 30), including converted and recovered records. Courier cache records expire after the configured 1-168 hours (default 24).

Cleanup runs daily through WordPress cron, so removal depends on cron running. Deactivation preserves records and stops cleanup. Uninstall preserves merchant records; remove records through the plugin before uninstalling if no longer needed. WordPress personal data export and erase tools include recovery records matching an email address. Phone/IP-only security records require manual review and removal because an email cannot establish ownership. Adapt the suggested WordPress Privacy Settings text to your actual practices and enabled services.

== Frequently Asked Questions ==

= Does this support Checkout Blocks? =
Yes. Phone validation, IP/phone blocklists and duplicate checks run through the Store API before payment. Block checkout has an optional recovery consent field in Additional information and uses the same local recovery dashboard. The protection also runs when browser JavaScript is bypassed. Third-party checkout blocks that bypass the WooCommerce Store API require their own integration.

= Are courier services required? =
No. Phone validation, blocklists and repeat order checks work locally. Courier providers may require separate paid accounts.

= Does it contact customers automatically? =
No. Staff can click Call and WhatsApp links in the recovery dashboard.

= How are proxy IPs handled? =
The default is server REMOTE_ADDR. Configure a trusted proxy on your server or use the afop_client_ip filter with trusted proxy validation. Never trust arbitrary forwarded headers.

== Changelog ==

= 1.9.2 =
* Point Plugin URI to the live Sarkar IT plugin information page.

= 1.9.1 =
* Use the plugin-specific source page as Plugin URI, separate from the Sarkar IT author website.

= 1.9.0 =
* Add server-side Store API protections for Checkout Blocks, including express order submissions.
* Add an optional native recovery consent checkbox and block data-store integration.
* Log and convert block orders; prevent an order retry from matching its own history.
* Ignore failed/cancelled/refunded orders for duplicate checks.
* Require WooCommerce 8.9 or newer for native additional checkout fields.

= 1.8.1 =
* Cache blocklist checks within each request and invalidate after changes.
* Allow blocklist management when enforcement is disabled.
* Validate write results instead of reporting failed changes as successful.
* Bound checkout recovery field lengths to avoid database truncation.
* Verify operation on a local MySQL site with HPOS enabled.

= 1.8.0 =
* Standardize Sarkar IT branding and GPL metadata.
* Preserve settings on other tabs when saving.
* Include local icons in place of external CSS.
* Add customer recovery consent and merchant external service opt-in.
* Bind recovery records to server sessions and limit capture requests.
* Add cleanup, privacy policy suggestions and recovery export/erase.
* Secure default IP handling, CSV cells and blocklist values.
* Escape dynamic display text and constrain Pathao hosts.
* Load modules only when WooCommerce is active and remove unsupported Blocks claims.

== Upgrade Notice ==

= 1.9.1 =
Plugin metadata correction for WordPress.org submission. Checkout protection behavior is unchanged.

= 1.9.0 =
Checkout Blocks are now supported alongside classic checkout. Use WooCommerce 8.9 or newer. Existing privacy settings are preserved.

= 1.8.1 =
The initial upgrade from older releases to 1.8.0 resets incomplete capture and external requests to disabled. Upgrading from 1.8.0 to 1.8.1 preserves settings. Review your privacy policy and enable them again if needed. Use the classic checkout.

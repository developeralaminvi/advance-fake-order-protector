# Sarkar IT Fake Order Protector

WooCommerce classic and block checkout protection for Bangladeshi stores, developed by [Sarkar IT](https://www.sarkarit.com/). Version 1.9.4; GPLv2 or later.

See [readme.txt](readme.txt) for installation, service disclosures, privacy, compatibility and changes. All assets are local; no build step is required.

Checkout Blocks are supported through Store API validation and native WooCommerce fields. WooCommerce 8.9+ is required. Optional recovery requires customer consent; courier requests require merchant opt-in and credentials. Upgrading from 1.8.x preserves these settings. Phone pattern checks are heuristic and disabled by default on new installs.

WordPress.org contributor username: `sarkarit`. The company name appears in the plugin Author header.

## External services

Optional courier providers: BDCourier, Steadfast and Pathao. Requests require merchant opt-in and the merchant's own credentials. Opening a courier report can query every configured provider; tables use cached results. Saved-credential checks are local and send no test phone. WhatsApp opens only after a click. See the External services section in [readme.txt](readme.txt) for every API destination, transmitted field, trigger, Terms of Service and Privacy Policy link. FraudBD integration was removed in 1.9.3.

Plugin information: https://www.sarkarit.com/fake-order-protector/

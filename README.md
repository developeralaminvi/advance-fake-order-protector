# Advance Fake Order Protector & Courier Checker

![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0%2B-purple.svg)
![HPOS Compatible](https://img.shields.io/badge/HPOS-Compatible-success.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%20|%208.0%20|%208.1%20|%208.2-8892BF.svg)
![Version](https://img.shields.io/badge/Version-1.2.0-brightgreen.svg)
![License](https://img.shields.io/badge/License-GPLv2-green.svg)

**Advance Fake Order Protector & Courier Checker** is an all-in-one WooCommerce security, fraud prevention, incomplete order recovery, and courier delivery ratio analytics plugin tailored specifically for Bangladeshi e-commerce stores.

---

## 🌟 Key Features

### 📱 1. Bangladeshi Phone Number Validation
* **Operator Prefix Checking:** Validates 11-digit Bangladeshi numbers with valid operator prefixes (`013, 014, 015, 016, 017, 018, 019`).
* **Bangla Number Support:** Automatically normalizes Bengali digits (`০১২৩৪৫৬৭৮৯` -> `0123456789`) and strips `+88` / `88` international country codes.
* **Strict Fake Pattern Filter:** Detects and blocks obvious fake/dummy numbers (e.g., `01700000000`, `01711111111`, `01234567890`).
* **Interactive Frontend Modal:** Triggers a modern alert popup (`"আপনার নম্বরটি ভুল। দয়া করে সঠিক নম্বর লিখুন।"`) and auto-focuses on the phone field.

### ⛔ 2. One-Click IP & Phone Blocklist
* **Orders Table Actions:** 1-click `[ 📞 Block Phone / 🚫 Blocked ]` and `[ 🌐 Block IP / 🚫 IP Blocked ]` toggle buttons directly inside the WooCommerce Orders list.
* **Instant AJAX Updates:** Page does not reload; instant toast alerts appear at the bottom right.
* **Checkout Defense & WhatsApp Redirection:** Blocked visitors cannot place orders and are presented with a support modal with a direct **WhatsApp Chat** button.
* **Blocklist Manager:** Dedicated admin panel to view, search, add, or delete blocked records.

### 📦 3. Ordered Products Column in Orders Table
* Shows ordered product **thumbnails/images**, **product title with direct clickable link** to view/edit the product, and **quantities** (`× 2`) directly in the WooCommerce Orders list table.

### 🔁 4. Repeat / Duplicate Order Protection
* **Accidental Order Blocker:** Detects if the same customer (matching phone or IP) is ordering the same product(s) within a configurable time interval (e.g. 60 minutes).
* **Interactive Confirm / Cancel Popup:** Prompt: `"আপনি {X} মিনিট আগে এই প্রোডাক্টটি অর্ডার করেছিলেন। আপনি কি আবার এই একই প্রোডাক্ট অর্ডার করতে চান?"`
  * **Confirm:** Completes the order.
  * **Cancel:** Cancels duplicate submission.

### 🛒 5. Real-Time Incomplete Order Capture (Abandoned Leads)
* **11-Digit Phone Trigger:** Captures checkout lead data via AJAX debounce **only when an 11-digit phone number is typed**. Does not capture incomplete leads without a phone number.
* **Auto-Conversion / Cleanup:** Automatically converts/removes incomplete records when the customer successfully places the order.
* **Incomplete Orders Dashboard:** View customer leads with 1-click **Call** and **WhatsApp** direct message buttons, cart thumbnails, item quantities, and total amount.

### 📊 6. Courier Delivery Ratio & Fraud Checker
* **Supported Courier APIs:** **BD Courier API**, **Steadfast API**, and **FraudBD API**.
* **Visual Ratio Modal:** Click `[ 📊 Courier Ratio ]` in the orders table to view:
  * Circular Delivery Success Gauge & Return Percentage.
  * Delivered, Returned, and Total parcel counts.
  * Risk Level Badge: 🟢 Safe Customer, 🟡 Medium Return Risk, 🔴 High Risk Alert.
  * Courier-wise breakdown table (Steadfast, Pathao, RedX, etc.).
  * 1-click button to block phone directly from inside the modal.

---

## 🛠️ Tech Stack & Requirements

* **WordPress:** 5.8 or higher
* **WooCommerce:** 5.0 to 9.0+ (Full **HPOS** and **Cart/Checkout Blocks** compatibility)
* **PHP:** 7.4, 8.0, 8.1, 8.2+
* **Dependencies:** None (Pure vanilla CSS & modular JavaScript)

---

## 📁 Directory Structure

```
advance-fake-order-protector/
├── advance-fake-order-protector.php       # Main plugin loader & compatibility declarations
├── README.md                             # GitHub Documentation
├── readme.txt                             # WordPress.org plugin metadata
├── includes/
│   ├── class-afop-activator.php          # Database tables creation & upgrades
│   ├── class-afop-validator.php          # BD Phone & fake number validation logic
│   ├── class-afop-blocklist.php          # Phone & IP blocklist engine + WhatsApp modal
│   ├── class-afop-repeat-order.php       # Duplicate order prevention within time limits
│   ├── class-afop-incomplete-orders.php  # Real-time abandoned checkout capture
│   ├── class-afop-courier-checker.php    # Steadfast, BDCourier & FraudBD API integration
│   └── class-afop-admin.php              # Admin menus, settings tabs, orders table columns & AJAX
└── assets/
    ├── css/
    │   ├── afop-frontend.css             # Frontend modals, animations & WhatsApp button
    │   └── afop-admin.css                # Admin dashboard, orders table buttons, ratio gauge
    └── js/
        ├── afop-frontend.js              # Checkout phone watcher, popup triggers, repeat modal
        └── afop-admin.js                 # Orders table AJAX block/unblock, courier modal, toasts
```

---

## 🚀 Installation & Setup

1. **Upload:** Download the plugin directory and upload to `wp-content/plugins/advance-fake-order-protector/` or install via WordPress Admin (`Plugins -> Add New -> Upload Plugin`).
2. **Activate:** Go to **Plugins** in WordPress and click **Activate** on *Advance Fake Order Protector & Courier Checker*.
3. **Configure:** Navigate to **Fake Order Protector -> Settings**:
   * **General & WhatsApp:** Set your WhatsApp Support number (e.g., `017XXXXXXXX` or `88017XXXXXXXX`).
   * **BD Phone Validator:** Customize phone validation and fake check rules.
   * **Repeat Order Protection:** Configure repeat order time limit (default: 60 minutes).
   * **Courier API Settings:** Select your API provider (BDCourier / Steadfast / FraudBD), enter your API Key, and click *Test Courier API Connection*.

---

## 📋 Changelog

### Version 1.2.0
* **Added:** Replaced all emojis with FontAwesome 6 icons across admin panels, orders table, settings tabs, and frontend modals.
* **Added:** Multi-Provider tabs inside Courier Ratio modal (BD Courier, Steadfast, FraudBD live checking).
* **Added:** Incomplete Orders submenu placed directly under WooCommerce with dynamic pending lead count bubble.
* **Added:** Bulk Block (paste multiple phone numbers / IPs), Export to CSV, and Import from CSV/TXT in Blocklist Manager.
* **Added:** Direct customer phone number display in WooCommerce Orders table.

### Version 1.1.0
* **Added:** Ordered Products column in WooCommerce Orders table with thumbnail image, clickable title, and quantity.
* **Added:** Full WooCommerce High-Performance Order Storage (HPOS) and Cart/Checkout Blocks compatibility declarations.
* **Fixed:** Resolved HPOS static meta box registration method.

### Version 1.0.0
* Initial release with BD phone validator, 1-click IP/phone blocklist, repeat order detector, incomplete checkout capture, and courier delivery ratio checker.

---

## 📄 License
This project is open-source software licensed under the [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html).

---

## 👨‍💻 Author
Developed by [Sarkarhost](https://sarkarhost.com/).

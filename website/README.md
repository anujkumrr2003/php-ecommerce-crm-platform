# E-commerce Website  (Part 2 of 2)

Luxury, fully dynamic online store. Everything - products, categories, banners, pages, colours, fonts, shipping, coupons, Razorpay keys, e-mails -
is managed from the **CRM** (Part 1). This ZIP is only the public website; it reads and writes the CRM's database.

## Features
Home page with hero slider (separate desktop / mobile images), category tiles, featured / new / best-seller / offers / reviews sections (order and headings editable) - shop with category, search, price, size, colour, availability, offers, sorting, pagination - product page with gallery, variant picker (price and stock change live), reviews, similar products, SEO tags and Product schema - AJAX cart drawer and wishlist (no page reload) - coupons - checkout with saved addresses, shipping by state, GST, COD fee - **Razorpay** (UPI, cards, net banking, wallets) with server-side signature check, webhook and automatic refunds - registration with **e-mail OTP**, login, forgot password - account: orders and tracking, cancel, return request, addresses, profile, wishlist - CMS pages, FAQ, contact form (creates a CRM inquiry) - sitemap.xml, robots.txt, 404 - professional HTML e-mails for OTP, welcome, order, payment, shipped, delivered, cancelled, return, refund.

## Install (after the CRM is installed)
1. Upload this ZIP to the website folder (`public_html`, or a sub-folder).
2. Open `https://your-domain.com/install/` -> enter the **same database** as the CRM -> Connect website.
3. **Delete the `install` folder.**
4. In the CRM open **Online Store -> Website settings** and finish: Razorpay keys, SMTP e-mail, colours, social links.

Manual install: copy `config.sample.php` to `config.php`, fill the 4 database lines (and `BASE_PATH` if in a sub-folder), then in the CRM settings set *Website address* and *CRM address*.

## Requirements
PHP 7.4+, MySQL / MariaDB, `mysqli mbstring curl openssl iconv`, Apache `mod_rewrite` (`.htaccess` included). On Nginx add equivalent rewrite rules (see `.htaccess`). For a quick local test: `php -S localhost:8000 router.php`.

## Important
* Product and banner pictures are loaded from the CRM's `uploads/` folder, so the **CRM address** (Website settings) must be correct and reachable.
* Prices are always calculated on the server from the database; the browser cannot change a price.
* Unpaid online orders release their stock after the time set in Website settings (default 45 minutes).
* Razorpay webhook URL: `https://YOUR-SITE/payment/webhook`.

## Folder map
```
index.php shop.php product.php cart.php checkout.php ...   pages (clean URLs through .htaccess)
api.php                                                    AJAX (cart, wishlist, coupon, search, newsletter)
payment-*.php                                              Razorpay pay / verify / failed / webhook
inc/                                                       bootstrap, catalog, cart, pricing, layout, account, webcore (shared with CRM)
assets/css/site.css  assets/js/site.js                     design + small script (all colours come from CRM settings)
install/                                                   one-time installer (delete after use)
database/complete_database.sql                             the ONE complete database (CRM + website), same file as in the CRM ZIP
```

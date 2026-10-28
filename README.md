# CRM + Online Store Admin  (Part 1 of 2)

This ZIP is the **CRM / admin panel**: customers, products, billing, reminders, WhatsApp, employees with roles and permissions,
plus the complete **Online Store management** (orders, payments, banners, categories, pages, coupons, reviews, shipping, Razorpay, e-mail).
The public website is the **second ZIP** (`ecommerce-website`). Both use the **same database**.

> One client = one installation of CRM + one website + one empty database. This keeps every client's products, customers,
> orders, Razorpay keys and settings completely separate. See `DEPLOYMENT-GUIDE.md`.

## 1. Requirements
PHP 7.4 or newer (8.x recommended), MySQL 5.7+ / MariaDB 10.3+, PHP extensions `mysqli mbstring curl openssl iconv`, Apache with `mod_rewrite` (Hostinger / cPanel / any shared hosting works).

## 2. Install a NEW client (about 5 minutes)
1. In your hosting panel create an **empty MySQL database** + user.
2. Upload the contents of this ZIP to a folder, e.g. `public_html/crm`.
3. Open `https://your-domain.com/crm/install/` and fill the form (database, business name, Super Admin name/e-mail/password). Tick *demo content* if you want sample products to see the website working.
4. **Delete the `install` folder.**
5. Log in at `https://your-domain.com/crm/login.php`.
6. Open **Online Store -> Website settings**: enter the website address, Razorpay keys (Payments), SMTP e-mail details (E-mail), colours (Colours & fonts).
7. Install the website ZIP (its own `install/`).

Prefer phpMyAdmin? Import the ONE file `database/complete_database.sql` into an empty database (it contains the complete CRM + website tables and demo products; demo login `admin@demo.com` / `Admin@12345`, password change forced at first login), then copy `config.sample.php` to `config.php` and edit it.

## 3. Add the online store to your EXISTING live CRM (keeps all your data)
1. **Backup** the database and the files.
2. Upload all files of this ZIP over your CRM folder, **but keep your own `config.php`** (it is not in this ZIP).
3. In phpMyAdmin run `database/extra_only_for_old_live_crm/upgrade_to_ecommerce.sql` on your CRM database (safe to run twice). Use this ONLY for an existing live CRM; everyone else uses `complete_database.sql`.
4. Log in as Super Admin -> **Team & Access -> Roles & permissions** and tick the new *Website* permissions for Admin / Manager as needed (Admin already has them).
5. Install the website ZIP and point it to the same database.
Existing customers, products, bills and settings are not changed. Website orders appear in **Billing** automatically (one invoice per confirmed order), so dashboard graphs include online sales.

## 4. Where things are (menu **Online Store**)
| Menu | What it does |
|---|---|
| Store Overview | Today's orders / sales, orders to ship, returns, failed payments |
| Orders | Every website order: status, courier + tracking, cancel, return approval, refund, history. Each change e-mails the customer |
| Payments & Refunds | Razorpay payments, failed attempts, refunds, COD to collect |
| Website Customers | Accounts, orders, purchases, wishlist, activity; block / unblock; newsletter subscribers |
| Products on Website | Hide a product, mark Featured / New / Best seller, SEO text (products themselves are added in **Products**) |
| Categories / Banners / Home Page Sections | Category pictures, hero slider (separate **desktop and mobile** image), which sections show on the home page |
| Pages / FAQ | Privacy Policy, Terms, Return Policy, Shipping Policy, About Us, any new page; FAQ |
| Coupons & Offers / Reviews | Percentage or flat codes with limits; approve reviews |
| State-wise Shipping | Optional charge per state |
| E-mail Templates | Edit every customer e-mail (OTP, order, shipped, delivered, cancel, return, refund ...), preview, send test |
| Website Settings | Address, colours & fonts, social links, SEO, shipping & checkout rules, **Razorpay keys**, SMTP |

Everything in Online Store is protected by role permissions (Super Admin sees all). *Website Inquiries* is unchanged: the website contact form creates inquiries and rings the bell.

## 5. Security notes
* Passwords are hashed, every form has a CSRF token, every query is prepared, uploads accept real images only, secrets are never shown again after saving.
* Razorpay secret and SMTP password are stored in the `settings` table of that client's own database. Restrict database access accordingly and use HTTPS.
* After install delete `install/`. Keep `config.php` private (it is blocked from the web by `.htaccess`).
* `cron-send.php` / `cron-sync-apis.php` need the `CRON_KEY` from `config.php`.

## 6. Folder map
```
install/            one-time installer (delete after use)
includes/           login, permissions, menu, notifications, webcore (shared with website)
web-*.php           Online Store admin screens
database/complete_database.sql   the ONE complete database (CRM + website)
database/extra_only_for_old_live_crm/   only for adding the store to an old live CRM
uploads/products    product pictures      uploads/web   banners, category pictures
config.sample.php   copy to config.php (the installer does it for you)
```

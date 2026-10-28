# PHP E-commerce CRM & Online Store Management System

**Part 1 of 2 – CRM & Online Store Admin Panel**

A full-featured CRM and E-commerce Management Platform developed using Core PHP and MySQL. This project combines business management functionality with a complete Online Store administration system.

The platform helps businesses manage customers, products, employees, billing, orders, payments, inventory-related operations, and website content from a centralized CRM dashboard.

The public-facing E-commerce Website is maintained in a separate repository (Part 2). Both applications connect to the same MySQL database.

---

## Project Overview

This project consists of two integrated applications:

| Application | Description |
|---|---|
| CRM & Admin Panel | Manages business operations, customers, employees, products, billing, and online store activities. |
| Public E-commerce Website | Provides customers with online shopping, accounts, cart, wishlist, checkout, and order management. |

**Multi-client Architecture:** Each client gets an independent CRM installation, an independent website installation, and a separate database. This keeps customer records, products, orders, payment credentials, and business settings isolated between clients.

## Technologies Used

- **Backend:** Core PHP
- **Database:** MySQL
- **Database Connectivity:** MySQLi
- **Frontend:** HTML5, CSS3, JavaScript
- **Server:** Apache
- **Payment Gateway:** Razorpay
- **Email Integration:** PHPMailer / SMTP
- **API Integration:** WhatsApp and other supported APIs

## Key Features

### 1. CRM & Business Management

- Admin dashboard and business overview
- Customer management
- Product management
- Employee management
- Role-based access control
- User permissions
- Billing and invoice management
- Reminders and task management
- Activity logs
- Notifications
- Website inquiry management
- WhatsApp integration

### 2. Online Store Management

- Centralized online store dashboard
- Website order management
- Payment tracking and refunds
- Website customer management
- Product visibility and featured product settings
- Product categories
- Desktop and mobile homepage banners
- Homepage section management
- CMS pages and FAQ management
- Coupon and discount management
- Customer reviews and approval
- State-wise shipping configuration
- Email templates and notifications
- Website SEO settings
- Website branding and social media settings

### 3. Order & Payment Management

- Online order status management
- Courier and tracking details
- Order cancellation
- Return request approval
- Refund management
- Razorpay payment integration
- Failed payment tracking
- Cash on Delivery management
- Automatic invoice creation for confirmed website orders
- Customer email notifications for order updates

### 4. Website Configuration

Administrators can configure:

- Website URL
- Business address
- Website colours and fonts
- Social media links
- SEO settings
- Shipping and checkout rules
- Razorpay API credentials
- SMTP email configuration

### 5. Security & Access Control

- Password hashing
- CSRF token validation
- Prepared SQL queries
- Role-based permissions
- Image upload validation
- Private configuration files
- Protected API credentials
- Client-specific database isolation

---

## Project Architecture

```text
PHP E-commerce CRM Platform
│
├── CRM Admin Panel
│   ├── Dashboard
│   ├── Customers
│   ├── Products
│   ├── Employees
│   ├── Billing & Invoices
│   ├── Roles & Permissions
│   ├── Reminders
│   └── Online Store Management
│
├── Online Store Administration
│   ├── Orders
│   ├── Payments & Refunds
│   ├── Website Customers
│   ├── Categories & Banners
│   ├── Coupons & Offers
│   ├── Pages & FAQ
│   ├── Reviews
│   ├── Shipping
│   └── Website Settings
│
└── Public E-commerce Website
    └── Separate Repository (Part 2)
```

## Repository Structure

```text
php-ecommerce-crm-platform/
│
├── install/
│   └── Installation files
│
├── includes/
│   ├── Authentication
│   ├── Permissions
│   ├── Navigation and Menus
│   ├── Notifications
│   └── Shared Web Core
│
├── database/
│   ├── complete_database.sql
│   └── extra_only_for_old_live_crm/
│
├── uploads/
│   ├── products/
│   └── web/
│
├── web-*.php
│   └── Online Store administration screens
│
├── config.sample.php
├── .gitignore
├── login.php
├── index.php
└── README.md
```

*The folder structure above describes the main application components. Private configuration files, production uploads and sensitive database backups must not be committed.*

---

## Installation Requirements

- PHP 7.4 or newer (PHP 8.x recommended)
- MySQL 5.7+ or MariaDB 10.3+
- Apache Web Server
- Apache `mod_rewrite`
- PHP Extensions:
  - mysqli
  - mbstring
  - curl
  - openssl
  - iconv

Compatible with XAMPP, Hostinger, cPanel and other supported Apache hosting environments.

## Installation Guide

### Option 1: Install a New Client

1. Create a new empty MySQL database and database user.
2. Upload the CRM project files to your hosting directory, for example `public_html/crm`.
3. Open the installation URL:

   `https://your-domain.com/crm/install/`

4. Enter database credentials and create the Super Admin account.
5. Select demo content if sample products and records are required.
6. Complete the installation.
7. Delete the `install` directory after successful installation.
8. Log in using:

   `https://your-domain.com/crm/login.php`

9. Open Online Store → Website Settings.
10. Configure website URL, Razorpay credentials, SMTP details, colours and other settings.

### Option 2: Install Using phpMyAdmin

1. Create an empty MySQL database.
2. Import the complete database schema and approved demo data.
3. Configure the private database connection file.
4. Create or configure the Super Admin account securely.
5. Complete the website installation using the second project.

**Database:** `database/complete_database.sql`

Do not publish production database backups or actual customer records.

### Option 3: Upgrade an Existing Live CRM

This option is intended for businesses already using an older version of the CRM.

1. Take a complete backup of the database and application files.
2. Upload the updated CRM files.
3. Preserve the existing private `config.php`.
4. Execute the following SQL upgrade script using phpMyAdmin:

   `database/extra_only_for_old_live_crm/upgrade_to_ecommerce.sql`

5. Assign the new Website permissions to the required employee roles.
6. Install the separate E-commerce Website project.
7. Connect the website to the same CRM database.

**Important:** The upgrade script is intended only for an existing live CRM. Do not use it for a fresh installation.

---

## Database Integration

Both the CRM and public E-commerce Website use the same MySQL database.

The integration connects:

- CRM Products → Website Products
- CRM Customers → Website Customers
- Website Orders → CRM Billing
- Website Payments → Payment Management
- Website Settings → Online Store Configuration

Confirmed website orders are integrated into CRM billing according to the application's order workflow.

## Security Considerations

- Never commit actual database credentials.
- Keep `config.php` private.
- Use HTTPS in production.
- Change default demo credentials immediately.
- Protect Razorpay API secrets and SMTP passwords.
- Delete installation directories after installation.
- Restrict access to database backups and customer information.
- Configure cron jobs using private `CRON_KEY` credentials.

---

## Related Project – Part 2

**Public E-commerce Website**

The customer-facing shopping website is maintained separately.

It connects to this CRM database and uses the products, categories, banners, orders, customers and website settings managed through the CRM.

**Repository:** Add Part 2 GitHub repository URL here after publishing it.

---

## Developer Information

**Project:** PHP E-commerce CRM & Online Store Management System

**Development:** Full-stack PHP and MySQL development

**Primary Technologies:** Core PHP, MySQL, JavaScript, HTML5, CSS3

**GitHub:** (https://github.com/anujkumrr2003)

---

*This project demonstrates practical experience in CRM development, database design, business workflow automation, E-commerce administration, payment integration and PHP application development.*

# Delivering this software to many clients

## The model: one installation per client
| Client A | Client B |
|---|---|
| `clienta.com` (website) + `clienta.com/crm` (CRM) | `clientb.in` (website) + `crm.clientb.in` (CRM) |
| database `clienta_db` | database `clientb_db` |
| Razorpay keys of client A | Razorpay keys of client B |

A client's products, customers, orders, payment keys, e-mail account, logo and colours live only in his own database and his own settings.
There is **no shared table** between clients, so one client can never read another client's data. Nothing is hard-coded for any client (name, logo, WhatsApp IDs, API keys all come from Settings).

## Checklist for every new client
1. Buy / point the domain, enable SSL (HTTPS).
2. Create an empty database + user.
3. Upload CRM ZIP -> run `/install` -> delete `install/`.
4. Upload Website ZIP -> run `/install` -> delete `install/`.
5. In CRM -> Online Store -> Website settings: website URL, colours/fonts, logo (General Settings), social links, SEO, shipping, **Razorpay Key ID + Secret (+ webhook)**, **SMTP e-mail**.
6. Add categories pictures, banners, products, edit the policy pages for the client's business.
7. Place a test order (COD, then a Razorpay *test-mode* payment), check e-mails, then switch Razorpay to live keys.
8. Give the client his Super Admin login and tell him to change the password.

## Razorpay
* Key ID / Key Secret: Razorpay Dashboard -> Settings -> API Keys (test keys start `rzp_test_`, live keys `rzp_live_`).
* Webhook (recommended, makes payments reliable even if the customer closes the browser): Dashboard -> Webhooks -> Add -> URL `https://CLIENT-SITE/payment/webhook`, events `payment.captured`, `order.paid`, `payment.failed`, `refund.processed`, and type the same secret in CRM -> Website settings -> Payments -> Webhook secret.
* Use **Test Razorpay keys** button after saving.

## E-mail that reaches the inbox
Use SMTP of the client's own domain (Hostinger mail, Google Workspace, Zoho, SendGrid ...). Set the sender to an address on the same domain and ask the client to add SPF/DKIM records at the domain host.

## Updating a client later
Upload the new files over the old ones (never overwrite `config.php`, `uploads/`), then run any new SQL file from `database/`. Take a backup first.

## Backups
Daily database export (hosting panel) + weekly copy of `uploads/`.

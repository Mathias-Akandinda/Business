# TemboNet local setup

Requirements: XAMPP with PHP 7.4+ (8.x recommended), PDO MySQL, MySQL/MariaDB, Apache. cURL is optional for SMS.

1. Start Apache and MySQL in XAMPP.
2. Configure config.php or DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD in the server environment. Defaults target the local XAMPP root account and a new database named tembonet. Use a dedicated database user and a password for deployment.
3. In PowerShell, from this project directory run:

    C:\xampp\php\php.exe tools/install.php

   This creates the database and tables without deleting existing data. Alternatively create/select a database in phpMyAdmin and import database/schema.sql.
4. Create an administrator (choose your own password):

    $env:TEMBONET_ADMIN_PASSWORD = 'your-own-long-password'
    C:\xampp\php\php.exe tools/create_admin.php admin
    Remove-Item Env:TEMBONET_ADMIN_PASSWORD

5. Open http://localhost/Business/admin_login.php and import existing router vouchers through Import vouchers. Each plan has separate inventory. Duplicate codes within a plan reject the entire import. Do not import the same code into multiple plans.
6. Open http://localhost/Business/ to submit a request. An administrator must verify the actual mobile-money transfer before approving. Approval reserves one voucher using a database transaction and row locks. Failed approvals leave the request pending. The dashboard shows the latest 500 requests.
7. The approved code appears in the dashboard. Deliver it manually if SMS is not configured or fails. Optional SMS uses SMS_USERNAME and SMS_API_KEY for Africa's Talking; no external messages are sent during installation. Set these variables in the Apache environment and restart Apache. API acceptance does not guarantee delivery.

## MikroTik integration

The original login.html contains MikroTik template variables. They resolve only when the router serves the page. Local XAMPP mode supports requests and administration; it does not authenticate devices or grant internet access. Provision vouchers on the router first, using identical voucher username and password and the appropriate duration/profile. For router deployment, provide the router's standard md5.js, host the request API on an HTTPS server, change the request URL accordingly, and configure an explicit allowed origin plus the router walled garden. This project intentionally does not enable wildcard CORS. Test CHAP and non-CHAP authentication on your actual router before use. Free trials require router configuration.

## Deployment and review notes

- Original hardcoded database and SMS secrets were removed. Rotate those exposed credentials in the respective services.
- Admin login uses password hashing, session renewal, a 30-minute idle timeout and a database-backed limit of 10 attempts per IP per 15 minutes. Admin writes require POST and a CSRF token.
- Package prices are fixed server-side; customer requests cannot choose their own price. Requests for a phone number are limited to one per 10 minutes, using a database advisory lock.
- Automatic mobile-money collection, payment-provider callbacks, router provisioning and background SMS retries are not implemented. No request is proof of payment.
- Use HTTPS for deployment; restrict database access. Back up the database regularly. database and tools directories are denied over Apache by .htaccess. Setup scripts also reject web execution.
- The dashboard still uses CDN Bootstrap, jQuery and Font Awesome. Internet access is needed for these assets. The customer portal retains its original inline styling.
- The old css/style.css is unused by the supplied pages and retained for compatibility.

## Verification

Lint PHP: Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & C:\xampp\php\php.exe -l $_.FullName }

After setup, verify wrong-password login, unauthenticated admin access, invalid phone/package/price, duplicate requests, empty inventory, successful approval, repeat approval, rejection and duplicate import. Verify that approval without SMS configuration reports that manual voucher delivery is required.

## Registration and administrator approval

Registration at `/register` saves buyers, sellers, logistics operators, and riders to the configured database with hashed passwords and a `pending` status. All applicants need a valid ID. Sellers submit the certificates required for their business type, logistics operators submit a business permit, SEC certificate, and BIR Form 2303, and riders submit vehicle details, OR/CR, and a driver's license. Documents accept JPG, PNG, WebP, or PDF files up to 5 MB each; profile and store avatar photos accept 5 MB, and store banners accept 8 MB.

An active administrator signs in at `/login` and opens `/admin/registrations` to inspect all applications and their documents. The administrator decides buyer, seller, and logistics applications; the rider's selected logistics center decides rider applications. Decisions save the reviewer and review time. Reviewed applications cannot be decided a second time. Only approved accounts can sign in or use protected workspaces. Buyers and sellers use `/login`; logistics operators and riders use `/logistics/login`.

For a fresh setup, configure the database in `.env`, then run:

```sh
php artisan migrate
php artisan app:create-admin
npm run build
```

For the Windows/XAMPP development and Cloudflare Tunnel stack, run:

```sh
php artisan serve
```

This command starts and supervises Apache/Cloudflare proxy on port 8000 when the port is free, Laravel on port 8001, Reverb on port 8080, and Vite on port 5173. Cloudflare Tunnel can continue targeting `http://127.0.0.1:8000`. Open the app directly at `http://127.0.0.1:8001`, or use the computer's LAN address on port 8001 from another device. If port 8000 is already listening, startup skips launching a duplicate Apache proxy while Laravel remains available on port 8001. The existing listener must be the intended proxy for Cloudflare traffic to keep working through the tunnel. If Windows Firewall prompts, allow Apache, PHP, and Node on your private network. `VITE_HMR_HOST` can be set in `.env` when the computer has multiple network interfaces.

The supervised Laravel server uses `serve:uploads` internally so the PHP web process accepts files allowed by the registration form. A standalone PHP or web server must also set `upload_max_filesize` above 8 MB and `post_max_size` above the total multipart request size; `public/.user.ini` supplies the project limits for supported hosted PHP setups.

Verify the full proxy-to-Reverb broadcast path with:

```sh
node scripts/check-reverb.mjs 127.0.0.1 8000
```

The command returns success only after Laravel publishes an event and a WebSocket client subscribed through the Apache proxy receives it. `scripts/run-likhae-continuously.ps1` keeps the development stack running and restarts it after an unexpected supervisor exit.

For production, run Reverb as a long-running process alongside Laravel and point the reverse proxy's WebSocket route to it:

```sh
php artisan reverb:start --host=0.0.0.0 --port=8080
```

Set `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`, and the `VITE_REVERB_*` values to the public WebSocket endpoint before running `npm run build`. When Laravel and Reverb share a host, set `REVERB_BROADCAST_HOST=127.0.0.1`, `REVERB_BROADCAST_PORT=8080`, and `REVERB_BROADCAST_SCHEME=http` so server-side broadcasts do not loop through Cloudflare. If Reverb runs as a separate service, use its private service hostname and port instead.

The Windows/XAMPP production setup keeps Cloudflare Tunnel on port 8000 and uses [`deploy/apache/likhae-reverse-proxy.conf`](deploy/apache/likhae-reverse-proxy.conf) to route `/app/` and `/apps/` to Reverb while forwarding normal web requests to Laravel on port 8001. Start Laravel with the internal flag below so the development Vite/Reverb supervisor is not launched in production:

```powershell
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8001 --no-reload --likhae-internal
C:\xampp\php\php.exe artisan reverb:start --host=0.0.0.0 --port=8080
php scripts/run-apache-proxy.php
```

Registration sends a six-digit email verification code before an application can be submitted. For Gmail SMTP, configure these values in the local `.env` using a Google App Password (with two-step verification enabled):

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=your-address@gmail.com
MAIL_PASSWORD="your-new-google-app-password"
MAIL_FROM_ADDRESS=your-address@gmail.com
MAIL_FROM_NAME="LIKHAE"
```

Keep the password out of source control and use a newly generated app password if one has been exposed. Clear cached Laravel configuration after changing mail settings with `php artisan config:clear`.

Password recovery uses Laravel's `password_reset_tokens` table and expires reset links after 60 minutes. When `MAIL_MAILER=log` is used locally, reset emails are written to `storage/logs/laravel.log` instead of being delivered to an inbox; configure SMTP for real delivery.

For production or hosting uploads, always run:

```sh
composer run deploy
```

This rebuilds the Vite assets, removes both the private Vite development marker and the legacy `public/hot` marker, clears stale Laravel caches, and refreshes the production caches. The development marker is stored at `storage/framework/vite.hot`; it is never part of the web root or a production deployment.

The app schedules announcement publishing and the 30-day soft-delete cleanup in `routes/console.php`. Production hosts must run Laravel's scheduler every minute (`php artisan schedule:run`) so expired deleted products and messages are permanently removed on schedule. Sellers can restore deleted products from **Recently Deleted** during the 30-day recovery window.

The admin command privately prompts for a password and never overwrites an existing account. Registration does not create administrator accounts.

To restore the five complete demo accounts and their sample buyer, seller, logistics, and rider data on an existing installation, run this from the deployed Laravel project directory after migrations:

```sh
php artisan db:seed --force
```

This creates missing demo users with active, verified accounts and fills in their sample addresses and role profiles. Re-running the seeder preserves existing passwords. Newly created demo accounts use `Password1`; change the administrator password immediately and replace demo contact and address details before using them for real operations. The seeder also adds or updates the sample marketplace product and delivery area.

For an existing installation, run `php artisan app:secure-registration-documents` after migration to move old public uploads into private storage. New uploads are private automatically and can only be downloaded through an authenticated admin route.

Address dropdowns require `PSGC_API_TOKEN` in `.env`. Approval and rejection decision emails are sent to the applicant using the configured mail settings. PHP's `mbstring` extension must be enabled. For this XAMPP CLI, commands can also be run with `php -d extension=mbstring artisan ...`.

Run the backend checks with `php -d extension=mbstring vendor/phpunit/phpunit/phpunit --columns=80` (tests use an isolated SQLite database).

## Platform User Flow

### Guest

Browse products, categories, stores, and product details. Registration or login is required to purchase, add to cart, save products, message sellers, or manage orders.

### Buyer

Browse products → Add to cart or buy now → Place order → Pay → Track shipment → Confirm receipt → Review product or request return/refund.

### Seller

Manage products and inventory → Receive order → Prepare package → Select courier → Request pickup → Track delivery → Receive order completion and earnings.

### Admin

Approve registrations → Manage users and marketplace activity → Monitor product violations and deliveries → Resolve disputes and refunds → Manage commissions, transactions, policies, and platform settings.

### Overall Flow

Guest registers as Buyer or Seller → Buyer places an order → Seller prepares it → Logistics delivers it → Buyer confirms receipt → Admin monitors and resolves platform issues.

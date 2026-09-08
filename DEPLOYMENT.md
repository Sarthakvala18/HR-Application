# Deploying to Cloudways

Target: `<SERVER_IP>` — `https://your-app.cloudwaysapps.com/`

---

## Before you start: check the PHP version

This application requires **PHP 8.4 or newer** (Laravel 13). Cloudways stacks are often on 8.1–8.3, and the app will not boot on those.

In the Cloudways panel: **Server → Settings & Packages → Packages → PHP Version**. If 8.4 is not offered on that server, the app cannot run there as-is and you will need a newer stack.

Verify over SSH before uploading anything:

```bash
php -v
```

---

## What to upload

Build the deployment archive:

```bash
bash scripts/build-deploy-zip.sh
```

This produces `dist/hr-app-deploy-<date>.zip` containing the application **with `vendor/` included** so no Composer run is needed on the server, and deliberately **excluding**:

- `.env` and any `.env.bak-*` — secrets
- `database/*.sqlite` — the local database, which holds real bank details
- `storage/imports/` and any `.csv` — real employee bank exports
- `node_modules/`, `.git/`, test caches and logs

The exclusions are the point. Uploading the local SQLite file would put real bank account numbers on a public-facing server.

---

## Upload with FileZilla

Use the master credentials from **Cloudways → Servers → your server → Master Credentials**.

| Setting | Value |
|---|---|
| Host | `<SERVER_IP>` |
| Protocol | SFTP |
| Port | 22 |
| Username | your master username |
| Password | your master password |

Upload the zip into the application folder:

```
/home/master/applications/<app-name>/public_html/
```

Then extract it over SSH (FileZilla cannot unzip):

```bash
cd /home/master/applications/<app-name>/public_html
unzip -o hr-app-deploy-*.zip && rm hr-app-deploy-*.zip
```

---

## Configure on the server

### 1. Create the .env

Never upload your local one. Copy the example and fill it in on the server:

```bash
cp .env.example .env
php artisan key:generate
```

**`APP_KEY` warning.** This key encrypts bank details, salary and addresses. If you generate a *new* key on the server while the database already holds encrypted rows written under a different key, that data becomes unreadable. For a fresh install this is safe. If you are migrating existing data, copy the original `APP_KEY` across instead of generating one.

Set at minimum:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-app.cloudwaysapps.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=<from Cloudways app credentials>
DB_USERNAME=<from Cloudways app credentials>
DB_PASSWORD=<from Cloudways app credentials>

ZOHO_CLIENT_ID=
ZOHO_CLIENT_SECRET=
ZOHO_REFRESH_TOKEN=
ZOHO_DC=com
HR_SIGNATORY_NAME="Coach Foundation HR"
```

`APP_DEBUG=false` matters: with it on, an error page will print environment variables including your Zoho refresh token.

### 2. Database

```bash
php artisan migrate --force
php artisan db:seed --class=DepartmentSeeder --force
php artisan db:seed --class=AppSeeder --force
php artisan db:seed --class=RoleTemplateSeeder --force
php artisan db:seed --class=DocumentTemplateSeeder --force
```

`DatabaseSeeder` also creates a local admin account, which is why the seeders are listed individually here. Create the first production user deliberately instead:

```bash
php artisan tinker
>>> App\Models\User::create([
...   'name' => 'Your Name',
...   'email' => 'you@example.com',
...   'password' => 'a-strong-password',
...   'role' => App\Enums\UserRole::SuperAdmin,
...   'is_active' => true,
...   'email_verified_at' => now(),
... ]);
```

### 3. Point the webroot at `public/`

In **Cloudways → Application → Settings & Packages → General → Webroot**, set it to `public_html/public`.

If you cannot change the webroot, the application will expose its source files. Do not skip this.

### 4. Permissions

```bash
chmod -R 775 storage bootstrap/cache
```

### 5. Cache for production

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run these after any `.env` change — a cached config ignores edits to `.env`.

---

## Verify

```bash
php artisan about --only=environment
php artisan hr:zoho-sign ping
```

Then open `https://your-app.cloudwaysapps.com/admin` and sign in.

---

## Security checklist before going live

- [ ] `APP_DEBUG=false`
- [ ] Webroot points at `public/`
- [ ] `.env` was created on the server, not uploaded
- [ ] No `.sqlite` or `.csv` file was uploaded
- [ ] SSL enabled in Cloudways (**Application → SSL Certificate**)
- [ ] The local `admin@example.com` / `password` account does **not** exist in production
- [ ] `APP_KEY` backed up somewhere durable, such as Bitwarden

---

## Updating later

```bash
cd /home/master/applications/<app-name>/public_html
php artisan down
# upload and extract the new zip
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan up
```

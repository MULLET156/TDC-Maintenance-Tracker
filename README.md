# TDC Maintenance Tracker

Daily, weekly, monthly, quarterly and yearly maintenance checklists for Toledo Dental Care, with a shared completion log. Staff sign in with one office password; tasks, the log and settings are stored in a MySQL database on Hostinger.

## Files

| File | What it is |
|---|---|
| `index.html` | The tracker itself (the page staff open). |
| `api.php` | Saves and loads data, and handles the office password. Creates its database tables on first use. |
| `config.sample.php` | Template for `config.php`, which holds the office password and database login. `config.php` is never committed. |
| `.htaccess` | Forces HTTPS and keeps `config.php` and the `.git` folder private. |

Requirements: Hostinger web hosting with PHP 8.1 or newer and MySQL (Premium or Business plans include both).

## Set up on Hostinger

### 1. Choose where it lives and turn on SSL
In hPanel, pick the website or subdomain for the tracker (for example `maintenance.yourdomain.com`; a subdomain is created under **Domains → Subdomains**). Under **Security → SSL**, make sure SSL is active. The tracker always redirects to `https://`.

### 2. Create the database
**Databases → Management → Create a new MySQL database.** Note the database name, username and password. Hostinger adds a prefix such as `u123456789_` to the name and username; you'll need the full versions.

### 3. Connect this GitHub repository
**Websites → Manage → Advanced → GIT.**

1. Because this repository is private, copy the **SSH key** Hostinger shows on that page. On GitHub, open this repository → **Settings → Deploy keys → Add deploy key**, paste it, and leave "Allow write access" unchecked.
2. Back in hPanel, under **Create a New Repository**:
   - Repository: `git@github.com:MULLET156/TDC-maintenance-tracker.git`
   - Branch: `main`
   - Directory: leave empty to install at the root of the site, or enter a folder name (for example `maintenance`). **The folder must be empty.**
3. Click **Create**, then **Deploy**.
4. Optional, for automatic updates: click **Auto Deployment**, copy the webhook URL, and add it on GitHub under **Settings → Webhooks → Add webhook** (Payload URL = that link, event = "Just the push event"). After that, every push to `main` updates the live site.

### 4. Create `config.php`
**Files → File Manager**, open the folder from step 3, copy `config.sample.php` to `config.php`, and edit it:

```php
define('OFFICE_PASSWORD', 'the password your team will use');
define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_tracker');   // from step 2
define('DB_USER', 'u123456789_tracker');   // from step 2
define('DB_PASS', 'the database password');
```

Pick a strong office password (at least 12 characters). Changing it later signs every device out.

### 5. Sign in and bring over your data
Open the site, sign in with the office password, then go to **Setup → Import & export → Choose file…** and pick the backup file. The preview shows what will be added; tap **Import**.

To include anything logged since the last backup, open the old claude.ai tracker, export a fresh **Full backup (.json)** from Setup, and import that file instead. Log entries you already have are skipped, so importing twice is safe.

## Your logo

The repository ships with a neutral "Your logo here" placeholder. To use your own logo:

- **For everyone, from the app:** sign in, open **Setup → Logo** and upload it (with an optional separate logo for Dark mode). It's saved in the database and also shows on the sign-in screen.
- **On the server (also shows on the sign-in screen):** in **Files → File Manager**, create a `brand` folder next to `index.html` and put `logo.png` in it (and `logo-dark.png` for Dark mode, if you have one). The `brand` folder isn't in the repository, so deploys never touch it. A logo uploaded in Setup takes priority over these files.

## Updating

Edit the files and push to `main`. With auto deployment on, Hostinger picks the change up within a minute or two; otherwise click **Deploy** in hPanel → GIT. `config.php` and the database are never touched by a deploy.

## Backups

Use **Setup → Import & export → Full backup** regularly (for example monthly) and keep the file somewhere safe. Hostinger's own backups (**Files → Backups**) also cover the database.

## Security notes

- Every request to `api.php` needs the sign-in cookie, except signing in itself. Ten wrong passwords from one network blocks sign-in for 15 minutes.
- Sign-ins last 90 days per device. **Setup → Sign out** ends it on that device; changing `OFFICE_PASSWORD` ends it everywhere.
- Keep patient names and other patient information out of task notes.

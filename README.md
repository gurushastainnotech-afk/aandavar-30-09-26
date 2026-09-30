# Aandavar FieldApp — Installation Guide

## What Was Fixed (v6)

### ⏰ Time Bug — RESOLVED
**Problem:** `device_submitted_at` was captured at page load time, not at the moment the
subadmin clicked Submit. If they spent 20 minutes filling the form, the recorded time
was wrong by 20 minutes.

**Fix applied in `subadmin/submit.php`:**
- Time is now captured **at the exact moment Submit is clicked** (not at page load)
- A **live running clock** (blue bar) is always visible on the form so the subadmin
  knows the current time as they work
- After submission, a **confirmation card** shows the exact date + time that was recorded
  (e.g. "15 Apr 2026, 09:23:47 AM — device local time")

**Also fixed:**
- `subadmin/my_data.php` — times now show `device_submitted_at` (falls back to `submitted_at`)
- `admin/submissions.php` — table and modal both show correct device time with AM/PM;
  a `*` marker appears if device time was unavailable (server time fallback)
- `admin/dashboard.php` + `subadmin/dashboard.php` — AM/PM time format everywhere
- `admin/reports.php` — Last Submission in Location Times tab uses device time

---

## Installation on Computer (Windows / Mac / Linux)

### Step 1 — Install XAMPP
Download from: https://www.apachefriends.org/
- Windows: Run the `.exe` installer, keep all defaults
- Mac: Run the `.dmg` installer
- Linux: `chmod +x xampp-linux-*.run && sudo ./xampp-linux-*.run`

### Step 2 — Copy App Files
1. Start XAMPP → Start **Apache** and **MySQL**
2. Copy this entire folder to:
   - Windows: `C:\xampp\htdocs\aandavar\`
   - Mac/Linux: `/opt/lampp/htdocs/aandavar/`

### Step 3 — Set Up Database
1. Open browser → go to `http://localhost/phpmyadmin`
2. Click **New** (left sidebar)
3. Database name: `aandavar_db` → click **Create**
4. Click the `aandavar_db` database → click **Import** tab
5. Click **Choose File** → select `database.sql` from this folder
6. Click **Go** (import runs, takes a few seconds)

### Step 4 — Edit Config (if needed)
Open `includes/config.php` and check:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'aandavar_db');
define('DB_USER', 'root');      // XAMPP default username
define('DB_PASS', '');          // XAMPP default = empty password
```

### Step 5 — Access the App
Open browser → `http://localhost/aandavar/`

**Default login:**
- Username: `mainadmin`
- Password: `Admin@1234`

---

## Access on Mobile (same WiFi network)

1. Find your computer's local IP address:
   - Windows: Open CMD → type `ipconfig` → look for IPv4 Address (e.g. `192.168.1.5`)
   - Mac/Linux: Open Terminal → type `ifconfig` → look for `inet` address
2. On your phone, connect to the **same WiFi** as your computer
3. Open mobile browser → go to `http://192.168.1.5/aandavar/`
   (replace with your actual IP)

> **Tip:** This only works on the same WiFi. For internet access from anywhere,
> you need to deploy to a web hosting service (see below).

---

## Deploy to Web Hosting (Access Anywhere)

### Option A — Shared Hosting (Hostinger, Namecheap, etc.)
1. Purchase hosting with PHP 8.1+ and MySQL support
2. Log in to cPanel → **File Manager** → upload all files to `public_html/aandavar/`
3. Go to **MySQL Databases** → create database + user → import `database.sql`
4. Edit `includes/config.php` with your hosting DB credentials
5. Access at `https://yourdomain.com/aandavar/`

### Option B — VPS (DigitalOcean, Vultr, etc.)
```bash
# Install LAMP stack
sudo apt update
sudo apt install apache2 php8.1 php8.1-mysql mysql-server -y

# Copy files
sudo cp -r aandavar/ /var/www/html/

# Set permissions
sudo chown -R www-data:www-data /var/www/html/aandavar/
sudo chmod -R 755 /var/www/html/aandavar/uploads/

# Set up database
mysql -u root -p
CREATE DATABASE aandavar_db;
USE aandavar_db;
SOURCE /var/www/html/aandavar/database.sql;
EXIT;
```

---

## Files Changed in This Version

| File | Change |
|------|--------|
| `subadmin/submit.php` | Live clock + time captured at submit-click + confirmation card |
| `subadmin/dashboard.php` | Live mini clock + AM/PM time format |
| `subadmin/my_data.php` | Uses device_submitted_at in all time displays |
| `admin/submissions.php` | AM/PM format + device vs server time indicator + fmtDateTime() |
| `admin/dashboard.php` | AM/PM time format |
| `admin/reports.php` | Device time for Last Submission in Location Times tab |
| `database.sql` | Migration v6 note added |

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| Blank white page | Enable PHP error display or check Apache error log |
| "Connection failed" | Check DB credentials in `includes/config.php` |
| Images not uploading | Check `uploads/` folder has write permission (`chmod 777 uploads/`) |
| Time still wrong | Clear browser cache; ensure JS is not blocked |
| Can't access from phone | Check firewall allows port 80; confirm same WiFi network |

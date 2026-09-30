# 🔐 Security Setup Guide — Aandavar App

## Files Provided & Where to Place Them

| File | Place in Project | Purpose |
|------|-----------------|---------|
| `.env` | `/` (root of project) | Real credentials — **never commit** |
| `.env.example` | `/` (root of project) | Safe template — commit this |
| `.gitignore` | `/` (root of project) | Keeps `.env` out of Git |
| `.htaccess` | `/` (root of project) | Main security rules + headers |
| `includes/.htaccess` | `/includes/` | Block direct URL access to PHP includes |
| `uploads/.htaccess` | `/uploads/` | Block PHP execution in uploads |
| `office_admin/data/.htaccess` | `/office_admin/data/` | Block access to SQLite DB |
| `includes/config.php` | `/includes/` | Replaces old config (reads from `.env`) |

---

## Step-by-Step Setup

### 1. Place & configure `.env`
```bash
# Copy the provided .env to your project root
cp .env /path/to/your/project/.env

# Open it and fill in real values:
#   DB_PASS=Aandavar_db00        ← keep or rotate
#   APP_SECRET_KEY=              ← generate below
```

**Generate APP_SECRET_KEY:**
```bash
php -r "echo bin2hex(random_bytes(32));"
```

### 2. Set file permissions
```bash
# .env — readable only by web server user, not world
chmod 640 .env

# uploads/ — writable by server, no execute
chmod 750 uploads/
chmod 640 uploads/.htaccess

# SQLite DB — no world access
chmod 640 office_admin/data/viz_gallery.db
```

### 3. Replace includes/config.php
```bash
cp includes/config.php /path/to/your/project/includes/config.php
```

The new config.php has **no hardcoded credentials** — it reads from `.env`.

### 4. Place all .htaccess files
```bash
cp .htaccess          /path/to/project/.htaccess
cp includes/.htaccess /path/to/project/includes/.htaccess
cp uploads/.htaccess  /path/to/project/uploads/.htaccess
cp office_admin/data/.htaccess /path/to/project/office_admin/data/.htaccess
```

### 5. Enable HSTS (after confirming SSL works)
In `.htaccess` root file, uncomment this line:
```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
```

### 6. Remove the /old/ folder
The `/old/` folder contains the old app with hardcoded credentials.  
**Delete it from the server:**
```bash
rm -rf /path/to/project/old/
```

---

## What Was Secured

| Area | Before | After |
|------|--------|-------|
| DB credentials | Hardcoded in `config.php` | In `.env`, outside code |
| `.env` web access | — | Blocked by `.htaccess` |
| `/includes/` direct access | Open | Fully blocked |
| `/uploads/` PHP execution | Possible | Blocked |
| SQLite DB web access | Open | Blocked |
| Session SameSite | `Lax` | `Strict` |
| Session strict mode | Off | On |
| Directory listing | On | Off |
| Error display | Could expose paths | Off in production |
| Security headers | None | X-Frame, CSP, HSTS-ready, etc. |
| SQL strict mode | Off | Enforced per connection |
| Old credentials folder | Live on server | Should be deleted |

---

## ⚠️ Important Reminders

1. **Never commit `.env`** — the `.gitignore` prevents it, but double-check.
2. **Rotate DB password** if the old `config.php` was ever in a Git repo.
3. **Change `APP_ENV=production`** — never `development` on a live server.
4. **Test after placing `.htaccess`** — visit `yourdomain.com/includes/config.php` and confirm you get a 403 Forbidden.
5. **Delete `/old/` folder** — it has the old hardcoded credentials.

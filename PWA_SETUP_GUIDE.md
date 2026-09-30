# 📱 PWA Setup Guide — Aandavar FieldApp
## Install as App on Desktop & Mobile (No App Store Needed)

---

## Files Provided — Where Each Goes

| File | Upload to Server at... |
|------|------------------------|
| `manifest.json` | `/manifest.json` (root) |
| `sw.js` | `/sw.js` (root) |
| `offline.html` | `/offline.html` (root) |
| `index.php` | `/index.php` (replaces existing) |
| `assets/pwa/icon-*.png` | `/assets/pwa/` (new folder) |
| `assets/pwa/splash-*.png` | `/assets/pwa/` (same folder) |
| `generate_splash.py` | Keep locally — already ran |

**Total new files:** manifest.json, sw.js, offline.html, 1 modified PHP file, 21 image files.

---

## Step 1 — Upload Files to Server

Using your hosting File Manager or FTP (FileZilla):

```
your-project/
├── manifest.json          ← NEW  upload here
├── sw.js                  ← NEW  upload here
├── offline.html           ← NEW  upload here
├── index.php              ← REPLACE with new version
└── assets/
    ├── logo.png           ← unchanged
    └── pwa/               ← NEW FOLDER — upload all 21 PNGs inside
        ├── icon-72x72.png
        ├── icon-96x96.png
        ├── icon-128x128.png
        ├── icon-144x144.png
        ├── icon-152x152.png
        ├── icon-192x192.png
        ├── icon-384x384.png
        ├── icon-512x512.png
        ├── icon-512x512-maskable.png
        ├── splash-640x1136.png
        ├── splash-750x1334.png
        ├── splash-828x1792.png
        ├── splash-1125x2436.png
        ├── splash-1170x2532.png
        ├── splash-1179x2556.png
        ├── splash-1242x2208.png
        ├── splash-1284x2778.png
        ├── splash-1290x2796.png
        ├── splash-1488x2266.png
        ├── splash-1668x2224.png
        └── splash-2048x2732.png
```

---

## Step 2 — Verify It Works

After uploading, open your site in Chrome and go to:

**Chrome DevTools → Application tab → Manifest**

You should see the app name, icons, and no errors.

Also check: **Application → Service Workers** — status should show "activated and is running".

---

## Step 3 — How Users Install the App

### Android (Chrome / Edge / Samsung Browser)
1. Open `https://yourdomain.com` in Chrome
2. A banner appears at the bottom: **"Add FieldApp to Home Screen"** — tap it
3. OR: tap the 3-dot menu → **"Add to Home Screen"** / **"Install App"**
4. The app icon appears on the home screen — it opens like a native app (no browser bar)

### iPhone / iPad (Safari only)
1. Open `https://yourdomain.com` in **Safari** (must be Safari, not Chrome)
2. Tap the **Share button** (box with arrow pointing up)
3. Scroll down → tap **"Add to Home Screen"**
4. Tap **"Add"** — the icon appears on the home screen

### Windows / Mac Desktop (Chrome or Edge)
1. Open `https://yourdomain.com` in Chrome or Edge
2. Look for the **install icon** (⊕) in the address bar, right side
3. Click it → **"Install Aandavar FieldApp"**
4. The app opens in its own window (no browser UI)

---

## What Changes vs. What Stays the Same

### ✅ DO NOT CHANGE
- All PHP files (admin, office_admin, subadmin, includes) — **completely unchanged**
- Database, sessions, authentication — **nothing touched**
- Your `.env`, security files — **unchanged**
- Layout, styles, colours — **unchanged**

### ✏️ ONLY CHANGES in `index.php`
The new `index.php` is **identical** to the old one except for these additions in `<head>`:

| Added | Purpose |
|-------|---------|
| `<link rel="manifest">` | Connects the PWA manifest |
| `<meta name="theme-color">` | Blue browser bar on Android |
| `<meta apple-mobile-web-app-*>` | iOS full-screen mode |
| `<link rel="apple-touch-icon">` | iOS home screen icons |
| `<link rel="apple-touch-startup-image">` | iOS splash screens |
| `<link rel="icon">` | Favicon for desktop browsers |
| PWA install banner HTML | Shows "Install" button on Android |
| Service worker registration JS | Registers sw.js (3 lines) |
| Install banner JS | Handles the install button |

All original PHP logic, form handling, CSRF, session, device_login_iso — **100% preserved**.

---

## Customisation — What You Can Change

| Setting | File | What to edit |
|---------|------|--------------|
| App name | `manifest.json` | `"name"` and `"short_name"` |
| Theme colour | `manifest.json` | `"theme_color"` and `"background_color"` |
| Splash BG colour | `generate_splash.py` | `BG_COLOR = (r, g, b)` |
| Cache version | `sw.js` line 8 | `const CACHE_VERSION = 'aandavar-v2'` |
| Offline page text | `offline.html` | Edit the card content |

---

## ⚠️ Requirements (Must Have)

| Requirement | Why |
|-------------|-----|
| **HTTPS** | PWA and service workers require SSL. HTTP will not work. |
| **Apache mod_rewrite** | Already needed by your .htaccess |
| **`sw.js` at root** | Must be served from `/sw.js`, not a subfolder |
| **`manifest.json` at root** | Same — must be at `/manifest.json` |

---

## When You Update the App

Whenever you deploy new PHP/CSS/JS changes, bump the cache version in `sw.js`:

```js
// Line 8 of sw.js:
const CACHE_VERSION = 'aandavar-v2';  // ← change v1 to v2, v3, etc.
```

This forces all installed app instances to pick up the new version automatically within 24 hours (or immediately when the user opens the app).

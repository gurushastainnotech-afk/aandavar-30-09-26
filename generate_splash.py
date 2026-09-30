#!/usr/bin/env python3
"""
generate_splash.py — Generate iOS PWA splash screens for Aandavar FieldApp
Run this once on your local machine before uploading to server.

Requirements:
    pip install Pillow

Usage:
    python3 generate_splash.py

Output:
    assets/pwa/splash-*.png  (several files, see SIZES below)
"""

from PIL import Image, ImageDraw
import os, sys

# ── Source logo ──────────────────────────────────────────────
LOGO_PATH = "assets/logo.png"
OUT_DIR   = "assets/pwa"

# ── Background color (matches your app gradient start) ───────
BG_COLOR  = (30, 58, 95)    # #1e3a5f

# ── iOS splash sizes (width x height) ────────────────────────
SIZES = {
    "splash-640x1136.png":   (640, 1136),    # iPhone 5
    "splash-750x1334.png":   (750, 1334),    # iPhone 6/7/8
    "splash-828x1792.png":   (828, 1792),    # iPhone XR / 11
    "splash-1125x2436.png":  (1125, 2436),   # iPhone X/XS/11 Pro
    "splash-1170x2532.png":  (1170, 2532),   # iPhone 12/13/14
    "splash-1179x2556.png":  (1179, 2556),   # iPhone 14 Pro
    "splash-1242x2208.png":  (1242, 2208),   # iPhone 8 Plus
    "splash-1284x2778.png":  (1284, 2778),   # iPhone 12/13 Pro Max
    "splash-1290x2796.png":  (1290, 2796),   # iPhone 14 Plus / 15 Plus
    "splash-1488x2266.png":  (1488, 2266),   # iPad Mini
    "splash-1668x2224.png":  (1668, 2224),   # iPad Pro 10.5"
    "splash-2048x2732.png":  (2048, 2732),   # iPad Pro 12.9"
}

def generate():
    os.makedirs(OUT_DIR, exist_ok=True)

    if not os.path.exists(LOGO_PATH):
        print(f"ERROR: Logo not found at {LOGO_PATH}")
        print("Run this script from the ROOT of your project folder.")
        sys.exit(1)

    logo = Image.open(LOGO_PATH).convert("RGBA")

    for filename, (w, h) in SIZES.items():
        canvas = Image.new("RGB", (w, h), BG_COLOR)

        # Logo = 25% of shorter screen dimension
        logo_size = int(min(w, h) * 0.25)
        logo_resized = logo.resize((logo_size, logo_size), Image.LANCZOS)

        # Center it slightly above mid
        x = (w - logo_size) // 2
        y = (h - logo_size) // 2 - int(h * 0.05)

        canvas.paste(logo_resized, (x, y), logo_resized)

        out_path = os.path.join(OUT_DIR, filename)
        canvas.save(out_path, "PNG", optimize=True)
        print(f"  ✓  {out_path}")

    print(f"\nDone. {len(SIZES)} splash screens created in {OUT_DIR}/")

if __name__ == "__main__":
    generate()

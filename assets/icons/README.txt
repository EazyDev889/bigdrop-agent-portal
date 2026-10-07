============================================================
  Big Drop Agent Portal — Icon Files
============================================================

You MUST add three PNG icon files to this folder before
deploying the plugin. The plugin will still work without
them, but PWA install and push notifications won't look
proper.

------------------------------------------------------------
  REQUIRED FILES
------------------------------------------------------------

1. icon-192.png
   Size: 192 × 192 pixels
   Purpose: Main app icon (PWA manifest, notifications,
            Apple touch icon, favicon on some browsers)

2. icon-512.png
   Size: 512 × 512 pixels
   Purpose: High-resolution app icon (PWA manifest, splash
            screens on Android)

3. badge-72.png
   Size: 72 × 72 pixels
   Purpose: Monochrome-style badge shown on push
            notifications (Android). Should be solid white
            on transparent background.

------------------------------------------------------------
  OPTIONAL FILES
------------------------------------------------------------

4. maskable-512.png
   Size: 512 × 512 pixels
   Purpose: Maskable icon for Android adaptive icons.
            Keep important content inside the central
            "safe area" (80% circle).
   If you provide this, add it to manifest.json under the
   icons array with "purpose": "maskable".

5. favicon.ico
   Size: 16 / 32 / 48 pixels (multi-size ICO)
   Purpose: Browser tab favicon fallback.

------------------------------------------------------------
  DESIGN GUIDELINES
------------------------------------------------------------

• Primary color:  #7FD344 (green)
• Secondary color: #1F0D5E (deep navy)
• The "Big Drop" mark is a water droplet in green
  on a navy background (or vice versa).

• Recommended for icon-192 and icon-512:
  - Navy background (#1F0D5E)
  - Green water droplet (#7FD344) centered
  - Soft rounded corners are fine; the maskable version
    should keep content in the central safe area.

• Recommended for badge-72:
  - Transparent background
  - White silhouette of the droplet (Android will tint
    it automatically).

------------------------------------------------------------
  HOW TO GENERATE ICONS QUICKLY
------------------------------------------------------------

Option A — Online (free):
1. Go to https://realfavicongenerator.net/
2. Upload your logo (PNG or SVG, at least 512×512).
3. Set the background color to #1F0D5E.
4. Generate and download the package.
5. Copy icon-192.png, icon-512.png, maskable-512.png,
   and badge-72.png into this folder.

Option B — Figma:
1. Create a 512×512 frame.
2. Add a rounded rectangle filled #1F0D5E.
3. Place your droplet SVG in the center.
4. Export as PNG at 512, then resize to 192 and 72.

Option C — Command line (ImageMagick):
1. Install ImageMagick.
2. Run:
     convert logo.png -resize 512x512 icon-512.png
     convert logo.png -resize 192x192 icon-192.png
     convert logo.png -resize 72x72   badge-72.png

------------------------------------------------------------
  ONCE ADDED
------------------------------------------------------------

After placing the icons here, verify by:

1. Opening the /manifest.webmanifest URL in your browser —
   you should see the icon URLs listed.
2. Opening the /wp-content/plugins/bigdrop-agent-portal/
   assets/icons/icon-192.png URL directly — it should
   load the image.
3. In Chrome DevTools → Application → Manifest — verify
   that icons are detected and not showing errors.

------------------------------------------------------------
# Installing Kiwe on Hostinger

## Where It Goes

Install Kiwe as a must-use plugin:

```text
public_html/
  wp-content/
    mu-plugins/
      dsa.php
      dsa/
        dsa.php
        includes/
        assets/
```

Do not install this first version in:

```text
wp-content/plugins/
```

Kiwe needs MU-plugin loading because Surface and Auth must run early and consistently on front-end requests.

## Files to Upload

From this local project:

```text
C:\Users\munaf\Documents\dsa-dual-surface\wp-content\mu-plugins\dsa.php
C:\Users\munaf\Documents\dsa-dual-surface\wp-content\mu-plugins\dsa\
```

Upload both:

1. The root loader file `dsa.php`
2. The full `dsa` folder

The root loader is required. WordPress only auto-loads PHP files directly inside `mu-plugins`; it does not automatically load nested folders.

## Hostinger File Manager Steps

1. Open Hostinger hPanel.
2. Go to Files > File Manager.
3. Open your WordPress install folder, usually `public_html`.
4. Open `wp-content`.
5. If `mu-plugins` does not exist, create it.
6. Upload `dsa.php` into `wp-content/mu-plugins/`.
7. Upload the entire `dsa` folder into `wp-content/mu-plugins/`.
8. Log in to WordPress admin.
9. Go to Plugins > Must-Use.
10. Confirm `Kiwe` is listed.
11. Go to `Kiwe` in the wp-admin sidebar.
12. Open `Kiwe > Auth` to manage PhoneKey settings, users, and activity.
13. Keep controlled editorial morphing and offline editorial caching disabled until their live proof tasks pass. Legacy fragment navigation cannot be enabled.
14. Visit the public front end and confirm the Surface/PhoneKey dock appears.

## FTP/SFTP Steps

Upload the files to:

```text
/public_html/wp-content/mu-plugins/
```

The final remote paths should be:

```text
/public_html/wp-content/mu-plugins/dsa.php
/public_html/wp-content/mu-plugins/dsa/dsa.php
```

## First Checks

After upload:

- WordPress admin should still load.
- Bricks Builder should still open normally.
- Public front end should show the Kiwe dock.
- `Kiwe` should appear in wp-admin.
- `Plugins > Must-Use` should list Kiwe.

## Browser Console Checks

Run these on a public front-end page, not inside the Bricks builder editor:

```js
window.DSA
```

If this is `undefined`, open page source and search for:

```text
dsa-boot-seed
dsa-surface
DSA Surface
Kiwe Surface
```

If none of those strings exist, Kiwe front-end output is not being printed on that page. Check `Kiwe > Developer > Diagnostics` in wp-admin and confirm the loaded version is current.

## If Something Breaks

Because this is an MU plugin, you cannot deactivate it from the normal Plugins screen.

To disable it:

1. Rename this file:

```text
wp-content/mu-plugins/dsa.php
```

to:

```text
wp-content/mu-plugins/dsa.disabled.php
```

2. Refresh the site.

The nested `dsa` folder can stay in place. Without the root loader, WordPress will not load it.

## Current Build Safety (`0.5.2`)

This build contains the full DSA Surface baseline, PhoneKey, SecureTrack, Woo commerce surfaces, PWA/Push, notifications, Search, Saved, Analytics, Schema/GEO, Bricks adapters, native read-only islands, and the S12-S19 controlled APEX contracts. Code presence is not production certification.

After upload:

- Keep SecureTrack enforcement off until recovery and proxy tests pass.
- Keep idle Home recall off unless intentionally configured.
- Keep controlled editorial morphing and offline editorial caching pilots off until their respective proof matrices pass.
- Configure SMTP and prove inbox delivery before relying on PhoneKey email OTP or abandoned-cart email.
- Configure VAPID/OpenSSL and prove cron/vendor delivery before relying on Web Push.
- Run real Woo checkout, gateway, tax, refund, Add & Save, cart, and Bricks mini-cart tests before production commerce use.
- Use the canonical upload source only: `C:\Users\munaf\Documents\dsa-dual-surface\wp-content\mu-plugins`.

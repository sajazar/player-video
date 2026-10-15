# Locked Video Player — Commercial V1 foundation

This branch adds the first host-agnostic foundation without deleting the existing player shortcode.

## What this foundation does
- Creates dedicated WordPress tables for video metadata and security/event logs.
- Creates an uploads-based private-storage directory and adds common Apache/IIS deny rules.
- Adds an admin video library that accepts MP4 uploads and stores metadata/product IDs in the custom table.
- Adds Action Scheduler-based FFmpeg HLS conversion when both are available.
- Adds a database schema upgrade check for existing plugin installs.
- Derives a per-install signing secret from the site's WordPress salts; it does not ship a fixed secret.
- Adds Settings → وضعیت پلیر امن with a basic host capability report.
- Detects FFmpeg and Action Scheduler availability where the host permits it.

## Important security status
This is a foundation, not a complete secure streaming release. The legacy shortcode still outputs the supplied media URL directly. Do not treat public MP4 URLs as protected. Nginx and some managed/shared hosts do not honor .htaccess; a server-level access test is required before using the private directory for paid content.

The video library accepts MP4 files and can queue an initial HLS conversion with FFmpeg + Action Scheduler. This conversion is currently **unencrypted and not authorized for playback**; it is an engineering stage only. Do not publish the generated manifest or segments. Uploaded originals and HLS output remain in the configured directory and must not be served directly.

The next required implementation before commercial release is:
1. Admin video library and upload/move into private storage.
2. Harden and test the background FFmpeg conversion, cleanup, retries, and unsupported-host fallback.
3. Server-side WooCommerce order entitlement checks.
4. Encrypted HLS plus short-lived playback sessions and authorization for the manifest, every segment, and encryption key.
5. HLS.js/native playback integration and real-world access-denial tests on Apache, Nginx, and shared hosting.

## Data placement
- Metadata and logs: dedicated prefixed tables in the site's WordPress database.
- Media: wp-content/uploads/lvp-private/ as a bootstrap default only; production must verify the web server blocks direct HTTP access or configure storage outside the public document root.
- Signing secret: derived from WordPress installation salts, not embedded in the plugin JavaScript.

## Compatibility
The plugin can detect capabilities and report limitations, but no plugin can guarantee equally strong private-file delivery on literally every host without server support. The commercial product must fail closed for protected playback if it cannot verify private delivery.

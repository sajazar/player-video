# Locked Video Player — Commercial V1 foundation

This branch adds the first host-agnostic foundation without deleting the existing player shortcode.

## What this foundation does
- Creates dedicated WordPress tables for video metadata and security/event logs.
- Creates an uploads-based private-storage directory and adds common Apache/IIS deny rules.
- Adds an admin video library that accepts MP4 uploads and stores metadata/product IDs in the custom table.
- Adds Action Scheduler-based FFmpeg HLS conversion when both are available.
- Adds a database schema upgrade check for existing plugin installs.
- Adds server-side WooCommerce entitlement checks for logged-in customers with processing/completed orders for linked products.
- Adds a short-lived HMAC-signed playback-token foundation (5-minute default; maximum 10 minutes).
- Derives a per-install signing secret from the site's WordPress salts; it does not ship a fixed secret.
- Adds Settings → وضعیت پلیر امن with a basic host capability report.
- Detects FFmpeg and Action Scheduler availability where the host permits it.

## Important security status
This is a foundation, not a complete secure streaming release. The legacy shortcode still outputs the supplied media URL directly. Do not treat public MP4 URLs as protected. Nginx and some managed/shared hosts do not honor .htaccess; a server-level access test is required before using the private directory for paid content.

The video library accepts MP4 files and can queue HLS conversion with FFmpeg + Action Scheduler. Conversion now requests AES-128 HLS encryption and stores the 16-byte key in the private storage directory. The playlist contains an internal `lvp-key://` marker that must be rewritten by the future authorized key endpoint. **Encrypted output is still not authorized for playback**; no manifest/segment/key delivery endpoint is implemented yet. Do not publish the generated manifest, segments, or key. Uploaded originals and HLS output remain in the configured directory and must not be served directly.

The next required implementation before commercial release is:
1. Admin video library and upload/move into private storage.
2. Harden and test the background FFmpeg conversion, cleanup, retries, and unsupported-host fallback.
3. Complete the protected media endpoint: re-check purchase entitlement on every request, validate token expiry, stream manifests and segments, and serve encryption keys only after authorization.
4. Rewrite the `lvp-key://` playlist marker to the authorized key endpoint; do not expose key file paths.
5. HLS.js/native playback integration and real-world access-denial tests on Apache, Nginx, and shared hosting.

## Data placement
- Metadata and logs: dedicated prefixed tables in the site's WordPress database.
- Media: wp-content/uploads/lvp-private/ as a bootstrap default only; production must verify the web server blocks direct HTTP access or configure storage outside the public document root.
- Signing secret: derived from WordPress installation salts, not embedded in the plugin JavaScript.

## Compatibility
The plugin can detect capabilities and report limitations, but no plugin can guarantee equally strong private-file delivery on literally every host without server support. The commercial product must fail closed for protected playback if it cannot verify private delivery.


## Current shortcode status
- `[lvp_secure_video id="123"]` checks that the visitor is logged in and has a qualifying WooCommerce order for a linked product. At this stage it intentionally displays a status message instead of video because the protected media endpoint and player integration are not complete.
- The legacy `[locked_video url="..."]` shortcode remains for compatibility and still uses a direct URL. It must not be used with public URLs for paid/protected content.

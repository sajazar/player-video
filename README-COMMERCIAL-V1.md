# Locked Video Player — Commercial V1 foundation

This branch adds the first host-agnostic foundation without deleting the existing player shortcode.

## What this foundation does
- Creates dedicated WordPress tables for video metadata and security/event logs.
- Creates an uploads-based private-storage directory and adds common Apache/IIS deny rules.
- Adds an admin video library that accepts MP4 uploads and stores metadata/product IDs in the custom table.
- Adds Action Scheduler-based FFmpeg HLS conversion when both are available; workers atomically claim queued jobs to avoid concurrent duplicate conversions.
- Adds a database schema upgrade check for existing plugin installs.
- Adds server-side WooCommerce entitlement checks for logged-in customers with processing/completed orders for linked products.
- Adds a short-lived HMAC-signed playback token (5-minute default; maximum 10 minutes).
- Adds a protected `admin-post.php` media endpoint that rechecks login, token signature/expiry, and WooCommerce entitlement for the manifest, each HLS segment, and the AES-128 key.
- Adds `[lvp_secure_video id="VIDEO_ID"]` shortcode with HLS.js for browsers that do not natively play HLS.
- Derives a per-install signing secret from the site's WordPress salts; it does not ship a fixed secret.
- Adds Settings → وضعیت پلیر امن with a basic host capability report.
- Detects FFmpeg and Action Scheduler availability where the host permits it.

## Important security status
This is a foundation, not a complete secure streaming release. The legacy shortcode still outputs the supplied media URL directly. Do not treat public MP4 URLs as protected. Nginx and some managed/shared hosts do not honor .htaccess; a server-level access test is required before using the private directory for paid content.

The video library accepts MP4 files and can queue HLS conversion with FFmpeg + Action Scheduler. Conversion requests AES-128 HLS encryption and stores the 16-byte key in the private storage directory. Before marking a video converted, the worker checks that FFmpeg produced an AES-128 manifest, the internal key marker, a valid 16-byte key, and at least one segment. The protected PHP endpoint rewrites the internal key marker and serves the manifest, segments, and key only after rechecking the logged-in user, short-lived token, and current WooCommerce entitlement. Duplicate queued jobs are guarded by an atomic worker claim. Use `[lvp_secure_video id="VIDEO_ID"]` with the numeric ID shown in the video library. However, this is still **not a production security certification**: do not merge into `main` until PHP syntax, FFmpeg encryption, browser playback, expired-token denial, refunded-order denial, and direct-path denial are tested on the target host. The HLS key is delivered to the authorized browser because playback requires it; AES-128 HLS is not DRM and cannot prevent screen recording or determined extraction.

The next required implementation before commercial release is:
1. Confirm FFmpeg actually generates encrypted playlists/segments on the target host, and test cleanup/retry behavior after failed conversions.
2. Implement playback-token renewal and test long-video playback; the current token is short-lived and does not yet auto-renew, so long videos may stop requesting new segments after expiry.
3. Run PHP syntax checks and test browser playback on Safari and Chrome/Firefox (HLS.js), including seek operations.
4. Test unauthorized, logged-out, expired-token, refunded/cancelled-order, missing-key, and missing-segment cases.
5. Confirm direct HTTP access to the original MP4, HLS directory, and key file returns 403/404 on Apache, Nginx, and shared hosting; PHP endpoint checks alone cannot block direct web-server access if private storage is exposed.

## Data placement
- Metadata and logs: dedicated prefixed tables in the site's WordPress database.
- Media: wp-content/uploads/lvp-private/ as a bootstrap default only; production must verify the web server blocks direct HTTP access or configure storage outside the public document root.
- Signing secret: derived from WordPress installation salts, not embedded in the plugin JavaScript.

## Compatibility
The plugin can detect capabilities and report limitations, but no plugin can guarantee equally strong private-file delivery on literally every host without server support. The commercial product must fail closed for protected playback if it cannot verify private delivery.


## Current shortcode status
- `[lvp_secure_video id="123"]` checks the visitor's login and qualifying WooCommerce order, then requests the protected playlist endpoint. Replace `123` with the video ID in the library.
- The legacy `[locked_video url="..."]` shortcode remains for compatibility and still uses a direct URL. It must not be used with public URLs for paid/protected content.

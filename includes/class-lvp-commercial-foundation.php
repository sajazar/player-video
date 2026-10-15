<?php
/**
 * Commercial foundation for Locked Video Player.
 *
 * @package LockedVideoPlayer
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LVP_Commercial_Foundation {
	const DB_VERSION = '1.0.0';
	const PRIVATE_DIR = 'lvp-private';

	public static function boot() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_lvp_upload_video', array( __CLASS__, 'handle_video_upload' ) );
		add_action( 'admin_post_lvp_queue_conversion', array( __CLASS__, 'handle_queue_conversion' ) );
		add_shortcode( 'lvp_secure_video', array( __CLASS__, 'secure_video_shortcode' ) );
		add_action( 'admin_post_lvp_stream', array( __CLASS__, 'handle_stream_request' ) );
		add_action( 'admin_post_nopriv_lvp_stream', array( __CLASS__, 'handle_stream_request' ) );
		add_action( 'lvp_convert_video', array( __CLASS__, 'convert_video' ), 10, 1 );
		add_action( 'admin_notices', array( __CLASS__, 'legacy_notice' ) );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'lvp_commercial_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	public static function activate() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$videos = $wpdb->prefix . 'lvp_videos';
		$events = $wpdb->prefix . 'lvp_events';

		$sql_videos = "CREATE TABLE {$videos} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL DEFAULT '',
			source_path text NOT NULL,
			stream_path text NOT NULL,
			product_ids longtext NULL,
			status varchar(24) NOT NULL DEFAULT 'pending',
			conversion_status varchar(24) NOT NULL DEFAULT 'not_started',
			duration int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY conversion_status (conversion_status)
		) {$charset};";

		$sql_events = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			video_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(40) NOT NULL DEFAULT '',
			ip_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY video_user (video_id,user_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql_videos );
		dbDelta( $sql_events );
		update_option( 'lvp_commercial_db_version', self::DB_VERSION, false );
		self::prepare_private_directory();
	}

	public static function signing_secret() {
		return hash_hmac( 'sha256', 'lvp-playback-v1|' . home_url( '/' ), wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) );
	}

	public static function private_directory() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . self::PRIVATE_DIR;
	}

	public static function prepare_private_directory() {
		$dir = self::private_directory();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$files = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security><directoryBrowse enabled=\"false\" /></system.webServer></configuration>",
		);
		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $contents, LOCK_EX );
			}
		}
		return is_dir( $dir ) && is_writable( $dir );
	}

	public static function ffmpeg_path() {
		$custom = trim( (string) get_option( 'lvp_ffmpeg_path', '' ) );
		if ( $custom && is_executable( $custom ) ) {
			return $custom;
		}
		if ( function_exists( 'exec' ) ) {
			$output = array();
			$status = 1;
			@exec( 'command -v ffmpeg 2>/dev/null', $output, $status );
			if ( 0 === $status && ! empty( $output[0] ) && is_executable( $output[0] ) ) {
				return $output[0];
			}
		}
		return '';
	}

	public static function environment_report() {
		$uploads = wp_upload_dir( null, false );
		$private_dir = self::private_directory();
		return array(
			'PHP version' => array( PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ) ),
			'WordPress uploads directory' => array( $uploads['basedir'], empty( $uploads['error'] ) && is_dir( $uploads['basedir'] ) && is_writable( $uploads['basedir'] ) ),
			'Private storage directory' => array( $private_dir, is_dir( $private_dir ) && is_writable( $private_dir ) ),
			'FFmpeg' => array( self::ffmpeg_path() ? self::ffmpeg_path() : 'پیدا نشد؛ تبدیل خودکار HLS فعال نیست', (bool) self::ffmpeg_path() ),
			'WooCommerce' => array( class_exists( 'WooCommerce' ) ? 'فعال' : 'نصب/فعال نیست', class_exists( 'WooCommerce' ) ),
			'Background queue' => array( function_exists( 'as_enqueue_async_action' ) ? 'Action Scheduler در دسترس است' : 'Action Scheduler در دسترس نیست', function_exists( 'as_enqueue_async_action' ) ),
			'Web-server private-path rule' => array( 'نیازمند آزمون از بیرون سرور؛ وجود .htaccess به‌تنهایی روی Nginx کافی نیست', false ),
		);
	}

	public static function admin_menu() {
		add_submenu_page( 'options-general.php', 'وضعیت پلیر امن', 'وضعیت پلیر امن', 'manage_options', 'lvp-commercial-status', array( __CLASS__, 'render_status_page' ) );
		add_submenu_page( 'options-general.php', 'کتابخانه ویدئوهای امن', 'کتابخانه ویدئوهای امن', 'manage_options', 'lvp-video-library', array( __CLASS__, 'render_video_library' ) );
	}

	public static function handle_video_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'اجازه انجام این عملیات را ندارید.' );
		}
		check_admin_referer( 'lvp_upload_video_action' );
		if ( empty( $_FILES['lvp_video_file']['name'] ) || ! isset( $_FILES['lvp_video_file']['tmp_name'] ) ) {
			self::redirect_library( 'missing_file' );
		}
		$file = $_FILES['lvp_video_file'];
		if ( ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::redirect_library( 'upload_error' );
		}
		if ( ! empty( $file['size'] ) && $file['size'] > wp_max_upload_size() ) {
			self::redirect_library( 'too_large' );
		}

		$checked = wp_check_filetype_and_ext( $file['tmp_name'], sanitize_file_name( $file['name'] ), array( 'mp4' => 'video/mp4' ) );
		if ( empty( $checked['ext'] ) || 'mp4' !== strtolower( $checked['ext'] ) || empty( $checked['type'] ) || 'video/mp4' !== $checked['type'] ) {
			self::redirect_library( 'invalid_type' );
		}

		$dir = self::private_directory();
		if ( ! self::prepare_private_directory() || ! is_writable( $dir ) ) {
			self::redirect_library( 'storage_error' );
		}
		$filename = wp_unique_filename( $dir, sanitize_file_name( $file['name'] ) );
		$destination = trailingslashit( $dir ) . $filename;
		if ( ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
			self::redirect_library( 'move_error' );
		}
		@chmod( $destination, 0640 );

		$title = isset( $_POST['lvp_video_title'] ) ? sanitize_text_field( wp_unslash( $_POST['lvp_video_title'] ) ) : '';
		if ( '' === $title ) {
			$title = pathinfo( $filename, PATHINFO_FILENAME );
		}
		$product_ids = array();
		if ( isset( $_POST['lvp_product_ids'] ) ) {
			$raw_ids = preg_split( '/[,\s]+/', sanitize_text_field( wp_unslash( $_POST['lvp_product_ids'] ) ) );
			foreach ( $raw_ids as $raw_id ) {
				$id = absint( $raw_id );
				if ( $id && get_post_type( $id ) === 'product' ) {
					$product_ids[] = $id;
				}
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lvp_videos';
		$now = current_time( 'mysql' );
		$inserted = $wpdb->insert(
			$table,
			array(
				'title' => $title,
				'source_path' => $filename,
				'stream_path' => '',
				'product_ids' => wp_json_encode( array_values( array_unique( $product_ids ) ) ),
				'status' => 'pending',
				'conversion_status' => 'not_started',
				'duration' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $inserted ) {
			@unlink( $destination );
			self::redirect_library( 'db_error' );
		}
		self::redirect_library( 'uploaded' );
	}

	public static function handle_queue_conversion() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'اجازه انجام این عملیات را ندارید.' );
		}
		check_admin_referer( 'lvp_queue_conversion_action' );
		$video_id = isset( $_POST['video_id'] ) ? absint( $_POST['video_id'] ) : 0;
		if ( ! $video_id ) {
			self::redirect_library( 'invalid_video' );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			self::redirect_library( 'queue_missing' );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'lvp_videos';
		$video = $wpdb->get_row( $wpdb->prepare( "SELECT id,conversion_status,updated_at FROM {$table} WHERE id = %d", $video_id ) );
		if ( ! $video ) {
			self::redirect_library( 'invalid_video' );
		}
		if ( 'processing' === $video->conversion_status && strtotime( $video->updated_at ) > ( time() - 1800 ) ) {
			self::redirect_library( 'conversion_running' );
		}
		$updated = $wpdb->update( $table, array( 'conversion_status' => 'queued', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $video_id ), array( '%s', '%s' ), array( '%d' ) );
		if ( false === $updated ) {
			self::redirect_library( 'queue_error' );
		}
		as_enqueue_async_action( 'lvp_convert_video', array( $video_id ), 'locked-video-player' );
		self::redirect_library( 'conversion_queued' );
	}


	/**
	 * Check entitlement server-side. Secure playback requires a logged-in account
	 * and a paid WooCommerce order for at least one product linked to this video.
	 */
	public static function user_can_access_video( $video_id, $user_id = 0 ) {
		$video_id = absint( $video_id );
		$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $video_id || ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'lvp_videos';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT product_ids,status,conversion_status FROM {$table} WHERE id = %d", $video_id ) );
		if ( ! $row || 'converted' !== $row->status || 'complete' !== $row->conversion_status ) {
			return false;
		}
		$product_ids = json_decode( (string) $row->product_ids, true );
		if ( ! is_array( $product_ids ) || ! $product_ids ) {
			return false;
		}
		$orders = wc_get_orders( array(
			'customer_id' => $user_id,
			'status'      => array( 'processing', 'completed' ),
			'limit'       => -1,
			'return'      => 'objects',
		) );
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$ordered_product_id = absint( $item->get_product_id() );
				$variation_id = absint( $item->get_variation_id() );
				if ( in_array( $ordered_product_id, array_map( 'absint', $product_ids ), true ) || ( $variation_id && in_array( $variation_id, array_map( 'absint', $product_ids ), true ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Create/verify short-lived signed playback claims for the protected media endpoint.
	 */
	public static function create_playback_token( $video_id, $user_id, $lifetime = 300 ) {
		$video_id = absint( $video_id );
		$user_id  = absint( $user_id );
		$expires  = time() + min( 600, max( 30, absint( $lifetime ) ) );
		if ( ! $video_id || ! $user_id || ! self::user_can_access_video( $video_id, $user_id ) ) {
			return '';
		}
		$payload = $video_id . '|' . $user_id . '|' . $expires;
		$signature = hash_hmac( 'sha256', $payload, self::signing_secret() );
		return rtrim( strtr( base64_encode( $payload . '|' . $signature ), '+/', '-_' ), '=' );
	}

	public static function verify_playback_token( $token, $video_id, $user_id ) {
		$encoded = strtr( (string) $token, '-_', '+/' );
		$encoded .= str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 );
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
			return false;
		}
		$parts = explode( '|', $decoded );
		if ( 4 !== count( $parts ) ) {
			return false;
		}
		list( $token_video, $token_user, $expires, $signature ) = $parts;
		if ( absint( $token_video ) !== absint( $video_id ) || absint( $token_user ) !== absint( $user_id ) || absint( $expires ) < time() ) {
			return false;
		}
		$payload = absint( $token_video ) . '|' . absint( $token_user ) . '|' . absint( $expires );
		return hash_equals( hash_hmac( 'sha256', $payload, self::signing_secret() ), (string) $signature );
	}

	public static function secure_video_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'lvp_secure_video' );
		$video_id = absint( $atts['id'] );
		if ( ! is_user_logged_in() ) {
			return '<p class="lvp-access-message">برای مشاهده این ویدئو ابتدا وارد حساب کاربری خود شوید.</p>';
		}
		$user_id = get_current_user_id();
		if ( ! self::user_can_access_video( $video_id, $user_id ) ) {
			return '<p class="lvp-access-message">دسترسی به این ویدئو تأیید نشد؛ خرید دوره و وضعیت سفارش خود را بررسی کنید.</p>';
		}
		$token = self::create_playback_token( $video_id, $user_id, 300 );
		if ( ! $token ) {
			return '<p class="lvp-access-message">ساخت نشست پخش امن ناموفق بود؛ لطفاً صفحه را دوباره بارگذاری کنید.</p>';
		}
		wp_enqueue_script( 'lvp-hls-js', 'https://cdn.jsdelivr.net/npm/hls.js@1.5.17/dist/hls.min.js', array(), '1.5.17', true );
		$stream_url = add_query_arg(
			array( 'action' => 'lvp_stream', 'kind' => 'playlist', 'video_id' => $video_id, 'token' => $token ),
			admin_url( 'admin-post.php' )
		);
		$player_id = 'lvp-secure-' . wp_generate_uuid4();
		$script = 'document.addEventListener("DOMContentLoaded",function(){var v=document.getElementById(' . wp_json_encode( $player_id ) . ');if(!v)return;var src=' . wp_json_encode( $stream_url ) . ';if(v.canPlayType("application/vnd.apple.mpegurl")){v.src=src;}else if(window.Hls&&Hls.isSupported()){var h=new Hls({enableWorker:true});h.loadSource(src);h.attachMedia(v);}else{v.insertAdjacentHTML("afterend","<p>مرورگر شما پخش HLS را پشتیبانی نمی‌کند.</p>");}});';
		wp_add_inline_script( 'lvp-hls-js', $script, 'after' );
		return '<div class="lvp-secure-player" data-video-id="' . esc_attr( $video_id ) . '"><video id="' . esc_attr( $player_id ) . '" controls playsinline preload="metadata" style="width:100%;height:auto;background:#000"></video></div>';
	}

	/**
	 * Authenticated media delivery. Every manifest, segment and key request checks
	 * the signed token, logged-in user and current WooCommerce entitlement.
	 */
	public static function handle_stream_request() {
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$video_id = isset( $_GET['video_id'] ) ? absint( $_GET['video_id'] ) : 0;
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$user_id = get_current_user_id();
		if ( ! is_user_logged_in() || ! $video_id || ! $token || ! self::verify_playback_token( $token, $video_id, $user_id ) || ! self::user_can_access_video( $video_id, $user_id ) ) {
			self::stream_error( 403, 'دسترسی به پخش این ویدئو مجاز نیست.' );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'lvp_videos';
		$video = $wpdb->get_row( $wpdb->prepare( "SELECT stream_path,conversion_status,status FROM {$table} WHERE id = %d", $video_id ) );
		if ( ! $video || 'complete' !== $video->conversion_status || 'converted' !== $video->status ) {
			self::stream_error( 404, 'ویدئو آماده پخش نیست.' );
		}
		$base = realpath( self::private_directory() );
		if ( ! $base ) {
			self::stream_error( 500, 'فضای ذخیره‌سازی خصوصی در دسترس نیست.' );
		}
		$hls_dir = realpath( trailingslashit( $base ) . 'hls-' . $video_id );
		if ( ! $hls_dir || 0 !== strpos( $hls_dir, trailingslashit( $base ) ) || is_link( $hls_dir ) ) {
			self::stream_error( 404, 'مسیر ویدئو پیدا نشد.' );
		}
		if ( 'playlist' === $kind ) {
			$path = realpath( trailingslashit( $hls_dir ) . 'index.m3u8' );
			if ( ! $path || ! is_file( $path ) ) {
				self::stream_error( 404, 'فهرست پخش پیدا نشد.' );
			}
			$content = file_get_contents( $path );
			if ( false === $content || false === strpos( $content, 'lvp-key://' . $video_id ) ) {
				self::stream_error( 500, 'فهرست پخش رمزگذاری‌شده معتبر نیست.' );
			}
			$key_url = add_query_arg( array( 'action' => 'lvp_stream', 'kind' => 'key', 'video_id' => $video_id, 'token' => $token ), admin_url( 'admin-post.php' ) );
			$content = str_replace( 'lvp-key://' . $video_id, esc_url_raw( $key_url ), $content );
			$content = preg_replace_callback( '/^(segment_[a-zA-Z0-9_-]+\.ts)$/m', function( $matches ) use ( $video_id, $token ) {
				return add_query_arg( array( 'action' => 'lvp_stream', 'kind' => 'segment', 'video_id' => $video_id, 'file' => $matches[1], 'token' => $token ), admin_url( 'admin-post.php' ) );
			}, $content );
			nocache_headers();
			header( 'Content-Type: application/vnd.apple.mpegurl' );
			header( 'Content-Disposition: inline; filename="stream.m3u8"' );
			header( 'X-Content-Type-Options: nosniff' );
			echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HLS playlist generated by FFmpeg and URI lines rewritten above.
			exit;
		}
		if ( 'key' === $kind ) {
			$key_path = trailingslashit( $base ) . 'hls-' . $video_id . '.key';
			if ( is_link( $key_path ) || ! is_file( $key_path ) || 16 !== filesize( $key_path ) ) {
				self::stream_error( 404, 'کلید رمزگشایی پیدا نشد.' );
			}
			nocache_headers();
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Length: 16' );
			header( 'X-Content-Type-Options: nosniff' );
			readfile( $key_path );
			exit;
		}
		if ( 'segment' === $kind ) {
			$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
			if ( ! preg_match( '/^segment_[0-9]+\.ts$/', $file ) ) {
				self::stream_error( 400, 'نام قطعه نامعتبر است.' );
			}
			$path = realpath( trailingslashit( $hls_dir ) . $file );
			if ( ! $path || 0 !== strpos( $path, trailingslashit( $hls_dir ) ) || is_link( trailingslashit( $hls_dir ) . $file ) || ! is_file( $path ) ) {
				self::stream_error( 404, 'قطعه ویدئو پیدا نشد.' );
			}
			nocache_headers();
			header( 'Content-Type: video/mp2t' );
			header( 'Content-Length: ' . filesize( $path ) );
			header( 'X-Content-Type-Options: nosniff' );
			readfile( $path );
			exit;
		}
		self::stream_error( 400, 'نوع درخواست نامعتبر است.' );
	}

	private static function stream_error( $status, $message ) {
		status_header( absint( $status ) );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo esc_html( $message );
		exit;
	}

	public static function convert_video( $video_id ) {
		$video_id = absint( $video_id );
		if ( ! $video_id ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'lvp_videos';
		// Atomically claim only a queued job. Duplicate Action Scheduler jobs then exit.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET conversion_status = 'processing', updated_at = %s WHERE id = %d AND conversion_status = 'queued'",
				current_time( 'mysql' ),
				$video_id
			)
		);
		if ( 1 !== (int) $claimed ) {
			return;
		}
		$ffmpeg = self::ffmpeg_path();
		if ( ! $ffmpeg ) {
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		$video = $wpdb->get_row( $wpdb->prepare( "SELECT id,source_path FROM {$table} WHERE id = %d", $video_id ) );
		if ( ! $video || ! preg_match( '/^[a-zA-Z0-9._-]+\.mp4$/i', $video->source_path ) ) {
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		$base = realpath( self::private_directory() );
		$source = realpath( trailingslashit( self::private_directory() ) . $video->source_path );
		if ( ! $base || ! $source || 0 !== strpos( $source, trailingslashit( $base ) ) || ! is_file( $source ) ) {
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		$out_dir = trailingslashit( $base ) . 'hls-' . $video_id;
		if ( is_link( $out_dir ) ) {
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		if ( ! is_dir( $out_dir ) && ! wp_mkdir_p( $out_dir ) ) {
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		if ( ! is_writable( $out_dir ) ) {
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		$playlist = trailingslashit( $out_dir ) . 'index.m3u8';
		$segments = trailingslashit( $out_dir ) . 'segment_%05d.ts';
		$key_path = trailingslashit( $base ) . 'hls-' . $video_id . '.key';
		$key_info = trailingslashit( $out_dir ) . 'keyinfo.tmp';
		if ( is_link( $key_path ) || is_link( $key_info ) ) {
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		// Remove this video's old outputs before retrying conversion.
		@unlink( trailingslashit( $out_dir ) . 'index.m3u8' );
		@unlink( $key_info );
		foreach ( glob( trailingslashit( $out_dir ) . 'segment_*.ts' ) as $old_segment ) {
			@unlink( $old_segment );
		}
		if ( file_exists( $key_path ) && ! @unlink( $key_path ) ) {
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		try {
			$key = random_bytes( 16 );
			$iv = bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $e ) {
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		if ( false === $key || false === $iv || false === @file_put_contents( $key_path, $key, LOCK_EX ) ) {
			@unlink( $key_path );
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		@chmod( $key_path, 0600 );
		$key_info_contents = "lvp-key://" . $video_id . "\n" . $key_path . "\n" . $iv . "\n";
		if ( false === @file_put_contents( $key_info, $key_info_contents, LOCK_EX ) ) {
			@unlink( $key_path );
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		@chmod( $key_info, 0600 );
		$command = escapeshellarg( $ffmpeg ) . ' -nostdin -y -i ' . escapeshellarg( $source ) . ' -c:v libx264 -c:a aac -hls_time 6 -hls_playlist_type vod -hls_key_info_file ' . escapeshellarg( $key_info ) . ' -hls_segment_filename ' . escapeshellarg( $segments ) . ' ' . escapeshellarg( $playlist ) . ' 2>&1';
		$output = array();
		$status = 1;
		@exec( $command, $output, $status );
		@unlink( $key_info );
		$playlist_contents = is_file( $playlist ) ? file_get_contents( $playlist ) : false;
		$segments_found = glob( trailingslashit( $out_dir ) . 'segment_*.ts' );
		$valid_segments = is_array( $segments_found ) && ! empty( $segments_found );
		if ( 0 !== $status || false === $playlist_contents || false === strpos( $playlist_contents, 'METHOD=AES-128' ) || false === strpos( $playlist_contents, 'lvp-key://' . $video_id ) || ! $valid_segments || ! is_file( $key_path ) || 16 !== filesize( $key_path ) ) {
			@unlink( $key_info );
			@unlink( $playlist );
			@unlink( $key_path );
			if ( is_array( $segments_found ) ) {
				foreach ( $segments_found as $segment ) {
					@unlink( $segment );
				}
			}
			self::set_conversion_status( $video_id, 'failed' );
			return;
		}
		@chmod( $playlist, 0640 );
		// Only mark conversion complete when FFmpeg produced an AES-128 manifest and segments.
		foreach ( $segments_found as $segment ) {
			@chmod( $segment, 0640 );
		}
		$wpdb->update(
			$table,
			array( 'stream_path' => 'hls-' . $video_id . '/index.m3u8', 'conversion_status' => 'complete', 'status' => 'converted', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $video_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function set_conversion_status( $video_id, $status ) {
		global $wpdb;
		$table = $wpdb->prefix . 'lvp_videos';
		$wpdb->update( $table, array( 'conversion_status' => sanitize_key( $status ), 'updated_at' => current_time( 'mysql' ) ), array( 'id' => absint( $video_id ) ), array( '%s', '%s' ), array( '%d' ) );
	}

	private static function redirect_library( $result ) {
		wp_safe_redirect( add_query_arg( array( 'page' => 'lvp-video-library', 'lvp_result' => sanitize_key( $result ) ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function render_video_library() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$messages = array(
			'uploaded' => 'ویدئو در پوشه خصوصی ذخیره و اطلاعات آن ثبت شد؛ هنوز تبدیل HLS و پخش امن فعال نشده است.',
			'missing_file' => 'فایل انتخاب نشده است.',
			'upload_error' => 'بارگذاری فایل ناموفق بود.',
			'too_large' => 'حجم فایل از محدودیت آپلود PHP/وردپرس بیشتر است.',
			'invalid_type' => 'فعلاً فقط فایل MP4 معتبر پذیرفته می‌شود.',
			'storage_error' => 'پوشه ذخیره‌سازی خصوصی قابل نوشتن نیست.',
			'move_error' => 'انتقال فایل به پوشه خصوصی ناموفق بود.',
			'db_error' => 'ثبت اطلاعات در دیتابیس ناموفق بود؛ فایل موقت حذف شد.',
			'invalid_video' => 'ویدئوی انتخاب‌شده معتبر نیست.',
			'queue_missing' => 'صف پردازش پس‌زمینه در این هاست موجود نیست؛ تبدیل خودکار فعال نشد.',
			'conversion_queued' => 'درخواست تبدیل ثبت شد. وضعیت را بعداً در همین صفحه بررسی کنید.',
			'conversion_running' => 'تبدیل این ویدئو در حال اجراست؛ برای جلوگیری از تداخل، درخواست تکراری ثبت نشد.',
			'queue_error' => 'ثبت وضعیت صف تبدیل ناموفق بود؛ دوباره تلاش کنید.',
		);
		if ( isset( $_GET['lvp_result'] ) ) {
			$key = sanitize_key( wp_unslash( $_GET['lvp_result'] ) );
			if ( isset( $messages[ $key ] ) ) {
				echo '<div class="notice ' . ( 'uploaded' === $key ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $messages[ $key ] ) . '</p></div>';
			}
		}
		echo '<div class="wrap"><h1>کتابخانه ویدئوهای امن</h1>';
		echo '<p>فایل MP4 در مسیر خصوصی افزونه ذخیره می‌شود و URL عمومی در صفحه درج نمی‌شود. تا تکمیل تبدیل HLS و تست قوانین وب‌سرور، این بخش را سامانه پخش امن نهایی در نظر نگیرید.</p>';
		echo '<h2>افزودن ویدئو</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		echo '<input type="hidden" name="action" value="lvp_upload_video">';
		wp_nonce_field( 'lvp_upload_video_action' );
		echo '<table class="form-table"><tr><th><label for="lvp_video_title">عنوان ویدئو</label></th><td><input class="regular-text" id="lvp_video_title" name="lvp_video_title" type="text" maxlength="255"></td></tr>';
		echo '<tr><th><label for="lvp_video_file">فایل MP4</label></th><td><input required id="lvp_video_file" name="lvp_video_file" type="file" accept=".mp4,video/mp4"><p class="description">حداکثر حجم فعلی: ' . esc_html( size_format( wp_max_upload_size() ) ) . '</p></td></tr>';
		echo '<tr><th><label for="lvp_product_ids">شناسه محصول ووکامرس</label></th><td><input class="regular-text" id="lvp_product_ids" name="lvp_product_ids" type="text" placeholder="مثلاً 123 یا 123,456"><p class="description">برای پخش امن باید شناسه محصولی وارد شود که خریدار آن اجازه مشاهده ویدئو را داشته باشد؛ بدون محصول مرتبط، پخش مجاز نمی‌شود.</p></td></tr></table>';
		echo '<p><button class="button button-primary" type="submit">ذخیره در فضای خصوصی</button></p></form>';

		global $wpdb;
		$table = $wpdb->prefix . 'lvp_videos';
		$videos = $wpdb->get_results( "SELECT id,title,source_path,product_ids,status,conversion_status,created_at FROM {$table} ORDER BY id DESC LIMIT 100" );
		echo '<h2>ویدئوهای ثبت‌شده</h2><table class="widefat striped"><thead><tr><th>شناسه</th><th>عنوان</th><th>فایل خصوصی</th><th>محصول‌ها</th><th>وضعیت</th><th>تبدیل</th><th>تاریخ</th></tr></thead><tbody>';
		if ( $videos ) {
			foreach ( $videos as $video ) {
				echo '<tr><td>' . absint( $video->id ) . '</td><td>' . esc_html( $video->title ) . '</td><td><code>' . esc_html( $video->source_path ) . '</code></td><td>' . esc_html( (string) $video->product_ids ) . '</td><td>' . esc_html( $video->status ) . '</td><td>' . esc_html( $video->conversion_status );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:6px"><input type="hidden" name="action" value="lvp_queue_conversion"><input type="hidden" name="video_id" value="' . absint( $video->id ) . '">';
				wp_nonce_field( 'lvp_queue_conversion_action' );
				echo '<button class="button button-small" type="submit">درخواست تبدیل HLS</button></form></td><td>' . esc_html( $video->created_at ) . '</td></tr>';
			}
		} else {
			echo '<tr><td colspan="7">هنوز ویدئویی ثبت نشده است.</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function render_status_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['lvp_prepare_storage'] ) ) {
			check_admin_referer( 'lvp_prepare_storage_action' );
			self::prepare_private_directory();
			echo '<div class="notice notice-info"><p>آماده‌سازی پوشه خصوصی انجام شد. این کار به‌تنهایی ثابت نمی‌کند وب‌سرور دسترسی مستقیم را مسدود کرده است.</p></div>';
		}
		$report = self::environment_report();
		echo '<div class="wrap"><h1>وضعیت پلیر امن و سازگاری هاست</h1>';
		echo '<p>این گزارش قابلیت‌های پایه را بررسی می‌کند. وضعیت مسدودسازی URL پوشه خصوصی باید روی وب‌سرور واقعی هم آزموده شود.</p>';
		echo '<table class="widefat striped"><thead><tr><th>بررسی</th><th>نتیجه</th><th>وضعیت</th></tr></thead><tbody>';
		foreach ( $report as $label => $item ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td><code>' . esc_html( (string) $item[0] ) . '</code></td><td>' . ( $item[1] ? '<span style="color:green">آماده</span>' : '<span style="color:#b32d2e">نیازمند بررسی</span>' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" style="margin-top:16px">';
		wp_nonce_field( 'lvp_prepare_storage_action' );
		echo '<button class="button button-secondary" name="lvp_prepare_storage" value="1">آماده‌سازی پوشه خصوصی</button></form>';
		echo '<h2>نکته امنیتی</h2><p>تا وقتی تبدیل HLS و کنترل دسترسی سمت سرور برای manifest، قطعه‌ها و کلیدها کامل و آزموده نشده، ویدئوها را به‌عنوان محتوای محافظت‌شده منتشر نکنید. قفل ظاهری پلیر یا پنهان‌کردن دکمه دانلود، لینک MP4 عمومی را امن نمی‌کند.</p></div>';
	}

	public static function legacy_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] ) || 'lvp-commercial-status' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>شورت‌کد قدیمی هنوز URL مستقیم MP4 را در صفحه قرار می‌دهد. این نسخه پایه تجاری را اضافه می‌کند و هنوز پخش امن HLS را جایگزین نکرده است.</p></div>';
	}
}
register_activation_hook( dirname( __DIR__ ) . '/locked-video-player.php', array( 'LVP_Commercial_Foundation', 'activate' ) );
LVP_Commercial_Foundation::boot();

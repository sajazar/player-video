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
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'legacy_notice' ) );
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

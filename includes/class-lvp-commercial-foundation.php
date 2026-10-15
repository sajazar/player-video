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
		echo '<tr><th><label for="lvp_product_ids">شناسه محصول ووکامرس</label></th><td><input class="regular-text" id="lvp_product_ids" name="lvp_product_ids" type="text" placeholder="مثلاً 123 یا 123,456"><p class="description">اختیاری؛ فقط شناسه‌های معتبر محصول پذیرفته می‌شوند. کنترل دسترسی خرید در مرحله بعد اضافه می‌شود.</p></td></tr></table>';
		echo '<p><button class="button button-primary" type="submit">ذخیره در فضای خصوصی</button></p></form>';

		global $wpdb;
		$table = $wpdb->prefix . 'lvp_videos';
		$videos = $wpdb->get_results( "SELECT id,title,source_path,product_ids,status,conversion_status,created_at FROM {$table} ORDER BY id DESC LIMIT 100" );
		echo '<h2>ویدئوهای ثبت‌شده</h2><table class="widefat striped"><thead><tr><th>شناسه</th><th>عنوان</th><th>فایل خصوصی</th><th>محصول‌ها</th><th>وضعیت</th><th>تبدیل</th><th>تاریخ</th></tr></thead><tbody>';
		if ( $videos ) {
			foreach ( $videos as $video ) {
				echo '<tr><td>' . absint( $video->id ) . '</td><td>' . esc_html( $video->title ) . '</td><td><code>' . esc_html( $video->source_path ) . '</code></td><td>' . esc_html( (string) $video->product_ids ) . '</td><td>' . esc_html( $video->status ) . '</td><td>' . esc_html( $video->conversion_status ) . '</td><td>' . esc_html( $video->created_at ) . '</td></tr>';
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

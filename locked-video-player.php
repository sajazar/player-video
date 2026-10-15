<?php
/*
Plugin Name: پلیر هوشمند ویدئو دوره ها
Description: پلیر ویدئویی سفارشی امن با کنترل‌های کامل، قفل ترتیبی، پرش زمانی، نشانگر لودینگ و اسکرول خودکار به ویدئوی بعدی.
Version: 60.0
Author: چهارده خورشید
Text Domain: locked-video-player
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Commercial V1 foundation. The legacy shortcode remains for backward compatibility;
// do not treat a public MP4 URL as protected streaming.
$lvp_commercial_module = plugin_dir_path( __FILE__ ) . 'includes/class-lvp-commercial-foundation.php';
if ( file_exists( $lvp_commercial_module ) ) {
	require_once $lvp_commercial_module;
}

/**
 * Register front-end assets.
 */
function lvp_enqueue_assets() {
	wp_enqueue_style( 'lvp-style', plugin_dir_url( __FILE__ ) . 'assets/style.css', array(), '60.1' );
	wp_enqueue_script( 'lvp-script', plugin_dir_url( __FILE__ ) . 'assets/script.js', array( 'jquery' ), '60.1', true );
	wp_localize_script( 'lvp-script', 'lvp_vars', array( 'postID' => get_the_ID() ) );
}
add_action( 'wp_enqueue_scripts', 'lvp_enqueue_assets' );

/**
 * Legacy shortcode. This still uses the provided URL directly; secure HLS playback
 * will replace this path only after the server-side streaming pipeline is complete.
 */
function lvp_shortcode( $atts ) {
	$a = shortcode_atts(
		array(
			'url' => '',
			'controls' => 'true',
			'download' => 'true',
			'locked' => 'true',
			'timeline_lock' => 'true',
		),
		$atts,
		'locked_video'
	);

	if ( empty( $a['url'] ) ) {
		return '<p style="color:red">ویدئو مشخص نشده است.</p>';
	}

	static $counter = 0;
	$counter++;
	$id = (string) $counter;
	$prev_id = $counter > 1 ? (string) ( $counter - 1 ) : '';
	$use_custom_controls = ( 'true' === $a['controls'] );
	$controls_attr = $use_custom_controls ? '' : 'controls';

	ob_start();
	?>
	<div class="lvp-wrapper lvp-player"
		data-lvp-id="<?php echo esc_attr( $id ); ?>"
		<?php if ( $prev_id ) { echo 'data-prev-id="' . esc_attr( $prev_id ) . '"'; } ?>
		data-locked="<?php echo esc_attr( $a['locked'] ); ?>"
		data-timeline-lock="<?php echo esc_attr( $a['timeline_lock'] ); ?>"
		data-download="<?php echo esc_attr( $a['download'] ); ?>">
		<div class="lvp-video-box">
			<video class="lvp-video" <?php echo $controls_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> preload="metadata" playsinline oncontextmenu="return false;">
				<source src="<?php echo esc_url( $a['url'] ); ?>">
				مرورگر شما از پخش ویدئو پشتیبانی نمی‌کند.
			</video>
			<div class="lvp-watermark"><img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/vidlog.png' ); ?>" alt="Logo" /></div>
			<div class="lvp-loading-overlay"><div class="lvp-spinner"></div></div>
			<?php if ( 'true' === $a['locked'] ) : ?>
				<div class="lvp-locked-overlay" style="display:none;">ویدئو قفل است — ابتدا ویدئوی قبلی را کامل مشاهده کنید.</div>
			<?php endif; ?>
		</div>
		<?php if ( $use_custom_controls ) : ?>
			<div class="lvp-custom-controls">
				<div class="lvp-controls-row">
					<button class="lvp-play-button" title="پخش/توقف">&#9658;</button>
					<div class="lvp-volume-control"><button class="lvp-mute-button" title="صدا">🔊</button><input type="range" class="lvp-volume-slider" min="0" max="1" step="0.01" value="1"></div>
					<div class="lvp-time-current-wrap"><span class="lvp-time lvp-time-current">00:00</span></div>
					<div class="lvp-progress"><div class="lvp-progress-fill"></div></div>
					<span class="lvp-time lvp-time-duration">00:00</span>
					<button class="lvp-speed-button" title="سرعت پخش">عادی</button>
					<?php if ( 'false' === $a['download'] ) : ?>
						<a href="<?php echo esc_url( $a['url'] ); ?>" class="lvp-download-button" download title="دانلود ویدئو">⏬</a>
					<?php endif; ?>
					<button class="lvp-fullscreen-button" title="تمام صفحه"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"></path></svg></button>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'locked_video', 'lvp_shortcode' );

// Legacy media-button UI.
function lvp_add_media_button( $context ) {
	echo '<button id="lvp-insert-button" type="button" class="button"><span class="dashicons dashicons-video-alt3" style="vertical-align:top"></span> درج ویدئو دوره</button>';
}
add_action( 'media_buttons', 'lvp_add_media_button' );

function lvp_add_standalone_modal_and_script_early() {
	if ( ! is_admin() ) {
		return;
	}
	?>
	<style>
	#lvp-insert-button{margin:0 5px;font-size:14px;cursor:pointer;background:#2271b1;color:#fff;padding:4px 10px;border-radius:3px;border:0;line-height:24px;vertical-align:top}
	#lvp-custom-modal{display:none;position:fixed;z-index:10000000;inset:0;overflow:auto;background:rgba(0,0,0,.7);justify-content:center;align-items:center}
	#lvp-modal-content{background:#fff;padding:30px;border-radius:12px;width:90%;max-width:450px}
	#lvp-modal-content label{display:block;margin-top:15px;font-weight:500}
	#lvp-modal-content input[type=text]{width:95%;padding:10px;margin-top:5px}
	.lvp-button-container{margin-top:30px;display:flex;justify-content:flex-end;gap:10px}
	</style>
	<div id="lvp-custom-modal"><div id="lvp-modal-content">
	<h2>درج ویدئو دوره قفل شده</h2>
	<label for="lvp_url">آدرس ویدئو:</label><input type="text" id="lvp_url" placeholder="آدرس مستقیم فایل ویدئو (با https://)">
	<label><input type="checkbox" id="lvp_download" checked> قفل دانلود ویدئو (عدم نمایش دکمه دانلود)</label>
	<label><input type="checkbox" id="lvp_locked" checked> فعال کردن قفل مشاهده ترتیبی</label>
	<label><input type="checkbox" id="lvp_timeline_lock" checked> قفل نوار پیشرفت</label>
	<div class="lvp-button-container"><button id="lvp_insert_btn" class="button button-primary">درج در ویرایشگر</button><button id="lvp_cancel_btn" class="button">انصراف</button></div>
	</div></div>
	<script>
	document.addEventListener('DOMContentLoaded',function(){
		var modal=document.getElementById('lvp-custom-modal');if(!modal)return;
		var urlInput=document.getElementById('lvp_url');
		document.body.addEventListener('click',function(e){if(e.target.matches('#lvp-insert-button')||e.target.closest('#lvp-insert-button')){e.preventDefault();modal.style.display='flex';urlInput.value='';}});
		document.getElementById('lvp_cancel_btn').addEventListener('click',function(){modal.style.display='none';});
		modal.addEventListener('click',function(e){if(e.target.id==='lvp-custom-modal')modal.style.display='none';});
		document.getElementById('lvp_insert_btn').addEventListener('click',function(){
			var url=urlInput.value;if(!url){alert('آدرس ویدئو وارد نشده');return;}
			var download=document.getElementById('lvp_download').checked?'true':'false';
			var locked=document.getElementById('lvp_locked').checked?'true':'false';
			var timeline=document.getElementById('lvp_timeline_lock').checked?'true':'false';
			var shortcode='[locked_video url="'+url.replace(/"/g,'&quot;')+'" controls="true" download="'+download+'" locked="'+locked+'" timeline_lock="'+timeline+'"]';
			if(typeof tinyMCE!=='undefined'&&tinyMCE.activeEditor&&!tinyMCE.activeEditor.isHidden()){tinyMCE.activeEditor.execCommand('mceInsertContent',false,shortcode);}
			else{var textarea=document.getElementById('content');if(textarea)textarea.value+='\n'+shortcode+'\n';}
			modal.style.display='none';urlInput.value='';
		});
	});
	</script>
	<?php
}
add_action( 'admin_print_footer_scripts', 'lvp_add_standalone_modal_and_script_early', 10 );

<?php
/*
Plugin Name: پلیر هوشمند ویدئو دوره ها
Description: پلیر ویدئویی سفارشی امن با کنترل‌های کامل، قفل ترتیبی، پرش زمانی، نشانگر لودینگ و اسکرول خودکار به ویدئوی بعدی.
Version: 60.0 
Author: چهارده خورشید
Text Domain: locked-video-player
*/

if(!defined('ABSPATH')) exit;

/**
 * ۱. ثبت و بارگذاری فایل‌های CSS و JS
 * از تابع wp_enqueue_scripts برای بارگذاری مناسب در فرانت‌اند استفاده می‌شود.
 */
function lvp_enqueue_assets() {
    // بارگذاری فایل استایل
    wp_enqueue_style( 
        'lvp-style', 
        plugin_dir_url(__FILE__) . 'assets/style.css', 
        array(), 
        '60.0' 
    );

    // بارگذاری فایل جاوااسکریپت
    wp_enqueue_script(
        'lvp-script',
        plugin_dir_url(__FILE__) . 'assets/script.js',
        array('jquery'), // اطمینان از بارگذاری jQuery اگر نیاز باشد (اگرچه از Vanilla JS استفاده شده)
        '60.0',
        true // بارگذاری در فوتر
    );

    // ارسال متغیرهای PHP به فایل جاوااسکریپت (Localization)
    // این روش امن‌ترین راه برای ارسال اطلاعات داینامیک (مثل ID نوشته) به JS است.
    wp_localize_script( 'lvp-script', 'lvp_vars', array(
        'postID' => get_the_ID()
    ));
}
add_action('wp_enqueue_scripts','lvp_enqueue_assets');


/**
 * ۲. شورت‌کد [locked_video] 
 */
function lvp_shortcode($atts){
    $a = shortcode_atts(array(
        'url'=>'',
        'controls'=>'true', 
        'download'=>'true',
        'locked'=>'true',
        'timeline_lock'=>'true' 
    ), $atts);

    if(empty($a['url'])) return '<p style="color:red">ویدئو مشخص نشده است.</p>';

    // استفاده از یک شمارنده ثابت برای تولید ID منحصر به فرد در هر پست
    static $counter=0;
    $counter++;
    $id = (string)$counter;
    $prevId = $counter>1 ? (string)($counter-1) : '';

    $useCustomControls = ($a['controls']==='true'); 
    $controlsAttr = $useCustomControls ? '' : 'controls'; 

    ob_start(); ?>
<div class="lvp-wrapper lvp-player" 
    data-lvp-id="<?php echo esc_attr($id); ?>" 
    <?php if($prevId) echo 'data-prev-id="'.esc_attr($prevId).'"';?> 
    data-locked="<?php echo esc_attr($a['locked']); ?>"
    data-timeline-lock="<?php echo esc_attr($a['timeline_lock']); ?>"
    data-download="<?php echo esc_attr($a['download']); ?>"> 

    <div class="lvp-video-box">
        <video class="lvp-video" <?php echo $controlsAttr;?> preload="metadata" playsinline oncontextmenu="return false;">
            <source src="<?php echo esc_url($a['url']); ?>">
            مرورگر شما از پخش ویدئو پشتیبانی نمی‌کند.
        </video>
        
        <div class="lvp-watermark">
             <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/vidlog.png'; ?>" alt="Logo" />
        </div>
        
        <div class="lvp-loading-overlay"><div class="lvp-spinner"></div></div>
        
        <?php if($a['locked']==='true'){ ?>
        <div class="lvp-locked-overlay" style="display:none;">ویدئو قفل است — ابتدا ویدئوی قبلی را کامل مشاهده کنید.</div>
        <?php } ?>
    </div>
    <?php if ($useCustomControls) { ?>
        <div class="lvp-custom-controls"> 
            
            <div class="lvp-controls-row">
                <button class="lvp-play-button" title="پخش/توقف">&#9658;</button> 
                
                <div class="lvp-volume-control">
                    <button class="lvp-mute-button" title="صدا">🔊</button>
                    <input type="range" class="lvp-volume-slider" min="0" max="1" step="0.01" value="1">
                </div>
                
                <div class="lvp-time-current-wrap">
                    <span class="lvp-time lvp-time-current">00:00</span>
                </div>
                
                <div class="lvp-progress">
                    <div class="lvp-progress-fill"></div>
                </div>
                
                <span class="lvp-time lvp-time-duration">00:00</span>

                <button class="lvp-speed-button" title="سرعت پخش">عادی</button>
                
                <?php 
                // توجه: اگر download='false' باشد، لینک دانلود نمایش داده می شود.
                // اگر download='true' باشد، دکمه دانلود پنهان است.
                if($a['download'] === 'false'): 
                ?>
                    <a href="<?php echo esc_url($a['url']); ?>" class="lvp-download-button" download title="دانلود ویدئو">⏬</a>
                <?php endif; ?>

                <button class="lvp-fullscreen-button" title="تمام صفحه">
                    <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                        <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"></path>
                    </svg>
                </button>
            </div>
        </div>
    <?php } ?>
    
</div>
<?php
    return ob_get_clean();
}
add_shortcode('locked_video','lvp_shortcode');

// **********************************************************
// ۳. بخش ادمین: دکمه و پاپ‌آپ (Modal & Button Logic)
// **********************************************************

function lvp_add_media_button($context) {
    // از یک دکمه ساده برای باز کردن Modal استفاده می کند
    $button_html = '<button id="lvp-insert-button" type="button" class="button"><span class="dashicons dashicons-video-alt3" style="vertical-align: top;"></span> درج ویدئو دوره</button>';
    echo $button_html;
}
add_action('media_buttons', 'lvp_add_media_button');

function lvp_add_standalone_modal_and_script_early() {
    if (!is_admin()) return;

    // استایل‌های Modal (بهتر است اینجا بماند تا با CSS اصلی قاطی نشود)
    ?>
    <style>
        #lvp-insert-button { margin-left: 5px; margin-right: 5px; font-size: 14px; cursor: pointer; background: #2271b1; color: #fff; padding: 4px 10px; border-radius: 3px; border: none; line-height: 24px; vertical-align: top; display: inline-block; transition: background 0.2s; }
        #lvp-insert-button:hover { background: #17538a; }
        #lvp-custom-modal { display: none; position: fixed; z-index: 10000000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.7); justify-content: center; align-items: center; }
        #lvp-modal-content { background-color: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); width: 90%; max-width: 450px; animation: fadeIn 0.3s ease-out; transform: translateY(0); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        #lvp-modal-content h2 { font-size: 22px; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; color: #333; font-weight: 600; text-align: right; }
        #lvp-modal-content label { display: block; margin-top: 15px; font-weight: 500; color: #555; text-align: right; }
        #lvp-modal-content input[type="text"] { width: 95%; padding: 10px; margin-top: 5px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; transition: border-color 0.2s; text-align: right; }
        #lvp-modal-content input[type="text"]:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
        #lvp-modal-content input[type="checkbox"] { margin-left: 8px; transform: scale(1.1); }
        .lvp-button-container { margin-top: 30px; display: flex; justify-content: flex-end; gap: 10px; }
        #lvp_insert_btn { background-color: #2271b1; border-color: #2271b1; color: #fff; padding: 8px 20px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background-color 0.2s, border-color 0.2s; }
        #lvp_insert_btn:hover { background-color: #17538a; border-color: #17538a; }
        #lvp_cancel_btn { padding: 8px 20px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; background-color: #f0f0f1; border-color: #ddd; color: #333; transition: background-color 0.2s; }
        #lvp_cancel_btn:hover { background-color: #e4e4e4; }
    </style>

    <div id="lvp-custom-modal"> 
        <div id="lvp-modal-content">
            <h2><span class="dashicons dashicons-video-alt3"></span> درج ویدئو دوره قفل شده</h2>
            <label for="lvp_url">آدرس ویدئو:</label>
            <input type="text" id="lvp_url" placeholder="آدرس مستقیم فایل ویدئو (با https://)">
            
            <label>
                <input type="checkbox" id="lvp_download" checked> **قفل دانلود ویدئو (عدم نمایش دکمه دانلود)**
            </label>
            <label>
                <input type="checkbox" id="lvp_locked" checked> **فعال کردن قفل مشاهده ترتیبی (باز شدن ویدئوی بعدی)**
            </label>
            <label>
                <input type="checkbox" id="lvp_timeline_lock" checked> **قفل نوار پیشرفت (جلو بردن زمان ویدئو)**
            </label>
            
            <div class="lvp-button-container">
                <button id="lvp_insert_btn" class="button button-primary">درج در ویرایشگر</button>
                <button id="lvp_cancel_btn" class="button">انصراف</button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var modal = document.getElementById('lvp-custom-modal');
        if (!modal) return; 

        var urlInput = document.getElementById('lvp_url');
        var insertBtn = document.getElementById('lvp_insert_btn');
        var cancelBtn = document.getElementById('lvp_cancel_btn');
        var downloadCheck = document.getElementById('lvp_download');
        var lockedCheck = document.getElementById('lvp_locked');
        var timelineLockCheck = document.getElementById('lvp_timeline_lock'); 

        document.body.addEventListener('click', function(e) {
            if (e.target.matches('#lvp-insert-button') || e.target.closest('#lvp-insert-button')) {
                e.preventDefault(); 
                e.stopPropagation();
                modal.style.display = 'flex'; 
                urlInput.value = ''; 
            }
        });

        if (cancelBtn) {
            cancelBtn.addEventListener('click', function() {
                modal.style.display = 'none';
            });
        }
        
        modal.addEventListener('click', function(e) {
            if (e.target.id === 'lvp-custom-modal') {
                modal.style.display = 'none';
            }
        });

        if (insertBtn) {
            insertBtn.addEventListener('click', function() {
                var url = urlInput.value;
                if (!url) { alert('آدرس ویدئو وارد نشده'); return; }
                
                // توجه: download='true' در اینجا به معنی پنهان کردن دکمه دانلود است
                var download = downloadCheck.checked ? 'true' : 'false'; 
                var locked = lockedCheck.checked ? 'true' : 'false'; 
                var timelineLock = timelineLockCheck.checked ? 'true' : 'false'; 

                var shortcode = '[locked_video url="' + url + '" controls="true" download="' + download + '" locked="' + locked + '" timeline_lock="' + timelineLock + '"]'; 

                if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden()) {
                    tinyMCE.activeEditor.execCommand('mceInsertContent', false, shortcode);
                } else {
                    var textarea = document.getElementById('content');
                    if (textarea) textarea.value += "\n" + shortcode + "\n";
                    
                    var elementorTextarea = document.querySelector('textarea.elementor-control-default:focus');
                    if (elementorTextarea) elementorTextarea.value += "\n" + shortcode + "\n";
                }
                
                modal.style.display = 'none';
                urlInput.value = ''; 
            });
        }
    });
    </script>
    <?php
}
add_action('admin_print_footer_scripts', 'lvp_add_standalone_modal_and_script_early', 10);
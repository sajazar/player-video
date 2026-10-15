// مطمئن می شویم که DOM کاملا بارگذاری شده است
document.addEventListener('DOMContentLoaded', function(){
    // متغیرهای سراسری از PHP (wp_localize_script)
    // lvp_vars.postID حاوی ID نوشته وردپرس است
    var postID = typeof lvp_vars !== 'undefined' ? lvp_vars.postID : '0';
    var players = document.querySelectorAll('.lvp-player');

    // جلوگیری از راست کلیک (با استفاده از contextmenu)
    document.addEventListener('contextmenu', function(e){
        e.preventDefault();
    });

    // تابع کمکی برای فرمت زمان (مثلاً 1:30 به 01:30)
    function formatTime(seconds) {
        if(isNaN(seconds) || seconds < 0) return "00:00";
        var date = new Date(null);
        date.setSeconds(seconds);
        var time = date.toISOString().substr(11, 8);
        if (seconds < 3600) {
            time = time.substr(3); 
        }
        return time.replace(/^0/, ''); 
    }
    
    /**
     * تابع اسکرول نرم به عنصر بعدی
     * @param {HTMLElement} nextPlayerElement - کانتینر ویدئوی بعدی
     */
    function scrollToNextPlayer(nextPlayerElement) {
        if (!nextPlayerElement) return;

        // محاسبه موقعیت اسکرول با در نظر گرفتن نوار ابزار بالای صفحه
        const offset = 80; 
        const elementPosition = nextPlayerElement.getBoundingClientRect().top;
        const offsetPosition = elementPosition + window.pageYOffset - offset;

        window.scrollTo({
            top: offsetPosition,
            behavior: "smooth"
        });
    }

    /**
     * باز کردن قفل و اسکرول به ویدئوی بعدی
     * @param {string} currentId - آی دی ویدئویی که تازه تمام شده است.
     */
    function unlockNext(currentId){
        let nextPlayer = null;
        players.forEach(function(p){
            var prev = p.getAttribute('data-prev-id');
            if(prev && prev === currentId){
                p.classList.remove('lvp-next-locked');
                var ov = p.querySelector('.lvp-locked-overlay');
                if(ov) ov.style.display = 'none';
                nextPlayer = p; // پیدا کردن عنصر ویدئوی بعدی
            }
        });
        
        // اگر ویدئوی بعدی پیدا شد، اسکرول را انجام بده
        if (nextPlayer) {
            setTimeout(() => {
                scrollToNextPlayer(nextPlayer);
            }, 500); // تاخیر 500 میلی ثانیه ای برای نمایش تغییر
        }
    }

    // پیمایش روی تمام پلیرهای ویدئویی در صفحه
    players.forEach(function(p){
        var video = p.querySelector('video');
        if(!video) return;

        var idxId = p.getAttribute('data-lvp-id');
        var prev = p.getAttribute('data-prev-id');
        var storageKey = 'lvp_' + postID + '_' + idxId;
        var prevKey = prev ? 'lvp_' + postID + '_' + prev : null;

        var maxSeen = 0;
        var tolerance = 1.0; 
        
        var overlay = p.querySelector('.lvp-locked-overlay');
        var loadingOverlay = p.querySelector('.lvp-loading-overlay'); 
        var isLocked = p.getAttribute('data-locked') === 'true'; 
        var isTimelineLocked = p.getAttribute('data-timeline-lock') === 'true'; 

        var isDragging = false; 
        var wasPlayingBeforeDrag = false; 

        // ارجاعات به کنترل‌های سفارشی
        var customControls = p.querySelector('.lvp-custom-controls');
        var playBtn = p.querySelector('.lvp-play-button');
        var progressContainer = p.querySelector('.lvp-progress');
        var progressBar = p.querySelector('.lvp-progress-fill');
        var timeCurrent = p.querySelector('.lvp-time-current');
        var timeDuration = p.querySelector('.lvp-time-duration');
        var muteBtn = p.querySelector('.lvp-mute-button');
        var volumeSlider = p.querySelector('.lvp-volume-slider');
        var speedButton = p.querySelector('.lvp-speed-button');
        var fullScreenBtn = p.querySelector('.lvp-fullscreen-button'); 
        
        var durationReady = false;
        function setDurationOnce(){
            if(durationReady || isNaN(video.duration) || video.duration <= 0) return;
            if(timeDuration){
                 timeDuration.innerText = formatTime(video.duration);
                 durationReady = true;
            }
        }
        
        function showLoading() {
            if(loadingOverlay) loadingOverlay.style.display = 'flex';
        }
        function hideLoading() {
            if(loadingOverlay) loadingOverlay.style.display = 'none';
        }

        // *** ۱. منطق قفل ترتیبی ***
        if(isLocked && prevKey && localStorage.getItem(prevKey) !== 'unlocked'){
            p.classList.add('lvp-next-locked');
            if(overlay){
                 overlay.innerText = 'این ویدئو قفل است — ابتدا ویدئوی قبلی را کامل مشاهده کنید.';
                 overlay.style.display = 'flex'; 
            }
            try{ video.pause(); }catch(e){}
        } else {
            p.classList.remove('lvp-next-locked');
            if(overlay) overlay.style.display = 'none';
        }
        
        video.addEventListener('play', function(e){ 
            if(isLocked && p.classList.contains('lvp-next-locked')){
                 try { video.pause(); } catch(err){}
                 e.preventDefault();
                 e.stopPropagation();
            }
        }, true);
        
        // **********************************************************
        // *** ۲. منطق کنترل‌های سفارشی و پرش زمانی (Seeking Logic) ***
        // **********************************************************

        video.addEventListener('click', function() {
            if (video.paused || video.ended) {
                video.play();
            } else {
                video.pause();
            }
        });

        if(customControls){
            // Play/Pause - دکمه کنترلی
            video.addEventListener('play', function() { playBtn.innerHTML = '&#9632;'; });
            video.addEventListener('pause', function() { playBtn.innerHTML = '&#9658;'; });
            playBtn.addEventListener('click', function() {
                if (isLocked && p.classList.contains('lvp-next-locked')) return; 
                if (video.paused || video.ended) {
                    video.play();
                } else {
                    video.pause();
                }
            });

            // Volume Control (کامل)
            var lastVolume = video.volume;
            if(volumeSlider) volumeSlider.value = video.volume; 
            
            if(muteBtn){
                muteBtn.innerHTML = (video.volume > 0 && !video.muted) ? '🔊' : '🔇';
                
                muteBtn.addEventListener('click', function(){
                    if(video.muted){
                        video.muted = false;
                        video.volume = lastVolume > 0 ? lastVolume : 0.5;
                        muteBtn.innerHTML = '🔊';
                    } else {
                        lastVolume = video.volume;
                        video.muted = true;
                        muteBtn.innerHTML = '🔇';
                    }
                    if(volumeSlider) volumeSlider.value = video.volume;
                });
            }
            
            if(volumeSlider){
                volumeSlider.addEventListener('input', function(){
                    video.volume = this.value;
                    video.muted = false;
                    lastVolume = this.value;
                    if(muteBtn) muteBtn.innerHTML = (this.value > 0) ? '🔊' : '🔇';
                });
                video.addEventListener('volumechange', function(){
                    if(volumeSlider) volumeSlider.value = video.volume;
                    if(muteBtn) muteBtn.innerHTML = (video.volume > 0 && !video.muted) ? '🔊' : '🔇';
                });
            }

            // Speed Control (کامل)
            if(speedButton){
                const speeds = [1.0, 1.5, 2.0]; 
                const speedNames = ["عادی", "تند", "تندتر"];
                let currentSpeedIndex = 0;
                
                video.playbackRate = speeds[currentSpeedIndex];
                speedButton.innerText = speedNames[currentSpeedIndex]; 
                speedButton.setAttribute('title', speedNames[currentSpeedIndex] + ' (' + speeds[currentSpeedIndex] + 'x)');
                
                speedButton.addEventListener('click', function(){
                    currentSpeedIndex = (currentSpeedIndex + 1) % speeds.length;
                    const newRate = speeds[currentSpeedIndex];
                    
                    video.playbackRate = newRate;
                    speedButton.innerText = speedNames[currentSpeedIndex];
                    speedButton.setAttribute('title', speedNames[currentSpeedIndex] + ' (' + newRate + 'x)');
                });
            }
            
            // ** Full-Screen Logic (with Orientation Lock Attempt) **
            if(fullScreenBtn){
                const fsIcon = fullScreenBtn.querySelector('svg');
                // SVG Paths (Material Icons Full Screen)
                const enterFSPath = "M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z";
                const exitFSPath = "M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm7 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z";
                
                // مقداردهی اولیه آیکون
                if(fsIcon) fsIcon.innerHTML = `<path d="${enterFSPath}"></path>`;
                
                function toggleFullScreen() {
                    var container = p; 
                    
                    if (!document.fullscreenElement) {
                        // 1. ورود به تمام صفحه
                        if (container.requestFullscreen) {
                            container.requestFullscreen();
                        } else if (container.mozRequestFullScreen) { 
                            container.mozRequestFullScreen();
                        } else if (container.webkitRequestFullscreen) { 
                            container.webkitRequestFullscreen();
                        } else if (container.msRequestFullscreen) { 
                            container.msRequestFullscreen();
                        }
                        
                        // 2. تلاش برای چرخش به افقی (Landscape)
                        if (screen.orientation && screen.orientation.lock) {
                            screen.orientation.lock('landscape').catch(function(error) {
                                console.warn("Orientation lock failed (User must disable rotation lock or browser restricts it):", error);
                            });
                        }

                    } else {
                        // 1. خروج از تمام صفحه
                        if (document.exitFullscreen) {
                            document.exitFullscreen();
                        } else if (document.mozCancelFullScreen) { 
                            document.mozCancelFullScreen();
                        } else if (document.webkitExitFullscreen) { 
                            document.webkitExitFullscreen();
                        } else if (document.msExitFullscreen) { 
                            document.msExitFullscreen();
                        }

                        // 2. تلاش برای بازگرداندن قفل چرخش به حالت پیش‌فرض
                        if (screen.orientation && screen.orientation.unlock) {
                            screen.orientation.unlock(); 
                        }
                    }
                }

                fullScreenBtn.addEventListener('click', toggleFullScreen);
                
                // به‌روزرسانی آیکون تمام صفحه هنگام تغییر وضعیت
                document.addEventListener('fullscreenchange', function(){
                    if (document.fullscreenElement && document.fullscreenElement === p) {
                        // در حالت تمام صفحه: تغییر به آیکون خروج
                        if(fsIcon) fsIcon.innerHTML = `<path d="${exitFSPath}"></path>`;
                        fullScreenBtn.title = 'خروج از تمام صفحه';
                    } else {
                        // در حالت عادی: تغییر به آیکون ورود
                        if(fsIcon) fsIcon.innerHTML = `<path d="${enterFSPath}"></path>`;
                        fullScreenBtn.title = 'تمام صفحه';
                    }
                });
                // اضافه کردن شنونده‌های رویداد برای مرورگرهای مختلف
                document.addEventListener('webkitfullscreenchange', function(){ document.dispatchEvent(new Event('fullscreenchange')); });
                document.addEventListener('mozfullscreenchange', function(){ document.dispatchEvent(new Event('fullscreenchange')); });
                document.addEventListener('msfullscreenchange', function(){ document.dispatchEvent(new Event('fullscreenchange')); });
            }

            // ** Timeline Lock / Click and Dragging Logic **
            if (isTimelineLocked) {
                progressContainer.classList.add('lvp-timeline-lock-active'); 
            } else if (progressContainer) {
                
                function calculateTimeFromEvent(e) {
                    if (!video.duration) return null;
                    var rect = progressContainer.getBoundingClientRect();
                    
                    var clientX = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : null); 
                    if (clientX === null) return null;

                    var clickX = clientX - rect.left; 
                    var width = rect.width;
                    
                    var percent = clickX / width; 
                    percent = Math.max(0, Math.min(1, percent));
                    
                    return video.duration * percent; 
                }

                progressContainer.addEventListener('mousedown', handleTimelineStart);
                progressContainer.addEventListener('touchstart', handleTimelineStart);

                function handleTimelineStart(e){
                    if(e.type === 'mousedown' && e.button !== 0) return; 
                    isDragging = true;
                    wasPlayingBeforeDrag = !video.paused; 
                    
                    if(wasPlayingBeforeDrag) video.pause(); 
                    
                    var newTime = calculateTimeFromEvent(e);
                    if (newTime !== null) {
                        video.currentTime = newTime;
                        showLoading();
                    }
                }
                
                document.addEventListener('mousemove', handleTimelineMove);
                document.addEventListener('touchmove', handleTimelineMove);
                
                function handleTimelineMove(e){
                    if (!isDragging) return;
                    e.preventDefault(); 

                    var newTime = calculateTimeFromEvent(e);
                    if (newTime !== null) {
                        video.currentTime = newTime;
                        
                        var percent = (newTime / video.duration) * 100;
                        progressBar.style.width = percent + '%';
                        if(timeCurrent) timeCurrent.innerText = formatTime(newTime);
                    }
                }
                
                document.addEventListener('mouseup', handleTimelineEnd);
                document.addEventListener('touchend', handleTimelineEnd);
                
                function handleTimelineEnd(e){
                    if (!isDragging) return;
                    
                    if(progressBar && video.duration){
                        var percent = (video.currentTime / video.duration) * 100;
                        progressBar.style.width = percent + '%';
                    }
                    
                    if(wasPlayingBeforeDrag) {
                         video.play().catch(function(err){ 
                            console.warn("Autoplay failed after drag end:", err);
                         });
                    }
                    
                    isDragging = false;
                    wasPlayingBeforeDrag = false; 
                }

                progressContainer.addEventListener('click', function(e){
                    if(!isDragging){ 
                        var newTime = calculateTimeFromEvent(e);
                        if (newTime !== null) {
                            video.currentTime = newTime; 
                            showLoading();
                            video.play().catch(function(err){ 
                                console.warn("Autoplay failed after seek:", err);
                            });
                        }
                    }
                });
            }
        }
        
        // *** ۳. منطق لودینگ، زمان، و قفل پرش (Universal) ***
        
        video.addEventListener('seeking', showLoading);

        video.addEventListener('seeked', function(){
            hideLoading();
            
            if (!isDragging && !video.paused) {
                 video.play().catch(function(err){ 
                    console.warn("Autoplay failed after seek:", err);
                 });
            }
        });
        
        video.addEventListener('loadedmetadata', setDurationOnce);

        video.addEventListener('timeupdate', function(){
            if(!durationReady) setDurationOnce();

            if(!isNaN(video.duration)){
                if(customControls && !isDragging){ 
                    if(timeCurrent) timeCurrent.innerText = formatTime(video.currentTime);
                    if(progressBar){
                        var percent = (video.currentTime / video.duration) * 100;
                        progressBar.style.width = percent + '%'; 
                    }
                }
                
                if(isTimelineLocked) maxSeen = Math.max(maxSeen, Math.floor(video.currentTime));
            }
        });

        // جلوگیری از پرش با بازگرداندن زمان (لایه امنیتی نهایی) - فقط در حالت قفل
        video.addEventListener('seeking', function(){
            if(isTimelineLocked){ 
                if(video.currentTime > (maxSeen + tolerance)){
                    video.currentTime = maxSeen; 
                    video.pause(); 
                }
            }
        });
        
        // *** رویداد ENDED: باز کردن قفل و اسکرول ***
        video.addEventListener('ended', function(){
            if(isLocked) localStorage.setItem(storageKey,'unlocked');
            unlockNext(idxId); // فراخوانی تابع با قابلیت اسکرول
        });
        
        if(prevKey && isLocked && localStorage.getItem(prevKey) === 'unlocked'){
            p.classList.remove('lvp-next-locked');
            if(overlay) overlay.style.display = 'none';
        }
    });
});
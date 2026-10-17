(function () {
    'use strict';

    var KEY = 'rtk_cookie_consent_v1';
    var banner = document.getElementById('cookie-banner');
    if (!banner) return;

    var acceptBtn  = document.getElementById('cookie-banner-accept');
    var declineBtn = document.getElementById('cookie-banner-decline');

    var stored = null;
    try { stored = window.localStorage.getItem(KEY); } catch (e) { stored = null; }

    if (!stored) {
        setTimeout(function () { banner.classList.add('is-visible'); }, 400);
    }

    function close(value) {
        try { window.localStorage.setItem(KEY, value); } catch (e) {}
        banner.style.opacity = '0';
        banner.style.transform = 'translateY(12px)';
        setTimeout(function () { banner.classList.remove('is-visible'); }, 220);
    }

    acceptBtn.addEventListener('click', function () { close('accepted'); });
    declineBtn.addEventListener('click', function () { close('essential_only'); });
})();
(function () {
    var phoneMax = 768;
    var desktopMin = 1024;
    var w = window.innerWidth || document.documentElement.clientWidth || 0;
    var viewport = w < phoneMax ? 'phone' : (w < desktopMin ? 'tablet' : 'desktop');
    document.cookie = 'tp_viewport=' + viewport + ';path=/;max-age=31536000;SameSite=Lax';

    var layoutCookie = (document.cookie.split('; ').find(function (r) {
        return r.indexOf('tp_floor_layout=') === 0;
    }) || '').split('=')[1] || null;
    var floorShell = viewport === 'phone' || (viewport === 'tablet' && layoutCookie !== 'desktop');

    if (floorShell && document.body) {
        document.body.classList.add('tp-floor-shell-page');
    } else if (floorShell) {
        document.addEventListener('DOMContentLoaded', function () {
            document.body.classList.add('tp-floor-shell-page');
        });
    }
})();

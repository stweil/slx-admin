
// Mouseover and clicking

var boldItem = false;

function showmod(e, action) {
    var list = $(e).attr('data-modlist');
    list = list.split(',');
    if (action === 'bold') {
        $(boldItem).removeClass("slx-bold");
        if (boldItem === e) {
            action = 'fade';
            boldItem = false;
        }
    } else if (boldItem !== false) {
        return;
    }
    $('.modrow').each(function () {
        var elem = $(this);
        elem.removeClass("slx-fade slx-bold");
        if (action === 'reset')
            return;
        if (action === 'bold' && list.indexOf(elem.attr('data-id')) !== -1)
            elem.addClass("slx-bold");
        if (list.indexOf(elem.attr('data-id')) === -1)
            elem.addClass("slx-fade");
    });
    if (action === 'bold') {
        boldItem = e;
        $(e).addClass("slx-bold");
    }
}

// Polling for updated status (outdated, missing, ok)

var statusChecks = 0;

function checkBuildStatus() {
    var mods = [];
    var confs = [];
    $(".refmod.btn-primary").each(function (index) {
        mods.push($(this).val());
    });
    $(".refconf.btn-primary").each(function (index) {
        confs.push($(this).val());
    });
    if (mods.length === 0 && confs.length === 0) return;
    if (++statusChecks < 10) setTimeout(checkBuildStatus, 200 + 50 * statusChecks);
    $.post('?do=SysConfig', { mods: mods.join(), confs: confs.join(), token: TOKEN, action: 'status' }, function (data) {
        if (typeof data === 'undefined') return;
        if (typeof data.mods === 'object') updateButtonColor($(".refmod.btn-primary"), data.mods);
        if (typeof data.confs === 'object') updateButtonColor($(".refconf.btn-primary"), data.confs);
    }, 'json');
}

function updateButtonColor(list,ids) {
    list.each(function() {
        if (ids.indexOf($(this).val()) >= 0) $(this).removeClass('btn-primary').addClass('btn-default');
    });
}

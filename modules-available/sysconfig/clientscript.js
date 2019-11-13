
// Mouseover and clicking

(function() {
    var boldItem = false;
    var revList = false;

    var $ct = $('#conftable').find('.confrow');
    $ct.click(function () {
        showmod(this, 'bold');
    }).mouseenter(function () {
        showmod(this, 'fade');
    }).mouseleave(function () {
        showmod(this, 'reset');
    });
    var $mt = $('#modtable').find('.modrow');
    $mt.click(function () {
        showconf(this, 'bold');
    }).mouseenter(function () {
        showconf(this, 'fade');
    }).mouseleave(function () {
        showconf(this, 'reset');
    });
    var $confirm = $('#delete-item-list');
    $('.btn-del-module').click(function() {
        if (!revList) buildRevList();
        var mid = $(this).val() + '';
        var list = revList[mid];
        if (!list || !list.length) {
            $confirm.append($msgs).addClass('hidden');
            return;
        }
        var $msgs = $confirm.find('ul').empty();
        for (var i = 0; i < list.length; ++i) {
            $msgs.append($('<li>').text(
            $('.confrow[data-id="' + list[i] + '"]').text()
            ));
        }
        $confirm.removeClass('hidden');
    });
    $('.btn-del-config').click(function() {
        $confirm.addClass('hidden');
    });

    function showpre(e, action) {
        if (boldItem && action !== 'bold') return 'reset';
        if (boldItem) {
            if (e === boldItem) action = 'fade';
            boldItem = false;
        }
        $mt.removeClass("slx-bold slx-fade");
        $ct.removeClass("slx-bold slx-fade");
        return action;
    }

    function buildRevList() {
        revList = {};
        $ct.each(function () {
            var elem = $(this);
            var cid = elem.data('id') + '';
            var list = (elem.data('modlist') + '').split(',');
            for (var i = 0; i < list.length; ++i) {
                if (!revList[list[i]]) revList[list[i]] = [];
                revList[list[i]].push(cid);
            }
        });
    }

    function showconf(e, action) {
        action = showpre(e, action);
        if (action === 'reset') return;
        var $e = $(e);
        if (!revList) buildRevList();
        var mid = $e.data('id') + '';
        var list = revList[mid];
        if (list && list.length > 0) $ct.each(function () {
            var elem = $(this);
            var cid = elem.data('id') + '';
            if (list.indexOf(cid) === -1)
                elem.addClass('slx-fade');
            else if (action === 'bold')
                elem.addClass('slx-bold');
        }); else $ct.addClass('slx-fade');
        if (action === 'bold') {
            boldItem = e;
            $e.addClass("slx-bold");
        }
    }

    function showmod(e, action) {
        action = showpre(e, action);
        if (action === 'reset') return;
        var $e = $(e);
        var list = ($e.data('modlist') + '').split(',');
        $mt.each(function () {
            var elem = $(this);
            if (list.indexOf(elem.data('id') + '') === -1)
                elem.addClass("slx-fade");
            else if (action === 'bold')
                elem.addClass("slx-bold");
        });
        if (action === 'bold') {
            boldItem = e;
            $e.addClass("slx-bold");
        }
    }
})();

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
    if (++statusChecks < 10) setTimeout(checkBuildStatus, 150 + 100 * statusChecks);
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

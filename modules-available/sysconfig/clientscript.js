
function forceTable(t)
{
    var pwidth = t.parent().innerWidth();
    var rows = t.find('tr');
    var row = rows.first();
    pwidth = Math.round(pwidth);
    t.width(pwidth);
    var sum = 0;
    row.find('td').each(function() {
        if (!$(this).hasClass('slx-width-ignore'))
            sum += $(this).outerWidth(true);
    });
    var w = Math.round(pwidth - sum);
    do {
        rows.find('.slx-dyn-ellipsis').each(function() {
            $(this).width(w).css('width', w + 'px').css('max-width', w + 'px');
        });
        w -= 3;
    } while (t.width() > pwidth);
}

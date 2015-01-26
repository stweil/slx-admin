function loadContent(elem, source)
{
	var waitForIt = function() {
		if (typeof $ === 'undefined') {
			setTimeout(waitForIt, 50);
			return;
		}
		$(elem).load(source);
	}
	waitForIt();
}

function forceTable(t)
{
	var pwidth = t.parent().innerWidth();
	t.width(pwidth - 5);
	var rows = t.find('tr');
	var sum = 0;
	rows.first().find('td').each(function (index) {
		if (!$(this).hasClass('slx-width-ignore'))
			sum += $(this).outerWidth();
	});
	var w = pwidth - (sum + 30);
	rows.find('.slx-dyn-ellipsis').each(function (index) {
		$(this).width(w).css('width', w + 'px').css('max-width', w + 'px');
	});
}
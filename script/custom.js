/*
function loadContent(elem, source)
{
	$(elem).html('<div class="progress progress-striped active"><div class="progress-bar" style="width:100%"><span class="sr-only">In Progress....</span></div></div>');
	$(elem).load(source);
}

function selectDir(obj)
{
	dirname = $(obj).parent().parent().find('td.isdir').text() + '/';
	console.log("CALLED! Dirname: " + dirname);
	$('td.fileEntry').each(function() {
		var text = $(this).text();
		if (text.length < dirname.length) return;
		if (text.substr(0, dirname.length) !== dirname) return;
		$(this).parent().find('.fileBox')[0].checked = obj.checked;
	});
}
*/

function updater(url, postdata, callback)
{
	var updateTimer = setInterval(function () {
		if (typeof $ === 'undefined')
			return;
		$.post(url, postdata, function (data, status) {
			if (!callback(data, status))
				clearInterval(updateTimer);
		}, 'json').fail(function (jqXHR, textStatus, errorThrown) {
			if (!callback(errorThrown, textStatus))
				clearInterval(updateTimer);
		});
	}, 1000);
}

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
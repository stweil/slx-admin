var tmItems = false;
var tmErrors = 0;
var TM_MAX_ERRORS = 5;

function tmInit()
{
	tmItems = $("div[data-tm-id]");
	if (tmItems.length === 0) return;
	tmItems.each(function(i, item) {
		item = $(item);
		if (item.is('[data-tm-progress]')) {
			item.append('<div class="data-tm-progress"><div class="progress"><div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%"></div></div></div>');
		}
		if (item.is('[data-tm-log]')) {
			item.append('<pre class="data-tm-log" style="display:none" />');
		}
		item.prepend('<span class="data-tm-icon" />');
	});
	setTimeout(tmUpdate, 100);
}

function tmUpdate()
{
	var active = [];
	tmItems.each(function(i, item) {
		item = $(item);
		var id = item.attr('data-tm-id');
		if (typeof id === 'undefined' || id === false || id === '') return;
		active.push(id);
	});
	if (active.length === 0) return;
	$.post('api.php?do=taskmanager', { 'ids[]' : active, token : TOKEN }, function (data, status) {
		// POST success
		if (tmResult(data, status)) {
			setTimeout(tmUpdate, 1000);
		}
	}, 'json').fail(function (jqXHR, textStatus, errorThrown) {
		// POST failure
		console.log("TaskManager Error: " + textStatus + " - " + errorThrown);
		if (++tmErrors < TM_MAX_ERRORS) setTimeout(tmUpdate, 2000);
	});
}

function tmResult(data, status)
{
	// Bail out on error
	if (typeof data.error !== 'undefined') {
		$('#mainpage').prepend($('<div class="alert alert-danger"/>').append(document.createTextNode(data.error)));
		console.log("Error ERROR");
		return false;
	}
	// No task list is also bad
	if (typeof data.tasks === 'undefined') {
		$('#mainpage').prepend('<div class="alert alert-danger">Internal Error #67452</div>');
		console.log("Error UNEXPECTED");
		return false;
	}
	var lastRunOnError = (tmErrors > TM_MAX_ERRORS);
	// Let's continue handling stuff
	var counter = 0;
	for (var idx in data.tasks) {
		var task = data.tasks[idx];
		if (!task.id) continue;
		counter++;
		if (lastRunOnError) {
			task.statusCode = 'TASK_ERROR';
		} else if (task.error) {
			++tmErrors;
			continue;
		} else if (tmErrors > 0) {
			--tmErrors;
		}
		var obj = $('div[data-tm-id="' + task.id + '"]');
		if (!obj) continue;
		if (task.statusCode !== 'TASK_WAITING' && task.statusCode !== 'TASK_PROCESSING') {
			obj.attr('data-tm-id', '');
		}
		var icon = obj.find('.data-tm-icon');
		if (icon) {
			if (typeof task.statusCode === 'undefined') {
				icon.attr('class', 'data-tm-icon glyphicon glyphicon-question-sign');
			} else if (task.statusCode === 'TASK_WAITING') {
				icon.attr('class', 'data-tm-icon glyphicon glyphicon-pause');
			} else if (task.statusCode === 'TASK_PROCESSING') {
				icon.attr('class', 'data-tm-icon glyphicon glyphicon-play');
			} else if (task.statusCode === 'TASK_FINISHED') {
				icon.attr('class', 'data-tm-icon glyphicon glyphicon-ok');
			} else if (task.statusCode === 'TASK_ERROR') {
				icon.attr('class', 'data-tm-icon glyphicon glyphicon-remove');
			} else {
				icon.attr('class', 'data-tm-icon glyphicon glyphicon-trash');
			}
		} else {
			console.log('Icon for ' + obj + ': ' + icon);
		}
		var progress = obj.find('.data-tm-progress');
		if (progress) {
			var pKey = obj.attr('data-tm-progress');
			if (task.data && task.data[pKey]) {
				tmSetProgress(progress, task.data[pKey], task.statusCode);
			} else {
				tmSetProgress(progress, false, task.statusCode);
			}
		}
		var log = obj.find('.data-tm-log');
		if (log) {
			var lKey = obj.attr('data-tm-log');
			if (task.data && task.data[lKey]) {
				log.text(task.data[lKey]);
				log.attr('style', (task.data[lKey] !== '' ? '' : 'display:none'));
			}
		}
		var cb = obj.attr('data-tm-callback');
		if (cb && window[cb]) {
			window[cb](task);
		}
	}
	if (lastRunOnError) {
		$('#mainpage').prepend($('<div class="alert alert-danger"/>').append(document.createTextNode(task.error)));
		return false;
	}
	return counter > 0;
}

function tmSetProgress(elem, percent, status)
{
	var outer = '', inner = '';
	if (status === 'TASK_PROCESSING') {
		outer = ' active';
	} else if (status === 'TASK_ERROR') {
		inner = ' progress-bar-danger';
	} else if (status === 'TASK_FINISHED') {
		inner = ' progress-bar-success';
	}
	elem.find('.progress').attr('class', 'progress progress-striped' + outer);
	var bar = elem.find('.progress-bar');
	bar.attr('class', 'progress-bar' + inner);
	if (percent !== false) {
		bar.attr('aria-valuenow', percent);
		bar.attr('style', 'width: ' + percent + '%');
	}
}

tmInit();

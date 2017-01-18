var onceOnlyGoddammit = false;
var slxModules = {};
var slxTries = {};
var slxCurrent = false;
var slxTimer = false;

function slxRunInstall(btn) {
	if (onceOnlyGoddammit)
		return;
	$(btn).prop('disabled', true);
	onceOnlyGoddammit = true;
	var first = false;
	list = $('.id-col').each(function () {
		var id = $(this).text();
		slxModules[id] = 'IDLE';
		slxTries[id] = 0;
		if (first === false) {
			first = id;
		}
	});
	if (first !== false) {
		slxRun(first);
	}
}

function makeCallback(callback, userData) {
	return function (firstParam) {
		callback(this, userData, firstParam);
	};
}

function slxRun(moduleName) {
	var dest = $('#mod-' + moduleName);
	if (dest.length !== 1 || typeof slxModules[moduleName] === 'undefined') {
		alert('No such module: ' + moduleName);
		return;
	}
	if (slxModules[moduleName] === 'IDLE' || slxModules[moduleName] === 'UPDATE_RETRY') {
		if (slxTries[moduleName]++ > 3)
			return;
		slxModules[moduleName] = 'WORKING';
		dest.text('Working.....');
		slxCancelTimer();
		slxCurrent = moduleName;
		$.post('install.php', {module: moduleName}, makeCallback(slxDone, moduleName), 'json')
			.always(makeCallback(slxTrigger, moduleName));
		slxTimer = window.setTimeout(slxWatchdog, 5000);
	}
}

var slxDone = function (elem, moduleName, jsonReply) {
	if (!jsonReply) {
		jsonReply = {};
	}
	if (!jsonReply.status) {
		jsonReply.status = 'UPDATE_FAILED';
		jsonReply.message = 'Unknown/no status code received from server';
	}
	var status = jsonReply.status;
	if (jsonReply.message) {
		status = status + ' (' + jsonReply.message + ')';
	}
	slxModules[moduleName] = jsonReply.status;
	$('#mod-' + moduleName).text(status);
	if (jsonReply.status === 'UPDATE_DONE') {
		$('#mod-' + moduleName).css('color', '#0c0');
	}
	if (jsonReply.status === 'UPDATE_FAILED') {
		$('#mod-' + moduleName).css('color', '#c00');
	}
	if (jsonReply.status === 'UPDATE_RETRY') {
		$('#mod-' + moduleName).css('color', '#c50');
	}
}

var slxTrigger = function (elem, moduleName) {
	//alert('always: ' + moduleName + ', status: ' + slxModules[moduleName] + ', current: ' + slxCurrent);
	if (slxModules[moduleName] === 'WORKING') {
		slxModules[moduleName] = 'UPDATE_FAILED';
		$('#mod-' + moduleName).text('UPDATE_FAILED (No response from server)').css('color', '#c00');
	}
	if (slxCurrent === moduleName) {
		slxCurrent = false;
		slxRunNext(moduleName);
	}
}

function slxRunNext(lastModule) {
	var next = false;
	var first = false;
	for (var key in slxModules) {
		if (!slxModules.hasOwnProperty(key))
			continue;
		//
		if (slxTries[key] < 3 && (slxModules[key] === 'IDLE' || slxModules[key] === 'UPDATE_RETRY')) {
			if (next === true) {
				next = key;
				break;
			}
			if (first === false) {
				first = key;
			}
		}
		if (next === false && key === lastModule) {
			next = true;
		}
	}
	if (next === false || next === true) {
		next = first;
	}
	if (next !== false) {
		slxRun(next);
	} else {
		slxCancelTimer();
		setTimeout(function() {
			alert('Done.');
		}, 10);
	}
}

function slxCancelTimer()
{
	if (slxTimer !== false) {
		window.clearTimeout(slxTimer);
		slxTimer = false;
	}
}

var slxWatchdog = function()
{
	slxTimer = false;
	slxRunNext(slxCurrent);
}
/*
 * Generic helpers.
 */

/**
 * Initialize timepicker on given element.
 */
function setTimepicker($e) {
	$e.timepicker({
		minuteStep: 15,
		appendWidgetTo: 'body',
		showSeconds: false,
		showMeridian: false,
		defaultTime: false
	});
}

function getTime(str) {
	if (!str) return false;
	str = str.split(':');
	if (str.length !== 2) return false;
	var h = parseInt(str[0].replace(/^0/, ''));
	var m = parseInt(str[1].replace(/^0/, ''));
	if (h < 0 || h > 23) return false;
	if (m < 0 || m > 59) return false;
	return h * 60 + m;
}

const allDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

/*
 * Opening times related...
 */

var slxIdCounter = 0;

/**
 * Adds a new opening time to the table in expert mode.
 */
function newOpeningTime(vals) {
	var $row = $('#expert-template').find('div.row').clone();
	if (vals['days'] && Array.isArray(vals['days'])) {
		for (var i = 0; i < allDays.length; ++i) {
			$row.find('.i-' + allDays[i]).prop('checked', vals['days'].indexOf(allDays[i]) !== -1);
		}
	}
	$row.find('input').each(function() {
		var $inp = $(this);
		if ($inp.length === 0) return;
		slxIdCounter++;
		$inp.prop('id', 'id-inp-' + slxIdCounter);
		$inp.siblings('label').prop('for', 'id-inp-' + slxIdCounter);
	});
	$row.find('.i-openingtime').val(vals['openingtime']);
	$row.find('.i-closingtime').val(vals['closingtime']);
	$('#expert-table').append($row);
	return $row;
}

/**
 * Convert fields from simple mode view to entries in expert mode.
 * @returns {Array}
 */
function simpleToExpert() {
	var retval = [];
	if ($('#week-open').val() || $('#week-close').val()) {
		retval.push({
			'days': ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
			'openingtime': $('#week-open').val(),
			'closingtime': $('#week-close').val(),
			'tag': '#week'
		});
	}
	if ($('#saturday-open').val() || $('#saturday-close').val()) {
		retval.push({
			'days': ['Saturday'],
			'openingtime': $('#saturday-open').val(),
			'closingtime': $('#saturday-close').val(),
			'tag': '#saturday'
		});
	}
	if ($('#sunday-open').val() || $('#sunday-close').val()) {
		retval.push({
			'days': ['Sunday'],
			'openingtime': $('#sunday-open').val(),
			'closingtime': $('#sunday-close').val(),
			'tag': '#sunday'
		});
	}
	return retval;
}

/**
 * Triggered when the form is submitted
 */
function submitLocationSettings(event) {
	var schedule, s, e;
	var badFormat = false;
	$('#settings-outer').find('.red-bg').removeClass('red-bg');
	if ($('#week-open').length > 0) {
		schedule = simpleToExpert();
		for (var i = 0; i < schedule.length; ++i) {
			s = getTime(schedule[i].openingtime);
			e = getTime(schedule[i].closingtime);
			if (s === false) {
				$(schedule[i].tag + '-open').addClass('red-bg');
				badFormat = true;
			}
			if (e === false || e <= s) {
				$(schedule[i].tag + '-close').addClass('red-bg');
				badFormat = true;
			}
		}
	} else {
		// Serialize
		schedule = [];
		$('#expert-table').find('.expert-row').each(function () {
			var $t = $(this);
			if ($t.find('.i-delete').is(':checked')) return; // Skip marked as delete
			var entry = {
				'days': [],
				'openingtime': $t.find('.i-openingtime').val(),
				'closingtime': $t.find('.i-closingtime').val()
			};
			for (var i = 0; i < allDays.length; ++i) {
				if ($t.find('.i-' + allDays[i]).is(':checked')) {
					entry['days'].push(allDays[i]);
				}
			}
			if (entry.openingtime.length === 0 && entry.closingtime.length === 0 && entry.days.length === 0) return; // Also ignore empty lines
			s = getTime(entry.openingtime);
			e = getTime(entry.closingtime);
			if (s === false) {
				$t.find('.i-openingtime').addClass('red-bg');
				badFormat = true;
			}
			if (e === false || e <= s) {
				$t.find('.i-closingtime').addClass('red-bg');
				badFormat = true;
			}
			if (entry.days.length === 0) {
				$t.find('.days-box').addClass('red-bg');
				badFormat = true;
			}
			if (badFormat) return;
			schedule.push(entry);
		});
	}
	if (badFormat) {
		event.preventDefault();
	}
	$('#json-openingtimes').val(JSON.stringify(schedule));
}
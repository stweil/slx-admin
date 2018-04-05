/**
 * checks if a room is on a given date/time open
 * @param date Date Object
 * @param room Room object
 * @returns {Boolean} for open or not
 */
function IsOpen(date, room) {
	if (!room.openingTimes || room.openingTimes.length === 0) return true;
	var tmp = room.openingTimes[date.getDay()];
	if (!tmp) return false;
	var openDate = new Date(date.getTime());
	var closeDate = new Date(date.getTime());
	for (var i = 0; i < tmp.length; i++) {
		openDate.setHours(tmp[i].HourOpen);
		openDate.setMinutes(tmp[i].MinutesOpen);
		closeDate.setHours(tmp[i].HourClose);
		closeDate.setMinutes(tmp[i].MinutesClose);
		if (openDate < date && closeDate > date) {
			return true;
		}
	}
	return false;
}

/**
 * Convert passed argument to integer if possible, return NaN otherwise.
 * The difference to parseInt() is that leading zeros are ignored and not
 * interpreted as octal representation.
 *
 * @param str string or already a number
 * @return {number} str converted to number, or NaN
 */
function toInt(str) {
	var t = typeof str;
	if (t === 'number') return str | 0;
	if (t === 'string') return parseInt(str.replace(/^0+([^0])/, '$1'));
	return NaN;
}

/**
 *  used for countdown
 * computes the time difference between 2 Date objects
 * @param {Date} a
 * @param {Date} b
 * @param {Object} globalConfig
 * @returns {string} printable time
 */
function GetTimeDiferenceAsString(a, b, globalConfig) {
	if (!a || !b) {
		return "";
	}
	var milliseconds = a.getTime() - b.getTime();
	var days = Math.floor((milliseconds / (1000 * 60 * 60 * 24)) % 31);
	if (days !== 0) {
		// don't show?
		return "";
	}
	var seconds = Math.floor((milliseconds / 1000) % 60);
	milliseconds -= seconds * 1000;
	var minutes = Math.floor((milliseconds / (1000 * 60)) % 60);
	milliseconds -= minutes * 1000 * 60;
	var hours = Math.floor((milliseconds / (1000 * 60 * 60)) % 24);

	if (globalConfig && globalConfig.prettytime) {
		var str = '';
		if (hours > 0) {
			str += hours + 'h ';
		}
		str += minutes + 'min ';
		return str;
	}

	if (minutes < 10) {
		minutes = "0" + minutes;
	}
	if (globalConfig && globalConfig.eco) {
		return hours + ":" + minutes;
	}
	if (seconds < 10) {
		seconds = "0" + seconds;
	}
	return hours + ":" + minutes + ":" + seconds;
}
'use strict';

function ip2long(IP) {
	var i = 0;
	IP = IP.match(/^([1-9]\d*|0[0-7]*|0x[\da-f]+)(?:\.([1-9]\d*|0[0-7]*|0x[\da-f]+))?(?:\.([1-9]\d*|0[0-7]*|0x[\da-f]+))?(?:\.([1-9]\d*|0[0-7]*|0x[\da-f]+))?$/i);
	if (!IP) {
		return false;
	}
	IP.push(0, 0, 0, 0);
	for (i = 1; i < 5; i += 1) {
		IP[i] = parseInt(IP[i]) || 0;
		if (IP[i] < 0 || IP[i] > 255)
			return false;
	}
	return IP[1] * 16777216 + IP[2] * 65536 + IP[3] * 256 + IP[4] * 1;
}

function long2ip(a) {
	return [
		a >>> 24,
		255 & a >>> 16,
		255 & a >>> 8,
		255 & a
	].join('.');
}

function cidrToRange(cidr) {
	var range = [];
	cidr = cidr.split('/');
	if (cidr.length !== 2)
		return false;
	var cidr_1 = parseInt(cidr[1]);
	if (cidr_1 <= 0 || cidr_1 > 32)
		return false;
	var param = ip2long(cidr[0]);
	if (param === false)
		return false;
	range[0] = long2ip((param) & ((-1 << (32 - cidr_1))));
	var start = ip2long(range[0]);
	range[1] = long2ip(start + Math.pow(2, (32 - cidr_1)) - 1);
	return range;
}

/**
 * Add listener to start IP input; when it loses focus, see if we have a
 * CIDR notation and fill out start+end field.
 */
function slxAttachCidr() {
	$('.cidrmagic').each(function () {
		var t = $(this);
		var s = t.find('input.cidrstart');
		var e = t.find('input.cidrend');
		if (!s || !e)
			return;
		t.removeClass('cidrmagic');
		s.focusout(function () {
			var res = cidrToRange(s.val());
			if (res === false)
				return;
			s.val(res[0]);
			e.val(res[1]);
		});
	});
}

// Attach
slxAttachCidr();
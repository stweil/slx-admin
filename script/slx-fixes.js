// Give file select dialogs a modern style and feel
$(document).on('change', '.btn-file :file', function() {
	var input = $(this);
	var numFiles = input.get(0).files ? input.get(0).files.length : 1;
	var label = input.val().replace(/\\/g, '/').replace(/.*\//, '');
	input.trigger('fileselect', [numFiles, label]);
});
$(document).ready(function() {
	$('.btn-file :file').on('fileselect', function(event, numFiles, label) {
		var input = $(this).parents('.upload-ex').find(':text');
		var log = numFiles > 1 ? numFiles + ' files selected' : label;
		if (input.length) {
			input.val(log);
		}
	});
});
$('.upload-ex :text').click(function () {
	$(this).parents('.upload-ex').find(':file').click();
});

// Replace message query params in URL, so you won't see them again if you bookmark or share the link
if (history && history.replaceState && window && window.location && window.location.search && window.location.search.indexOf('message[]=') !== -1) {
	var str = window.location.search;
	console.log('TRUE: ' + str);
	do {
		var repl = str.match(/([\?&])message\[\]=[^&]+(&|$)/);
		console.log(repl);
		if (!repl) break;
		if (repl[2].length === 0) {
			str = str.replace(repl[0], '');
		} else {
			str = str.replace(repl[0], repl[1]);
		}
		console.log('Replace: ' + str);
	} while (1);
	history.replaceState(null, null, window.location.pathname + str);
}

// Simple decollapse functionality for tables
$('.slx-decollapse').click(function () {
	$(this).siblings('.collapse').removeClass('collapse');
});
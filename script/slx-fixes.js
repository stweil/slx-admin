// Give file select dialogs a modern style and feel
$(document).ready(function() {
	$(document).on('change', '.btn-file :file', function() {
		var input = $(this);
		if (input.parents('.disabled').length !== 0)
			return;
		var numFiles = input.get(0).files ? input.get(0).files.length : 1;
		var label = input.val().replace(/\\/g, '/').replace(/.*\//, '');
		input.trigger('fileselect', [numFiles, label]);
	});
	$('.btn-file :file').on('fileselect', function(event, numFiles, label) {
		var input = $(this).parents('.upload-ex').find(':text');
		var log = numFiles > 1 ? numFiles + ' files selected' : label;
		if (input.length) {
			input.val(log);
		}
	});
	$('.upload-ex :text').click(function () {
		var $this = $(this);
		if ($this.parents('.disabled').length !== 0)
			return;
		$this.parents('.upload-ex').find(':file').click();
	});
});

// Replace message query params in URL, so you won't see them again if you bookmark or share the link
if (history && history.replaceState && window && window.location && window.location.search && window.location.search.indexOf('message[]=') !== -1) {
	var str = window.location.search;
	do {
		var repl = str.match(/([\?&])message\[\]=[^&]+(&|$)/);
		if (!repl) break;
		if (repl[2].length === 0) {
			str = str.replace(repl[0], '');
		} else {
			str = str.replace(repl[0], repl[1]);
		}
	} while (1);
	history.replaceState(null, null, window.location.pathname + str);
}

// Simple decollapse functionality for tables
$(document).ready(function() {
	$('.slx-decollapse').click(function () {
		$(this).siblings('.collapse').removeClass('collapse');
	});
});

// Show not-allowed cursor for disabled links (not in btn-group as it messes up the style)
$('a.disabled').each(function() {
	var $this = $(this);
	if ($this.parent().hasClass('btn-group')) return;
	var $hax = $('<div class="disabled-hack">');
	$this.after($hax);
	$hax.append($this);
});

// Modern confirmation dialogs using bootstrap modal
$(document).ready(function() {
	var $title, $body, $button, $function, $modal = null, $cache = {};
	$function = function (e) {
		e.preventDefault();
		var $this = $(this);
		if ($modal === null) {
			$modal = $('<div class="modal fade" id="modal-autogen" tabindex="-1" role="dialog"><div class="modal-dialog" role="document"><div class="modal-content"><div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>'
				+ '<b id="modal-autogen-title"></b></div><div id="modal-autogen-body" class="modal-body"></div>'
				+ '<div class="modal-footer"><button type="submit" id="modal-autogen-button" data-dismiss="modal"></button></div></div></div></div>');
			$('#mainpage').append($modal);
			$title = $('#modal-autogen-title');
			$body = $('#modal-autogen-body');
			$button = $('#modal-autogen-button');
		}
		$title.text($this.data('title') || $this.text());
		$button.html($this.html()).attr('class', $this.attr('class')).removeClass('btn-xs btn-sm btn-lg').off('click').click(function() {
			// Click and reconnect click handler so pressing "back" on the next page works
			$this.off('click').click().click($function);
		});
		var $wat, str = $this.data('confirm');
		if (str.substr(0, 9) === '#confirm-') {
			if ($cache[str]) {
				$wat = $cache[str];
			} else {
				$cache[str] = $wat = $(str).detach(); // .detach as $wat might contain elements with id attribute
			}
			$body.empty().append($wat.clone(true).removeClass('hidden collapse invisible'));
		} else {
			$body.text(str);
		}
		$modal.modal();
	};
	$('button[data-confirm]').click($function);
});

// Taskmanager callbacks for running tasks
$(document).ready(function() {
	var slxCbCooldown = 0;
	function slxCheckCallbacks() {
		$.post('api.php?do=cb', { token: TOKEN }, function(data) {
			if ( data.indexOf('True') >= 0 ) {
				slxCbCooldown += 1;
			} else {
				slxCbCooldown += 10;
			}
			if (slxCbCooldown < 30)
				setTimeout(slxCheckCallbacks, slxCbCooldown * 1000);
		}, 'text');
	}
	slxCheckCallbacks();
});

// Caching script fetcher (https://api.jquery.com/jQuery.getScript/); use exactly like $.getScript
jQuery.cachedScript = function(url, options) {
	options = $.extend( options || {}, {
		dataType: "script",
		cache: true,
		url: url
	});
	return jQuery.ajax( options );
};

document.addEventListener("DOMContentLoaded", function() {
	var table = $("table").stupidtable();

	// to show the sort-arrow next to the table header
	table.on("aftertablesort", function (event, data) {
		var th = $(this).find("th");
		th.find(".arrow").remove();
		var dir = $.fn.stupidtable.dir;
		var arrow = data.direction === dir.ASC ? "down" : "up";
		th.eq(data.column).append(' <span class="arrow glyphicon glyphicon-chevron-'+arrow+'"></span>');
	});

	var selectize = $('#select-role').selectize({
		allowEmptyOption: false,
		maxItems: null,
		highlight: false,
		hideSelected: true,
		create: false,
		plugins: [ "remove_button" ]
	})[0].selectize;

	// If Site gets refreshed, all data-selectizeCounts will be reset to 0, so delete the filters from the selectize
	selectize.clear();

	selectize.on('item_add', function(value, $item) {
		// When first item gets added the filter isn't empty anymore, so hide all rows
		if (selectize.items.length === 1) {
			$('.dataTable tbody').find('tr').hide();
		}
		// Find all rows which shall be shown and increase their counter by 1
		$(".roleId-"+value).closest("tr").each(function() {
			$(this).data("selectizeCount", $(this).data("selectizeCount") + 1);
			$(this).show();
		});
	});

	selectize.on('item_remove', function(value, $item) {
		// When no items in the filter, show all rows again
		if (selectize.items.length === 0) {
			$('.dataTable tbody').find('tr').show();
		} else {
			// Find all rows which have the delete role, decrease their counter by 1
			$(".roleId-"+value).closest("tr").each(function() {
				$(this).data("selectizeCount", $(this).data("selectizeCount") - 1);
				// If counter is 0, hide the row (no filter given to show the row anymore)
				if ($(this).data("selectizeCount") === 0) {
					$(this).closest("tr").hide();
				}
			});
		}
	});

	$("form input").keydown(function(e) {
		if (e.keyCode === 13) e.preventDefault();
	});
});
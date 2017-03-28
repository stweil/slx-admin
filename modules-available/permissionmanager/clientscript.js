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
});
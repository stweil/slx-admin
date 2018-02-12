document.addEventListener("DOMContentLoaded", function() {
	var selectize = $("#select-role");
	if (selectize.length) {
		selectize = selectize.selectize({
			allowEmptyOption: false,
			maxItems: null,
			highlight: false,
			hideSelected: true,
			create: false,
			plugins: ["remove_button"]
		})[0].selectize;

		var $body = $(".dataTable tbody");
		var filterFunc = function(value) {
			var selected = selectize.getValue();
			if (!selected || !selected.length) {
				$body.find("tr").show();
			} else {
				$body.find("tr").hide();
				var str = 'tr.roleid-' + selected.join('.roleid-');
				$body.find(str).show();
			}
		};

		selectize.on("item_add", filterFunc);

		selectize.on("item_remove",filterFunc);
	}

	$("tr").on("click", function(e) {
		if (e.target.type !== "checkbox" && e.target.tagName !== "A") {
			$(this).find("input[type=checkbox]").trigger("click");
		}
	});
});
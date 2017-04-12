document.addEventListener("DOMContentLoaded", function() {
	var table = $("table");
	table.stupidtable({
		"ipsort":function(a,b){
			var aa = a.split(".");
			var bb = b.split(".");

			var resulta = aa[0]*0x1000000 + aa[1]*0x10000 + aa[2]*0x100 + aa[3]*1;
			var resultb = bb[0]*0x1000000 + bb[1]*0x10000 + bb[2]*0x100 + bb[3]*1;

			return resulta-resultb;
		}
	});

	table.on("aftertablesort", function (event, data) {
		var th = $(this).find("th");
		th.find(".arrow").remove();
		var dir = $.fn.stupidtable.dir;
		var arrow = data.direction === dir.ASC ? "down" : "up";
		th.eq(data.column).append(' <span class="arrow glyphicon glyphicon-chevron-'+arrow+'"></span>');
	});
});
var stillActive = true;
document.addEventListener('DOMContentLoaded', function() {
	var clients = [];
	$('.machineuuid').each(function() { clients.push($(this).data('uuid')); });
	if (clients.length === 0)
		return;
	function updateClientStatus() {
		if (!stillActive) return;
		stillActive = false;
		setTimeout(updateClientStatus, 5000);
		$.ajax({
			url: "?do=rebootcontrol",
			method: "POST",
			dataType: 'json',
			data: { token: TOKEN, action: "clientstatus", clients: clients }
		}).done(function(data) {
			console.log(data);
			if (!data)
				return;
			for (var e in data) {
				$('#status-' + e).prop('class', 'glyphicon ' + data[e]);
				if (!stillActive) $('#spinner-' + e).remove();
			}
		});
	}
	setTimeout(updateClientStatus, 1000);
});
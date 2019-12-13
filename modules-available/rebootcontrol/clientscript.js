var stillActive = 10;
document.addEventListener('DOMContentLoaded', function() {
	var clients = [];
	$('.machineuuid').each(function() { clients.push($(this).data('uuid')); });
	if (clients.length === 0)
		return;
	function updateClientStatus() {
		if (stillActive <= 0) return;
		stillActive--;
		setTimeout(updateClientStatus, Math.max(1, 30 - stillActive) * 1000);
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
				if (stillActive <= 0) $('#spinner-' + e).remove();
			}
		});
	}
	setTimeout(updateClientStatus, 10);
});
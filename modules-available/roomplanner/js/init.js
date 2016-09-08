/* */

function initRoomplanner() {
	
	$('#drawarea').css('top',(-roomplanner.settings.scale*10)+'px');
	$('#drawarea').css('left',(-roomplanner.settings.scale*10)+'px');
	
	roomplanner.computerAttributes = [
	"muuid",
	"mac_address",
	"ip",
	"hostname"
	];
	
	$("#loadButton").click(function() {
		roomplanner.load($('#serializedRoom').val());
	});

	$("#serializeButton").click(function() {
		$('#serializedRoom').val(roomplanner.serialize());
	});

	$("#saveBtn").click(function() {
		var managerip = $('#manager-ip').val().trim();
		if (managerip.length !== 0 && !(/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(managerip))) {
			alert('Invalid IP address format');
			return;
		}
		$('#saveBtn').prop('disabled', true);
		$('#error-msg').hide();
		$('#success-msg').hide();
		$('#saving-msg').show();
		var serializedCurrent = roomplanner.serialize();
		$.post('?do=roomplanner&locationid=' + locationId,
			{ token: TOKEN, action: 'save', serializedRoom: serializedCurrent, managerip: managerip }
		).done(function ( data ) {
			if (data.indexOf('SUCCESS') !== -1) {
				window.close();
				// If window.close() failed, we give some feedback and remember the state as saved
				$('#success-msg').show();
				plannerLoadState = serializedCurrent;
				return;
			}
			$('#error-msg').text('Error: ' + data).show();
		}).fail(function (jq, textStatus, errorThrown) {
			$('#error-msg').text('AJAX save call failed: ' + textStatus + ' (' + errorThrown + ')').show();
		}).always(function() {
			$('#saveBtn').prop('disabled', false);
			$('#saving-msg').hide();
		});
	});
	
	$('#zoom-out').click(function() {
		roomplanner.slider.slider('value', roomplanner.settings.scale - 10);
	});
	
	$('#zoom-in').click(function() {
		roomplanner.slider.slider('value', roomplanner.settings.scale + 10);
	});
}

var translation = {
	"muuid" : "Machine UUID",
	"mac_address" : "MAC Adresse",
	"ip" : "IP Adresse",
	"hostname": "Rechnername",
	
	"wall-horizontal" : "Wand (horizontal)",
	"wall-vertical" : "Mauer (vertikal)",
	"window-horizontal" : "Fenster",
	"window-vertical" : "Fenster",
	"door-nw" : "Tür",
	"door-ne" : "Tür",
	"door-sw" : "Tür",
	"door-se" : "Tür",
	"door-wn" : "Tür",
	"door-ws" : "Tür",
	"door-en" : "Tür",
	"door-es" : "Tür",			
	//"pc" : "PC",
	"pc-east" : "PC",
	"pc-south" : "PC",
	"pc-west" : "PC",
	"pc-north" : "PC",
	"copier" : "Kopierer",
	"printer" : "Drucker",
	"telephone" : "Telefon",
	"flatscreen" : "Flatscreen",
	"lamp" : "Schreibtischlampe",
	"tvcamera" : "Projektor",
	"4chairs1squaretable" : "4 Stühle und ein quadratischer Tisch",
	//"6chairs1table" : "6 Stühle und ein Tisch",
	"6chairs1table-horizontal" : "6 Stühle und ein Tisch",
	"6chairs1table-vertical" : "6 Stühle und ein Tisch",
	//"8chairs1conferencetable" : "8 Stühle und 1 Konferenztisch",
	"8chairs1conferencetable-horizontal" : "8 Stühle und 1 Konferenztisch",
	"8chairs1conferencetable-vertical" : "8 Stühle und 1 Konferenztisch",
	//"armchair" : "Sessel",
	"armchair-east" : "Sessel",
	"armchair-south" : "Sessel",
	"armchair-west" : "Sessel",
	"armchair-north" : "Sessel",
	//"chair" : "Stuhl",
	"chair-east" : "Stuhl",
	"chair-south" : "Stuhl",
	"chair-west" : "Stuhl",
	"chair-north" : "Stuhl",
	//"chair2" : "Stuhl",
	"chair2-east" : "Stuhl",
	"chair2-south" : "Stuhl",
	"chair2-west" : "Stuhl",
	"chair2-north" : "Stuhl",
	//"classroomdesk" : "Klassenzimmerpult",
	"classroomdesk-east" : "Klassenzimmerpult",
	"classroomdesk-south" : "Klassenzimmerpult",
	"classroomdesk-west" : "Klassenzimmerpult",
	"classroomdesk-north" : "Klassenzimmerpult",
	//"classroomdeskchair" : "Klassenzimmerpult mit Stuhl",
	"classroomdeskchair-east" : "Klassenzimmerpult mit Stuhl",
	"classroomdeskchair-south" : "Klassenzimmerpult mit Stuhl",
	"classroomdeskchair-west" : "Klassenzimmerpult mit Stuhl",
	"classroomdeskchair-north" : "Klassenzimmerpult mit Stuhl",
	//"classroomtable" : "Klassenzimmertisch",
	"classroomtable-east" : "Klassenzimmertisch",
	"classroomtable-south" : "Klassenzimmertisch",
	"classroomtable-west" : "Klassenzimmertisch",
	"classroomtable-north" : "Klassenzimmertisch",
	//"classroomtablechair" : "Klassenzimmertisch mit Stuhl",
	"classroomtablechair-east" : "Klassenzimmertisch mit Stuhl",
	"classroomtablechair-south" : "Klassenzimmertisch mit Stuhl",
	"classroomtablechair-west" : "Klassenzimmertisch mit Stuhl",
	"classroomtablechair-north" : "Klassenzimmertisch mit Stuhl",
	//"coatrack" : "Garderobe",
	"coatrack-east" : "Garderobe",
	"coatrack-south" : "Garderobe",
	"coatrack-west" : "Garderobe",
	"coatrack-north" : "Garderobe",
	//"conferencetable" : "Konferenztisch",
	"conferencetable-horizontal" : "Konferenztisch",
	"conferencetable-vertical" : "Konferenztisch",
	//"couch" : "Couch",
	"couch-east" : "Couch",
	"couch-south" : "Couch",
	"couch-west" : "Couch",
	"couch-north" : "Couch",
	//"greenchair" : "Stuhl",
	"greenchair-east" : "Stuhl",
	"greenchair-south" : "Stuhl",
	"greenchair-west" : "Stuhl",
	"greenchair-north" : "Stuhl",
	"lecturetheaterrow" : "Vorlesungssaalreihe mit Stühlen",
	"lecturetheaterrowseats" : "Vorlesungssaalstuhlreihe",
	//"locker" : "Schließfach",
	"locker-east" : "Schließfach",
	"locker-south" : "Schließfach",
	"locker-west" : "Schließfach",
	"locker-north" : "Schließfach",
	//"podium" : "Podium",
	"podium-east" : "Podium",
	"podium-south" : "Podium",
	"podium-west" : "Podium",
	"podium-north" : "Podium",
	//"roundeddesk" : "Eckschreibtisch",
	"roundeddesk-east" : "Eckschreibtisch",
	"roundeddesk-south" : "Eckschreibtisch",
	"roundeddesk-west" : "Eckschreibtisch",
	"roundeddesk-north" : "Eckschreibtisch",
	"roundtable" : "Runder Tisch",
	//"semicirculartable" : "Nierentisch",
	"semicirculartable-east" : "Nierentisch",
	"semicirculartable-south" : "Nierentisch",
	"semicirculartable-west" : "Nierentisch",
	"semicirculartable-north" : "Nierentisch",
	"squaretable" : "Quadratischer Tisch",
	//"studentdesk" : "Schülerpult",
	"studentdesk-east" : "Schülerpult",
	"studentdesk-south" : "Schülerpult",
	"studentdesk-west" : "Schülerpult",
	"studentdesk-north" : "Schülerpult",
	//"studentdeskchair" : "Schülerpult mit Stuhl",
	"studentdeskchair-east" : "Schülerpult mit Stuhl",
	"studentdeskchair-south" : "Schülerpult mit Stuhl",
	"studentdeskchair-west" : "Schülerpult mit Stuhl",
	"studentdeskchair-north" : "Schülerpult mit Stuhl",
	"papertray" : "Papierfach",
	"wastecan" : "Papierkorb",
	"plant" : "Pflanze",
	"plant2" : "Pflanze",
	"plant3" : "Pflanze",
	"projectionscreen" : "Projektionswand",
	"are you sure" : "Sind Sie sicher?"
};

function __(key) {
	if (translation[key]) {
		return translation[key];
	}

	return key;
}

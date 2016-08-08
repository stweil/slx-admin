/**
 * Pop-Up to select a machine
 *
 * Copyright 2016 Christian Klinger
 * */

/* Map: uuid -> obj */
machineCache = {};

selectMachinInitialized = false;



function renderMachineEntry(item, escape) {
    machineCache[item.machineuuid] = item;
    return '<div class="machine-entry">'
            //+ ' <div class="machine-logo"><i class="glyphicon glyphicon-hdd"></i></div>'
            + ' <div class="machine-body">'
            + '    <div class="machine-entry-header"> ' + escape(item.hostname) + '</div>'
            + '          <table class="table table-sm">'
            +               '<tr><td>UUID:</td> <td>' +  escape(item.machineuuid) + '</td></tr>'
            +               '<tr><td>MAC:</td> <td>' +  escape(item.macaddr) + '</td></tr>'
            +               '<tr><td>IP:  </td> <td>' +  escape(item.clientip)    + '</td></tr>'
            + '          </table>'
            + '    </div>'
            + '</div>';
}

function loadMachines(query, callback) {
    console.log('queryMachines(' + query + ')');
    if (query.length < 2) return callback();
    $.ajax({
        url: '?do=roomplanner&action=getmachines&query=' + encodeURIComponent(query),
        type: 'GET',
        error: function() {
            console.log('error while doing ajax call');
            callback();
        },
        success: function(res) {
            console.log('success ajax call');
            var json = JSON.parse(res);
            json.machines.forEach(function (v,i,a){
                a[i].combined = v.machineuuid + " " + v.hostname + " " + v.clientip + " " + v.macaddr;
            });
            return callback(json.machines);
        }
    });
}



function clearSearchBox() {
    $selectizeSearch[0].selectize.setValue([], true);
}
function clearSubnetBox() {
    $selectizeSubnet[0].selectize.setValue([], true);
}

function initSelectize() {
    if(!selectMachinInitialized) {
        console.log("initializing selectize");
        /* init modal */
        $modal = $('#selectMachineModal');

        /* for the search */
        $selectizeSearch = $('#machineSearchBox').selectize({
            plugins : ["remove_button"],
            valueField: 'machineuuid',
            searchField: "combined",
            openOnFocus: false,
            create: false,
            render : { option : renderMachineEntry, item: renderMachineEntry},
            load: loadMachines,
            maxItems: 1,
            sortField: 'clientip',
            sortDirection: 'asc',
            onChange: clearSubnetBox
        });


        /* for the subnet box */
        $selectizeSubnet = $('#subnetBox').selectize({
            options: subnetMachines,
            plugins : ["remove_button"],
            valueField: 'machineuuid',
            searchField: "combined",
            openOnFocus:  true,
            create: false,
            render : { option : renderMachineEntry, item: renderMachineEntry},
            maxItems: 1,
            sortField: 'clientip',
            sortDirection: 'asc',
            onChange: clearSearchBox
        });

        $('#selectMachineButton').on('click', onBtnSelect);

        selectMachinInitialized = true;
    }
}
function onBtnSelect() {
        /* check which one has a value */
        console.assert($selectizeSubnet.length == 1);
        console.assert($selectizeSearch.length == 1);

        var bySubnet = machineCache[$selectizeSubnet[0].selectize.getValue()];
        var bySearch = machineCache[$selectizeSearch[0].selectize.getValue()];

        var value = (bySubnet === undefined || bySubnet == "") ? bySearch : bySubnet;
        var result = {muuid: value.machineuuid, ip: value.clientip, mac_address : value.macaddr, hostname: value.hostname};

        currentCallback(result);

        $modal.modal('hide');
        clearSubnetBox();
        clearSearchBox();
}

/* to be called from berryous' code */
function selectMachine(usedUuids, callback) {
    initSelectize();
    currentCallback = callback;
    $modal.modal('show');
}


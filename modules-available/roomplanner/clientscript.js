/**
 * Pop-Up to select a machine
 *
 * Copyright 2016 Christian Klinger
 * */

/* uuid -> obj */
machineCache = {};



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


var searchSettings = {
    plugins : ["remove_button"],
    valueField: 'machineuuid',
    searchField: "combined",
    //labelField: "combined",
    openOnFocus: false,
    create: false,
    render : { option : renderMachineEntry, item: renderMachineEntry},
    load: loadMachines,
    maxItems: 1,
    sortField: 'clientip',
    sortDirection: 'asc',
    onChange: clearSubnetBox

}

var subnetSettings = {
    plugins : ["remove_button"],
    valueField: 'machineuuid',
    searchField: "combined",
    //labelField: "combined",
    openOnFocus: false,
    create: false,
    render : { option : renderMachineEntry, item: renderMachineEntry},
    load: loadMachines,
    maxItems: 1,
    sortField: 'clientip',
    sortDirection: 'asc',
    onChange: clearSearchBox

}

function clearSearchBox() {
    console.log("clearSearchBox()");
    $selectizeSearch[0].selectize.clear(false);
}
function clearSubnetBox() {
    console.log("clearSubnetBox()");
    $selectizeSubnet[0].selectize.clear(false);
}

function selectMachine(usedUuids, callback) {
    /* show a popup */
    $modal = $('#selectMachineModal');
    $selectizeSearch = $('#machineSearchBox').selectize(searchSettings);
    $selectizeSubnet = $('#subnetBox').selectize(subnetSettings);

    /* connect subnet tab and search tab such that on change of one the other gets emptied */


    $modal.modal('show');

    $('#selectMachineButton').on('click', function() {

        /* check which one has a value */
        var bySubnet = machineCache[$selectizeSubnet[0].selectize.getValue()];
        var bySearch = machineCache[$selectizeSearch[0].selectize.getValue()];

        var selected = bySubnet; // (bySubnet === undefined || bySubnet == "")? bySearch : bySubnet;

        console.log('value is ');
        console.log($selectizeSubnet[0].selectize.getValue());
        console.log(machineCache);
        console.log('selected is ');
        console.log(selected);

        var result = {muuid: selected.machineuuid, ip: selected.clientip, mac_address : selected.macaddr, hostname: selected.hostname};
        $modal.modal('hide');
        clearSubnetBox();
        clearSearchBox();
        callback(result);
    });

    var result = {muuid: "blabla", ip: "blabla", mac_address: "blub", hostname: "lalala"};

    callback(result);
}

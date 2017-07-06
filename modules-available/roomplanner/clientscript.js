/**
 * Pop-Up to select a machine
 *
 * AUTHOR: Christian Klinger
 * */

/* Map: uuid -> obj */
var machineCache = {};

var selectMachinInitialized = false;

var placedMachines = [];

function makeCombinedField(machineArray)
{
   machineArray.forEach(function (v,i,a){
      machineArray[i].combined = (v.machineuuid + " " + v.hostname + " " + v.clientip + " " + v.macaddr + " " + v.macaddr.replace(/-/g, ':')).toLocaleLowerCase();
   });
   return machineArray;
}

function renderMachineEntry(item, escape) {
    machineCache[item.machineuuid] = item;
    // console.log('rendering ' + item.machineuuid);
    // console.log('used uuids is ');
    // console.log(placedMachines);

    var isUsed = $.inArray(item.machineuuid, placedMachines) > -1;
    var extraClass = '';
    var extraText = '';
    if (isUsed) {
        extraText = ' (already placed)';
        extraClass = 'used';
    } else if (item.otherroom) {
        extraText = ' (in ' + item.otherroom + ')';
        extraClass = 'used';
    }
    return '<div class="machine-entry ' + extraClass +'">'
            //+ ' <div class="machine-logo"><i class="glyphicon glyphicon-hdd"></i></div>'
            + ' <div class="machine-body">'
            + '    <div class="machine-entry-header"> ' + escape(item.hostname) + extraText + '</div>'
            + '          <table>'
            +               '<tr><td>UUID:</td> <td>' +  escape(item.machineuuid) + '</td></tr>'
            +               '<tr><td>MAC: </td> <td>' +  escape(item.macaddr) + '</td></tr>'
            +               '<tr><td>IP:  </td> <td>' +  escape(item.clientip)    + '</td></tr>'
            + '          </table>'
            + '    </div>'
            + '</div>';
}

function renderMachineSelected(item, escape) {
   return '<div>' + escape(item.hostname) + '</div>';
}

var queryCache = {};

function filterCache(key, query) {
    return queryCache[key].filter(function (el) {
       return -1 !== el.combined.indexOf(query);
    });
}

function loadMachines(query, callback) {
    console.log('queryMachines(' + query + ')');
    if (query.length < 2) {
       callback();
       return;
    }
    query = query.toLocaleLowerCase();
    // See if we have a previous query in our cache that is a superset for this one
    for (var k in queryCache) {
        if (query.indexOf(k) !== -1) {
            callback(filterCache(k, query));
            return;
        }
    }
    $.ajax({
        url: '?do=roomplanner&action=getmachines&query=' + encodeURIComponent(query),
        type: 'GET',
        dataType: 'json',
        error: function() {
            console.log('error while doing ajax call');
            callback();
        },
        success: function(json) {
            console.log('success ajax call');
            var machines = makeCombinedField(json.machines);
            // Server cuts off at 100, so only cache if it contains less entries, as
            // the new, more specific query could return previously removed results.
            if (machines.length < 100) {
                queryCache[query] = machines;
            }
            callback(machines);
        }
    });
}

function clearSearchBox() {
    $selectizeSearch[0].selectize.setValue([], true);
    $selectizeSearch[0].selectize.clearCache();
}
function clearSubnetBox() {
    $selectizeSubnet[0].selectize.setValue([], true);
    $selectizeSubnet[0].selectize.clearCache();
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
            render : { option : renderMachineEntry, item: renderMachineSelected},
            load: loadMachines,
            maxItems: 1,
            sortField: 'hostname',
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
            render : { option : renderMachineEntry, item: renderMachineSelected},
            maxItems: 1,
            sortField: 'hostname',
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
        currentCallback = null;

        $modal.modal('hide');
        clearSubnetBox();
        clearSearchBox();
}

/* to be called from berryous' code */
function selectMachine(usedUuids, callback) {
    initSelectize();
    currentCallback = callback;
    placedMachines =  usedUuids;
    $modal.modal('show');
    $modal.one('hidden.bs.modal', function () {
        if (currentCallback != null) {
            currentCallback(false);
        }
    });
}


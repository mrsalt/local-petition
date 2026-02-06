"use strict";

let hasEditPrivileges = false;
const searchParams = new URL(document.location.href).searchParams;

let localities = [];
let locality_index = undefined;
let localityLeftButton = undefined;
let localityRightButton = undefined;
let currentHighlightType = undefined;
let currentHighlightLocality = undefined;
let allMarkers = [];
let markersByLocality = {};

function addSidebarRow(element, items, addRow = true) {
    const interactiveContainer = element.closest('div.interactive-map-container');
    if (!interactiveContainer) return;
    let sideBar = interactiveContainer.querySelector('div.interactive-map-sidebar');
    if (addRow) {
        let row = document.createElement('div');
        row.classList.add('sidebar-row');
        for (const item of items) {
            row.appendChild(item);
        }
        sideBar.appendChild(row);
    } else {
        for (const item of items) {
            sideBar.appendChild(item);
        }
    }
}

// position should be an object like this:
// const position = { lat: -25.344, lng: 131.031 };
// zoom should be a zoom level.  0 = whole earth, 4 = zoomed out very far.  15?
// mapTypeId: google.maps.MapTypeId.SATELLITE
async function initMap(element, position, zoom, mapId, mapTypeId, locality) {
    const { Map } = await google.maps.importLibrary("maps");

    let mapOptions = {
        zoom: zoom,
        disableDoubleClickZoom: true,
        streetViewControl: false,
        scaleControl: true, // Enable the scale control
    };
    if (position) {
        mapOptions['center'] = position;
    }
    if (mapId && mapId !== 'null') {
        mapOptions['mapId'] = mapId;
    }
    else {
        mapOptions['styles'] = [{
            featureType: 'poi',
            stylers: [{ visibility: 'off' }]  // Turn off points of interest.
        },
        {
            featureType: 'transit.station',
            stylers: [{ visibility: 'off' }]  // Turn off bus, train stations etc.
        }];
    }
    if (mapTypeId && mapTypeId !== 'null') {
        mapOptions['mapTypeId'] = mapTypeId;
    }

    //https://developers.google.com/maps/documentation/get-map-id
    let map = new Map(element, mapOptions);
    element.map = map;

    if (locality) {
        highlightArea(map, 'locality', {'name': locality, 'latitude': position.lat, 'longitude': position.lng, 'color': 'rgb(196, 163, 16)'});
    }

    google.maps.event.addListener(map, 'maptypeid_changed', function() {
        // Re-apply style or re-add features if necessary
        if (currentHighlightType !== undefined && currentHighlightLocality !== undefined) {
            highlightArea(map, currentHighlightType, currentHighlightLocality);
        }
    });

    const interactiveContainer = element.closest('div.interactive-map-container');
    if (interactiveContainer) {
        const rect = interactiveContainer.getBoundingClientRect();
        interactiveContainer.style.height = 'calc(100vh - '+(rect.top)+'px)';
        let fullscreenButton = document.createElement('button');
        fullscreenButton.textContent = 'Fullscreen';
        let fullscreenMode = false;
        let sideBar = interactiveContainer.querySelector('div.interactive-map-sidebar');
        fullscreenButton.addEventListener('click', () => {
            fullscreenMode = !fullscreenMode;
            if (fullscreenMode) {
                fullscreenButton.textContent = 'Exit Fullscreen';
                interactiveContainer.requestFullscreen();
                sideBar.classList.add('lp-map-sidebar-fullscreen');
            }
            else {
                fullscreenButton.textContent = 'Fullscreen';
                document.exitFullscreen();
                sideBar.classList.remove('lp-map-sidebar-fullscreen');
            }
        });
        addSidebarRow(element, [fullscreenButton]);

        // Radius slider (miles) ------------------------------------------------
        const radiusLabel = document.createElement('label');
        radiusLabel.textContent = 'Radius (miles)';
        const radiusInput = document.createElement('input');
        radiusInput.type = 'range';
        radiusInput.min = '1.0';
        radiusInput.max = '3.0';
        radiusInput.step = '0.5';
        radiusInput.value = '2.0';
        radiusInput.classList.add('lp-radius-slider');
        const radiusValue = document.createElement('span');
        radiusValue.classList.add('lp-radius-value');
        radiusValue.textContent = radiusInput.value;

        // Update function: converts miles to meters and applies to all markers with radiusControl
        function applyRadiusMiles(miles) {
            const meters = parseFloat(miles) * 1609.344; // 1 mile = 1609.344 meters
            for (const entry of allMarkers) {
                if (entry && entry.radiusControl) {
                    try {
                        entry.radiusControl.setRadius(meters);
                        if (entry.info) entry.info.radius = meters;
                    } catch (e) {
                        console.warn('Failed to set radius on marker', e);
                    }
                }
            }
        }

        radiusInput.addEventListener('input', (e) => {
            const val = e.target.value;
            radiusValue.textContent = val;
            applyRadiusMiles(val);
        });

        addSidebarRow(element, [radiusLabel, radiusInput, radiusValue]);

        /* we'll re-enable this checkbox once it does something.
        let checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        let label = document.createElement('label');
        label.textContent = 'Borders Overlap';
        map.lpcontrols = { bordersOverlap: checkbox };
        addSidebarRow(element, [checkbox, label]);*/
    }
}

function updateMarkerListForLocality(localityId, markerListEl = undefined) {
    if (!markerListEl) {
        markerListEl = document.querySelector('.lp-locality-marker-list');
        if (!markerListEl) return;
    }
    // Populate the marker list for the current locality using markersByLocality
    // Clear existing list
    markerListEl.innerHTML = '';
    if (markersByLocality[localityId]) {
        const markers = markersByLocality[localityId];
        for (const m of markers) {
            // kinda weird to check this here, but the marker's radius color is currently
            // the only way we have to determine whether the marker is a 'primary' marker
            // of the city.  We may want to add 'includeInList' or something in the future.
            // Another option would be to set locality to null for the other items that
            // share the same locality id.
            if ("#D47BAC" === m.info.radius_color) {
                addToMarkerList(m.info.name, markerListEl);
            }
        }
    } else {
        // Optionally show empty state
        const li = document.createElement('li');
        li.textContent = 'None';
        markerListEl.appendChild(li);
    }
}

async function highlightArea(map, type, locality) {
    currentHighlightType = type;
    currentHighlightLocality = locality;
    if (!locality['placesResult']) {
        let request = {
            textQuery: locality['name'],
            fields: ["id", "location"],
            includedType: type,
        };
        if (locality['latitude'] && locality['longitude']) {
            request['locationBias'] = {'lat': locality['latitude'], 'lng': locality['longitude']};
        }
        const { Place } = await google.maps.importLibrary("places");
        const { places } = await Place.searchByText(request);
        locality['placesResult'] = places;
    }

    const places = locality['placesResult'];
    if (places.length) {
        const place = places[0];
        let layerType;
        if (type == 'locality')
            layerType = 'LOCALITY';
        else
            console.error('highlightArea: type ' + type + ' not recognized');
        let featureLayer = map.getFeatureLayer(layerType);
        if (featureLayer.isAvailable)
            styleBoundary(place.id, featureLayer, locality['color']);
        else
            console.warn("Feature layer " + layerType + " not available");
    } else {
        console.warn("Locality query: No results");
    }
}

function styleBoundary(placeid, featureLayer, color) {
  // Define a style of transparent purple with opaque stroke.
  const style = {
    strokeColor: color,
    strokeOpacity: 0.9,
    strokeWeight: 2.0,
    fillColor: color,
    fillOpacity: 0.2,
  };

  // Define the feature style function.
  featureLayer.style = (params) => {
    if (params.feature.placeId == placeid) {
      return style;
    }
  };
}

class Color {
    constructor(r, g, b) {
        this.r = r;
        this.g = g;
        this.b = b;
    }

    static blend(ratio, colorA, colorB) {
        return new Color(
            Math.floor((colorB.r - colorA.r) * ratio + colorA.r),
            Math.floor((colorB.g - colorA.g) * ratio + colorA.g),
            Math.floor((colorB.b - colorA.b) * ratio + colorA.b));
    }

    toCSS() {
        return 'rgb(' + this.r + ',' + this.g + ',' + this.b + ')';
    }
}

function addChartLegend(map, minPerSquare, minColor, maxPerSquare, maxColor) {
    const legendControlDiv = document.createElement('div');
    const gradientElement = document.createElement('span');
    const gradientLabels = document.createElement('span');
    const minValueElement = document.createElement('div');
    const maxValueElement = document.createElement('div');
    gradientLabels.appendChild(minValueElement);
    gradientLabels.appendChild(maxValueElement);
    legendControlDiv.appendChild(gradientElement);
    legendControlDiv.appendChild(gradientLabels);
    legendControlDiv.classList.add("lp-map-legend");
    legendControlDiv.title = 'Number of Petition Signers Per Block';
    gradientElement.classList.add("lp-gradient");
    gradientLabels.classList.add("lp-gradient-labels");
    maxValueElement.classList.add("lp-max-value");
    minValueElement.classList.add("lp-min-value");
    minValueElement.innerText = minPerSquare;
    maxValueElement.innerText = maxPerSquare;
    gradientElement.style.backgroundImage = 'linear-gradient(' + maxColor.toCSS() + ', ' + minColor.toCSS() + ')';
    map.controls[google.maps.ControlPosition.LEFT_CENTER].push(legendControlDiv);
}

async function addMapSupporterOverlays(element, gridLat = undefined, gridLng = undefined, latStep = undefined, lngStep = undefined, minSupporters = undefined) {
    const { Marker } = await google.maps.importLibrary("marker")
    let url = '/wp-admin/admin-ajax.php?action=lp_get_supporters_map_coordinates_json&lat_center=' + gridLat + '&lng_center=' + gridLng + '&lat_box_size=' + latStep + '&lng_box_size=' + lngStep;
    fetch(url)
        .then(req => req.json())
        .then(supporters => {
            //console.log(supporters);
            let squares = new Map();
            let markers = {};
            supporters.forEach(supporter => {
                if (supporter.lat_box !== undefined) {
                    let key = supporter.lat_box + ',' + supporter.lng_box;
                    let value = squares.get(key);
                    if (value === undefined) {
                        let south = supporter.lat_box * latStep + gridLat;
                        let north = south + latStep;
                        let west = supporter.lng_box * lngStep + gridLng;
                        let east = west + lngStep;
                        squares.set(key, { count: 1, position: { north: north, south: south, east: east, west: west } });
                    }
                    else {
                        value.count++;
                    }
                }
                if (supporter.lat !== undefined) {
                    let key = supporter.lat + ',' + supporter.lng;
                    if (!markers.hasOwnProperty(key)) {
                        markers[key] = { position: { lat: parseFloat(supporter.lat), lng: parseFloat(supporter.lng) }, map: element.map };
                    }
                    if (supporter.hasOwnProperty('name')) {
                        if (!markers[key].hasOwnProperty('label'))
                            markers[key]['label'] = [];
                        markers[key]['label'].push(supporter.name);
                    }
                }
            });
            let markerList = [];
            for (const key in markers) {
                let m = markers[key];
                if (m['label'].length > 1)
                    m['label'] = '(' + m['label'].length + ') ' + m['label'].join(', ');
                else
                    m['label'] = m['label'][0];
                markerList.push(new Marker(m));
            }
            if (markerList.length > 0) {
                const markerCluster = new markerClusterer.MarkerClusterer({ map: element.map, markers: markerList });
            }
            if (squares.size > 0) {
                drawSupporterGrid(squares, element, supporters, minSupporters);
            }
        });
}

function drawSupporterGrid(squares, element, supporters, minSupporters) {
    let minPerSquare = 1;
    let maxPerSquare = 1;
    let latTotal = 0;
    let lngTotal = 0;
    for (const square of squares.values()) {
        let p = square.position;
        const coords = [
            { lat: p.north, lng: p.east },
            { lat: p.south, lng: p.east },
            { lat: p.south, lng: p.west },
            { lat: p.north, lng: p.west },
        ];
        let feature = element.map.data.add({ geometry: new google.maps.Data.Polygon([coords]) });
        feature.setProperty('count', square.count);
        if (square.count > maxPerSquare)
            maxPerSquare = square.count;
        latTotal += square.count * (p.north + p.south) / 2;
        lngTotal += square.count * (p.east + p.west) / 2;
    }
    element.map.setCenter({ lat: latTotal / supporters.length, lng: lngTotal / supporters.length });
    let maxColor = new Color(160, 50, 50);
    let minColor = new Color(50, 50, 160);
    element.map.data.setStyle(function (feature) {
        let color = null;
        if (minPerSquare === maxPerSquare)
            color = minColor.toCSS();
        else
            color = Color.blend((feature.getProperty('count') - minPerSquare) / (maxPerSquare - minPerSquare), minColor, maxColor).toCSS();
        return {
            fillColor: color,
            strokeWeight: 1
        };
    });
    if (minSupporters === null || maxPerSquare >= minSupporters)
        addChartLegend(element.map, minPerSquare, minColor, maxPerSquare, maxColor);
}

var menuControl;
function showContextMenu(parent, e, buttons) {
    menuControl = document.createElement('div');
    for (const button of buttons) {
        menuControl.appendChild(button);
    }

    // Set the position for menu
    menuControl.style.position = 'absolute';
    const rect = parent.getBoundingClientRect();
    const x = e.domEvent.clientX - rect.left;
    const y = e.domEvent.clientY - rect.top;
    menuControl.style.top = `${y}px`;
    menuControl.style.left = `${x}px`;
    parent.appendChild(menuControl);
}

function hideContextMenu() {
    if (menuControl) menuControl.parentElement.removeChild(menuControl);
    menuControl = undefined;
}

async function addAddItemButton(element, mapId) {
    hasEditPrivileges = true;
    let addressWindow = document.createElement('div');
    addressWindow.classList.add('visit-window');
    let addressLine = document.createElement('div');
    addressWindow.appendChild(addressLine);

    let itemCount = 0;
    let itemRow;
    function addItem(item) {
        if (itemCount++ % 2 == 0) {
            itemRow = document.createElement('div');
            addressWindow.appendChild(itemRow);
        }
        itemRow.appendChild(item);
        return itemRow;
    }

    function addOption(select, value) {
        let option = document.createElement('option');
        option.innerText = value;
        option.value = value;
        select.appendChild(option);
    }

    let typeLabel = document.createElement('label');
    typeLabel.innerText = 'Type';
    addItem(typeLabel);
    let typeInput = document.createElement('select');
    addOption(typeInput, 'Marker');
    addOption(typeInput, 'Locality');
    addItem(typeInput);

    let titleLabel = document.createElement('label');
    titleLabel.innerText = 'Name';
    addItem(titleLabel);
    let titleInput = document.createElement('input');
    addItem(titleInput);

    let addressLabel = document.createElement('label');
    addressLabel.innerText = 'Address';
    addItem(addressLabel);
    let addressInput = document.createElement('input');
    addItem(addressInput);

    let radiusLabel = document.createElement('label');
    radiusLabel.innerText = 'Radius (meters)';
    addItem(radiusLabel);
    let radiusInput = document.createElement('input');
    radiusInput.value = '3219';
    let radiusRow = addItem(radiusInput);

    let colorLabel = document.createElement('label');
    colorLabel.innerText = 'Color';
    addItem(colorLabel);
    let colorInput = document.createElement('input');
    colorInput.value = '#D47BAC';
    addItem(colorInput);

    let markerTypeLabel = document.createElement('label');
    markerTypeLabel.innerText = 'Type';
    addItem(markerTypeLabel);
    let markerTypeInput = document.createElement('select');
    addOption(markerTypeInput, 'Library');
    addOption(markerTypeInput, 'Question Mark');
    let markerTypeRow = addItem(markerTypeInput);

    let visibilityHandler = () => {
        radiusRow.style.display = typeInput.value === 'Marker' ? '' : 'none';
        markerTypeRow.style.display = typeInput.value === 'Marker' ? '' : 'none';
    };
    visibilityHandler();

    typeInput.addEventListener('change', visibilityHandler);

    let button = document.createElement('button');
    button.textContent = 'Submit';
    button.addEventListener('click', async () => {
        button.disabled = true;
        let type = typeInput.value;
        let url = '/wp-admin/admin-ajax.php?action=lp_place_map_item' +
            '&type=' + encodeURIComponent(type) +
            '&address=' + encodeURIComponent(addressInput.value) +
            '&color=' + encodeURIComponent(colorInput.value) +
            '&name=' + encodeURIComponent(titleInput.value) +
            '&map_id=' + mapId;
        if (type === 'Marker') {
            url += '&markerType=' + encodeURIComponent(markerTypeInput.value);
            url += '&radius=' + encodeURIComponent(radiusInput.value);
            if (locality_index !== null && locality_index !== undefined) {
                url += '&locality_id=' + encodeURIComponent(localities[locality_index].id);
            }
        }
        const response = await fetch(url);
        button.disabled = false;
        if (!response.ok) {
            const errorText = await response.text();
            console.error('Error placing map item: ' + errorText);
            alert(errorText);
            return;
        }
        const json = await response.json();
        addressInput.value = '';
        titleInput.value = '';
        for (const item of json) {
            if (type === 'Locality') {
                highlightArea(element.map, 'locality', item);
                localities.push(item);
                initializeLocalityControls(element);
                updateLocalityButtons();
            } else if (type === 'Marker') {
                addMapMarker(element, item);
            }
        }
    });
    addItem(button);
    button.disabled = true;

    function enableMarkerButton() {
        button.disabled = !(addressInput.value);
    }

    titleInput.addEventListener('change', enableMarkerButton);
    addressInput.addEventListener('change', enableMarkerButton);

    var controlsVisible = true;
    element.map.addListener('contextmenu', (e) => {
        function toggleVisibility() {
            element.map.setOptions({ mapTypeControl: !controlsVisible, panControl: !controlsVisible, fullscreenControl: !controlsVisible, zoomControl: !controlsVisible });
            addressWindow.style.display = controlsVisible ? 'none' : '';
            controlsVisible = !controlsVisible;
            hideContextMenu();
        }
        if (document.fullscreenElement) {
            toggleVisibility();
            return;
        }
        let changeButton = document.createElement('button');
        changeButton.textContent = controlsVisible ? 'Hide Controls' : 'Show Controls';
        changeButton.addEventListener('click', () => toggleVisibility());
        showContextMenu(element, e, [changeButton]);
    });

    element.map.controls[google.maps.ControlPosition.BOTTOM_CENTER].push(addressWindow);
}

function loadMapMarkers(element, mapId) {
    fetch('/wp-admin/admin-ajax.php?action=lp_load_markers_json' +
        '&map_id=' + encodeURIComponent(mapId))
        .then(req => req.json())
        .then(json => {
            for (const marker of json)
                addMapMarker(element, marker);
            if (locality_index !== undefined && locality_index !== null) {
                updateMarkerListForLocality(localities[locality_index].id);
            }
        });
}

async function addMapMarker(element, info) {
    const map = element.map;
    const { Marker } = await google.maps.importLibrary("marker")
    let iconUrl;
    let scaledSize = undefined;
    let label = undefined;
    if (info.icon === 'Library') {
        iconUrl = '/wp-content/plugins/local-petition/images/logo-image-only-200px.png';
        scaledSize = { 'height': 40, 'width': 40 };
    }
    else if (info.icon === 'Question Mark') {
        iconUrl = '/wp-content/plugins/local-petition/images/question-mark-45x89.png';
        scaledSize = { 'height': 45, 'width': 23 };
    }
    else {
        throw new Error('icon type ' + info.icon + ' not known')
    }
    if (info.name) {
        label = {
            text: info.name,
            fontSize: '20px'
        };
    }

    const markerLocation = { lat: info.latitude, lng: info.longitude }

    let options = {
        icon: {
            url: iconUrl,
            anchor: { 'x': 20, 'y': 20 },
            scaledSize: scaledSize,
            labelOrigin: { 'x': 20, 'y': 50 }
        }, map: map, label: label, position: markerLocation
    };
    let marker = new Marker(options);
    let markerEntry = { marker: marker, info: info };
    allMarkers.push(markerEntry);

    if (info.locality_id) {
        if (!markersByLocality[info.locality_id]) {
            markersByLocality[info.locality_id] = [];
        }
        markersByLocality[info.locality_id].push(markerEntry);
    }

    if (info.radius) {
        const radiusControl = new google.maps.Circle({
            strokeColor: info.radius_color,
            strokeOpacity: 0.8,
            strokeWeight: 2,
            fillColor: info.radius_color,
            fillOpacity: 0.20,
            map,
            center: markerLocation,
            radius: info.radius,
            clickable: false
        });
        allMarkers[allMarkers.length - 1]['radiusControl'] = radiusControl;

        // radius unit is meters
        const infowindow = new google.maps.InfoWindow({
            content: info.name + '<br>' +
                info.line_1 + ', ' + info.city + ', ' + info.state
        });
2
        if (hasEditPrivileges) {
            marker.addListener('contextmenu', (e) => {
                if (menuControl) {
                    hideContextMenu();
                    return;
                }
                let deleteButton = document.createElement('button');
                deleteButton.textContent = 'Delete Marker';
                deleteButton.addEventListener('click', (e) => {
                    hideContextMenu();
                    let url = '/wp-admin/admin-ajax.php?action=lp_delete_marker&id=' + info.id;
                    fetch(url)
                        .then(req => req.json())
                        .then(response => {
                            marker.setMap(null);
                            radiusControl.setMap(null);
                        });
                });
                showContextMenu(element, e, [deleteButton]);
            });
        }
        marker.addListener('click', () => {
            infowindow.open({
                anchor: marker,
                map,
            });
        });
    }
}

async function placeImageMarker(map, image, address, label) {
    const { Marker } = await google.maps.importLibrary("marker")
    let geocoder = new google.maps.Geocoder();
    geocoder.geocode({ 'address': address }).then((response) => {
        let result = response.results[0];
        let options = { 'icon': image, 'map': map, 'label': label, 'position': result.geometry.location };
        new Marker(options);
    }).catch(() => { });
}

function updateLocalityButtons() {
    localityLeftButton.disabled = locality_index === 0;
    localityRightButton.disabled = locality_index === localities.length - 1;
}

function addToMarkerList(markerName, markerListEl = undefined) {
    if (!markerListEl) {
        markerListEl = document.querySelector('.lp-locality-marker-list');
        if (!markerListEl) return;
    }
    if (markerListEl.querySelector('li') && markerListEl.querySelector('li').textContent === 'None') {
        markerListEl.innerHTML = '';
    }
    const li = document.createElement('li');
    li.textContent = markerName;
    markerListEl.appendChild(li);
}

function initializeLocalityControls(element) {
    if (localityLeftButton !== undefined) return;
    locality_index = localities.length > 0 ? 0 : null;

    // Create a simple control with left arrow, title, right arrow
    const controlDiv = document.createElement('div');
    controlDiv.classList.add('lp-locality-control');

    localityLeftButton = document.createElement('button');
    localityLeftButton.type = 'button';
    localityLeftButton.classList.add('lp-locality-left');
    localityLeftButton.textContent = '<';

    const titleEl = document.createElement('div');
    titleEl.classList.add('lp-locality-title');
    // Container to show markers that belong to the current locality
    const markerListEl = document.createElement('ol');
    markerListEl.classList.add('lp-locality-marker-list');

    localityRightButton = document.createElement('button');
    localityRightButton.type = 'button';
    localityRightButton.classList.add('lp-locality-right');
    localityRightButton.textContent = '>';

    function updateForIndex() {
        if (locality_index === null || localities.length === 0) return;
        const cur = localities[locality_index];
        titleEl.textContent = (locality_index + 1) + '. ' + cur.name;
        updateLocalityButtons();
        element.map.setCenter({ lat: parseFloat(cur.latitude), lng: parseFloat(cur.longitude) });
        highlightArea(element.map, 'locality', cur);
        updateMarkerListForLocality(cur.id, markerListEl);
    }

    // Initialize title and map center if we have at least one locality
    updateForIndex();

    localityLeftButton.addEventListener('click', () => {
        if (locality_index > 0) {
            locality_index -= 1;
            updateForIndex();
        }
    });

    localityRightButton.addEventListener('click', () => {
        if (locality_index < localities.length - 1) {
            locality_index += 1;
            updateForIndex();
        }
    });

    controlDiv.appendChild(localityLeftButton);
    controlDiv.appendChild(titleEl);
    controlDiv.appendChild(localityRightButton);

    // Add control to the map (top center)
    //element.map.controls[google.maps.ControlPosition.TOP_CENTER].push(controlDiv);
    const separator = document.createElement('hr');
    addSidebarRow(element, [separator], false);
    addSidebarRow(element, [controlDiv]);

    // Add the marker list below the controls
    addSidebarRow(element, [markerListEl]);
}

function loadMapLocalities(element, mapId) {
    fetch('/wp-admin/admin-ajax.php?action=lp_load_localities_json' +
        '&map_id=' + encodeURIComponent(mapId))
        .then(req => req.json())
        .then(json => {

            // Store json in a variable and create an index for the current locality
            localities = json;
            if (localities.length > 0)
                locality_index = 0;

            initializeLocalityControls(element);
        });
}

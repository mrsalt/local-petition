// position should be an object like this:
// const position = { lat: -25.344, lng: 131.031 };
// zoom should be a zoom level.  0 = whole earth, 4 = zoomed out very far.  15?
async function initMap(element, position, zoom, mapId) {
    const { Map } = await google.maps.importLibrary("maps");

    let mapOptions = {
        zoom: zoom,
        center: position,
        disableDoubleClickZoom: true,
        streetViewControl: false,
    };
    if (mapId) {
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

    //https://developers.google.com/maps/documentation/get-map-id
    let map = new Map(element, mapOptions);

    element.map = map;
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
        feature = element.map.data.add({ geometry: new google.maps.Data.Polygon([coords]) });
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

var managerOptions;
var routeInProgress = {};
var routeData = { maxRouteID: 0, routes: [] };

async function addMapRoutes(element) {
    const { Drawing } = await google.maps.importLibrary("drawing");
    managerOptions = {
        drawingMode: null,
        drawingControl: false,
        drawingControlOptions: {
            position: google.maps.ControlPosition.TOP_CENTER,
            drawingModes: [
                google.maps.drawing.OverlayType.POLYGON,
            ],
        },
    };
    fetch('/wp-admin/admin-ajax.php?action=lp_get_map_routes')
        .then(req => req.json())
        .then(routeInfo => {
            let centerString = localStorage.getItem(element.id + '-center');
            if (centerString) {
                console.log('restoring map location');
                element.map.setCenter(JSON.parse(centerString));
            }
            let zoomString = localStorage.getItem(element.id + '-zoom');
            if (zoomString) {
                console.log('restoring map zoom');
                element.map.setZoom(JSON.parse(zoomString));
            }
            if (routeInfo.is_editor) {
                fetch('/wp-admin/admin-ajax.php?action=lp_get_users')
                    .then(req => req.json())
                    .then(userList => {
                        routeData.userList = userList;
                    })
                    .then(() => {
                        drawRoutes(element.map, routeInfo);
                    })
            }
            else {
                drawRoutes(element.map, routeInfo);
            }
        })
        .then(() => {
            element.map.addListener('center_changed', () => {
                if (element.centerUpdateHandler) clearTimeout(element.centerUpdateHandler);
                element.centerUpdateHandler = setTimeout(() => {
                    let centerString = JSON.stringify(element.map.getCenter());
                    localStorage.setItem(element.id + '-center', centerString);
                    delete element.centerUpdateHandler;
                }, 1000);
            });
            element.map.addListener('zoom_changed', () => {
                let oldZoom = JSON.parse(localStorage.getItem(element.id + '-zoom'));
                let newFontSize = getTextSizeForZoomLevel(element.map.getZoom());
                if (oldZoom && getTextSizeForZoomLevel(oldZoom) != newFontSize) {
                    for (const route of routeData.routes) {
                        route.mapNumber.then((marker) => { marker.content.style.fontSize = newFontSize })
                    }
                }
                let zoom = JSON.stringify(element.map.getZoom());
                localStorage.setItem(element.id + '-zoom', zoom);
            });
            const drawingManager = new google.maps.drawing.DrawingManager(managerOptions);
            drawingManager.setMap(element.map);
            element.drawingManager = drawingManager;

            google.maps.event.addListener(drawingManager, 'polygoncomplete', function (polygon) {
                if (routeInProgress.hasOwnProperty('okButton'))
                    routeInProgress.okButton.disabled = false;
                routeInProgress.polygon = polygon;
                routeInProgress.path = JSON.stringify(polygon.getPath().getArray());
                routeInProgress.mapNumber = placeNumber(routeData.maxRouteID + 1, element.map, findCenterOf(JSON.parse(routeInProgress.path)));
            });
        });
}

function findCenterOf(path) {
    let east, west, north, south;
    for (const pos of path) {
        if (east === undefined) {
            east = pos.lng;
            west = pos.lng;
            north = pos.lat;
            south = pos.lat;
            continue;
        }
        if (pos.lng < west) west = pos.lng;
        else if (pos.lng > east) east = pos.lng;
        if (pos.lat < south) south = pos.lat;
        else if (pos.lat > north) north = pos.lat;
    }
    return { lng: (east + west) / 2.0, lat: (north + south) / 2.0 };
}

function getTextSizeForZoomLevel(level) {
    return level > 16 ? '30px' : '18px';
}

async function placeNumber(number, map, position) {
    const { AdvancedMarkerElement } = await google.maps.importLibrary("marker")
    let el = document.createElement('div')
    el.innerText = number;
    el.style.fontSize = getTextSizeForZoomLevel(map.getZoom());
    el.style.fontWeight = 'bold';
    let marker = new AdvancedMarkerElement({ content: el, gmpDraggable: true, map: map, position: position });
    marker.addListener('dragend', () => {
        if (routeInProgress.polygon) {
            routeInProgress.numberPosition = JSON.stringify(marker.position);
        }
        else {
            let url = '/wp-admin/admin-ajax.php?action=lp_update_route_number_position&id=' + number + '&position=' + encodeURIComponent(JSON.stringify(marker.position));
            fetch(url);
        }
    });
    return marker;
}

function beginAddingRoute(element) {
    let addRouteButton = this;
    this.disabled = true;
    managerOptions.drawingControl = true;
    managerOptions.drawingMode = google.maps.drawing.OverlayType.POLYGON;
    element.drawingManager.setOptions(managerOptions);

    let container = document.createElement('div');
    container.classList.add('dynamic-prompt');

    let resetState = function (e) {
        managerOptions.drawingControl = false;
        managerOptions.drawingMode = null;
        element.drawingManager.setOptions(managerOptions);
        addRouteButton.disabled = false;
        container.parentElement.removeChild(container);
        routeInProgress.polygon.setMap(null);
        routeInProgress.mapNumber.then((marker) => { marker.map = null })
        delete routeInProgress.polygon;
        routeInProgress = {};
        e.stopPropagation();
    }

    let residenceInput = document.createElement('input');
    residenceInput.placeholder = 'Number of residences';
    residenceInput.type = 'number';

    let neighborhoodInput = document.createElement('input');
    neighborhoodInput.placeholder = 'Neighborhood';
    neighborhoodInput.type = 'text';

    let okButton = document.createElement('button');
    okButton.innerText = 'Save';
    okButton.disabled = !routeInProgress.hasOwnProperty('polygon');
    okButton.addEventListener('click', (e) => {
        if (!residenceInput.valueAsNumber) {
            alert('Enter the number of residences inside the region');
            residenceInput.focus();
            e.stopImmediatePropagation();
            return;
        }
        let url = '/wp-admin/admin-ajax.php?action=lp_add_route&neighborhood=' + encodeURIComponent(neighborhoodInput.value) + '&residences=' + residenceInput.valueAsNumber + '&bounds=' + encodeURIComponent(routeInProgress.path);
        if (routeInProgress.numberPosition)
            url += '&number_position=' + encodeURIComponent(routeInProgress.numberPosition);
        fetch(url)
            .then(req => req.json())
            .then(routeInfo => {
                drawRoutes(element.map, routeInfo);
            });
        resetState(e);
    });
    let cancelButton = document.createElement('button');
    cancelButton.innerText = 'Cancel';
    cancelButton.addEventListener('click', resetState);

    container.appendChild(residenceInput);
    container.appendChild(neighborhoodInput);
    container.appendChild(okButton);
    container.appendChild(cancelButton);
    this.insertAdjacentElement('afterend', container);

    routeInProgress.okButton = okButton;
}

function updateRoute(map, routeAction, route, userId) {
    if (route.status == 'Complete' && routeAction == 'delete') {
        alert('Completed routes cannot be deleted.');
        return;
    }
    let url = '/wp-admin/admin-ajax.php?action=lp_update_route&route_action=' + routeAction + '&id=' + route.id + '&user_id=' + userId;
    fetch(url)
        .then(req => req.json())
        .then(routeInfo => {
            // delete existing polygon and container, replace with updated version:
            const index = routeData.routes.indexOf(route);
            if (index > -1) routeData.routes.splice(index, 1);
            route.polygon.setMap(null);
            route.mapNumber.then((marker) => { marker.map = null })
            route.container.parentElement.removeChild(route.container);
            if (routeInfo) drawRoutes(map, routeInfo);
        });
}

async function drawRoutes(map, routeInfo) {
    const { Polygon } = await google.maps.importLibrary("maps")
    let existingRoutesContainer = document.getElementById('existing-routes');
    let scoreRoute = function (route) {
        if (route.status == 'Assigned' && route.assigned_to_wp_user_id == routeInfo.user_id) return 0;
        if (route.status == 'Unassigned') return 1;
        if (route.status == 'Assigned') return 2;
        return 3;
    };
    let routeComparator = function (a, b) {
        let delta = scoreRoute(a) - scoreRoute(b);
        if (delta !== 0) return delta;
        return a.id - b.id;
    };
    let routes = routeInfo.routes;
    for (const route of routes) {
        if (parseInt(route.id) > parseInt(routeData.maxRouteID))
            routeData.maxRouteID = parseInt(route.id);
        let fillColor, fillOpacity = 0.3;
        switch (route.status) {
            case 'Unassigned': fillColor = new Color(210, 50, 50); fillOpacity = 0; break;
            case 'Assigned': fillColor = new Color(50, 50, 210); fillOpacity = 0; break;
            case 'Complete': fillColor = new Color(100, 100, 100); break;
        }
        let black = new Color(0, 0, 0);
        let standardPolygonOptions = { fillColor: fillColor.toCSS(), fillOpacity: fillOpacity, strokeColor: fillColor.toCSS(), strokeOpacity: 0.8 };
        let focusedPolygonOptions = { fillColor: Color.blend(0.5, black, fillColor).toCSS(), fillOpacity: 0.2, strokeColor: fillColor.toCSS(), strokeOpacity: 1.0 };
        let paths = JSON.parse(route.bounds);
        let options = { map: map, paths: paths };
        let polygon = new google.maps.Polygon(options);
        polygon.setOptions(standardPolygonOptions);

        let container = document.createElement('div');
        container.classList.add('route-container');
        container.tabIndex = 0;
        container.innerHTML = '<b>' + route.id + (route.neighborhood ? ' (' + route.neighborhood + ')' : '') + '</b>';
        container.innerHTML += '<div style="color: ' + fillColor.toCSS() + '">Status: ' + route.status + '</div>';
        container.innerHTML += '<div>Residences: ' + route.number_residences + '</div>';
        if (route.assigned_to) {
            container.innerHTML += '<div' + (route.assigned_to_wp_user_id == routeInfo.user_id ? ' style="font-weight: bold"' : '') + '>Assigned: ' + route.assigned_to + '</div>';
        }

        polygon.route = route;
        container.route = route;
        route.polygon = polygon;
        route.container = container;

        let clickHandler = () => {
            container.focus();
        };

        polygon.addListener('click', clickHandler);
        container.addEventListener('click', clickHandler);

        container.addEventListener('focusin', () => {
            // make polygon appear focused
            polygon.setOptions(focusedPolygonOptions);
            let polyCenter = {
                lng: (parseFloat(polygon.route.east) + parseFloat(polygon.route.west)) / 2.0,
                lat: (parseFloat(polygon.route.north) + parseFloat(polygon.route.south)) / 2.0
            };
            if (!polygon.map.getBounds().contains(polyCenter)) {
                polygon.map.setCenter(polyCenter);
            }

            let routeControl = buildRouteControl(route, routeInfo.is_editor);
            container.appendChild(routeControl);
            container.routeControl = routeControl;
            container.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
        });

        container.addEventListener('focusout', (event) => {
            // restore original appearance
            polygon.setOptions(standardPolygonOptions);
            container.removeChild(container.routeControl);
            delete container.routeControl;
        });

        for (const el of existingRoutesContainer.children) {
            if (el.route && routeComparator(route, el.route) < 0) {
                el.insertAdjacentElement('beforebegin', container);
                break;
            }
        }
        if (!container.parentElement) {
            existingRoutesContainer.appendChild(container);
        }

        let number_position;
        if (route.number_position) number_position = JSON.parse(route.number_position);
        else number_position = findCenterOf(paths);
        route.mapNumber = placeNumber(route.id, map, number_position);

        routeData.routes.push(route);
    }

    function buildRouteControl(route, is_editor) {
        let container = document.createElement('div');
        let assignedToMe = route.assigned_to_wp_user_id == routeInfo.user_id;

        // assign button
        if (!is_editor) {
            if (assignedToMe || route.status == 'Unassigned') {
                let assignButton = document.createElement('button');
                let route_action;
                container.appendChild(assignButton);
                if (assignedToMe) {
                    assignButton.innerText = 'Unassign';
                    route_action = 'unassign';
                }
                else {
                    assignButton.innerText = 'Assign To Me';
                    route_action = 'assign';
                }
                assignButton.addEventListener('click', () => { updateRoute(map, route_action, route, routeInfo.user_id) });
                assignButton.addEventListener('mousedown', (event) => { event.preventDefault(); });
            }
        }
        else {
            let assignButton = document.createElement('button');
            assignButton.innerText = 'Assign';
            container.appendChild(assignButton);
            let select = document.createElement('div');
            select.classList.add('assignee-list');
            for (const user of routeData.userList) {
                let option = document.createElement('button');
                option.classList.add('assignee-button');
                option.value = user.ID;
                option.innerText = user.display_name;
                if (route.assigned_to_wp_user_id == user.ID) option.disabled = true;
                select.appendChild(option);
            }
            if (route.status == 'Assigned') {
                let option = document.createElement('button');
                option.classList.add('assignee-button');
                option.value = '-1';
                option.innerText = '<Unassign>';
                select.appendChild(option);
            }
            select.style.display = 'None';
            container.appendChild(select);
            getUserId = () => { select.value };
            select.addEventListener('mousedown', (event) => { event.preventDefault(); });
            select.addEventListener('click', (e) => { updateRoute(map, e.target.value == '-1' ? 'unassign' : 'assign', route, e.target.value) });
            assignButton.addEventListener('click', () => { select.style.display = '' });
            assignButton.addEventListener('mousedown', (event) => { event.preventDefault(); });
        }

        // complete button
        if (routeInfo.is_editor || assignedToMe) {
            let completeButton = document.createElement('button');
            completeButton.innerText = 'Mark Complete';
            container.appendChild(completeButton);
            completeButton.addEventListener('click', () => { updateRoute(map, 'complete', route) });
            completeButton.addEventListener('mousedown', (event) => { event.preventDefault(); });
            container.completeButton = completeButton;
        }

        // delete button
        if (routeInfo.is_editor) {
            let deleteButton = document.createElement('button');
            deleteButton.innerText = 'Delete';
            container.appendChild(deleteButton);
            deleteButton.addEventListener('click', () => { updateRoute(map, 'delete', route) });
            deleteButton.addEventListener('mousedown', (event) => { event.preventDefault(); });
            container.deleteButton = deleteButton;
        }
        return container;
    }
}
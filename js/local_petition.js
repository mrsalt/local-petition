function onVisible(element, callback) {
    new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.intersectionRatio > 0) {
                callback(element);
                observer.disconnect();
            }
        });
    }).observe(element);
}

function animateCounter(el, stopvalue, timeInterval) {
    onVisible(el, (element) => {
        element.innerText = '';
        var count = 0;
        let timeoutId;
        timeoutId = setInterval(() => {
            if (count == stopvalue) {
                clearTimeout(timeoutId);
                return;
            }
            count++;
            element.innerText = count;
        }, timeInterval);
    });
}

function loadSupporterBox(el, campaignSlug) {
    fetch('/wp-admin/admin-ajax.php?action=lp_get_supporters_json')
        .then(req => req.json())
        .then(json => {
            if (json.length === 0) {
                el.style.display = 'None';
                return;
            }
            arg = { current: 0, next: 1, data: json, element: el, campaignSlug: campaignSlug, mode: 'play' };
            updateSupporterBox.call(arg);
            if (json.length > 1) {
                arg.timerId = setInterval(moveToNextSupporter.bind(arg), 10000);
            }
            let navControls = el.querySelector('.nav-controls');
            navControls.style.display = json.length > 1 ? '' : 'None';
            if (typeof jQuery == 'undefined')
                navControls.addEventListener('click', clickNavControl.bind(arg));
            else
                jQuery(navControls).on('click', clickNavControl.bind(arg));
        });
}

function moveToNextSupporter() {
    this.current = this.next;
    this.next++;
    if (this.next === this.data.length) this.next = 0;
    updateSupporterBox.call(this);
}

function moveToPriorSupporter() {
    this.current--;
    if (this.current < 0) this.current = this.data.length - 1;
    this.next = this.current + 1;
    if (this.next === this.data.length) this.next = 0;
    updateSupporterBox.call(this);
}

function updateSupporterBox() {
    let person = this.data[this.current];
    let photoElement = this.element.querySelector('.supporter-photo');
    photoElement.src = getImageUrl.call(this, person);
    this.element.querySelector('.supporter-name').innerText = person.name;
    this.element.querySelector('.supporter-title').innerText = person.title;
    this.element.querySelector('.supporter-comments').innerText = person.comments;
    this.preload = new Image();
    this.preload.src = getNextImageUrl.call(this);
}

function getNextImageUrl() {
    return getImageUrl.call(this, this.data[this.next]);
}

function getImageUrl(person) {
    if (person.photo_file)
        return '/wp-content/uploads/local-petition/' + this.campaignSlug + '/' + person.id + '/' + person.photo_file;
    else
        return '/wp-content/plugins/local-petition/images/placeholder-image-person.png';
}

function clickNavControl() {
    if (event.target.id === 'previous' || event.target.id === 'next') {
        clearInterval(this.timerId);
        if (this.mode === 'play')
            this.timerId = setInterval(moveToNextSupporter.bind(this), 10000);
        if (event.target.id === 'previous')
            moveToPriorSupporter.call(this);
        else
            moveToNextSupporter.call(this);
        updateSupporterBox.call(this);
    }
    else if (event.target.id === 'play_pause') {
        if (this.mode === 'play') {
            this.mode = 'pause';
            event.target.innerHTML = '&#x23F8;';
            clearInterval(this.timerId);
        }
        else if (this.mode === 'pause') {
            this.mode = 'play';
            event.target.innerHTML = '&#x23EF;';
            this.timerId = setInterval(moveToNextSupporter.bind(this), 10000);
        }
    }
}

// https://pqina.nl/blog/compress-image-before-upload/
const compressImage = async (file, { quality = 0.90, type = file.type, maxHeight = null, maxWidth = null }) => {
    // Get as image data
    const imageBitmap = await createImageBitmap(file);

    let width = imageBitmap.width;
    let height = imageBitmap.height;

    if (maxHeight != null || maxWidth != null) {
        let relativeSize = 1.0
        if (maxWidth != null && width > maxWidth) {
            relativeSize = maxWidth / width;
        }
        if (maxHeight != null && height > maxHeight) {
            let relativeVSize = maxHeight / height;
            if (relativeVSize < relativeSize) relativeSize = relativeVSize;
        }
        width = width * relativeSize;
        height = height * relativeSize;
    }

    // Draw to canvas
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(imageBitmap, 0, 0, width, height);

    // Turn into Blob
    const blob = await new Promise((resolve) =>
        canvas.toBlob(resolve, type, quality)
    );

    // Turn Blob into File
    return new File([blob], file.name, {
        type: blob.type,
    });
};

// This method will watch for changes to the input specified.
// It will compress any images that are selected to the specified maxWidth and maxHeight using quality level.
function watchImageInput(input, quality = 0.8, maxWidth = null, maxHeight = null) {
    input.addEventListener('change', (e) => {
        // Get the files
        const { files } = e.target;

        // No files selected
        if (!files.length) return;

        // We'll store the files in this data transfer object
        const dataTransfer = new DataTransfer();

        promises = []
        // For every file in the files list
        for (const file of files) {
            // We don't have to compress files that aren't images
            if (!file.type.startsWith('image')) {
                // Ignore this file, but do add it to our result
                dataTransfer.items.add(file);
                continue;
            }

            promises.push(compressImage(file, {
                quality: quality,
                type: 'image/jpeg',
                maxWidth: maxWidth,
                maxHeight: maxHeight
            }).then(compressedFile => {
                // Save back the compressed file instead of the original file
                dataTransfer.items.add(compressedFile);
            }));
        }

        Promise.all(promises).then(() => {
            // Set value of the file input to our new files list
            e.target.files = dataTransfer.files;
        });
    });
}

// position should be an object like this:
// const position = { lat: -25.344, lng: 131.031 };
// zoom should be a zoom level.  0 = whole earth, 4 = zoomed out very far.  15?
async function initMap(element, position, zoom) {
    const { Map } = await google.maps.importLibrary("maps");

    //https://developers.google.com/maps/documentation/get-map-id
    let map = new Map(element, {
        zoom: zoom,
        center: position,
        styles: [{
            featureType: 'poi',
            stylers: [{ visibility: 'off' }]  // Turn off POI.
        },
        {
            featureType: 'transit.station',
            stylers: [{ visibility: 'off' }]  // Turn off bus, train stations etc.
        }],
        disableDoubleClickZoom: true,
        streetViewControl: false,
    });

    element.map = map;
}

async function addMapOverlays(element, slug, gridLat, gridLng, latStep, lngStep) {
    const { Marker } = await google.maps.importLibrary("marker")
    fetch('/wp-admin/admin-ajax.php?action=lp_get_supporters_map_coordinates_json&lat_center=' + gridLat + '&lng_center=' + gridLng + '&lat_box_size=' + latStep + '&lng_box_size=' + lngStep)
        .then(req => req.json())
        .then(supporters => {
            //console.log(supporters);
            let squares = new Map();
            supporters.forEach(supporter => {
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
                if (supporter.lat !== undefined) {
                    new Marker({ label: supporter.name, position: { lat: parseFloat(supporter.lat), lng: parseFloat(supporter.lng) }, map: element.map });
                }
            });
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
            }
            element.map.data.setStyle(function (feature) {
                var color = feature.getProperty('count') > 1 ? 'red' : 'blue';
                return {
                    fillColor: color,
                    strokeWeight: 1
                };
            });
        });
}

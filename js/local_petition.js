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

function getTimeToDisplay(person) {
    let timeout = 3000;
    if (person.comments)
        timeout += 7 * person.comments.length;
    if (person.photo_file)
        timeout += 3000;
    return timeout;
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
    if (this.data.length > 1 && this.mode === 'play') {
        this.timerId = setTimeout(moveToNextSupporter.bind(this), getTimeToDisplay(person));
    }
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
    clearTimeout(this.timerId);
    if (event.target.id === 'previous' || event.target.id === 'next') {
         if (event.target.id === 'previous')
            moveToPriorSupporter.call(this);
        else
            moveToNextSupporter.call(this);
    }
    else if (event.target.id === 'play_pause') {
        if (this.mode === 'play') {
            this.mode = 'pause';
            event.target.innerHTML = '&#x23F8;';
        }
        else if (this.mode === 'pause') {
            this.mode = 'play';
            event.target.innerHTML = '&#x23EF;';
            this.timerId = setTimeout(moveToNextSupporter.bind(this), 5000);
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

function update_filter(filter, newValue) {
    let search = {};
    let qs = window.location.search.substring(1);
    let found = false;
    qs.split('&').forEach(component => {
        let [key, value] = component.split('=');
        key = decodeURIComponent(key);
        key = key.replace('_', ' '); // Is wordpress replacing spaces with underscores?  We need to convert back to spaces.
        if (key === filter) {
            found = true;
            if (newValue !== '<All>') {
                search[key] = newValue;
            }
        }
        else {
            search[key] = decodeURIComponent(value);
        }
    });
    // when changing filters, clear any limit/offset
    delete search.limit;
    delete search.offset;
    if (!found) search[filter] = newValue;
    let searchString = '';
    let sep = '?';
    for (const [key, value] of Object.entries(search)) {
        searchString += sep + encodeURIComponent(key) + '=' + encodeURIComponent(value);
        sep = '&';
    }
    window.location.href = window.location.origin + window.location.pathname + searchString;
}

function update_visible_columns(select, cookie_name) {
    let Cookies2 = Cookies.noConflict();
    let hidden_columns = [];
    for (idx in select.children) {
        //console.log(select.children[idx].innerText);
        if (!select.children[idx].selected)
            hidden_columns.push(select.children[idx].innerText);
    }
    Cookies2.set(cookie_name, JSON.stringify(hidden_columns));
    window.location.reload();
}

function toggle_checkbox(srcCheckbox) {
    for (const checkbox of document.querySelectorAll('td.lp-table-data input[type=checkbox]')) {
        checkbox.checked = srcCheckbox.checked;
    }
}

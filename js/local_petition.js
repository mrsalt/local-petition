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
            arg = { current: 0, data: json, element: el, campaignSlug: campaignSlug };
            updateSupporterBox.call(arg);
            if (json.length > 1)
                setInterval(updateSupporterBox.bind(arg), 5000);
        });
}

function updateSupporterBox() {
    if (this.current === this.data.length) this.current = 0;
    let person = this.data[this.current++];

    let photoElement = this.element.querySelector('.supporter-photo');
    if (person.photo_file)
        photoElement.src = '/wp-content/uploads/local-petition/' + this.campaignSlug + '/' + person.id + '/' + person.photo_file;
    else
        photoElement.src = '/wp-content/plugins/local-petition/images/placeholder-image-person.png';
    this.element.querySelector('.supporter-name').innerText = person.name;
    this.element.querySelector('.supporter-title').innerText = person.title;
    this.element.querySelector('.supporter-comments').innerText = person.comments;
    // I should preload next image...
}
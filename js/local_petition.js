function onVisible(element, callback) {
    new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if(entry.intersectionRatio > 0) {
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
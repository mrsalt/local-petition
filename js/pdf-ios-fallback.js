(function () {
  var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
              (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  if (!isIOS) return;

  function replacePdfIframes() {
    document.querySelectorAll('iframe[src*=".pdf" i]').forEach(function (frame) {
      var url = new URL(frame.getAttribute('src'), location.href);
      url.hash = ''; // drop #view=fitH etc.

      var name = url.pathname.split('/').pop();
      try { name = decodeURIComponent(name); } catch (e) { /* keep raw name */ }
      var label = frame.dataset.label || frame.title || name;

      var link = document.createElement('a');
      link.href = url.href;
      link.target = '_blank';
      link.rel = 'noopener';
      link.className = 'wp-block-button__link wp-element-button';
      link.textContent = 'Open ' + label + ' (PDF)';

      var p = document.createElement('p');
      p.appendChild(link);
      frame.replaceWith(p);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', replacePdfIframes);
  } else {
    replacePdfIframes();
  }
})();
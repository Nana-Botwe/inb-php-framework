// Your own scripts.
//
// For AJAX/fetch requests that change data, send the CSRF token from the <meta name="csrf-token"> tag:
//
//   fetch(url, {
//       method: 'POST',
//       headers: {
//           'Content-Type': 'application/json',
//           'Accept': 'application/json',
//           'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
//       },
//       body: JSON.stringify(data),
//   });

(function () {
    'use strict';

    // Close buttons on alerts (flash messages). Bootstrap handles this when its JS is loaded;
    // this fallback keeps them working if the CDN is slow, offline or blocked.
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-bs-dismiss="alert"]') : null;
        if (!button || (window.bootstrap && window.bootstrap.Alert)) {
            return;
        }
        var alert = button.closest('.alert');
        if (alert) {
            alert.classList.remove('show');
            window.setTimeout(function () { alert.remove(); }, 150);
        }
    });
})();

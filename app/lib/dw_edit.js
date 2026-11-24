/* DW edit link loader (2026-11-04). Hidden by default. Asks /_dw/whoami (HttpOnly cookie, same origin);
 * only when the answer is {"edit":true} does it add the edit link and load the markup tool. No brand code on the page. */
(function () {
  'use strict';
  if (window.__dwEdit) return; window.__dwEdit = true;
  fetch('/_dw/whoami', { credentials: 'include', cache: 'no-store' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (j) {
      if (!j || j.edit !== true) {
        if (j) document.cookie = 'dw_ed=; Max-Age=0; Path=/';
        if (j && location.hash === '#suggest-edit') location.href = '/_dw/start?return=' + encodeURIComponent(location.pathname + location.search + '#suggest-edit');
        return;
      }
      var s = document.createElement('script'); s.src = '/markup/markup.js'; s.async = true; document.body.appendChild(s);
    })
    .catch(function () {});
})();

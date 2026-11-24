/* Generic affiliate ref keeper. Safe to cache: it never contains a specific code.
   Reads dw_ref (or ?ref= / ?aff= on this page) and adds ref to same-site links and forms. */
(function () {
  'use strict';
  var ALPHA = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
  var CODE_RE = new RegExp('^(AFF|CH)-[' + ALPHA + ']{4,12}$', 'i');

  function readCode() {
    var params = new URLSearchParams(window.location.search);
    var q = params.get('ref') || params.get('aff') || '';
    if (CODE_RE.test(q)) {
      return q.toUpperCase();
    }
    var match = document.cookie.match(/(?:^|;\s*)dw_ref=([^;]+)/);
    if (!match) {
      return '';
    }
    var raw;
    try {
      raw = decodeURIComponent(match[1]);
    } catch (e) {
      return '';
    }
    var parts = raw.split('.');
    if (parts.length >= 4 && parts[0] === 'v1' && CODE_RE.test(parts[2])) {
      return parts[2].toUpperCase();
    }
    return '';
  }

  function withRef(url, code) {
    try {
      var parsed = new URL(url, window.location.href);
      if (parsed.origin !== window.location.origin) {
        return url;
      }
      if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
        return url;
      }
      if (parsed.searchParams.has('ref') || parsed.searchParams.has('aff')) {
        return url;
      }
      parsed.searchParams.set('ref', code);
      return parsed.pathname + parsed.search + parsed.hash;
    } catch (e) {
      return url;
    }
  }

  function apply() {
    var code = readCode();
    if (!code) {
      return;
    }
    var links = document.querySelectorAll('a[href]');
    for (var i = 0; i < links.length; i++) {
      var href = links[i].getAttribute('href');
      if (!href || href.charAt(0) === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) {
        continue;
      }
      links[i].setAttribute('href', withRef(href, code));
    }
    var forms = document.querySelectorAll('form');
    for (var j = 0; j < forms.length; j++) {
      var form = forms[j];
      var action = form.getAttribute('action') || window.location.href;
      try {
        var actionUrl = new URL(action, window.location.href);
        if (actionUrl.origin !== window.location.origin) {
          continue;
        }
      } catch (e) {
        continue;
      }
      if (form.querySelector('input[name="ref"], input[name="aff"]')) {
        continue;
      }
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'ref';
      input.value = code;
      form.appendChild(input);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else {
    apply();
  }
})();

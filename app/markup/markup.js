/* DW Alliance "Suggest an edit" markup button (2026-10-28).
 * Loaded by the small inline loader that 404.php adds to pages of brands in markup.json, only for visitors
 * with the dw_mk hint cookie or a #suggest-edit link. Shows a button only when /markup/?a=status says this
 * visitor has a signed-in session with rights on this brand. Everyone else sees nothing.
 * Screenshot capture uses html2canvas 1.4.1 (MIT), served locally from /markup/vendor/. */
(function () {
  'use strict';
  if (window.__dwMarkup) return;
  window.__dwMarkup = true;
  var me = document.currentScript;
  var brand = (me && me.getAttribute('data-brand')) || '';
  var BASE = '/markup/';
  var csrf = '';
  var host, root, ui = {};

  // dw_mk=1 only tells the page loader to fetch this script; it carries no secret. The session is the HttpOnly dw_markup cookie.
  function hint(on) {
    try {
      document.cookie = 'dw_mk=' + (on ? '1; Max-Age=2592000' : '; Max-Age=0') + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    } catch (e) {}
  }

  function q(path) { return BASE + path + (path.indexOf('?') >= 0 ? '&' : '?') + 'brand=' + encodeURIComponent(brand); }

  fetch(q('?a=status'), { credentials: 'same-origin', cache: 'no-store' })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (j) {
      if (j && j.ok && j.show && j.csrf) { hint(true); csrf = j.csrf; mountButton(); }
      else if (j && j.ok) { hint(false); }
      if (j && j.ok && !j.show && location.hash === '#suggest-edit') {
        location.href = q('?a=signin') + '&return=' + encodeURIComponent(location.pathname + location.search + '#suggest-edit');
      }
    })
    .catch(function () {});

  var CSS = [
    ':host{all:initial}',
    '*{box-sizing:border-box;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}',
    '.fab{position:fixed;right:18px;bottom:18px;z-index:2147483646;display:flex;align-items:center;gap:8px;background:#1f4d2b;color:#fff;border:0;border-radius:999px;padding:11px 16px;font-size:14px;font-weight:600;box-shadow:0 4px 14px rgba(0,0,0,.25);cursor:pointer}',
    '.fab:hover{background:#2b6b3c}',
    '.dim{position:fixed;inset:0;z-index:2147483645;background:rgba(10,20,12,.22);pointer-events:none}',
    'canvas{position:fixed;left:0;top:0;z-index:2147483646;cursor:crosshair;touch-action:none}',
    'canvas.pan{cursor:grab}canvas.pan.dragging{cursor:grabbing}',
    '.hint{position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:2147483647;background:rgba(29,42,32,.88);color:#fff;font-size:12px;padding:6px 10px;border-radius:8px;pointer-events:none;max-width:calc(100vw - 380px);text-align:center}',
    '@media (max-width:760px){.hint{bottom:auto;top:calc(100% - 210px);max-width:calc(100vw - 24px)}}',
    '.bar{position:fixed;left:50%;top:14px;transform:translateX(-50%);z-index:2147483647;display:flex;gap:4px;align-items:center;background:#fff;color:#1d2a20;border-radius:12px;padding:6px;box-shadow:0 6px 24px rgba(0,0,0,.28);flex-wrap:wrap;max-width:calc(100vw - 20px)}',
    '.bar button{border:0;background:transparent;border-radius:8px;padding:7px 10px;font-size:13px;color:#1d2a20;cursor:pointer;display:flex;gap:5px;align-items:center}',
    '.bar button:hover{background:#eef2ec}.bar button.on{background:#1f4d2b;color:#fff}',
    '.bar .sep{width:1px;height:24px;background:#dde3db;margin:0 3px}',
    '.bar .send{background:#d4572a;color:#fff;font-weight:600}.bar .send:hover{background:#b8451c}',
    '.notebox{position:fixed;right:16px;bottom:16px;z-index:2147483647;width:min(340px,calc(100vw - 32px));background:#fff;border-radius:12px;padding:10px;box-shadow:0 6px 24px rgba(0,0,0,.28)}',
    '.notebox label{display:block;font-size:12px;font-weight:600;color:#4a5a4d;margin:0 0 5px}',
    '.notebox textarea{width:100%;height:92px;resize:vertical;border:1px solid #c8d1c6;border-radius:8px;padding:8px;font-size:14px;color:#1d2a20}',
    '.txt{position:fixed;z-index:2147483647;font-size:16px;font-weight:700;color:#e0262b;border:2px dashed #e0262b;background:rgba(255,255,255,.9);padding:2px 5px;border-radius:4px;min-width:140px;outline:none}',
    '.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2147483647;background:#1d2a20;color:#fff;padding:11px 16px;border-radius:10px;font-size:14px;box-shadow:0 4px 14px rgba(0,0,0,.3)}',
    '.busy{position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.45);font-size:15px;font-weight:600;color:#1d2a20}',
    '@media (max-width:600px){.bar{left:10px;right:10px;transform:none;justify-content:center;max-width:none}.bar .lbl{display:none}.bar button{min-width:38px;min-height:38px}.bar .send .lbl{display:inline}}',
  ].join('\n');

  // toolbar button: icon plus a label that narrow screens hide (aria-label keeps the name)
  function btn(attrs, icon, label) {
    attrs.type = 'button'; attrs['aria-label'] = label;
    var b = el('button', attrs, icon + ' ');
    b.appendChild(el('span', { class: 'lbl' }, label));
    return b;
  }
  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    for (var k in (attrs || {})) e.setAttribute(k, attrs[k]);
    if (text != null) e.textContent = text;
    return e;
  }

  function mountButton() {
    host = el('div', { id: 'dw-markup-root', 'data-html2canvas-ignore': 'true' });
    root = host.attachShadow({ mode: 'open' });
    root.appendChild(el('style', null, CSS));
    ui.fab = el('button', { class: 'fab', type: 'button', title: 'Mark up this page and send an edit request', part: 'fab' }, '\u270E Suggest an edit');
    ui.fab.addEventListener('click', start);
    root.appendChild(ui.fab);
    document.documentElement.appendChild(host);
    // #suggest-edit starts edit mode now and whenever the hash is set again later.
    window.addEventListener('hashchange', function () { if (location.hash === '#suggest-edit') start(); });
    if (location.hash === '#suggest-edit') start();
  }

  // ----- drawing state -----
  // Shapes are stored in document coordinates (pageX/pageY), so the page can scroll while marking up:
  // the canvas stays viewport-sized and is redrawn at the current scroll offset.
  var cv, ctx, dpr, tool = 'pen', shapes = [], cur = null, active = false, sending = false;
  var ptrs = {}, pan = null, raf = 0, nodes = [], offs = [];
  var TOOLS = [['pen', '\u270E', 'Pen'], ['arrow', '\u2197', 'Arrow'], ['box', '\u25A2', 'Box'], ['text', 'T', 'Text'], ['highlight', '\u25AE', 'Highlight'], ['pan', '\u270B', 'Scroll']];
  var RED = '#e0262b';

  function on(target, type, fn, opts) { target.addEventListener(type, fn, opts); offs.push(function () { target.removeEventListener(type, fn, opts); }); }
  function add(n) { root.appendChild(n); nodes.push(n); return n; }

  function start() {
    if (active || sending || !ui.fab) return;
    active = true;
    try {
      if (location.hash === '#suggest-edit' && history.replaceState) history.replaceState(null, '', location.pathname + location.search);
      shapes = []; cur = null; ptrs = {}; pan = null; nodes = []; offs = [];
      ui.fab.style.display = 'none';
      ui.dim = add(el('div', { class: 'dim' }));
      cv = add(el('canvas'));
      dpr = Math.min(window.devicePixelRatio || 1, 3);
      ctx = cv.getContext('2d');
      sizeCanvas();
      ui.bar = el('div', { class: 'bar', role: 'toolbar', 'aria-label': 'Markup tools' });
      ui.toolBtns = {};
      TOOLS.forEach(function (t) {
        var b = btn({ 'data-tool': t[0], title: t[0] === 'pan' ? 'Scroll the page (drag)' : t[2] }, t[1], t[2]);
        b.addEventListener('click', function () { setTool(t[0]); });
        ui.toolBtns[t[0]] = b; ui.bar.appendChild(b);
      });
      ui.bar.appendChild(el('span', { class: 'sep' }));
      var undo = btn({ 'data-act': 'undo', title: 'Undo' }, '\u21B6', 'Undo');
      undo.addEventListener('click', function () { shapes.pop(); redraw(); });
      var cancel = btn({ 'data-act': 'cancel', title: 'Cancel' }, '\u2715', 'Cancel');
      cancel.addEventListener('click', stop);
      var send = btn({ class: 'send', 'data-act': 'send', title: 'Send' }, '\u27A4', 'Send');
      send.addEventListener('click', submit);
      ui.bar.appendChild(undo); ui.bar.appendChild(cancel); ui.bar.appendChild(send);
      add(ui.bar);
      ui.note = el('div', { class: 'notebox' });
      ui.note.appendChild(el('label', { for: 'dw-mk-note' }, 'What should change?'));
      ui.text = el('textarea', { id: 'dw-mk-note', maxlength: '5000', placeholder: 'e.g. Change this headline to "Family owned since 1998"' });
      ui.note.appendChild(ui.text);
      add(ui.note);
      ui.hint = add(el('div', { class: 'hint' }, ('ontouchstart' in window || navigator.maxTouchPoints > 0)
        ? 'Scroll with two fingers, or pick \u270B Scroll.' : 'Scroll with the mouse wheel or trackpad, or pick \u270B Scroll.'));
      setTimeout(function () { if (ui.hint && ui.hint.parentNode) ui.hint.parentNode.removeChild(ui.hint); }, 6000);
      setTool('pen');
      on(cv, 'pointerdown', down);
      on(cv, 'pointermove', move);
      on(window, 'pointerup', up);
      on(window, 'pointercancel', up);
      on(window, 'resize', onResize);
      on(window, 'scroll', onScroll, { passive: true });
      on(window, 'keydown', onKey, true);
      // the canvas is touch-action:none, so stop the browser's own touch gestures there; two fingers scroll via down/move
      on(cv, 'touchmove', prevent, { passive: false });
      redraw();
    } catch (err) {
      stop();
      toast('Could not start markup: ' + (err && err.message ? err.message : err), 6000);
    }
  }

  function prevent(e) { e.preventDefault(); }
  function onKey(e) { if (e.key === 'Escape' && active && !ui.textInput && !sending) { e.stopPropagation(); stop(); } }
  function onResize() { sizeCanvas(); redraw(); }
  function onScroll() {
    if (raf) return;
    raf = requestAnimationFrame(function () { raf = 0; placeText(); redraw(); });
  }

  function sizeCanvas() {
    var w = document.documentElement.clientWidth || window.innerWidth, h = window.innerHeight;
    cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr);
    cv.style.width = w + 'px'; cv.style.height = h + 'px';
  }

  // Leave edit mode completely: drop every node and listener we added and bring the button back.
  function stop() {
    active = false;
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
    offs.forEach(function (f) { try { f(); } catch (e) {} });
    offs = [];
    if (ui.textInput) nodes.push(ui.textInput);
    nodes.forEach(function (n) { if (n && n.parentNode) n.parentNode.removeChild(n); });
    nodes = [];
    ui.textInput = null; ui.dim = ui.bar = ui.note = ui.hint = null;
    cv = ctx = null; cur = null; pan = null; ptrs = {}; shapes = [];
    if (ui.fab) ui.fab.style.display = '';
  }

  function setTool(t) {
    if (ui.textInput) commitText();
    tool = t;
    for (var k in ui.toolBtns) ui.toolBtns[k].classList.toggle('on', k === t);
    if (cv) cv.classList.toggle('pan', t === 'pan');
  }

  function pt(e) { return { x: e.clientX + window.scrollX, y: e.clientY + window.scrollY }; }
  function mid() {
    var ks = Object.keys(ptrs), x = 0, y = 0;
    ks.forEach(function (k) { x += ptrs[k].x; y += ptrs[k].y; });
    return { x: x / ks.length, y: y / ks.length };
  }

  function down(e) {
    ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
    try { cv.setPointerCapture(e.pointerId); } catch (x) {}
    var n = Object.keys(ptrs).length;
    if (n >= 2 || tool === 'pan') {
      // two fingers (or the Scroll tool) pan the page; drop any half-drawn stroke
      cur = null; pan = mid(); cv.classList.add('dragging'); redraw();
      return;
    }
    if (ui.textInput) { commitText(); return; }
    var p = pt(e);
    if (tool === 'text') { e.preventDefault(); openText(p); return; }
    cur = { t: tool, pts: [p], a: p, b: p };
  }
  function move(e) {
    if (ptrs[e.pointerId]) ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
    if (pan) {
      var m = mid();
      // 'instant' so a site-wide scroll-behavior:smooth does not swallow the small steps
      window.scrollTo({ left: window.scrollX + pan.x - m.x, top: window.scrollY + pan.y - m.y, behavior: 'instant' });
      pan = m;
      return;
    }
    if (!cur) return;
    var p = pt(e);
    if (cur.t === 'pen') cur.pts.push(p); else cur.b = p;
    redraw();
  }
  function up(e) {
    if (e && e.pointerId != null) delete ptrs[e.pointerId];
    if (pan) {
      if (Object.keys(ptrs).length) { pan = mid(); return; }
      pan = null; if (cv) cv.classList.remove('dragging');
      return;
    }
    if (!cur) return;
    var c = cur; cur = null;
    var big = c.t === 'pen' ? c.pts.length > 2 : (Math.abs(c.a.x - c.b.x) + Math.abs(c.a.y - c.b.y) > 6);
    if (big) { c.sel = selectorFor(c); shapes.push(c); }
    redraw();
  }

  function placeText() {
    var inp = ui.textInput; if (!inp) return;
    inp.style.left = (inp._p.x - window.scrollX) + 'px'; inp.style.top = (inp._p.y - window.scrollY - 14) + 'px';
  }
  function openText(p) {
    var inp = el('div', { class: 'txt', contenteditable: 'true', role: 'textbox', 'aria-label': 'Text label' });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); commitText(); }
      if (e.key === 'Escape') { e.preventDefault(); inp.textContent = ''; commitText(); }
    });
    inp._p = p;
    root.appendChild(inp);
    ui.textInput = inp;
    placeText();
    inp.focus();
    setTimeout(function () { if (ui.textInput === inp) inp.focus(); }, 0);
  }
  function commitText() {
    var inp = ui.textInput; if (!inp) return;
    ui.textInput = null;
    var t = (inp.textContent || '').trim().slice(0, 200);
    if (inp.parentNode) inp.parentNode.removeChild(inp);
    if (t) { var s = { t: 'text', a: inp._p, text: t }; s.sel = selectorFor(s); shapes.push(s); }
    redraw();
  }

  // Draw shape s onto context c: document point (x, y) lands at ((x - ox) * k, (y - oy) * k).
  function drawShape(c, s, k, ox, oy) {
    var X = function (v) { return (v - ox) * k; }, Y = function (v) { return (v - oy) * k; };
    c.save();
    c.lineCap = 'round'; c.lineJoin = 'round';
    if (s.t === 'highlight') {
      c.fillStyle = 'rgba(255,230,0,.38)'; c.strokeStyle = 'rgba(230,180,0,.9)'; c.lineWidth = 2 * k;
      var hx = X(Math.min(s.a.x, s.b.x)), hy = Y(Math.min(s.a.y, s.b.y)), hw = Math.abs(s.a.x - s.b.x) * k, hh = Math.abs(s.a.y - s.b.y) * k;
      c.fillRect(hx, hy, hw, hh); c.strokeRect(hx, hy, hw, hh);
    } else if (s.t === 'text') {
      c.font = 'bold ' + (18 * k) + 'px system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif';
      c.textBaseline = 'middle';
      var w = c.measureText(s.text).width;
      c.fillStyle = 'rgba(255,255,255,.92)';
      c.fillRect(X(s.a.x) - 5 * k, Y(s.a.y) - 14 * k, w + 10 * k, 28 * k);
      c.fillStyle = RED; c.fillText(s.text, X(s.a.x), Y(s.a.y));
    } else {
      c.strokeStyle = RED; c.lineWidth = 4 * k;
      c.beginPath();
      if (s.t === 'pen') {
        s.pts.forEach(function (p, i) { if (i) c.lineTo(X(p.x), Y(p.y)); else c.moveTo(X(p.x), Y(p.y)); });
      } else if (s.t === 'box') {
        c.rect(X(Math.min(s.a.x, s.b.x)), Y(Math.min(s.a.y, s.b.y)), Math.abs(s.a.x - s.b.x) * k, Math.abs(s.a.y - s.b.y) * k);
      } else if (s.t === 'arrow') {
        var ang = Math.atan2(s.b.y - s.a.y, s.b.x - s.a.x), L = 18 * k;
        c.moveTo(X(s.a.x), Y(s.a.y)); c.lineTo(X(s.b.x), Y(s.b.y));
        c.moveTo(X(s.b.x), Y(s.b.y)); c.lineTo(X(s.b.x) - L * Math.cos(ang - 0.45), Y(s.b.y) - L * Math.sin(ang - 0.45));
        c.moveTo(X(s.b.x), Y(s.b.y)); c.lineTo(X(s.b.x) - L * Math.cos(ang + 0.45), Y(s.b.y) - L * Math.sin(ang + 0.45));
      }
      c.stroke();
    }
    c.restore();
  }

  function redraw() {
    if (!ctx) return;
    ctx.clearRect(0, 0, cv.width, cv.height);
    var ox = window.scrollX, oy = window.scrollY;
    shapes.concat(cur ? [cur] : []).forEach(function (s) { drawShape(ctx, s, dpr, ox, oy); });
  }

  function bounds(s) {
    var ps = s.t === 'pen' ? s.pts : s.t === 'text' ? [s.a, { x: s.a.x + 10 * s.text.length + 10, y: s.a.y + 14 }, { x: s.a.x - 5, y: s.a.y - 14 }] : [s.a, s.b];
    var xs = ps.map(function (p) { return p.x; }), ys = ps.map(function (p) { return p.y; });
    return { x0: Math.min.apply(0, xs), y0: Math.min.apply(0, ys), x1: Math.max.apply(0, xs), y1: Math.max.apply(0, ys) };
  }

  // ----- selectors of marked elements -----
  function anchor(s) {
    if (s.t === 'pen') { var b = bounds(s); return { x: (b.x0 + b.x1) / 2, y: (b.y0 + b.y1) / 2 }; }
    if (s.t === 'arrow') return s.b;
    if (s.t === 'text') return s.a;
    return { x: (s.a.x + s.b.x) / 2, y: (s.a.y + s.b.y) / 2 };
  }
  function cssPath(n) {
    var parts = [];
    while (n && n.nodeType === 1 && n !== document.documentElement && parts.length < 6) {
      if (n.id && /^[A-Za-z][\w-]*$/.test(n.id) && document.querySelectorAll('#' + n.id).length === 1) { parts.unshift('#' + n.id); break; }
      var tag = n.tagName.toLowerCase(), part = tag;
      var cls = Array.prototype.filter.call(n.classList || [], function (c) { return /^[A-Za-z][\w-]*$/.test(c); }).slice(0, 2);
      if (cls.length) part += '.' + cls.join('.');
      var p = n.parentElement;
      if (p) {
        var same = Array.prototype.filter.call(p.children, function (c) { return c.tagName === n.tagName; });
        if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(n) + 1) + ')';
      }
      parts.unshift(part);
      n = p;
    }
    return parts.join(' > ');
  }
  // Worked out when the shape is finished, while it is on screen (elementsFromPoint only sees the viewport).
  function selectorFor(s) {
    try {
      var a = anchor(s);
      var list = document.elementsFromPoint(a.x - window.scrollX, a.y - window.scrollY).filter(function (e) { return e !== host && e !== document.documentElement && e !== document.body; });
      return list.length ? cssPath(list[0]) : '';
    } catch (e) { return ''; }
  }
  function selectors() {
    var out = [];
    shapes.forEach(function (s) { if (s.sel && out.indexOf(s.sel) < 0) out.push(s.sel); });
    return out.slice(0, 30);
  }

  // ----- capture and send -----
  function loadH2C() {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    return new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = BASE + 'vendor/html2canvas.min.js';
      s.onload = function () { window.html2canvas ? res(window.html2canvas) : rej(new Error('capture library missing')); };
      s.onerror = function () { rej(new Error('capture library failed to load')); };
      document.head.appendChild(s);
    });
  }
  function toBlob(c, type, qual) { return new Promise(function (res) { c.toBlob(res, type, qual); }); }

  // Lazy images below the fold would be blank in a full-page capture; ask for them now (max 4 s).
  function loadLazyImages(r) {
    var waits = [];
    Array.prototype.forEach.call(document.querySelectorAll('img[loading="lazy"]'), function (img) {
      var b = img.getBoundingClientRect(), top = b.top + window.scrollY;
      if (top > r.y + r.h || top + b.height < r.y) return;
      img.loading = 'eager';
      if (!img.complete) waits.push(new Promise(function (res) { img.addEventListener('load', res, { once: true }); img.addEventListener('error', res, { once: true }); }));
    });
    return waits.length ? Promise.race([Promise.all(waits), new Promise(function (res) { setTimeout(res, 4000); })]) : Promise.resolve();
  }

  // What to capture: the whole page when it is a sensible size, otherwise the current view plus every drawing (padded).
  var FULL_MAX_H = 12000, MAX_PIXELS = 30000000, MAX_SIDE = 16000;
  function captureRect() {
    var de = document.documentElement, body = document.body;
    var dw = Math.max(de.clientWidth, Math.min(de.scrollWidth, body ? body.scrollWidth : 0)), dh = Math.max(de.scrollHeight, body ? body.scrollHeight : 0);
    var vw = de.clientWidth || window.innerWidth, vh = window.innerHeight;
    if (dh <= FULL_MAX_H) return { x: 0, y: 0, w: dw, h: dh, full: true };
    var y0 = window.scrollY, y1 = window.scrollY + vh;
    shapes.forEach(function (s) { var b = bounds(s); y0 = Math.min(y0, b.y0 - 200); y1 = Math.max(y1, b.y1 + 200); });
    y0 = Math.max(0, Math.floor(y0)); y1 = Math.min(dh, Math.ceil(y1));
    if (y1 - y0 > FULL_MAX_H) { y0 = window.scrollY; y1 = Math.min(dh, window.scrollY + vh); }
    return { x: 0, y: y0, w: Math.max(vw, dw), h: y1 - y0, full: false };
  }

  function toast(t, ms) {
    var d = el('div', { class: 'toast', role: 'status' }, t);
    root.appendChild(d);
    setTimeout(function () { if (d.parentNode) d.parentNode.removeChild(d); }, ms || 4000);
  }

  function submit() {
    if (sending || !active) return;
    if (ui.textInput) commitText();
    sending = true;
    var sels = selectors();
    var vw = document.documentElement.clientWidth || window.innerWidth, vh = window.innerHeight;
    var sx = window.scrollX, sy = window.scrollY;
    var r = captureRect();
    var busy = el('div', { class: 'busy' }, 'Capturing\u2026');
    // hide our own UI from the capture (it is also marked data-html2canvas-ignore)
    ui.bar.style.visibility = 'hidden'; ui.note.style.visibility = 'hidden';
    var scale = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(MAX_PIXELS / Math.max(1, r.w * r.h)), MAX_SIDE / Math.max(1, r.h), MAX_SIDE / Math.max(1, r.w));
    root.appendChild(busy);
    loadH2C().then(function (h2c) {
      return loadLazyImages(r).then(function () {
        return h2c(document.body, {
          x: r.x, y: r.y, width: r.w, height: r.h,
          scrollX: 0, scrollY: 0,
          windowWidth: vw, windowHeight: vh,
          scale: scale, useCORS: true, logging: false, backgroundColor: '#ffffff',
          ignoreElements: function (n) { return n === host; }
        });
      });
    }).then(function (shot) {
      var out = document.createElement('canvas');
      out.width = shot.width; out.height = shot.height;
      var c = out.getContext('2d');
      c.drawImage(shot, 0, 0);
      var k = shot.width / r.w;
      shapes.forEach(function (s) { drawShape(c, s, k, r.x, r.y); });
      return toBlob(out, 'image/jpeg', 0.85).then(function (b) {
        return b && b.size > 9 * 1024 * 1024 ? toBlob(out, 'image/jpeg', 0.55) : b;
      });
    }).then(function (blob) {
      if (!blob) throw new Error('could not make the screenshot');
      busy.textContent = 'Sending\u2026';
      var fd = new FormData();
      fd.append('a', 'submit');
      fd.append('brand', brand);
      fd.append('note', ui.text.value);
      fd.append('page_url', location.href);
      fd.append('capture', (r.full ? 'full page ' : 'part ') + r.w + 'x' + r.h + ' from y=' + r.y + ', view at ' + sx + ',' + sy);
      fd.append('viewport', vw + 'x' + vh + '@' + (Math.round((window.devicePixelRatio || 1) * 100) / 100));
      fd.append('selectors', JSON.stringify(sels));
      fd.append('image', blob, 'markup.jpg');
      return fetch(BASE, { method: 'POST', credentials: 'same-origin', headers: { 'X-DW-Markup': '1', 'X-DW-CSRF': csrf }, body: fd })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
    }).then(function (j) {
      sending = false;
      if (busy.parentNode) busy.parentNode.removeChild(busy);
      if (j && j.ok) {
        window.__dwMarkupLast = j.id;
        stop();
        toast('Thanks! Your edit request was sent.');
      } else {
        if (ui.bar) { ui.bar.style.visibility = ''; ui.note.style.visibility = ''; }
        toast('Could not send: ' + ((j && j.error) || 'unknown error'), 6000);
      }
    }).catch(function (err) {
      sending = false;
      if (busy.parentNode) busy.parentNode.removeChild(busy);
      if (ui.bar) { ui.bar.style.visibility = ''; ui.note.style.visibility = ''; }
      toast('Could not send: ' + (err && err.message ? err.message : err), 6000);
    });
  }
})();

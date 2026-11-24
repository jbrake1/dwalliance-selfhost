/* DW Alliance JS client v0.1.2
 * Vanilla browser/Node library for the live JSON APIs on a DW Alliance origin.
 * Does not use or overwrite window.$ / jQuery. Global is `dw` (fallback `DW`).
 */
(function (root, factory) {
  var api = factory(root);
  var isMod = typeof module === 'object' && module.exports;
  if (isMod) module.exports = api;

  if (root && typeof root === 'object') {
    var prevDw = root.dw;
    var prevDW = root.DW;
    api.__dwAlliance = '0.1.2';
    api.noConflict = function (all) {
      if (root.dw === api) root.dw = prevDw;
      if (all && root.DW === api) root.DW = prevDW;
      return api;
    };
    if (!prevDw) {
      root.dw = api;
    } else if (!prevDw.__dwAlliance && !prevDW) {
      root.DW = api;
    } else if (!prevDw.__dwAlliance && prevDW && !prevDW.__dwAlliance) {
      /* both names taken — module export / noConflict only */
    } else if (prevDw && prevDw.__dwAlliance) {
      root.dw = api;
    }
    if (root.dw === api && !root.DW) root.DW = api;
  }
})(typeof window !== 'undefined' ? window : typeof globalThis !== 'undefined' ? globalThis : this, function (root) {
  'use strict';

  var inst;
  var VERSION = '0.1.2';
  var APP_ORIGIN = 'https://app.dwalliance.com';
  /* Edge client_max_body_size is 12m; base64 adds about a third. */
  var MAX_BODY = 12 * 1024 * 1024;
  var SESSION_RE = /^[0-9a-f]{50}$/;
  var STORAGE_KEY = 'dw_session';
  var TEXT_TYPES = { HTML: 1, CSS: 1, JS: 1, JSON: 1, TXT: 1, SVG: 1 };

  function isBrowser() {
    return typeof window !== 'undefined' && typeof document !== 'undefined';
  }

  function DwError(message, extra) {
    var e = new Error(message || 'DW request failed');
    e.name = 'DwError';
    extra = extra || {};
    e.status = extra.status;
    e.code = extra.code;
    e.body = extra.body;
    e.path = extra.path;
    return e;
  }

  function defaultOrigin() {
    if (isBrowser() && window.location && /^https?:$/.test(window.location.protocol)) {
      return '';
    }
    return APP_ORIGIN;
  }

  function trimSlash(s) {
    return String(s || '').replace(/\/+$/, '');
  }

  function joinUrl(origin, path) {
    var p = String(path || '');
    if (/^https?:\/\//i.test(p)) return p;
    if (p.charAt(0) !== '/') p = '/' + p;
    var o = origin == null || origin === '' ? '' : trimSlash(origin);
    return o + p;
  }

  function isPlainObject(v) {
    return !!v && typeof v === 'object' && !Array.isArray(v) &&
      !(typeof Uint8Array !== 'undefined' && v instanceof Uint8Array) &&
      !(typeof ArrayBuffer !== 'undefined' && v instanceof ArrayBuffer) &&
      !(typeof Blob !== 'undefined' && v instanceof Blob) &&
      !(typeof FormData !== 'undefined' && v instanceof FormData) &&
      !(typeof Buffer !== 'undefined' && typeof Buffer.isBuffer === 'function' && Buffer.isBuffer(v));
  }

  function assign() {
    var out = {};
    for (var i = 0; i < arguments.length; i++) {
      var s = arguments[i];
      if (!s) continue;
      var keys = Object.keys(s);
      for (var k = 0; k < keys.length; k++) out[keys[k]] = s[keys[k]];
    }
    return out;
  }


  function toBase64(data) {
    if (data == null) throw DwError('no data to encode');
    if (typeof data === 'string') {
      if (typeof Buffer !== 'undefined') return Buffer.from(data, 'utf8').toString('base64');
      return btoa(unescape(encodeURIComponent(data)));
    }
    if (typeof ArrayBuffer !== 'undefined' && data instanceof ArrayBuffer) data = new Uint8Array(data);
    if (typeof Buffer !== 'undefined' && Buffer.isBuffer(data)) return data.toString('base64');
    if (typeof Uint8Array !== 'undefined' && data instanceof Uint8Array) {
      if (typeof Buffer !== 'undefined') return Buffer.from(data).toString('base64');
      var s = '';
      var step = 0x8000;
      for (var i = 0; i < data.length; i += step) {
        s += String.fromCharCode.apply(null, data.subarray(i, i + step));
      }
      return btoa(s);
    }
    throw DwError('unsupported data type for file_data');
  }

  function fromBase64(b64) {
    var s = String(b64 || '');
    if (typeof Buffer !== 'undefined') return new Uint8Array(Buffer.from(s, 'base64'));
    var bin = atob(s);
    var out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function bytesToText(bytes) {
    if (typeof Buffer !== 'undefined') return Buffer.from(bytes).toString('utf8');
    var u = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
    if (typeof TextDecoder !== 'undefined') return new TextDecoder('utf-8').decode(u);
    var s = '';
    for (var i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
    return decodeURIComponent(escape(s));
  }

  function normalizeSession(id) {
    if (id == null || id === false) return '';
    var s = String(id).trim().toLowerCase();
    if (!s) return '';
    if (!SESSION_RE.test(s)) throw DwError('session id must be a 50-character hex string');
    return s;
  }

  function readCookie(name) {
    if (!isBrowser()) return '';
    var parts = String(document.cookie || '').split(';');
    var prefix = name + '=';
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i].replace(/^\s+/, '');
      if (p.indexOf(prefix) === 0) return decodeURIComponent(p.slice(prefix.length));
    }
    return '';
  }

  function storageOf(kind) {
    if (kind && typeof kind === 'object' && (kind.get || kind.set)) return kind;
    if (!isBrowser()) return null;
    try {
      if (kind === 'local') return window.localStorage;
      if (kind === 'session' || kind === true) return window.sessionStorage;
    } catch (e) { /* disabled */ }
    return null;
  }

  function queryString(obj) {
    if (!obj) return '';
    if (typeof obj === 'string') return obj.charAt(0) === '?' ? obj.slice(1) : obj;
    var keys = Object.keys(obj);
    var bits = [];
    for (var i = 0; i < keys.length; i++) {
      if (obj[keys[i]] == null) continue;
      bits.push(encodeURIComponent(keys[i]) + '=' + encodeURIComponent(String(obj[keys[i]])));
    }
    return bits.join('&');
  }

  function parseResponse(res, text) {
    var ct = (res.headers && res.headers.get && res.headers.get('content-type')) || '';
    if (/json/i.test(ct) || /^\s*[{[]/.test(text)) {
      try { return JSON.parse(text); } catch (e) { return text; }
    }
    return text;
  }

  function decodeFilePayload(body) {
    if (!body || typeof body !== 'object') return body;
    var file = body.file;
    if (file && typeof file === 'object') {
      if (typeof file.file_text === 'string') {
        file.text = file.file_text;
      } else if (typeof file.file_data === 'string') {
        file.bytes = fromBase64(file.file_data);
        if (TEXT_TYPES[file.file_type]) file.text = bytesToText(file.bytes);
      }
    }
    return body;
  }

  function encodeFileFields(fields) {
    var out = assign({}, fields);
    if (out.data != null && out.file_data == null && out.file == null) {
      out.file_data = toBase64(out.data);
      delete out.data;
    }
    if (out.file_text != null && out.file_data == null && typeof out.file_text !== 'string') {
      out.file_text = bytesToText(out.file_text);
    }
    return out;
  }

  function create(init) {
    init = init || {};
    var cfg = {
      origin: init.origin != null ? init.origin : defaultOrigin(),
      session: init.session ? normalizeSession(init.session) : '',
      token: init.token ? String(init.token) : '',
      brand: init.brand ? String(init.brand) : '',
      domain: init.domain ? String(init.domain) : '',
      storage: init.storage == null ? false : init.storage,
      storageKey: init.storageKey || STORAGE_KEY,
      credentials: init.credentials == null ? 'include' : init.credentials,
      timeout: init.timeout || 0
    };
    var caps = { known: false, modules: {} };
    var readyState = null;

    function store() {
      return storageOf(cfg.storage);
    }

    function storedSession() {
      var st = store();
      if (!st) return '';
      try {
        var v = typeof st.get === 'function' ? st.get(cfg.storageKey) : st.getItem(cfg.storageKey);
        return v ? normalizeSession(v) : '';
      } catch (e) {
        return '';
      }
    }

    function persistSession(id) {
      var st = store();
      if (!st) return;
      try {
        if (!id) {
          if (typeof st.remove === 'function') st.remove(cfg.storageKey);
          else if (typeof st.removeItem === 'function') st.removeItem(cfg.storageKey);
          else if (typeof st.set === 'function') st.set(cfg.storageKey, '');
        } else if (typeof st.set === 'function') {
          st.set(cfg.storageKey, id);
        } else if (typeof st.setItem === 'function') {
          st.setItem(cfg.storageKey, id);
        }
      } catch (e) { /* ignore quota / private mode */ }
    }

    /* Precedence: explicit per-call, client option, stored id, readable `session` cookie.
       __Host-dw_s is HttpOnly and is never readable here; same-origin requests may still
       send it automatically when credentials are included and the JSON field is omitted. */
    function resolveSession(callOpts) {
      callOpts = callOpts || {};
      if (Object.prototype.hasOwnProperty.call(callOpts, 'session')) {
        return callOpts.session ? normalizeSession(callOpts.session) : '';
      }
      if (cfg.session) return cfg.session;
      var stored = storedSession();
      if (stored) return stored;
      try {
        var cookie = readCookie('session');
        if (cookie) return normalizeSession(cookie);
      } catch (e) { /* ignore */ }
      return '';
    }

    function resolveSource(callOpts) {
      callOpts = callOpts || {};
      if (Object.prototype.hasOwnProperty.call(callOpts, 'session')) return callOpts.session ? 'explicit' : 'none';
      if (cfg.session) return 'explicit';
      if (storedSession()) return 'stored';
      try { if (readCookie('session')) return 'cookie'; } catch (e) {}
      return isBrowser() ? 'implicit' : 'none';
    }

    function setSession(id, persist) {
      cfg.session = id ? normalizeSession(id) : '';
      if (persist || cfg.storage) persistSession(cfg.session);
      if (!cfg.session && cfg.storage) persistSession('');
      return api;
    }

    function injectAuth(body, callOpts, headers, isForm) {
      var sid = resolveSession(callOpts);
      var token = (callOpts && callOpts.token != null) ? callOpts.token : cfg.token;
      if (sid) {
        if (isForm) body.set('session', sid);
        else body.session = sid;
      }
      if (cfg.brand && !isForm && body.brand == null) body.brand = cfg.brand;
      if (cfg.brand && isForm && !body.has('brand')) body.set('brand', cfg.brand);
      if (token) headers.Authorization = 'Bearer ' + token;
      return sid;
    }

    function doFetch(method, path, opts) {
      opts = opts || {};
      var url = joinUrl(cfg.origin, path);
      if (opts.query) {
        var qs = queryString(opts.query);
        if (qs) url += (url.indexOf('?') >= 0 ? '&' : '?') + qs;
      }
      var headers = assign({}, opts.headers || {});
      var body = opts.body;
      var isForm = typeof FormData !== 'undefined' && body instanceof FormData;
      /* Help is an empty POST. /session/ treats any non-empty body as a sign-in request,
         so never inject session/brand unless the caller asked (auth !== false). */
      var skipAuth = opts.help === true || opts.auth === false;
      if (body && !isForm && isPlainObject(body)) {
        body = assign({}, body);
        if (!skipAuth) injectAuth(body, opts, headers, false);
        var json = JSON.stringify(body);
        if (json.length > MAX_BODY) {
          return Promise.reject(DwError('request body exceeds the platform ~12MB limit; split or compress', { path: path, code: 'payload_too_large' }));
        }
        if (!headers['Content-Type'] && !headers['content-type']) headers['Content-Type'] = 'application/json';
        body = json;
      } else if (isForm && !skipAuth) {
        injectAuth(body, opts, headers, true);
      } else if (body != null && typeof body === 'string' && body.length > MAX_BODY) {
        return Promise.reject(DwError('request body exceeds the platform ~12MB limit; split or compress', { path: path, code: 'payload_too_large' }));
      }

      var initReq = {
        method: method,
        headers: headers,
        credentials: opts.credentials != null ? opts.credentials : cfg.credentials
      };
      if (method !== 'GET' && method !== 'HEAD' && body != null) initReq.body = body;

      var ctrl = null;
      var t = opts.timeout != null ? opts.timeout : cfg.timeout;
      if (t && typeof AbortController !== 'undefined') {
        ctrl = new AbortController();
        initReq.signal = ctrl.signal;
        setTimeout(function () { try { ctrl.abort(); } catch (e) {} }, t);
      }

      return fetch(url, initReq).then(function (res) {
        return res.text().then(function (text) {
          var parsed = parseResponse(res, text);
          if (!res.ok || (parsed && typeof parsed === 'object' && parsed.ok === false)) {
            var msg = (parsed && parsed.error) || ('HTTP ' + res.status);
            throw DwError(msg, { status: res.status, body: parsed, path: path, code: parsed && parsed.error });
          }
          return parsed;
        });
      });
    }

    function post(path, body, callOpts) {
      callOpts = callOpts || {};
      return doFetch('POST', path, assign({}, callOpts, { body: body == null ? {} : body }));
    }

    function get(path, query, callOpts) {
      callOpts = callOpts || {};
      return doFetch('GET', path, assign({}, callOpts, { query: query }));
    }

    function help(path, callOpts) {
      return post(path || '/', {}, assign({ help: true }, callOpts || {}));
    }

    function actionPost(path, action, fields, callOpts) {
      var body = assign({}, fields || {});
      if (action) body.action = action;
      return post(path, body, callOpts);
    }

    function filesCreate(fields, callOpts) {
      fields = encodeFileFields(fields || {});
      if (fields.file && typeof FormData !== 'undefined' && !(fields instanceof FormData)) {
        /* `{ file: Blob|File }` → multipart, which /files/ also accepts */
        var fd = new FormData();
        var keys = Object.keys(fields);
        for (var i = 0; i < keys.length; i++) {
          var k = keys[i];
          if (k === 'file' || k === 'session') continue;
          if (fields[k] == null) continue;
          fd.append(k, typeof fields[k] === 'object' ? JSON.stringify(fields[k]) : String(fields[k]));
        }
        fd.append('action', 'create');
        fd.append('file', fields.file);
        return post('/files/', fd, callOpts);
      }
      return actionPost('/files/', 'create', fields, callOpts);
    }

    function filesRead(idOrFields, callOpts) {
      var fields = typeof idOrFields === 'string' ? { id: idOrFields } : assign({}, idOrFields || {});
      if (fields.format == null && (fields.text === true || fields.format === 'text')) fields.format = 'text';
      if (fields.text === true) delete fields.text;
      return actionPost('/files/', 'read', fields, callOpts).then(decodeFilePayload);
    }

    function filesUpdate(idOrFields, patch, callOpts) {
      var fields;
      if (typeof idOrFields === 'string') fields = assign({ id: idOrFields }, patch || {});
      else fields = assign({}, idOrFields, patch || {});
      fields = encodeFileFields(fields);
      return actionPost('/files/', 'update', fields, callOpts);
    }

    function filesDelete(idOrFields, callOpts) {
      var fields = typeof idOrFields === 'string' ? { id: idOrFields } : assign({}, idOrFields || {});
      return actionPost('/files/', 'delete', fields, callOpts);
    }

    function filesSearch(fields, callOpts) {
      return actionPost('/files/', 'search', fields || {}, callOpts);
    }

    var MODULES = {
      files: '/files/',
      crm: '/crm/',
      relation: '/relation/',
      domain: '/domain/',
      dns: '/dns/',
      notes: '/notes/',
      note_files: '/note_files/',
      address: '/address/',
      permissions: '/permissions/',
      store: '/store/',
      session: '/session/',
      customer_session: '/customer_session/',
      account_setup: '/account_setup/',
      link_tree: '/link_tree/'
    };

    function modulePath(name) {
      var n = String(name || '').trim();
      if (!n) return '/';
      if (MODULES[n]) return MODULES[n];
      if (n.charAt(0) !== '/') n = '/' + n;
      if (n.charAt(n.length - 1) !== '/') n += '/';
      return n;
    }

    /* Primary API: POST {action, ...data, session} to the module path. */
    function call(module, action, data, callOpts) {
      var path = modulePath(module);
      var fields = assign({}, data || {});
      if (action) fields.action = action;
      if (path.indexOf('/files/') === 0) {
        fields = encodeFileFields(fields);
        if ((action === 'create' || fields.action === 'create') && fields.file && typeof FormData !== 'undefined') {
          return filesCreate(fields, callOpts);
        }
      }
      var req = post(path, fields, callOpts);
      if (path.indexOf('/files/') === 0 && action === 'read') req = req.then(decodeFilePayload);
      return req;
    }

    function resource(path) {
      return {
        path: path,
        get: function (query, o) { return get(path, query, o); },
        post: function (body, o) { return post(path, body, o); },
        help: function (o) { return help(path, o); },
        call: function (action, data, o) { return call(path, action, data, o); }
      };
    }

    function applyPermissions(body) {
      caps.known = true;
      caps.super_admin = !!body.super_admin;
      caps.modules = {};
      var rights = (body && body.rights) || {};
      Object.keys(rights).forEach(function (mod) {
        var rec = {};
        (rights[mod] || []).forEach(function (r) { rec[r] = true; });
        if (caps.super_admin) rec.SUPER_ADMIN = true;
        caps.modules[mod] = rec;
      });
    }

    function can(module, right) {
      if (!caps.known) return 'unknown';
      if (caps.super_admin) return true;
      var m = caps.modules[module];
      if (!m) return false;
      if (m.SUPER_ADMIN || m['*']) return true;
      if (!right) return true;
      return !!m[right];
    }

    function probeFiles(callOpts, info) {
      return filesSearch({ limit: 1 }, callOpts).then(function (body) {
        info.files = { ok: true, count: (body && body.files && body.files.length) || 0 };
        if (!caps.known) {
          caps.known = true;
          caps.modules.FILE = { READ: true, search: true };
        } else if (caps.modules.FILE) {
          caps.modules.FILE.READ = true;
          caps.modules.FILE.search = true;
        }
        info.ok = true;
      }).catch(function (err) {
        info.files = { ok: false, status: err && err.status, error: err && err.message };
        if (caps.known) {
          info.ok = info.ok || !!resolveSession(callOpts) || resolveSource(callOpts) === 'implicit';
          return;
        }
        if (err && err.status === 401) {
          caps.known = true;
          info.ok = false;
        } else if (err && err.status === 403) {
          caps.known = true;
          caps.modules.FILE = { READ: false };
          info.ok = !!resolveSession(callOpts) || resolveSource(callOpts) === 'implicit';
        } else {
          info.ok = !!resolveSession(callOpts);
        }
      });
    }

    function ready(callOpts) {
      var auth = resolveSource(callOpts);
      var sid = resolveSession(callOpts);
      var info = { ok: false, auth: auth, hasSession: !!sid, capabilities: caps, whoami: null, files: null, permissions: null };
      var steps = [];

      if (isBrowser()) {
        var host = (root.location && root.location.hostname) || '';
        var onApp = /(^|\.)app\.dwalliance\.com$/i.test(host);
        if (host && !onApp) {
          steps.push(get('/_dw/whoami', null, callOpts).then(function (w) {
            info.whoami = w;
          }).catch(function () {
            info.whoami = null;
          }));
        }
      }

      /* Prefer POST /session/ get_permissions (live rights snapshot, grouped
         by module). Older servers treat an unknown action as a failed sign-in
         (400) and do not create a session; fall back to a files search probe. */
      if (sid || auth === 'implicit' || cfg.token) {
        var permFields = {};
        if (callOpts && callOpts.domain) permFields.domain = callOpts.domain;
        else if (cfg.domain) permFields.domain = cfg.domain;
        if (callOpts && callOpts.brand) permFields.brand = callOpts.brand;
        else if (cfg.brand) permFields.brand = cfg.brand;
        steps.push(call('session', 'get_permissions', permFields, callOpts).then(function (body) {
          if (!body || body.ok !== true || !body.rights) {
            var err = new DwError('get_permissions returned an unexpected body');
            err.status = 0;
            throw err;
          }
          applyPermissions(body);
          info.permissions = {
            ok: true,
            super_admin: !!body.super_admin,
            modules: Object.keys(body.rights)
          };
          info.ok = true;
        }).catch(function (err) {
          info.permissions = { ok: false, status: err && err.status, error: err && err.message };
          return probeFiles(callOpts, info);
        }));
      } else {
        info.ok = true; /* anonymous client; public GETs and help still work */
        info.auth = 'none';
      }

      return Promise.all(steps).then(function () {
        readyState = info;
        return info;
      });
    }

    function api(arg) {
      if (typeof arg === 'string') return resource(arg);
      if (isPlainObject(arg)) return create(assign({}, cfg, arg, {
        session: arg.session != null ? arg.session : cfg.session,
        token: arg.token != null ? arg.token : cfg.token
      }));
      return api;
    }

    api.version = VERSION;
    api.__dwAlliance = VERSION;
    api.client = create;
    api.origin = function (v) {
      if (arguments.length) { cfg.origin = v; return api; }
      return cfg.origin;
    };
    api.session = function (id, persist) {
      if (arguments.length) return setSession(id, persist);
      /* Never return the raw id — it is a secret. */
      return resolveSession() ? true : false;
    };
    api.hasSession = function (o) { return !!resolveSession(o); };
    api.authSource = resolveSource;
    api.ready = ready;
    api.can = can;
    api.capabilities = function () { return caps; };
    api.call = call;
    api.get = get;
    api.post = post;
    api.help = help;
    api.Error = DwError;
    api.noConflict = function (all) {
      if (inst && typeof inst.noConflict === 'function' && inst.noConflict !== api.noConflict) {
        return inst.noConflict(all);
      }
      return api;
    };

    api.files = {
      path: '/files/',
      help: function (o) { return help('/files/', o); },
      get: function (q, o) { return get('/files/', q, o); },
      post: function (body, o) { return post('/files/', body, o); },
      create: filesCreate,
      read: filesRead,
      update: filesUpdate,
      delete: filesDelete,
      remove: filesDelete,
      search: filesSearch,
      move: function (fields, o) { return actionPost('/files/', 'move', fields || {}, o); },
      call: function (action, data, o) { return call('/files/', action, data, o); }
    };

    api.linkTree = {
      path: '/link_tree/',
      help: function (o) { return help('/link_tree/', o); },
      get: function (q, o) { return get('/link_tree/', q, o); },
      post: function (body, o) { return post('/link_tree/', body, o); },
      list: function (fields, o) { return actionPost('/link_tree/', 'list', fields || {}, o); },
      read: function (idOrFields, o) {
        var fields = typeof idOrFields === 'string' ? { id: idOrFields } : assign({}, idOrFields || {});
        return actionPost('/link_tree/', 'read', fields, o);
      },
      create: function (fields, o) { return actionPost('/link_tree/', 'create', fields || {}, o); },
      update: function (idOrFields, patch, o) {
        var fields;
        if (typeof idOrFields === 'string') fields = assign({ id: idOrFields }, patch || {});
        else fields = assign({}, idOrFields, patch || {});
        return actionPost('/link_tree/', 'update', fields, o);
      },
      delete: function (idOrFields, o) {
        var fields = typeof idOrFields === 'string' ? { id: idOrFields } : assign({}, idOrFields || {});
        return actionPost('/link_tree/', 'delete', fields, o);
      },
      remove: function (idOrFields, o) { return api.linkTree.delete(idOrFields, o); },
      call: function (action, data, o) { return call('/link_tree/', action, data, o); }
    };

    /* Thin path bindings — actions are the platform's real `action` names. */
    var modules = ['crm', 'relation', 'domain', 'dns', 'notes', 'note_files', 'address', 'permissions', 'store'];
    var paths = {
      crm: '/crm/',
      relation: '/relation/',
      domain: '/domain/',
      dns: '/dns/',
      notes: '/notes/',
      note_files: '/note_files/',
      address: '/address/',
      permissions: '/permissions/',
      store: '/store/'
    };
    for (var i = 0; i < modules.length; i++) {
      api[modules[i]] = resource(paths[modules[i]]);
    }

    return api;
  }

  inst = create();
  inst.client = create;
  inst.version = VERSION;
  inst.Error = DwError;
  inst.APP_ORIGIN = APP_ORIGIN;
  return inst;
});

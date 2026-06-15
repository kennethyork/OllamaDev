// Browser bridge — stands in for the Boson native bindings when the ADE runs in
// a browser (server mode). Defines window.<binding> as an HTTP call to
// /api/<binding>, so app.js (which calls window.listModels(), window.termRead(),
// …) runs completely unchanged. All work still happens locally on the server.
(function () {
  'use strict';
  // Don't clobber the real native bridge if it's present (desktop/Boson).
  if (typeof window.listModels === 'function') return;

  var TOKEN = new URLSearchParams(location.search).get('token') || '';
  var NAMES = ['listModels', 'termCreate', 'termRead', 'termWrite', 'termKill', 'termResize', 'agentRun',
    'cliPath', 'sttEnabled', 'sttTranscribe', 'crewBoard', 'homeDir',
    'crewCoderLog', 'memoryGraph', 'getRoot', 'setRoot', 'listFiles', 'readFile', 'writeFile',
    'wsList', 'wsAdd', 'wsRemove', 'wsSetActive', 'wsSaveState',
    'crewRoleList', 'crewRoleAdd', 'crewRoleRemove',
    'webAccess', 'setWebAccess', 'searchEnabled', 'setSearchEnabled',
    'codeSearch', 'codeIndexStatus', 'codeIndexBuild', 'reviewDiff', 'temperature', 'setTemperature',
    'sttModel', 'setSttModel', 'sttHistory', 'sttClearHistory', 'openExternal', 'proxyFetch', 'crewModels', 'setCrewModels',
    'crewSteer', 'boardList', 'boardDecide',
    'skillsList', 'skillsGet', 'skillsSave', 'skillsRemove',
    'hooksList', 'hooksAdd', 'hooksRemove', 'chatList', 'chatDelete', 'chatExport'];

  function rpc(name, args) {
    var headers = { 'Content-Type': 'application/json' };
    if (TOKEN) headers['X-ODV-Token'] = TOKEN;
    return fetch('/api/' + name, { method: 'POST', headers: headers, body: JSON.stringify(args) })
      .then(function (r) { return r.json(); })
      .then(function (j) { return j && 'result' in j ? j.result : null; });
  }

  NAMES.forEach(function (name) {
    window[name] = function () { return rpc(name, Array.prototype.slice.call(arguments)); };
  });

  // Low-latency terminal output over Server-Sent Events. The server 503s when it
  // can't stream concurrently (no workers), and onError below makes the terminal
  // fall back to polling — so this is a pure optimization that never breaks the app.
  window.__odvOpenStream = function (id, offset, onData, onError) {
    if (typeof EventSource === 'undefined') return null;
    var u = '/api/stream?term=' + encodeURIComponent(id) + '&offset=' + (offset | 0) + (TOKEN ? '&token=' + encodeURIComponent(TOKEN) : '');
    var es;
    try { es = new EventSource(u); } catch (e) { return null; }
    es.onmessage = function (ev) { try { var r = JSON.parse(ev.data); if (r && r.data) onData(r.data, r.offset); } catch (e) {} };
    es.onerror = function () { try { es.close(); } catch (e) {} if (onError) onError(); };
    return es;
  };
})();

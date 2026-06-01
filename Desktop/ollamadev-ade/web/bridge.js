// Browser bridge — stands in for the Boson native bindings when the ADE runs in
// a browser (server mode). Defines window.<binding> as an HTTP call to
// /api/<binding>, so app.js (which calls window.listModels(), window.termRead(),
// …) runs completely unchanged. All work still happens locally on the server.
(function () {
  'use strict';
  // Don't clobber the real native bridge if it's present (desktop/Boson).
  if (typeof window.listModels === 'function') return;

  var TOKEN = new URLSearchParams(location.search).get('token') || '';
  var NAMES = ['listModels', 'termCreate', 'termRead', 'termWrite', 'termKill', 'agentRun',
    'cliPath', 'sttEnabled', 'sttTranscribe', 'crewBoard', 'homeDir',
    'crewCoderLog', 'memoryGraph', 'getRoot', 'setRoot', 'listFiles', 'readFile', 'writeFile'];

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
})();

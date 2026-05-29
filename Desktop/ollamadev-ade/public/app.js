'use strict';

// ---------- helpers ----------
function $(sel) { return document.querySelector(sel); }
function banner(msg, kind) {
    var b = document.getElementById('banner');
    if (!b) return;
    b.textContent = 'OllamaDev: ' + msg;
    b.style.display = 'block';
    b.style.background = kind === 'ok' ? '#238636' : kind === 'err' ? '#b62324' : '#9a6700';
    if (kind === 'ok') setTimeout(function () { b.style.display = 'none'; }, 2000);
}
window.onerror = function (m, s, l) { banner('JS error: ' + m + ' @' + l, 'err'); return false; };

function strToB64(s) { return btoa(unescape(encodeURIComponent(s))); }
function b64ToStr(b64) {
    var bin = atob(b64), arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    try { return new TextDecoder('utf-8', { fatal: false }).decode(arr); } catch (e) { return bin; }
}
function rid() { return 'term_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8); }

var ANSI_FG = { 30:'#484f58',31:'#f85149',32:'#3fb950',33:'#d29922',34:'#58a6ff',35:'#bc8cff',36:'#39c5cf',37:'#b1bac4',90:'#6e7681',91:'#ff7b72',92:'#56d364',93:'#e3b341',94:'#79c0ff',95:'#d2a8ff',96:'#56d4dd',97:'#f0f6fc' };

// ---------- terminal pane (dependency-free, ANSI colors) ----------
function Terminal(id, model) {
    this.id = id; this.model = model; this.offset = 0; this.polling = false;
    this.screen = null; this.line = null; this.fg = null; this.bold = false;
}
Terminal.prototype.mount = function (host) {
    var self = this;
    this.offset = 0; this.line = null; this.fg = null; this.bold = false;
    host.innerHTML =
        '<div class="term-head"><span>' + esc(this.model) + '</span><span class="id">' + this.id.slice(-6) + '</span><button class="x" title="Close">&times;</button></div>' +
        '<div class="term-screen" tabindex="0"></div>' +
        '<div class="term-input-row"><span class="p">$</span><input class="term-input" placeholder="type a command, Enter to run"></div>' +
        '<div class="agent-row"><span class="ico">🤖</span><input class="agent-input" placeholder="ask the agent to do something here"><button>Run</button></div>';
    this.screen = host.querySelector('.term-screen');
    var input = host.querySelector('.term-input');
    var arow = host.querySelector('.agent-row');
    host.querySelector('.x').onclick = function () { app.closeTerminal(self.id); };
    host.querySelector('.term-input-row').onsubmit = function (e) { e.preventDefault(); };
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { window.termWrite(self.id, strToB64(input.value + '\n')); input.value = ''; }
        else if (e.ctrlKey && e.key === 'c') { window.termWrite(self.id, strToB64('\x03')); }
    });
    arow.querySelector('button').onclick = function () { self.runAgent(arow.querySelector('.agent-input')); };
    arow.querySelector('.agent-input').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') self.runAgent(arow.querySelector('.agent-input'));
    });
    this.screen.onclick = function () { input.focus(); };
    if (!this.polling) this.poll();
};
Terminal.prototype.runAgent = function (el) {
    var v = (el.value || '').trim(); if (!v) return; el.value = '';
    try { window.agentRun(this.id, v); } catch (e) {}
};
Terminal.prototype.newLine = function () { this.line = document.createElement('div'); this.line.className = 'term-line'; this.screen.appendChild(this.line); };
Terminal.prototype.emit = function (s) {
    if (!this.line) this.newLine();
    var sp = document.createElement('span');
    if (this.fg) sp.style.color = this.fg;
    if (this.bold) sp.style.fontWeight = '700';
    sp.textContent = s; this.line.appendChild(sp);
};
Terminal.prototype.sgr = function (p) {
    var codes = p.split(';').filter(function (x) { return x !== ''; }).map(Number);
    if (!codes.length) codes = [0];
    for (var i = 0; i < codes.length; i++) {
        var c = codes[i];
        if (c === 0) { this.fg = null; this.bold = false; }
        else if (c === 1) this.bold = true;
        else if (c === 22) this.bold = false;
        else if (c === 39) this.fg = null;
        else if (ANSI_FG[c]) this.fg = ANSI_FG[c];
    }
};
Terminal.prototype.write = function (text) {
    if (!this.screen) return;
    var bottom = this.screen.scrollHeight - this.screen.scrollTop - this.screen.clientHeight < 50;
    var i = 0;
    while (i < text.length) {
        var ch = text[i];
        if (ch === '\x1b') {
            var csi = /^\x1b\[([0-9;?]*)([A-Za-z])/.exec(text.slice(i));
            if (csi) { if (csi[2] === 'm') this.sgr(csi[1]); else if ((csi[2] === 'K' || csi[2] === 'J') && this.line) this.line.innerHTML = ''; i += csi[0].length; continue; }
            var osc = /^\x1b\][^\x07]*(?:\x07|\x1b\\)/.exec(text.slice(i));
            if (osc) { i += osc[0].length; continue; }
            i++; continue;
        }
        if (ch === '\r') { if (this.line) this.line.innerHTML = ''; i++; continue; }
        if (ch === '\n') { this.newLine(); i++; continue; }
        var j = i;
        while (j < text.length && text[j] !== '\x1b' && text[j] !== '\n' && text[j] !== '\r') j++;
        this.emit(text.slice(i, j)); i = j;
    }
    if (bottom) this.screen.scrollTop = this.screen.scrollHeight;
};
Terminal.prototype.poll = function () {
    var self = this; this.polling = true;
    function tick() {
        if (!self.polling) return;
        Promise.resolve(window.termRead(self.id, self.offset)).then(function (r) {
            if (r && r.data) { self.write(b64ToStr(r.data)); self.offset = r.offset; }
        }).catch(function () {}).then(function () { if (self.polling) setTimeout(tick, 80); });
    }
    tick();
};
Terminal.prototype.close = function () { this.polling = false; try { window.termKill(this.id); } catch (e) {} };

function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

// ---------- app ----------
var App = {
    terminals: [],
    init: function () {
        var self = this;
        $('#newTermBtn').onclick = function () { self.newTerminal(); };
        this.loadModels().then(function () {
            self.loadFiles('.');
            self.newTerminal();
            banner('ready', 'ok');
        });
    },
    loadModels: function () {
        return Promise.resolve(window.listModels()).then(function (s) {
            var sel = $('#modelSelect'); var conn = $('#conn');
            conn.className = 'conn' + (s && s.connected ? ' on' : '');
            var models = (s && s.models) || [];
            sel.innerHTML = models.map(function (m) { return '<option>' + esc(m.name || m) + '</option>'; }).join('') || '<option>llama3.2:latest</option>';
        }).catch(function (e) { banner('listModels failed: ' + e, 'err'); });
    },
    loadFiles: function (path) {
        var self = this;
        Promise.resolve(window.listFiles(path)).then(function (items) {
            if (!Array.isArray(items)) return;
            var tree = $('#fileTree');
            tree.innerHTML = items.map(function (it) {
                return '<div class="tree-item" data-path="' + esc(it.path) + '" data-type="' + esc(it.type) + '">' +
                    (it.type === 'dir' ? '📁' : '📄') + ' ' + esc(it.name) + '</div>';
            }).join('');
            tree.querySelectorAll('.tree-item').forEach(function (el) {
                el.onclick = function () { if (el.dataset.type === 'dir') self.loadFiles(el.dataset.path); };
            });
        }).catch(function () {});
    },
    newTerminal: function () {
        var model = $('#modelSelect').value || 'llama3.2:latest';
        var id = rid();
        var t = new Terminal(id, model);
        var self = this;
        Promise.resolve(window.termCreate(id, model)).then(function () {
            self.terminals.push(t);
            self.render();
        }).catch(function (e) { banner('termCreate failed: ' + e, 'err'); });
    },
    closeTerminal: function (id) {
        var i = this.terminals.findIndex(function (t) { return t.id === id; });
        if (i >= 0) { this.terminals[i].close(); this.terminals.splice(i, 1); this.render(); }
    },
    render: function () {
        var wrap = $('#terminals');
        wrap.className = this.terminals.length > 1 ? 'cols-2' : 'cols-1';
        wrap.innerHTML = '';
        var self = this;
        this.terminals.forEach(function (t) {
            var pane = document.createElement('div');
            pane.className = 'term-pane';
            wrap.appendChild(pane);
            t.mount(pane);
        });
    }
};

// ---------- boot (wait for Boson bindings, then start) ----------
(function boot() {
    var waited = 0;
    function ready() { return typeof window.listModels === 'function' && typeof window.termCreate === 'function'; }
    function go() {
        if (ready()) { try { App.init(); } catch (e) { banner('init error: ' + e.message, 'err'); } }
        else if (waited < 15000) { waited += 50; banner('waiting for bindings… ' + waited + 'ms'); setTimeout(go, 50); }
        else { banner('bindings unavailable (window.listModels=' + typeof window.listModels + ')', 'err'); }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();
window.app = App;

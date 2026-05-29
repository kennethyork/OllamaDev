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
    this.status = 'idle'; this.lastData = 0; this.badgeEl = null;
}
Terminal.prototype.mount = function (host) {
    var self = this;
    this.offset = 0; this.line = null; this.fg = null; this.bold = false;
    host.innerHTML =
        '<div class="term-head"><span class="nm">' + esc(this.model) + '</span><span class="id">' + this.id.slice(-6) + '</span>' +
        '<span class="badge ' + this.status + '"><span class="b-dot"></span><span class="b-label">' + this.status + '</span></span>' +
        '<button class="x" title="Close">&times;</button></div>' +
        '<div class="term-screen" tabindex="0"></div>' +
        '<div class="term-input-row"><span class="p">$</span><input class="term-input" placeholder="type a command, Enter to run"></div>' +
        '<div class="agent-row"><span class="ico">🤖</span><input class="agent-input" placeholder="ask the agent to do something here"><button>Run</button></div>';
    this.screen = host.querySelector('.term-screen');
    this.badgeEl = host.querySelector('.badge');
    var input = host.querySelector('.term-input');
    var arow = host.querySelector('.agent-row');
    var head = host.querySelector('.term-head');
    head.title = 'Double-click to zoom / restore';
    head.ondblclick = function (e) { if (e.target.classList.contains('x')) return; app.toggleZoom(self.id); };
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
Terminal.prototype.setStatus = function (s) {
    this.status = s;
    if (this.badgeEl) {
        this.badgeEl.className = 'badge ' + s;
        var lbl = this.badgeEl.querySelector('.b-label'); if (lbl) lbl.textContent = s;
    }
};
Terminal.prototype.runAgent = function (el) {
    var v = (el.value || '').trim(); if (!v) return; el.value = '';
    this.setStatus('running'); this.lastData = Date.now();
    // The terminal runs the interactive ollamadev CLI, so just type the prompt in.
    try { window.termWrite(this.id, strToB64(v + '\n')); } catch (e) {}
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
            if (r && r.data) { self.write(b64ToStr(r.data)); self.offset = r.offset; self.lastData = Date.now(); }
            // Agent "running" → "done" once output has been quiet for ~2.5s.
            if (self.status === 'running' && self.lastData && Date.now() - self.lastData > 2500) self.setStatus('done');
        }).catch(function () {}).then(function () { if (self.polling) setTimeout(tick, 80); });
    }
    tick();
};
Terminal.prototype.close = function () { this.polling = false; try { window.termKill(this.id); } catch (e) {} };

function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

// ---------- code editor (tabs + line-numbered textarea, vanilla) ----------
var Editor = {
    tabs: [], active: -1, mounted: null,
    cur: function () { return this.active >= 0 ? this.tabs[this.active] : null; },
    open: function (path, name) {
        var self = this;
        var i = this.tabs.findIndex(function (t) { return t.path === path; });
        if (i >= 0) { this.active = i; this.render(); App.markActiveFile(path); return; }
        Promise.resolve(window.readFile(path)).then(function (r) {
            if (!r || r.error) { banner('open failed: ' + ((r && r.error) || 'unknown'), 'err'); return; }
            self.tabs.push({ path: path, name: name || path.split('/').pop(), content: String(r.content == null ? '' : r.content), dirty: false });
            self.active = self.tabs.length - 1;
            self.render();
            App.markActiveFile(path);
        }).catch(function (e) { banner('open error: ' + e, 'err'); });
    },
    save: function () {
        var t = this.cur(); if (!t) return;
        var ta = document.querySelector('#editorBody .ed-text'); if (ta) t.content = ta.value;
        Promise.resolve(window.writeFile(t.path, t.content)).then(function (r) {
            if (r && r.success) { t.dirty = false; Editor.renderTabs(); Editor.refreshStatus(); banner('saved ' + t.name, 'ok'); }
            else banner('save failed: ' + ((r && r.error) || 'unknown'), 'err');
        }).catch(function (e) { banner('save error: ' + e, 'err'); });
    },
    close: function (i) {
        var t = this.tabs[i];
        if (t && t.dirty && !window.confirm('Discard unsaved changes to ' + t.name + '?')) return;
        this.tabs.splice(i, 1);
        if (this.active >= this.tabs.length) this.active = this.tabs.length - 1;
        this.mounted = null;
        this.render();
        var c = this.cur(); App.markActiveFile(c ? c.path : null);
    },
    renderTabs: function () {
        var bar = $('#editorTabs'); var self = this; if (!bar) return;
        bar.innerHTML = '';
        this.tabs.forEach(function (t, i) {
            var el = document.createElement('div');
            el.className = 'etab' + (i === self.active ? ' active' : '') + (t.dirty ? ' dirty' : '');
            el.innerHTML = '<span class="dot">•</span><span class="nm">' + esc(t.name) + '</span><button class="xc" title="Close">×</button>';
            el.querySelector('.nm').onclick = function () { self.active = i; self.render(); App.markActiveFile(t.path); };
            el.querySelector('.xc').onclick = function (e) { e.stopPropagation(); self.close(i); };
            bar.appendChild(el);
        });
    },
    refreshStatus: function () {
        var t = this.cur(); var st = document.querySelector('#editorBody .ed-status'); var ta = document.querySelector('#editorBody .ed-text');
        if (!t || !st || !ta) return;
        var n = ta.value.split('\n').length;
        st.textContent = (t.dirty ? '● ' : '') + t.name + ' — ' + n + ' lines  (Ctrl+S to save)';
    },
    render: function () {
        this.renderTabs();
        var body = $('#editorBody'); if (!body) return;
        var t = this.cur();
        if (!t) { body.innerHTML = '<div id="editorEmpty" class="dim">Select a file from the sidebar to edit it.</div>'; this.mounted = null; return; }
        if (this.mounted !== t.path) {
            body.innerHTML = '<div class="ed-gutter"></div><textarea class="ed-text" spellcheck="false" wrap="off"></textarea><div class="ed-status"></div>';
            var ta = body.querySelector('.ed-text'), gut = body.querySelector('.ed-gutter');
            ta.value = t.content;
            var sync = function () {
                var n = ta.value.split('\n').length, g = '';
                for (var k = 1; k <= n; k++) g += k + '\n';
                gut.textContent = g;
                Editor.refreshStatus();
            };
            ta.addEventListener('input', function () {
                t.content = ta.value;
                if (!t.dirty) { t.dirty = true; Editor.renderTabs(); }
                sync();
            });
            ta.addEventListener('scroll', function () { gut.scrollTop = ta.scrollTop; });
            ta.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); Editor.save(); return; }
                if (e.key === 'Tab') {
                    e.preventDefault();
                    var s = ta.selectionStart, en = ta.selectionEnd;
                    ta.value = ta.value.slice(0, s) + '    ' + ta.value.slice(en);
                    ta.selectionStart = ta.selectionEnd = s + 4;
                    t.content = ta.value; if (!t.dirty) { t.dirty = true; Editor.renderTabs(); } sync();
                }
            });
            this.mounted = t.path;
            sync(); ta.focus();
        }
    }
};

// ---------- app ----------
// ---------- kanban tasks (local, hands work to agent terminals) ----------
var Tasks = {
    items: [], COLS: [['todo', 'To-do'], ['doing', 'Doing'], ['done', 'Done']],
    load: function () { try { this.items = JSON.parse(localStorage.getItem('ade.tasks') || '[]'); } catch (e) { this.items = []; } if (!Array.isArray(this.items)) this.items = []; },
    save: function () { try { localStorage.setItem('ade.tasks', JSON.stringify(this.items)); } catch (e) {} },
    add: function (title) {
        title = (title || '').trim(); if (!title) return;
        this.items.push({ id: 't' + Date.now() + Math.random().toString(36).slice(2, 6), title: title, col: 'todo' });
        this.save(); this.render();
    },
    move: function (id, col) { var t = this.items.find(function (x) { return x.id === id; }); if (t) { t.col = col; this.save(); this.render(); } },
    del: function (id) { this.items = this.items.filter(function (x) { return x.id !== id; }); this.save(); this.render(); },
    run: function (id) { var t = this.items.find(function (x) { return x.id === id; }); if (!t) return; this.move(id, 'doing'); App.runTaskInAgent(t.title); },
    render: function () {
        var board = $('#board'); if (!board) return;
        var self = this;
        board.innerHTML = this.COLS.map(function (c) {
            var cards = self.items.filter(function (t) { return t.col === c[0]; });
            var body = cards.length ? cards.map(function (t) {
                return '<div class="card" draggable="true" data-id="' + t.id + '">' +
                    '<div class="title">' + esc(t.title) + '</div>' +
                    '<div class="actions">' +
                    (c[0] !== 'done' ? '<button class="run" data-act="run">▶ Run</button>' : '') +
                    '<button data-act="back">◀</button><button data-act="fwd">▶</button>' +
                    '<button class="del" data-act="del">✕</button></div></div>';
            }).join('') : '<div class="board-empty">—</div>';
            return '<div class="col" data-col="' + c[0] + '"><div class="col-head"><span class="dotc"></span>' + c[1] +
                '<span class="count">' + cards.length + '</span></div><div class="col-body">' + body + '</div></div>';
        }).join('');
        this.wire(board);
    },
    order: ['todo', 'doing', 'done'],
    wire: function (board) {
        var self = this;
        board.querySelectorAll('.card').forEach(function (card) {
            var id = card.dataset.id;
            card.addEventListener('dragstart', function (e) { card.classList.add('dragging'); e.dataTransfer.setData('text/plain', id); });
            card.addEventListener('dragend', function () { card.classList.remove('dragging'); });
            card.querySelectorAll('[data-act]').forEach(function (b) {
                b.onclick = function () {
                    var t = self.items.find(function (x) { return x.id === id; }); if (!t) return;
                    var i = self.order.indexOf(t.col);
                    if (b.dataset.act === 'run') self.run(id);
                    else if (b.dataset.act === 'del') self.del(id);
                    else if (b.dataset.act === 'fwd' && i < 2) self.move(id, self.order[i + 1]);
                    else if (b.dataset.act === 'back' && i > 0) self.move(id, self.order[i - 1]);
                };
            });
        });
        board.querySelectorAll('.col').forEach(function (col) {
            col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('drag-over'); });
            col.addEventListener('dragleave', function () { col.classList.remove('drag-over'); });
            col.addEventListener('drop', function (e) {
                e.preventDefault(); col.classList.remove('drag-over');
                var id = e.dataTransfer.getData('text/plain'); if (id) self.move(id, col.dataset.col);
            });
        });
    }
};

var App = {
    terminals: [], cwd: '.', layout: 'split', zoomed: null, view: 'code', panel: 'files',
    init: function () {
        var self = this;
        this.initThemes();
        $('#newTermBtn').onclick = function () { self.newTerminal(); };
        $('#layoutBtn').onclick = function () { self.cycleLayout(); };
        // Activity rail: switch the sidebar between Files and Tasks.
        document.querySelectorAll('.rail-btn').forEach(function (b) {
            b.onclick = function () { self.setPanel(b.dataset.panel); };
        });
        // Workspace view tabs: Workspace (code) vs Board (kanban).
        document.querySelectorAll('.ws-tab').forEach(function (t) {
            t.onclick = function () { self.setView(t.dataset.view); };
        });
        // Add a task from the sidebar.
        var ti = $('#taskInput');
        if (ti) ti.addEventListener('keydown', function (e) { if (e.key === 'Enter') { Tasks.add(ti.value); ti.value = ''; } });
        // Global Ctrl/Cmd+S saves the active editor tab from anywhere.
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); Editor.save(); }
        });
        Tasks.load(); Tasks.render();
        Promise.resolve(window.cliPath ? window.cliPath() : 'ollamadev').then(function (p) { self.cli = p || 'ollamadev'; }).catch(function () { self.cli = 'ollamadev'; });
        this.loadModels().then(function () {
            Promise.resolve(window.getRoot ? window.getRoot() : '.').then(function (root) {
                self.loadFiles(root || '.');
            }).catch(function () { self.loadFiles('.'); });
            self.newTerminal();
            banner('ready', 'ok');
        });
    },
    THEMES: [['void', 'Void'], ['neon-tokyo', 'Neon Tokyo'], ['synthwave', 'Synthwave'], ['dracula', 'Dracula'], ['midnight', 'Midnight'], ['mono', 'Mono']],
    initThemes: function () {
        var sel = $('#themeSelect'); if (!sel) return;
        sel.innerHTML = this.THEMES.map(function (t) { return '<option value="' + t[0] + '">' + t[1] + '</option>'; }).join('');
        var saved = 'void';
        try { saved = localStorage.getItem('ade.theme') || 'void'; } catch (e) {}
        this.applyTheme(saved); sel.value = saved;
        sel.onchange = function () { App.applyTheme(sel.value); };
    },
    applyTheme: function (t) {
        document.body.dataset.theme = t;
        try { localStorage.setItem('ade.theme', t); } catch (e) {}
    },
    setPanel: function (p) {
        this.panel = p;
        document.querySelectorAll('.rail-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.panel === p); });
        $('#filesPanel').hidden = p !== 'files';
        $('#tasksPanel').hidden = p !== 'tasks';
    },
    setView: function (v) {
        this.view = v;
        document.querySelectorAll('.ws-tab').forEach(function (t) { t.classList.toggle('active', t.dataset.view === v); });
        $('#codeView').hidden = v !== 'code';
        $('#boardView').hidden = v !== 'board';
        if (v === 'board') Tasks.render();
    },
    // Hand a task title to an agent terminal's CLI (create one if none exist).
    runTaskInAgent: function (title) {
        var self = this;
        var type = function (t, delay) {
            t.setStatus('running'); t.lastData = Date.now();
            setTimeout(function () { try { window.termWrite(t.id, strToB64(title + '\n')); } catch (e) {} }, delay || 0);
            self.setView('code'); banner('running task in ' + t.id.slice(-6), 'ok');
        };
        if (this.terminals.length) { type(this.terminals[0], 0); }
        else {
            var model = $('#modelSelect').value || 'llama3.2:latest';
            var id = rid(); var t = new Terminal(id, model);
            Promise.resolve(window.termCreate(id, model)).then(function () {
                self.terminals.push(t); self.render(); self.launchCli(id, model);
                type(t, 1800); // wait for the CLI to boot before sending the prompt
            });
        }
    },
    cycleLayout: function () {
        this.layout = this.layout === 'split' ? 'term' : this.layout === 'term' ? 'editor' : 'split';
        var cv = $('#codeView');
        cv.className = 'ws-view ' + (this.layout === 'term' ? 'focus-term' : this.layout === 'editor' ? 'focus-editor' : '');
        var label = this.layout === 'term' ? 'Terminals' : this.layout === 'editor' ? 'Editor' : 'Split';
        $('#layoutBtn').textContent = label;
        this.render();
    },
    toggleZoom: function (id) {
        this.zoomed = this.zoomed === id ? null : id;
        this.render();
    },
    markActiveFile: function (path) {
        document.querySelectorAll('#fileTree .tree-item').forEach(function (el) {
            el.classList.toggle('active', !!path && el.dataset.type === 'file' && el.dataset.path === path);
        });
    },
    loadModels: function () {
        return Promise.resolve(window.listModels()).then(function (s) {
            var sel = $('#modelSelect'); var conn = $('#conn');
            if (conn) {
                conn.className = 'conn' + (s && s.connected ? ' on' : '');
                conn.title = s && s.connected ? 'Ollama connected' : 'Ollama not reachable';
            }
            if (sel) {
                var models = (s && s.models) || [];
                sel.innerHTML = models.map(function (m) { return '<option>' + esc(m.name || m) + '</option>'; }).join('') || '<option>llama3.2:latest</option>';
            }
        }).catch(function (e) { banner('listModels failed: ' + e, 'err'); });
    },
    loadFiles: function (path) {
        var self = this;
        Promise.resolve(window.listFiles(path)).then(function (items) {
            if (items && items.error) { banner('list failed: ' + items.error, 'err'); return; }
            if (!Array.isArray(items)) return;
            self.cwd = path;
            var cwdEl = $('#cwd'); if (cwdEl) cwdEl.textContent = path;
            var bc = $('#breadcrumb'); if (bc) bc.textContent = path;
            var tree = $('#fileTree');
            var html = '';
            // Parent (".." ) entry, unless we're at the filesystem root.
            var parent = path.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') || '/';
            if (parent && parent !== path) {
                html += '<div class="tree-item up" data-path="' + esc(parent) + '" data-type="dir" data-name="..">📁 ..</div>';
            }
            html += items.map(function (it) {
                return '<div class="tree-item" data-path="' + esc(it.path) + '" data-type="' + esc(it.type) + '" data-name="' + esc(it.name) + '">' +
                    (it.type === 'dir' ? '📁' : '📄') + ' ' + esc(it.name) + '</div>';
            }).join('');
            tree.innerHTML = html;
            tree.querySelectorAll('.tree-item').forEach(function (el) {
                el.onclick = function () {
                    if (el.dataset.type === 'dir') self.loadFiles(el.dataset.path);
                    else Editor.open(el.dataset.path, el.dataset.name);
                };
            });
            self.markActiveFile(Editor.cur() ? Editor.cur().path : null);
        }).catch(function (e) { banner('list error: ' + e, 'err'); });
    },
    MAX_TERMINALS: 16,
    // Auto-launch the interactive ollamadev CLI (with the selected model) inside
    // a freshly-started pty. You can still switch models in the CLI with /model.
    launchCli: function (id, model) {
        var cmd = (this.cli || 'ollamadev') + (model ? ' -m ' + model : '') + '\n';
        // Small delay so the pty shell is ready to receive the command.
        setTimeout(function () { try { window.termWrite(id, strToB64(cmd)); } catch (e) {} }, 350);
    },
    newTerminal: function () {
        if (this.terminals.length >= this.MAX_TERMINALS) { banner('maximum of ' + this.MAX_TERMINALS + ' terminals', 'err'); return; }
        var model = $('#modelSelect').value || 'llama3.2:latest';
        var id = rid();
        var t = new Terminal(id, model);
        var self = this;
        Promise.resolve(window.termCreate(id, model)).then(function () {
            self.terminals.push(t);
            self.render();
            self.launchCli(id, model);
        }).catch(function (e) { banner('termCreate failed: ' + e, 'err'); });
    },
    closeTerminal: function (id) {
        var i = this.terminals.findIndex(function (t) { return t.id === id; });
        if (i >= 0) { this.terminals[i].close(); this.terminals.splice(i, 1); this.render(); }
    },
    render: function () {
        var wrap = $('#terminals');
        // A zoomed terminal takes the whole area; otherwise auto-tile into a
        // near-square grid: ceil(sqrt(n)) columns, up to 4×4 (16).
        if (this.zoomed && !this.terminals.some(function (t) { return t.id === this.zoomed; }, this)) this.zoomed = null;
        var list = this.zoomed ? this.terminals.filter(function (t) { return t.id === this.zoomed; }) : this.terminals;
        var n = list.length;
        var cols = (this.zoomed || n <= 1) ? 1 : Math.min(4, Math.ceil(Math.sqrt(n)));
        wrap.className = this.zoomed ? 'zoomed' : '';
        wrap.style.gridTemplateColumns = 'repeat(' + cols + ', minmax(0, 1fr))';
        wrap.innerHTML = '';
        list.forEach(function (t) {
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

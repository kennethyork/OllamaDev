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

// Map a browser keydown to the byte sequence a real terminal would send, so the
// pty (and the raw-mode ollamadev CLI inside it) gets live keystrokes.
function keyToBytes(e) {
    if (e.altKey) return null;
    var k = e.key;
    if (e.ctrlKey) {
        if (k === 'c') return '\x03';
        if (k === 'd') return '\x04';
        if (k === 'z') return '\x1a';
        if (k.length === 1) {
            var c = k.toLowerCase().charCodeAt(0);
            if (c >= 97 && c <= 122) return String.fromCharCode(c - 96); // Ctrl-A..Z
        }
        return null;
    }
    switch (k) {
        case 'Enter': return '\r';
        case 'Backspace': return '\x7f';
        case 'Tab': return '\t';
        case 'Escape': return '\x1b';
        case 'ArrowUp': return '\x1b[A';
        case 'ArrowDown': return '\x1b[B';
        case 'ArrowRight': return '\x1b[C';
        case 'ArrowLeft': return '\x1b[D';
        case 'Home': return '\x1b[H';
        case 'End': return '\x1b[F';
        case 'Delete': return '\x1b[3~';
        case 'PageUp': return '\x1b[5~';
        case 'PageDown': return '\x1b[6~';
    }
    if (k.length === 1) return k; // a printable character
    return null;
}

// ---------- terminal pane (dependency-free, ANSI colors) ----------
function Terminal(id, model) {
    this.id = id; this.model = model; this.offset = 0; this.polling = false;
    this.screen = null; this.line = null; this.fg = null; this.bold = false;
    this.status = 'idle'; this.lastData = 0; this.badgeEl = null; this.cr = false;
}
Terminal.prototype.mount = function (host) {
    var self = this;
    this.offset = 0; this.line = null; this.fg = null; this.bold = false; this.cr = false;
    host.innerHTML =
        '<div class="term-head"><span class="nm">' + esc(this.model) + '</span><span class="id">' + this.id.slice(-6) + '</span>' +
        '<span class="badge ' + this.status + '"><span class="b-dot"></span><span class="b-label">' + this.status + '</span></span>' +
        '<button class="x" title="Close">&times;</button></div>' +
        '<div class="term-screen" tabindex="0" title="Click and type — this is the live ollamadev CLI"></div>';
    this.screen = host.querySelector('.term-screen');
    this.badgeEl = host.querySelector('.badge');
    var head = host.querySelector('.term-head');
    head.title = 'Double-click to zoom / restore';
    head.ondblclick = function (e) { if (e.target.classList.contains('x')) return; app.toggleZoom(self.id); };
    host.querySelector('.x').onclick = function () { app.closeTerminal(self.id); };
    // The screen IS the terminal: forward every keystroke straight to the pty.
    this.screen.addEventListener('keydown', function (e) {
        var data = keyToBytes(e);
        if (data !== null) { e.preventDefault(); try { window.termWrite(self.id, strToB64(data)); } catch (err) {} }
    });
    this.screen.addEventListener('paste', function (e) {
        var t = (e.clipboardData || window.clipboardData).getData('text');
        if (t) { e.preventDefault(); try { window.termWrite(self.id, strToB64(t)); } catch (err) {} }
    });
    this.screen.onclick = function () { self.screen.focus(); };
    if (!this.polling) this.poll();
    setTimeout(function () { self.screen.focus(); }, 0);
};
Terminal.prototype.setStatus = function (s) {
    this.status = s;
    if (this.badgeEl) {
        this.badgeEl.className = 'badge ' + s;
        var lbl = this.badgeEl.querySelector('.b-label'); if (lbl) lbl.textContent = s;
    }
};
Terminal.prototype.newLine = function () { this.line = document.createElement('div'); this.line.className = 'term-line'; this.screen.appendChild(this.line); };
// Remove the last character of the current line (handles the pty's \b \b erase).
Terminal.prototype.backspace = function () {
    if (!this.line) return;
    var nodes = this.line.childNodes;
    for (var k = nodes.length - 1; k >= 0; k--) {
        var sp = nodes[k], t = sp.textContent || '';
        if (t.length > 0) { sp.textContent = t.slice(0, -1); if (sp.textContent === '') this.line.removeChild(sp); return; }
        this.line.removeChild(sp);
    }
};
Terminal.prototype.emit = function (s) {
    if (!this.line) this.newLine();
    // A pending carriage return overwrites the current line (e.g. progress bars),
    // but a bare \r\n must NOT erase the line — that's handled by clearing here
    // only when actual text follows the \r.
    if (this.cr) { this.line.innerHTML = ''; this.cr = false; }
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
        if (ch === '\r') { this.cr = true; i++; continue; }
        if (ch === '\n') { this.cr = false; this.newLine(); i++; continue; }
        if (ch === '\x08') { this.backspace(); i++; continue; } // erase (pty echoes \b \b)
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
    // Map a crew subtask state to a board column (held shows in Doing, flagged).
    crewCol: function (state) { return state === 'done' ? 'done' : (state === 'todo' ? 'todo' : 'doing'); },
    render: function () {
        var board = $('#board'); if (!board) return;
        var self = this;
        var crew = (App.crewBoard && Array.isArray(App.crewBoard.subtasks)) ? App.crewBoard.subtasks : [];
        board.innerHTML = this.COLS.map(function (c) {
            // Director's plan as live, read-only crew cards.
            var crewInCol = crew.filter(function (s) { return self.crewCol(s.state) === c[0]; });
            var crewCards = crewInCol.map(function (s) {
                var held = s.state === 'held';
                return '<div class="card crew' + (held ? ' held' : '') + '">' +
                    '<div class="title">🤖 ' + esc(s.title) + '</div>' +
                    '<div class="cmeta">' + (held ? '⚠ held' : (s.state === 'doing' ? '● working' : s.state)) + '</div></div>';
            }).join('');
            // The user's own manual tasks.
            var cards = self.items.filter(function (t) { return t.col === c[0]; });
            var manual = cards.map(function (t) {
                return '<div class="card" draggable="true" data-id="' + t.id + '">' +
                    '<div class="title">' + esc(t.title) + '</div>' +
                    '<div class="actions">' +
                    (c[0] !== 'done' ? '<button class="run" data-act="run">▶ Run</button>' : '') +
                    '<button data-act="back">◀</button><button data-act="fwd">▶</button>' +
                    '<button class="del" data-act="del">✕</button></div></div>';
            }).join('');
            var body = (crewCards + manual) || '<div class="board-empty">—</div>';
            var count = crewInCol.length + cards.length;
            return '<div class="col" data-col="' + c[0] + '"><div class="col-head"><span class="dotc"></span>' + c[1] +
                '<span class="count">' + count + '</span></div><div class="col-body">' + body + '</div></div>';
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
    terminals: [], cwd: '.', layout: 'split', zoomed: null, view: 'code', panel: 'files', crewBoard: null, crewPoll: null,
    init: function () {
        var self = this;
        this.initThemes();
        $('#newTermBtn').onclick = function () { self.newTerminal(); };
        $('#layoutBtn').onclick = function () { self.cycleLayout(); };
        // Open-folder modal
        var ofb = $('#openFolderBtn'); if (ofb) ofb.onclick = function () { self.openFolderModal(false); };
        var fok = $('#folderOpen'); if (fok) fok.onclick = function () { self.submitFolder(); };
        var fcx = $('#folderCancel'); if (fcx) fcx.onclick = function () { self.closeFolder(); };
        var fov = $('#folderOverlay'); if (fov) fov.onclick = function (e) { if (e.target === fov) self.closeFolder(); };
        var fpi = $('#folderPath'); if (fpi) fpi.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') self.submitFolder();
            else if (e.key === 'Escape') self.closeFolder();
        });
        // Crew setup wizard
        var fb = $('#crewBtn'); if (fb) fb.onclick = function () { self.openCrew(); };
        var fc = $('#crewCancel'); if (fc) fc.onclick = function () { self.closeCrew(); };
        var wn = $('#wizNext'); if (wn) wn.onclick = function () { self.wizNext(); };
        var wbk = $('#wizBack'); if (wbk) wbk.onclick = function () { self.wizBack(); };
        var fr = $('#crewRun'); if (fr) fr.onclick = function () { self.runCrewFromWizard(); };
        var ov = $('#modalOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.closeCrew(); };
        var cfo = $('#crewFolder'); if (cfo) cfo.addEventListener('keydown', function (e) { if (e.key === 'Enter') self.wizNext(); else if (e.key === 'Escape') self.closeCrew(); });
        var ft = $('#crewTask'); if (ft) ft.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') self.closeCrew();
            else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') self.wizNext();
        });
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
        this.initSplitter();
        // Global Ctrl/Cmd+S saves the active editor tab from anywhere.
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); Editor.save(); }
        });
        Tasks.load(); Tasks.render();
        this.setLayout('term'); // CLI/terminal is the focus by default; editor on demand
        Promise.resolve(window.cliPath ? window.cliPath() : 'ollamadev').then(function (p) { self.cli = p || 'ollamadev'; }).catch(function () { self.cli = 'ollamadev'; });
        this.loadModels().then(function () {
            // Startup: prompt for the project folder (prefilled with the last one),
            // so the file tree, terminals, and Crew all open on your chosen project.
            var last = ''; try { last = localStorage.getItem('ade.folder') || ''; } catch (e) {}
            self.pendingFolder = last;
            self.openFolderModal(true);
            banner('ready', 'ok');
        });
    },
    cdPrefix: function () {
        return (this.cwd && this.cwd !== '.') ? "cd '" + this.cwd.replace(/'/g, "'\\''") + "' && " : '';
    },
    openFolderModal: function (firstRun) {
        var o = $('#folderOverlay'); if (!o) return;
        this._folderFirstRun = !!firstRun;
        var inp = $('#folderPath');
        if (inp) {
            var pre = this.pendingFolder || this.cwd || '';
            inp.value = (pre && pre !== '.') ? pre : '';
            this.pendingFolder = '';
        }
        o.hidden = false; if (inp) { inp.focus(); inp.select(); }
    },
    closeFolder: function () {
        var o = $('#folderOverlay'); if (o) o.hidden = true;
        // First run with no folder chosen → fall back to the default root so the app isn't empty.
        if (this._folderFirstRun && !this.terminals.length) {
            this._folderFirstRun = false;
            var self = this;
            Promise.resolve(window.getRoot ? window.getRoot() : '.').then(function (r) { self.loadFiles(r || '.'); self.newTerminal(); }).catch(function () { self.loadFiles('.'); self.newTerminal(); });
        }
    },
    submitFolder: function () {
        var path = ($('#folderPath').value || '').trim();
        if (!path) { $('#folderPath').focus(); return; }
        this.openFolder(path, this._folderFirstRun);
    },
    // Set the workspace to a folder: file tree, future terminals, and Crew.
    openFolder: function (path, firstRun) {
        var self = this;
        Promise.resolve(window.setRoot ? window.setRoot(path) : { root: path }).then(function (r) {
            if (!r || r.error) { banner('open failed: ' + ((r && r.error) || 'unknown'), 'err'); $('#folderPath').focus(); return; }
            self.cwd = r.root;
            try { localStorage.setItem('ade.folder', r.root); } catch (e) {}
            var o = $('#folderOverlay'); if (o) o.hidden = true;
            self.loadFiles(r.root);
            banner('opened ' + r.root, 'ok');
            if (firstRun && !self.terminals.length) { self._folderFirstRun = false; self.newTerminal(); }
        }).catch(function (e) { banner('open error: ' + e, 'err'); });
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
    setLayout: function (name) {
        this.layout = name;
        var cv = $('#codeView');
        if (cv) cv.className = 'ws-view ' + (name === 'term' ? 'focus-term' : name === 'editor' ? 'focus-editor' : '');
        // Drag-sizing only applies in split; clear it so the focus modes fill fully.
        if (name !== 'split') { var ep = $('#editorPane'), tm = $('#terminals'); if (ep) ep.style.flex = ''; if (tm) tm.style.flex = ''; }
        var btn = $('#layoutBtn');
        if (btn) btn.textContent = name === 'term' ? 'Terminals' : name === 'editor' ? 'Editor' : 'Split';
        this.render();
    },
    // Draggable divider: in split mode, drag to give terminals more/less height.
    initSplitter: function () {
        var split = $('#vsplit'), cv = $('#codeView'), ep = $('#editorPane'), tm = $('#terminals');
        if (!split || !cv || !ep || !tm) return;
        var dragging = false;
        var self = this;
        split.addEventListener('mousedown', function (e) { if (self.layout !== 'split') return; dragging = true; document.body.style.userSelect = 'none'; e.preventDefault(); });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var r = cv.getBoundingClientRect();
            var top = e.clientY - r.top - 4;            // editor height under the cursor
            top = Math.max(60, Math.min(r.height - 90, top)); // keep both panes usable
            ep.style.flex = '0 0 ' + top + 'px';
            tm.style.flex = '1 1 0';
        });
        document.addEventListener('mouseup', function () { if (dragging) { dragging = false; document.body.style.userSelect = ''; } });
    },
    cycleLayout: function () {
        this.setLayout(this.layout === 'split' ? 'term' : this.layout === 'term' ? 'editor' : 'split');
    },
    // Recommended fast coder models, in preference order, for the crew run.
    CREW_PREFERRED: ['qwen2.5-coder', 'qwen3-coder', 'codestral', 'deepseek-coder', 'mistral', 'llama3.1'],
    // Setup templates (BridgeSpace-style) — prefill the task + suggested config.
    CREW_TEMPLATES: [
        { id: 'feature', label: '✨ Feature', task: 'Implement this feature: ', max: 3, review: true },
        { id: 'bugfix', label: '🐛 Bug fix', task: 'Find and fix this bug: ', max: 1, review: true },
        { id: 'tests', label: '🧪 Tests', task: 'Write thorough tests for: ', max: 2, review: true },
        { id: 'refactor', label: '♻️ Refactor', task: 'Refactor for clarity/maintainability (no behavior change): ', max: 2, review: true },
        { id: 'docs', label: '📝 Docs', task: 'Write/update documentation for: ', max: 1, review: false },
        { id: 'audit', label: '🔍 Audit & fix', task: 'Review the codebase for bugs and security issues, and fix them: ', max: 2, review: true },
        { id: 'blank', label: '➕ Blank', task: '', max: 2, review: true }
    ],
    openCrew: function () {
        var o = $('#modalOverlay'); if (!o) return;
        // Step 1 default folder = the open project.
        var cf = $('#crewFolder'); if (cf) cf.value = (this.cwd && this.cwd !== '.') ? this.cwd : '';
        // Populate the model dropdown, defaulting to a recommended fast model.
        var sel = $('#crewModel');
        if (sel) {
            var opts = Array.prototype.slice.call($('#modelSelect').options).map(function (o) { return o.value; });
            if (opts.length) {
                var pick = opts[0];
                for (var i = 0; i < this.CREW_PREFERRED.length; i++) {
                    var pref = this.CREW_PREFERRED[i];
                    var hit = opts.find(function (m) { return m.indexOf(pref) !== -1; });
                    if (hit) { pick = hit; break; }
                }
                sel.innerHTML = opts.map(function (m) { return '<option' + (m === pick ? ' selected' : '') + '>' + esc(m) + '</option>'; }).join('');
            }
        }
        this.renderTemplates();
        o.hidden = false;
        this.wizGo(1);
    },
    closeCrew: function () { var o = $('#modalOverlay'); if (o) o.hidden = true; },
    renderTemplates: function () {
        var box = $('#crewTemplates'); if (!box) return;
        var self = this;
        box.innerHTML = this.CREW_TEMPLATES.map(function (t) { return '<span class="wiz-tpl" data-id="' + t.id + '">' + t.label + '</span>'; }).join('');
        box.querySelectorAll('.wiz-tpl').forEach(function (el) {
            el.onclick = function () {
                var t = self.CREW_TEMPLATES.find(function (x) { return x.id === el.dataset.id; });
                if (!t) return;
                box.querySelectorAll('.wiz-tpl').forEach(function (e) { e.classList.remove('active'); });
                el.classList.add('active');
                $('#crewTask').value = t.task;
                $('#crewMax').value = String(t.max);
                if ($('#crewReview')) $('#crewReview').checked = t.review;
                var ta = $('#crewTask'); ta.focus(); ta.selectionStart = ta.selectionEnd = ta.value.length;
            };
        });
    },
    // ---- wizard navigation ----
    wizGo: function (step) {
        this.wizStep = step;
        document.querySelectorAll('.wiz-step').forEach(function (s) { s.hidden = (+s.dataset.step !== step); });
        document.querySelectorAll('.wiz-dot').forEach(function (d) {
            var s = +d.dataset.s; d.classList.toggle('active', s === step); d.classList.toggle('done', s < step);
        });
        $('#wizBack').hidden = step === 1;
        $('#wizNext').hidden = step === 4;
        $('#crewRun').hidden = step !== 4;
        if (step === 4) this.buildSummary();
        // focus the step's primary input
        var f = step === 1 ? '#crewFolder' : step === 2 ? '#crewTask' : null;
        if (f && $(f)) setTimeout(function () { $(f).focus(); }, 0);
    },
    wizBack: function () { if (this.wizStep > 1) this.wizGo(this.wizStep - 1); },
    wizNext: function () {
        var self = this;
        if (this.wizStep === 1) {
            var path = ($('#crewFolder').value || '').trim();
            if (!path) { $('#crewFolder').focus(); banner('enter a project folder', 'err'); return; }
            // Validate + set as the workspace folder, then advance.
            Promise.resolve(window.setRoot ? window.setRoot(path) : { root: path }).then(function (r) {
                if (!r || r.error) { banner('not a folder: ' + path, 'err'); $('#crewFolder').focus(); return; }
                self.cwd = r.root; try { localStorage.setItem('ade.folder', r.root); } catch (e) {}
                self.loadFiles(r.root);
                self.wizGo(2);
            }).catch(function (e) { banner('folder error: ' + e, 'err'); });
            return;
        }
        if (this.wizStep === 2) {
            if (!($('#crewTask').value || '').trim()) { $('#crewTask').focus(); banner('describe the task', 'err'); return; }
            this.wizGo(3); return;
        }
        if (this.wizStep === 3) { this.wizGo(4); return; }
    },
    buildSummary: function () {
        var box = $('#crewSummary'); if (!box) return;
        var review = $('#crewReview') ? $('#crewReview').checked : true;
        var researcher = $('#crewResearcher') ? $('#crewResearcher').checked : true;
        var auditor = $('#crewAuditor') ? $('#crewAuditor').checked : true;
        var team = ['🧭 Director'];
        if (researcher) team.push('🔎 Researcher');
        team.push('👷 ' + ($('#crewMax').value || '2') + ' Coder(s)');
        if (auditor) team.push('🔍 Auditor');
        box.innerHTML =
            '<div><b>Folder</b> <span class="val">' + esc(this.cwd || '.') + '</span></div>' +
            '<div><b>Task</b> <span class="val">' + esc(($('#crewTask').value || '').trim()) + '</span></div>' +
            '<div><b>Team</b> <span class="val">' + team.join(' · ') + '</span></div>' +
            '<div><b>Model</b> <span class="val">' + esc($('#crewModel').value || '') + '</span></div>' +
            '<div><b>Landing</b> <span class="' + (review ? 'val' : 'warnv') + '">' + (review ? 'review every branch (safe)' : (auditor ? 'auto-merge audit-clean' : 'review (no auditor)')) + '</span></div>';
    },
    runCrewFromWizard: function () {
        var task = ($('#crewTask').value || '').trim(); if (!task) { this.wizGo(2); return; }
        var opts = {
            max: $('#crewMax').value || '2',
            model: ($('#crewModel') && $('#crewModel').value) || $('#modelSelect').value || 'llama3.2:latest',
            review: $('#crewReview') ? $('#crewReview').checked : true,
            researcher: $('#crewResearcher') ? $('#crewResearcher').checked : true,
            auditor: $('#crewAuditor') ? $('#crewAuditor').checked : true
        };
        this.closeCrew();
        this.runCrew(task, opts);
    },
    // Launch `ollamadev crew "<task>"` in a fresh terminal and show it full-screen.
    runCrew: function (task, opts) {
        opts = opts || {};
        if (this.terminals.length >= this.MAX_TERMINALS) { banner('close a terminal first (max ' + this.MAX_TERMINALS + ')', 'err'); return; }
        var model = opts.model || $('#modelSelect').value || 'llama3.2:latest';
        var cli = this.cli || 'ollamadev';
        var q = "'" + String(task).replace(/'/g, "'\\''") + "'"; // shell single-quote
        // Run in the opened project folder, not the ADE's own directory.
        var cmd = this.cdPrefix() + cli + ' crew ' + q + ' --max ' + (parseInt(opts.max, 10) || 2) + ' -m ' + model +
            (opts.review !== false ? ' --review' : '') +
            (opts.researcher === false ? ' --no-research' : '') +
            (opts.auditor === false ? ' --no-audit' : '');
        var id = rid(); var t = new Terminal(id, 'crew');
        var self = this;
        Promise.resolve(window.termCreate(id, model)).then(function () {
            self.terminals.push(t); self.render();
            setTimeout(function () { try { window.termWrite(id, strToB64(cmd + '\n')); } catch (e) {} }, 400);
            self.setView('board');        // show the kanban so the Director's plan appears
            self.startCrewPoll();
            banner('crew running…', 'ok');
        }).catch(function (e) { banner('crew launch failed: ' + e, 'err'); });
    },
    // Poll the live crew board so the kanban reflects the Director's plan + progress.
    startCrewPoll: function () {
        var self = this;
        if (this.crewPoll) return;
        var idle = 0;
        this.crewPoll = setInterval(function () {
            Promise.resolve(window.crewBoard ? window.crewBoard() : null).then(function (b) {
                self.crewBoard = (b && b.subtasks) ? b : self.crewBoard;
                if (self.view === 'board') Tasks.render();
                // Stop polling a while after the run goes inactive.
                if (b && b.active === false) { if (++idle > 6) { clearInterval(self.crewPoll); self.crewPoll = null; } }
                else idle = 0;
            }).catch(function () {});
        }, 1500);
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
        // SIMPLE_INPUT: the CLI uses plain line input so the embedded terminal
        // (which the host pty echoes into) renders cleanly without raw-mode escapes.
        var cmd = this.cdPrefix() + 'OLLAMADEV_SIMPLE_INPUT=1 ' + (this.cli || 'ollamadev') + (model ? ' -m ' + model : '') + '\n';
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
        // A zoomed terminal takes the whole area; otherwise the CSS responsive
        // grid fits as many readable-width panes as possible and scrolls for more.
        if (this.zoomed && !this.terminals.some(function (t) { return t.id === this.zoomed; }, this)) this.zoomed = null;
        var list = this.zoomed ? this.terminals.filter(function (t) { return t.id === this.zoomed; }) : this.terminals;
        var n = list.length;
        // Fit all panes; shrink the font as the count rises so code stays legible.
        var cols = this.zoomed || n <= 1 ? 1 : Math.min(4, Math.ceil(Math.sqrt(n)));
        var fs = this.zoomed ? 13 : n <= 2 ? 13 : n <= 4 ? 12 : n <= 6 ? 11 : n <= 9 ? 10 : 9;
        wrap.className = (this.zoomed ? 'zoomed' : '') + (!this.zoomed && n > 6 ? ' dense' : '');
        wrap.style.gridTemplateColumns = 'repeat(' + cols + ', minmax(0, 1fr))';
        wrap.style.setProperty('--tfs', fs + 'px');
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
        else { banner('bindings unavailable', 'err'); }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();
window.app = App;

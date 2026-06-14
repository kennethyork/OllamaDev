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
window.onerror = function (m, s, l) {
    // "ResizeObserver loop completed with undelivered notifications" is a benign,
    // self-correcting browser warning (a resize handler resized something that
    // retriggered the observer; it settles next frame). Don't alarm the user with it.
    if (typeof m === 'string' && m.indexOf('ResizeObserver loop') !== -1) return true;
    banner('JS error: ' + m + ' @' + l, 'err'); return false;
};

// Voice dictation — records the mic locally and transcribes via the configured
// local STT engine (window.sttTranscribe → CLI → SttClient). Inserts the text
// into a target field. Nothing leaves the machine. Toggle: click to start, click
// to stop. The button is shown only when an STT engine is configured.
var Voice = {
    rec: null, btn: null, target: null,
    // Reveal the mic button if a local STT engine is configured.
    init: function (btnId, targetId) {
        var btn = document.getElementById(btnId), target = document.getElementById(targetId);
        if (!btn || !target || !window.sttEnabled) return;
        Promise.resolve(window.sttEnabled()).then(function (on) {
            if (!on) return;
            btn.hidden = false;
            btn.onclick = function () { Voice.toggle(btn, target); };
        }).catch(function () {});
    },
    toggle: function (btn, target) {
        if (this.rec) { try { this.rec.stop(); } catch (e) {} return; } // stop → triggers onstop
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
            banner('this build has no microphone access', 'err'); return;
        }
        var self = this;
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            var chunks = [], rec = new MediaRecorder(stream);
            rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
            rec.onstop = function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                self.rec = null; btn.classList.remove('rec'); btn.textContent = '🎤';
                var fr = new FileReader();
                fr.onload = function () {
                    var b64 = String(fr.result).split(',')[1] || '';
                    if (!b64) { banner('no audio captured', 'err'); return; }
                    banner('transcribing…');
                    Promise.resolve(window.sttTranscribe(b64, 'webm')).then(function (text) {
                        text = (text || '').trim();
                        if (text) { target.value += (target.value && !/\s$/.test(target.value) ? ' ' : '') + text; target.focus(); banner('transcribed', 'ok'); }
                        else banner('no transcription — check your STT engine', 'err');
                    }).catch(function () { banner('transcription failed', 'err'); });
                };
                fr.readAsDataURL(new Blob(chunks, { type: 'audio/webm' }));
            };
            self.rec = rec; rec.start();
            btn.classList.add('rec'); btn.textContent = '⏹'; banner('recording… click ⏹ to stop');
        }).catch(function () { banner('microphone permission denied', 'err'); });
    }
};

// Voice CONTROL — a press-to-talk window that runs your speech as a command (open
// windows, new terminal, center, …) or pipes it straight to a CLI terminal. Reuses
// the same local STT as dictation; nothing leaves the machine.
var VoiceCtl = {
    rec: null, mode: 'auto',
    bind: function () {
        var b = $('#voicePTT'); if (b) b.onclick = function () { VoiceCtl.toggle(); };
        document.querySelectorAll('input[name="voiceMode"]').forEach(function (r) {
            r.onchange = function () { if (r.checked) VoiceCtl.mode = r.value; };
        });
    },
    refreshTarget: function () {
        var el = $('#voiceTarget'); if (!el) return;
        var t = App.activeTerminal();
        el.textContent = t ? ('→ CLI target: ' + (t.model || 'terminal') + ' · ' + t.id.slice(-4)) : '→ CLI target: (open a terminal first)';
    },
    log: function (msg, kind) {
        var box = $('#voiceLog'); if (!box) return;
        var row = document.createElement('div');
        row.className = 'vlog-row' + (kind ? ' ' + kind : '');
        row.textContent = msg;
        box.insertBefore(row, box.firstChild);
        while (box.children.length > 8) box.removeChild(box.lastChild);
    },
    toggle: function () {
        var btn = $('#voicePTT'); if (!btn) return;
        if (this.rec) { try { this.rec.stop(); } catch (e) {} return; }
        if (!window.sttTranscribe) { banner('no local STT engine configured', 'err'); this.log('no STT engine configured', 'err'); return; }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
            banner('this build has no microphone access', 'err'); return;
        }
        var self = this;
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            var chunks = [], rec = new MediaRecorder(stream);
            rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
            rec.onstop = function () {
                stream.getTracks().forEach(function (t) { t.stop(); });
                self.rec = null; btn.classList.remove('rec'); var l = btn.querySelector('.vlabel'); if (l) l.textContent = 'Press to talk';
                var fr = new FileReader();
                fr.onload = function () {
                    var b64 = String(fr.result).split(',')[1] || '';
                    if (!b64) { banner('no audio captured', 'err'); return; }
                    banner('transcribing…');
                    Promise.resolve(window.sttTranscribe(b64, 'webm')).then(function (text) { self.handle((text || '').trim()); })
                        .catch(function () { banner('transcription failed', 'err'); self.log('transcription failed', 'err'); });
                };
                fr.readAsDataURL(new Blob(chunks, { type: 'audio/webm' }));
            };
            self.rec = rec; rec.start();
            btn.classList.add('rec'); var l = btn.querySelector('.vlabel'); if (l) l.textContent = 'Listening… tap to stop';
            banner('listening…');
        }).catch(function () { banner('microphone permission denied', 'err'); });
    },
    handle: function (raw) {
        var h = $('#voiceHeard'); if (h) h.textContent = raw ? ('“' + raw + '”') : '';
        if (!raw) { banner('no transcription', 'err'); return; }
        var lc = raw.toLowerCase().replace(/[.!?,]+$/, '');
        // Explicit dictation: "type/say/send/tell … <text>" → straight to the CLI.
        var pre = lc.match(/^(type|say|send|tell|dictate|prompt)\b[:,]?\s*/);
        if (pre && this.mode !== 'cmd') { this.toCli(raw.slice(pre[0].length)); return; }
        if (this.mode !== 'cli' && this.tryCommand(lc, raw)) return;
        if (this.mode === 'cmd') { this.log('no command matched: ' + raw, 'err'); banner('no matching command', 'err'); return; }
        this.toCli(raw);   // Auto fallthrough / "To CLI" mode
    },
    tryCommand: function (lc, raw) {
        raw = raw || lc;
        // ---- Crew control (additive — drives the EXISTING runCrew / crewSteer; the
        // crew, Director, worktrees, and auditor are untouched). Matched on `raw` so
        // the task/instruction keeps its original casing. ----
        var cm = raw.match(/\b(?:tell|steer|have|ask)\s+coder\s+(\d+)\s+(?:to\s+)?(.+)$/i);
        if (cm && window.crewSteer) { App.steerCrew(parseInt(cm[1], 10), cm[2].trim()); return true; }
        cm = raw.match(/\b(?:tell|steer|have|ask)\s+(?:the\s+)?(?:crew|team|everyone|all coders)\s+(?:to\s+)?(.+)$/i);
        if (cm && window.crewSteer) { App.steerCrew(0, cm[1].trim()); return true; }
        cm = raw.match(/\b(?:start|launch|spin up|kick off|run|create|fire up)\s+(?:a\s+|the\s+|new\s+)?(?:crew|team)\b\s*(?:to|for|that|:)?\s*(.*)$/i);
        if (cm) { App.voiceStartCrew(cm[1].trim()); return true; }
        cm = raw.match(/\bcrew[,:]?\s+(?:please\s+|should\s+|go\s+)?(?:to\s+)?(build|add|fix|implement|write|refactor|create|make)\b\s+(.+)$/i);
        if (cm) { App.voiceStartCrew(cm[1].trim() + ' ' + cm[2].trim()); return true; }
        if (/\bzoom in\b|\bzoom closer\b/.test(lc)) { App.zoomBy(1.2); this.log('zoomed in'); return true; }
        if (/\bzoom out\b|\bzoom away\b/.test(lc)) { App.zoomBy(1 / 1.2); this.log('zoomed out'); return true; }
        if (/\b(reset zoom|zoom reset|actual size|hundred percent|100 ?%)\b/.test(lc)) { App.resetZoom(); this.log('zoom reset'); return true; }
        if (/\b(re-?center|center|fit|recenter)\b/.test(lc)) { App.centerCanvas(); this.log('centered the canvas'); return true; }
        if (/\b(tiled?|grid)\b/.test(lc)) { App.setTermLayout('tiled'); this.log('tiled layout'); return true; }
        if (/\b(free|canvas mode|float)\b/.test(lc)) { App.setTermLayout('free'); this.log('free canvas'); return true; }
        if (/\bnew terminal\b|\b(add|open|another) (a )?terminal\b|\bnew (ollamadev|cli|agent)\b/.test(lc)) { App.spawnTerminal(); this.log('opened a new ollamadev CLI terminal', 'ok'); return true; }
        var m = lc.match(/\b(open|show|add|launch|bring up|go to|switch to)\b\s+(.+)$/);
        if (m) { var v = this.matchView(m[2]); if (v) { this.openView(v); this.log('opened ' + this.label(v), 'ok'); return true; } }
        m = lc.match(/\b(close|hide|dismiss)\b\s+(.+)$/);
        if (m) { var c = this.matchView(m[2]); if (c) { App.closePaneView(c); this.log('closed ' + this.label(c), 'ok'); return true; } }
        var bare = this.matchView(lc); if (bare) { this.openView(bare); this.log('opened ' + this.label(bare), 'ok'); return true; }
        return false;
    },
    matchView: function (s) {
        s = (s || '').toLowerCase();
        if (/\bterminal|shell|\bterm\b|cli|agent\b/.test(s)) return 'terminal';
        if (/editor|code editor/.test(s)) return 'editor';
        if (/\bfiles?\b|file tree|explorer/.test(s)) return 'files';
        if (/search/.test(s)) return 'search';
        if (/task/.test(s)) return 'tasks';
        if (/board|kanban/.test(s)) return 'board';
        if (/graph|memory|notes/.test(s)) return 'graph';
        if (/browser|preview|web ?view/.test(s)) return 'browser';
        if (/crew|team|director/.test(s)) return 'crew';
        if (/review|diff|changes/.test(s)) return 'diff';
        if (/roles?/.test(s)) return 'roles';
        if (/skills?/.test(s)) return 'skills';
        if (/hooks?/.test(s)) return 'hooks';
        if (/voice/.test(s)) return 'voice';
        return null;
    },
    label: function (v) { return (App.POP_TITLE[v] || v).replace(/^\S+\s/, ''); },
    openView: function (v) {
        if (v === 'terminal') { App.spawnTerminal(); return; }
        if (v === 'crew') { App.openCrew(); return; }
        if (v === 'diff') { Diff.open(); return; }
        if (v === 'roles') { Roles.open(); return; }
        if (v === 'skills') { SkillMgr.open(); return; }
        if (v === 'hooks') { HookMgr.open(); return; }
        App.addPane(v);   // files/search/tasks/editor/board/graph/browser/voice
    },
    toCli: function (text) {
        text = (text || '').trim();
        var t = App.activeTerminal();
        if (!t) { this.log('no terminal — say “new terminal” first', 'err'); banner('no terminal open', 'err'); return; }
        var lc = text.toLowerCase().replace(/[.!?,]+$/, '');
        if (/^(enter|press enter|submit|run it|send it|go ahead)$/.test(lc)) { App.sendToTerminal(t, '\n'); this.log('↵ enter → ' + t.id.slice(-4)); return; }
        if (/^(control c|ctrl c|cancel|stop it|interrupt)$/.test(lc)) { App.sendToTerminal(t, '\x03'); this.log('^C → ' + t.id.slice(-4)); return; }
        if (/^(clear|control l)$/.test(lc)) { App.sendToTerminal(t, '\x0c'); this.log('clear → ' + t.id.slice(-4)); return; }
        if (!text) return;
        App.sendToTerminal(t, text + '\n');
        this.log('→ CLI: ' + text, 'ok'); banner('sent to terminal', 'ok');
    }
};

// Tidy a long absolute path for a narrow label: collapse $HOME to ~ and keep the
// meaningful tail (last segments) instead of the start, e.g.
//   /home/me/Documents/OllamaDev/Desktop/ollamadev-ade  ->  ~/…/Desktop/ollamadev-ade
function shortPath(p, maxLen) {
    p = String(p || ''); maxLen = maxLen || 38;
    var home = (window.HOME_DIR || '');
    if (home && (p === home || p.indexOf(home + '/') === 0)) p = '~' + p.slice(home.length);
    if (p.length <= maxLen) return p;
    var lead = p[0] === '~' ? '~' : '';
    var parts = p.replace(/^~?\/?/, '').split('/').filter(Boolean);
    var tail = parts.slice(-2).join('/');
    return (lead ? lead + '/' : '') + '…/' + tail;
}

function strToB64(s) { return btoa(unescape(encodeURIComponent(s))); }
function b64ToStr(b64) {
    var bin = atob(b64), arr = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
    try { return new TextDecoder('utf-8', { fatal: false }).decode(arr); } catch (e) { return bin; }
}
function rid() { return 'term_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8); }

var ANSI_FG = { 30:'#484f58',31:'#f85149',32:'#3fb950',33:'#d29922',34:'#58a6ff',35:'#bc8cff',36:'#39c5cf',37:'#b1bac4',90:'#6e7681',91:'#ff7b72',92:'#56d364',93:'#e3b341',94:'#79c0ff',95:'#d2a8ff',96:'#56d4dd',97:'#f0f6fc' };
// The 16 base colors as an indexable palette (0-7 normal, 8-15 bright) for the
// 256-color cube, reusing the same GitHub-dark tones as ANSI_FG above.
var ANSI16 = ['#484f58','#f85149','#3fb950','#d29922','#58a6ff','#bc8cff','#39c5cf','#b1bac4','#6e7681','#ff7b72','#56d364','#e3b341','#79c0ff','#d2a8ff','#56d4dd','#f0f6fc'];
// xterm 256-color index → #rrggbb. 0-15 palette, 16-231 the 6×6×6 cube, 232-255 grayscale.
function xterm256(n) {
    n = n | 0;
    if (n < 16) return ANSI16[n] || '#b1bac4';
    if (n < 232) {
        n -= 16; var r = Math.floor(n / 36), g = Math.floor((n % 36) / 6), b = n % 6;
        var lv = function (v) { return v === 0 ? 0 : 55 + v * 40; };
        return 'rgb(' + lv(r) + ',' + lv(g) + ',' + lv(b) + ')';
    }
    var v = 8 + (n - 232) * 10; return 'rgb(' + v + ',' + v + ',' + v + ')';
}

// Apply an SGR (\x1b[…m) parameter string to a style state object {fg,bg,bold,
// dim,italic,underline,reverse}. Shared by the line renderer and the grid.
function parseSgr(st, p) {
    var codes = p.split(';').filter(function (x) { return x !== ''; }).map(Number);
    if (!codes.length) codes = [0];
    for (var i = 0; i < codes.length; i++) {
        var c = codes[i];
        if (c === 0) { st.fg = null; st.bg = null; st.bold = false; st.dim = false; st.italic = false; st.underline = false; st.reverse = false; }
        else if (c === 1) st.bold = true;
        else if (c === 2) st.dim = true;
        else if (c === 3) st.italic = true;
        else if (c === 4) st.underline = true;
        else if (c === 7) st.reverse = true;
        else if (c === 22) { st.bold = false; st.dim = false; }
        else if (c === 23) st.italic = false;
        else if (c === 24) st.underline = false;
        else if (c === 27) st.reverse = false;
        else if (c === 39) st.fg = null;
        else if (c === 49) st.bg = null;
        else if (c === 38 || c === 48) {
            var target = c === 38 ? 'fg' : 'bg';
            if (codes[i + 1] === 5) { st[target] = xterm256(codes[i + 2]); i += 2; }
            else if (codes[i + 1] === 2) { st[target] = 'rgb(' + (codes[i + 2] | 0) + ',' + (codes[i + 3] | 0) + ',' + (codes[i + 4] | 0) + ')'; i += 4; }
        }
        else if (ANSI_FG[c]) st.fg = ANSI_FG[c];
        else if (c >= 40 && c <= 47) st.bg = ANSI16[c - 40];
        else if (c >= 100 && c <= 107) st.bg = ANSI16[c - 100 + 8];
    }
}

// ---------- TermGrid: a real cell-grid terminal emulator for the alt screen ----
// Full-screen TUIs (vim, htop, less, top) switch to the alternate screen buffer
// and drive an absolute cursor. The line renderer can't model that, so when a
// program enters the alt screen we hand bytes to this grid: rows×cols of styled
// cells with a cursor, scroll region, erase, and scroll. Pure vanilla; the pty is
// told the same cols/rows (termResize) so the program's layout matches.
function TermGrid(cols, rows) {
    this.cols = Math.max(2, cols | 0); this.rows = Math.max(2, rows | 0);
    this.cx = 0; this.cy = 0; this.top = 0; this.bot = this.rows - 1;
    this.st = { fg: null, bg: null, bold: false, dim: false, italic: false, underline: false, reverse: false };
    this.saved = null;
    this.cells = []; for (var r = 0; r < this.rows; r++) this.cells.push(this.blankRow());
}
TermGrid.prototype.blankCell = function () { return { ch: ' ', fg: null, bg: null, bold: false, dim: false, italic: false, underline: false, reverse: false }; };
TermGrid.prototype.blankRow = function () { var a = []; for (var c = 0; c < this.cols; c++) a.push(this.blankCell()); return a; };
TermGrid.prototype.resize = function (cols, rows) {
    cols = Math.max(2, cols | 0); rows = Math.max(2, rows | 0);
    var old = this.cells, oc = this.cols;
    this.cols = cols; this.rows = rows; this.top = 0; this.bot = rows - 1;
    this.cells = [];
    for (var r = 0; r < rows; r++) {
        var row = this.blankRow();
        if (old[r]) for (var c = 0; c < cols && c < oc; c++) row[c] = old[r][c];
        this.cells.push(row);
    }
    if (this.cx >= cols) this.cx = cols - 1; if (this.cy >= rows) this.cy = rows - 1;
};
TermGrid.prototype.clamp = function () { if (this.cx < 0) this.cx = 0; if (this.cx >= this.cols) this.cx = this.cols - 1; if (this.cy < this.top) this.cy = this.top; if (this.cy > this.bot) this.cy = this.bot; };
TermGrid.prototype.scrollUp = function () { this.cells.splice(this.top, 1); this.cells.splice(this.bot, 0, this.blankRow()); };
TermGrid.prototype.scrollDown = function () { this.cells.splice(this.bot, 1); this.cells.splice(this.top, 0, this.blankRow()); };
TermGrid.prototype.lf = function () { if (this.cy >= this.bot) this.scrollUp(); else this.cy++; };
TermGrid.prototype.cr = function () { this.cx = 0; };
TermGrid.prototype.bs = function () { if (this.cx > 0) this.cx--; };
TermGrid.prototype.putText = function (s) {
    for (var i = 0; i < s.length; i++) {
        var ch = s[i];
        if (ch === '\t') { var n = 8 - (this.cx % 8); for (var k = 0; k < n && this.cx < this.cols; k++) this.putChar(' '); continue; }
        this.putChar(ch);
    }
};
TermGrid.prototype.putChar = function (ch) {
    if (this.cx >= this.cols) { this.cx = 0; this.lf(); }
    var cell = this.cells[this.cy][this.cx];
    cell.ch = ch; cell.fg = this.st.fg; cell.bg = this.st.bg; cell.bold = this.st.bold;
    cell.dim = this.st.dim; cell.italic = this.st.italic; cell.underline = this.st.underline; cell.reverse = this.st.reverse;
    this.cx++;
};
TermGrid.prototype.eraseLine = function (m, row) {
    var r = this.cells[row == null ? this.cy : row]; if (!r) return;
    var a = 0, b = this.cols - 1;
    if (m === 0) a = this.cx; else if (m === 1) b = this.cx;
    for (var c = a; c <= b; c++) r[c] = this.blankCell();
};
TermGrid.prototype.eraseDisplay = function (m) {
    if (m === 2 || m === 3) { for (var r = 0; r < this.rows; r++) this.cells[r] = this.blankRow(); return; }
    if (m === 0) { this.eraseLine(0); for (var r2 = this.cy + 1; r2 < this.rows; r2++) this.cells[r2] = this.blankRow(); }
    else if (m === 1) { this.eraseLine(1); for (var r3 = 0; r3 < this.cy; r3++) this.cells[r3] = this.blankRow(); }
};
// Handle one CSI sequence (params string + final letter). Covers the set TUIs use.
TermGrid.prototype.csi = function (params, fin) {
    var ps = params.replace(/^\?/, '').split(';').map(function (x) { return x === '' ? 0 : parseInt(x, 10); });
    var n = ps[0] || 0, n1 = ps[0] === 0 || isNaN(ps[0]) ? 1 : ps[0];
    switch (fin) {
        case 'm': parseSgr(this.st, params); break;
        case 'H': case 'f': this.cy = (this.top) + ((ps[0] || 1) - 1); this.cx = (ps[1] || 1) - 1; this.clamp(); break;
        case 'A': this.cy -= n1; this.clamp(); break;
        case 'B': this.cy += n1; this.clamp(); break;
        case 'C': this.cx += n1; this.clamp(); break;
        case 'D': this.cx -= n1; this.clamp(); break;
        case 'G': this.cx = (ps[0] || 1) - 1; this.clamp(); break;
        case 'd': this.cy = (ps[0] || 1) - 1; this.clamp(); break;
        case 'E': this.cx = 0; this.cy += n1; this.clamp(); break;
        case 'F': this.cx = 0; this.cy -= n1; this.clamp(); break;
        case 'J': this.eraseDisplay(n); break;
        case 'K': this.eraseLine(n); break;
        case 'L': for (var i = 0; i < n1; i++) { this.cells.splice(this.bot, 1); this.cells.splice(this.cy, 0, this.blankRow()); } break; // insert lines
        case 'M': for (var j = 0; j < n1; j++) { this.cells.splice(this.cy, 1); this.cells.splice(this.bot, 0, this.blankRow()); } break; // delete lines
        case 'P': { var r = this.cells[this.cy]; for (var d = 0; d < n1; d++) { r.splice(this.cx, 1); r.push(this.blankCell()); } break; } // delete chars
        case '@': { var r2 = this.cells[this.cy]; for (var e = 0; e < n1; e++) { r2.splice(this.cx, 0, this.blankCell()); r2.pop(); } break; } // insert chars
        case 'S': for (var s = 0; s < n1; s++) this.scrollUp(); break;
        case 'T': for (var t = 0; t < n1; t++) this.scrollDown(); break;
        case 'r': this.top = (ps[0] || 1) - 1; this.bot = (ps[1] || this.rows) - 1; this.cx = 0; this.cy = this.top; break; // scroll region
        case 's': this.saved = { cx: this.cx, cy: this.cy }; break;
        case 'u': if (this.saved) { this.cx = this.saved.cx; this.cy = this.saved.cy; } break;
        case 'X': { var r3 = this.cells[this.cy]; for (var x = 0; x < n1 && this.cx + x < this.cols; x++) r3[this.cx + x] = this.blankCell(); break; } // erase chars
    }
};
// Build the grid as styled HTML (one div per row, run-length spans by attributes).
TermGrid.prototype.renderHtml = function () {
    var out = [];
    for (var r = 0; r < this.rows; r++) {
        var row = this.cells[r], line = '', run = '', cur = null;
        var flush = function () {
            if (run === '') return;
            var sp = '<span', s = cur;
            var fg = s.fg, bg = s.bg;
            if (s.reverse) { var tmp = fg; fg = bg || '#0d1117'; bg = tmp || '#c9d1d9'; }
            var st = '';
            if (fg) st += 'color:' + fg + ';'; if (bg) st += 'background:' + bg + ';';
            if (s.bold) st += 'font-weight:700;'; if (s.dim) st += 'opacity:.6;';
            if (s.italic) st += 'font-style:italic;'; if (s.underline) st += 'text-decoration:underline;';
            line += (st ? '<span style="' + st + '">' : '<span>') + esc(run) + '</span>'; run = '';
        };
        for (var c = 0; c < this.cols; c++) {
            var cell = row[c];
            if (!cur || cell.fg !== cur.fg || cell.bg !== cur.bg || cell.bold !== cur.bold || cell.dim !== cur.dim || cell.italic !== cur.italic || cell.underline !== cur.underline || cell.reverse !== cur.reverse) { flush(); cur = cell; }
            run += cell.ch;
        }
        flush();
        out.push('<div class="term-line">' + (line || '&nbsp;') + '</div>');
    }
    return out.join('');
};

// ---------- CanvasRenderer: GPU-composited terminal via a single <canvas> --------
// Opt-in alternative to the DOM renderer. Instead of thousands of <div>/<span>
// nodes, it keeps a scrollback cell buffer and PAINTS the visible viewport into one
// canvas — a single hardware-composited layer with constant memory and dirty-cell
// repaint, so it stays fast under firehose output. Alt-screen TUIs reuse TermGrid.
// Pure vanilla (Canvas 2D, a browser built-in); no library, no WebGL.
function cellEq(a, b) {
    return a.ch === b.ch && a.fg === b.fg && a.bg === b.bg && a.bold === b.bold &&
        a.dim === b.dim && a.italic === b.italic && a.underline === b.underline && a.reverse === b.reverse;
}
function blankCell() { return { ch: ' ', fg: null, bg: null, bold: false, dim: false, italic: false, underline: false, reverse: false }; }
function CanvasRenderer(screenEl, opts) {
    opts = opts || {};
    this.fg = opts.fg || '#c9d1d9'; this.bg = opts.bg || '#0d1117';
    this.maxLines = opts.maxLines || 5000;
    this.screen = screenEl;
    this.canvas = document.createElement('canvas');
    this.canvas.className = 'term-canvas';
    this.ctx = this.canvas.getContext('2d', { alpha: false });
    // Match the DOM terminal exactly: pull the live theme colors/font from the
    // screen's computed style (so themes, font size, and family all carry over),
    // and zero its padding so the canvas fills edge-to-edge with clean cell math.
    if (screenEl) {
        this._padSave = screenEl.style.padding;
        screenEl.style.padding = '0';
        screenEl.appendChild(this.canvas);
        this.readTheme();
    }
    this.lines = [this.newRow(1)];      // scrollback (each row is a growable cell array)
    this.cols = 80; this.rows = 24; this.cw = 8; this.chh = 16; this.dpr = 1;
    this.cx = 0; this.cy = 0; this.view = 0;   // view = scrollback offset from bottom
    this.st = blankCell();
    this.painted = [];                  // last-painted snapshot for dirty-diff
    this.alt = false; this.grid = null;
    this.sel = null;                    // {a:{r,c}, b:{r,c}} selection in viewport coords
    this._bindMouse();
}
CanvasRenderer.prototype.newRow = function (n) { var a = []; for (var i = 0; i < (n || this.cols); i++) a.push(blankCell()); return a; };
// Read the real terminal colors/font from the screen's CSS so the canvas matches
// the DOM renderer (and the user's theme) instead of hardcoded values.
CanvasRenderer.prototype.readTheme = function () {
    var cs = getComputedStyle(this.screen);
    this.fg = (cs.color || '#c9d1d9').trim();
    var bg = cs.backgroundColor;
    this.bg = (bg && bg !== 'rgba(0, 0, 0, 0)' && bg !== 'transparent') ? bg : (cs.getPropertyValue('--bg2') || '#0d1117').trim();
    this.fontPx = parseFloat(cs.fontSize) || 13;
    var fam = (cs.fontFamily || cs.getPropertyValue('--mono') || 'monospace').trim() || 'monospace';
    // Append an emoji fallback so glyphs like 🧭 render instead of tofu (□□).
    if (!/emoji/i.test(fam)) fam += ', "Noto Color Emoji", "Apple Color Emoji", "Segoe UI Emoji", monospace';
    this.fontFam = fam;
    this.lineH = parseFloat(cs.lineHeight) || (this.fontPx * 1.45);
};
CanvasRenderer.prototype.dispose = function () {
    if (this.screen && this._padSave !== undefined) this.screen.style.padding = this._padSave;   // restore DOM padding
    if (this.canvas && this.canvas.parentNode) this.canvas.parentNode.removeChild(this.canvas);
};
// Resize: fill the screen, derive cols/rows from the LIVE container size (the
// authoritative measurement — avoids a stale clientWidth shrinking the canvas),
// and clear to the theme bg. Returns {cols, rows} so the pty can be sized to match.
CanvasRenderer.prototype.resize = function (cols, rows, cw, ch, dpr) {
    this.readTheme();   // re-read in case the theme/font changed
    this.cw = cw || this.cw; this.chh = ch || this.chh; this.dpr = dpr || this.dpr || 1;
    // Fill the container; the canvas CSS is 100%×100%, so use the live pixel size.
    var W = this.screen.clientWidth || Math.ceil((cols || 80) * this.cw);
    var H = this.screen.clientHeight || Math.ceil((rows || 24) * this.chh);
    this.cols = Math.max(2, Math.floor(W / this.cw));
    this.rows = Math.max(2, Math.floor(H / this.chh));
    this.canvas.style.width = W + 'px'; this.canvas.style.height = H + 'px';
    this.canvas.width = Math.ceil(W * this.dpr); this.canvas.height = Math.ceil(H * this.dpr);
    this.ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
    this.ctx.textBaseline = 'top';
    this.ctx.fillStyle = this.bg; this.ctx.fillRect(0, 0, W, H);   // paint the whole backdrop
    if (this.alt && this.grid) this.grid.resize(this.cols, this.rows);
    this.painted = []; this.fullRepaint();
    return { cols: this.cols, rows: this.rows };
};
// Active (bottom) screen rows of the scrollback buffer.
CanvasRenderer.prototype.base = function () { return Math.max(0, this.lines.length - this.rows); };
CanvasRenderer.prototype.ensureRows = function () {
    while (this.lines.length < this.rows) this.lines.push(this.newRow());
};
CanvasRenderer.prototype.lf = function () {
    if (this.cy < this.rows - 1) { this.cy++; return; }
    this.lines.push(this.newRow());
    if (this.lines.length > this.maxLines) this.lines.shift();
};
CanvasRenderer.prototype.cr = function () { this.cx = 0; };
CanvasRenderer.prototype.bs = function () { if (this.cx > 0) this.cx--; };
CanvasRenderer.prototype.curRow = function () { this.ensureRows(); var r = this.lines[this.base() + this.cy]; while (r.length < this.cols) r.push(blankCell()); return r; };
CanvasRenderer.prototype.putChar = function (ch) {
    if (this.cx >= this.cols) { this.cx = 0; this.lf(); }
    var row = this.curRow(), cell = row[this.cx];
    cell.ch = ch; cell.fg = this.st.fg; cell.bg = this.st.bg; cell.bold = this.st.bold;
    cell.dim = this.st.dim; cell.italic = this.st.italic; cell.underline = this.st.underline; cell.reverse = this.st.reverse;
    this.cx++;
};
CanvasRenderer.prototype.eraseLine = function (m) {
    var row = this.curRow(), a = 0, b = this.cols - 1;
    if (m === 0) a = this.cx; else if (m === 1) b = this.cx;
    for (var c = a; c <= b; c++) row[c] = blankCell();
};
CanvasRenderer.prototype.enterAlt = function () { if (this.alt) return; this.alt = true; this.grid = new TermGrid(this.cols, this.rows); this.painted = []; };
CanvasRenderer.prototype.leaveAlt = function () { if (!this.alt) return; this.alt = false; this.grid = null; this.painted = []; this.view = 0; this.fullRepaint(); };
// Feed pty bytes. Reuses the shared SGR parser + TermGrid for the alt screen.
CanvasRenderer.prototype.write = function (text) {
    var i = 0;
    while (i < text.length) {
        var ch = text[i];
        if (ch === '\x1b') {
            var m = /^\x1b\[([0-9;?]*)([A-Za-z@])/.exec(text.slice(i));
            if (m) {
                var pp = m[1], fin = m[2];
                if (/^\?(1049|47|1047)$/.test(pp) && (fin === 'h' || fin === 'l')) { if (fin === 'h') this.enterAlt(); else this.leaveAlt(); }
                else if (this.alt && this.grid) { if (fin !== 'h' && fin !== 'l') this.grid.csi(pp, fin); }
                else if (fin === 'm') parseSgr(this.st, pp);
                else if (fin === 'K') this.eraseLine((parseInt(pp, 10) || 0));
                else if (fin === 'J') { if ((parseInt(pp, 10) || 0) >= 2) { for (var z = 0; z < this.rows; z++) this.lines[this.base() + z] = this.newRow(); this.cx = 0; this.cy = 0; } else this.eraseLine(0); }
                else if (fin === 'H' || fin === 'f') { var ps = pp.split(';'); this.cy = Math.min(this.rows - 1, Math.max(0, (parseInt(ps[0], 10) || 1) - 1)); this.cx = Math.min(this.cols - 1, Math.max(0, (parseInt(ps[1], 10) || 1) - 1)); }
                else if (fin === 'A') this.cy = Math.max(0, this.cy - (parseInt(pp, 10) || 1));
                else if (fin === 'B') this.cy = Math.min(this.rows - 1, this.cy + (parseInt(pp, 10) || 1));
                else if (fin === 'C') this.cx = Math.min(this.cols - 1, this.cx + (parseInt(pp, 10) || 1));
                else if (fin === 'D') this.cx = Math.max(0, this.cx - (parseInt(pp, 10) || 1));
                else if (fin === 'G') this.cx = Math.min(this.cols - 1, Math.max(0, (parseInt(pp, 10) || 1) - 1));
                i += m[0].length; continue;
            }
            var osc = /^\x1b\][^\x07]*(?:\x07|\x1b\\)/.exec(text.slice(i));
            if (osc) { i += osc[0].length; continue; }
            i++; if (this.alt && text[i] && /[A-Za-z=>]/.test(text[i])) i++; continue;
        }
        if (this.alt && this.grid) {
            if (ch === '\r') { this.grid.cr(); i++; continue; }
            if (ch === '\n') { this.grid.lf(); i++; continue; }
            if (ch === '\x08') { this.grid.bs(); i++; continue; }
            var aj = i; while (aj < text.length && text[aj] !== '\x1b' && '\r\n\x08'.indexOf(text[aj]) < 0) aj++;
            this.grid.putText(text.slice(i, aj).replace(/[\x00-\x07\x0b-\x1f\x7f]/g, '')); i = aj; continue;
        }
        if (ch === '\r') { this.cr(); i++; continue; }
        if (ch === '\n') { this.lf(); i++; continue; }
        if (ch === '\x08') { this.bs(); i++; continue; }
        if (ch === '\t') { var t = 8 - (this.cx % 8); for (var k = 0; k < t; k++) this.putChar(' '); i++; continue; }
        var j = i; while (j < text.length && text[j] !== '\x1b' && '\r\n\x08\t'.indexOf(text[j]) < 0) j++;
        var run = text.slice(i, j).replace(/[\x00-\x07\x0b\x0c\x0e-\x1f\x7f]/g, '');
        for (var p = 0; p < run.length; p++) this.putChar(run[p]); i = j;
    }
    this.view = 0;   // new output snaps to the bottom
    this.paint();
};
// The cell grid currently visible in the viewport (alt grid, or scrollback window).
CanvasRenderer.prototype.viewRows = function () {
    if (this.alt && this.grid) return this.grid.cells;
    var start = Math.max(0, this.lines.length - this.rows - this.view), out = [];
    for (var r = 0; r < this.rows; r++) out.push(this.lines[start + r] || null);
    return out;
};
CanvasRenderer.prototype.fullRepaint = function () { this.painted = []; this.paint(); };
CanvasRenderer.prototype.paint = function () {
    if (!this.ctx || !this.canvas.width) return;
    var self = this;
    if (this._raf) return;
    this._raf = (window.requestAnimationFrame || function (f) { return setTimeout(f, 16); })(function () { self._raf = 0; self._paintNow(); });
};
CanvasRenderer.prototype._paintNow = function () {
    var rows = this.viewRows(), ctx = this.ctx, cw = this.cw, ch = this.chh;
    var fpx = this.fontPx || 13, fam = this.fontFam || 'monospace';
    var ty = Math.max(0, (ch - fpx) / 2);   // vertically center the glyph in the cell
    for (var r = 0; r < this.rows; r++) {
        var row = rows[r], prow = this.painted[r];
        for (var c = 0; c < this.cols; c++) {
            var cell = (row && row[c]) ? row[c] : blankCell();
            var pc = prow && prow[c];
            if (pc && cellEq(pc, cell) && !this._inSel(r, c) && !this._wasSel(r, c)) continue;   // unchanged
            var fg = cell.fg || this.fg, bg = cell.bg || this.bg;
            if (cell.reverse) { var tmp = fg; fg = bg; bg = tmp; }
            if (this._inSel(r, c)) { fg = this.bg; bg = '#2f6feb'; }   // selection highlight
            var x = c * cw, y = r * ch;
            ctx.fillStyle = bg; ctx.fillRect(x, y, cw + 0.5, ch + 0.5);
            if (cell.ch !== ' ') {
                ctx.fillStyle = fg;
                ctx.font = (cell.italic ? 'italic ' : '') + (cell.bold ? 'bold ' : '') + fpx + 'px ' + fam;
                ctx.globalAlpha = cell.dim ? 0.6 : 1;
                ctx.fillText(cell.ch, x, y + ty);
                ctx.globalAlpha = 1;
                if (cell.underline) ctx.fillRect(x, y + ch - 1.5, cw, 1);
            }
        }
    }
    // snapshot
    this.painted = []; this._selPainted = this.sel ? this._normSel() : null;
    for (var rr = 0; rr < this.rows; rr++) { var pr = []; var src = rows[rr]; for (var cc = 0; cc < this.cols; cc++) { var s = (src && src[cc]) ? src[cc] : blankCell(); pr.push({ ch: s.ch, fg: s.fg, bg: s.bg, bold: s.bold, dim: s.dim, italic: s.italic, underline: s.underline, reverse: s.reverse }); } this.painted.push(pr); }
};
// ---- selection + copy ----
CanvasRenderer.prototype._normSel = function () {
    if (!this.sel) return null;
    var a = this.sel.a, b = this.sel.b;
    if (a.r > b.r || (a.r === b.r && a.c > b.c)) { var t = a; a = b; b = t; }
    return { a: a, b: b };
};
CanvasRenderer.prototype._inSel = function (r, c) {
    var s = this._normSel(); if (!s) return false;
    if (r < s.a.r || r > s.b.r) return false;
    if (r === s.a.r && c < s.a.c) return false;
    if (r === s.b.r && c > s.b.c) return false;
    return true;
};
CanvasRenderer.prototype._wasSel = function (r, c) {
    var s = this._selPainted; if (!s) return false;
    if (r < s.a.r || r > s.b.r) return false;
    if (r === s.a.r && c < s.a.c) return false;
    if (r === s.b.r && c > s.b.c) return false;
    return true;
};
CanvasRenderer.prototype.selectionText = function () {
    var s = this._normSel(); if (!s) return '';
    var rows = this.viewRows(), out = [];
    for (var r = s.a.r; r <= s.b.r; r++) {
        var row = rows[r] || [], a = r === s.a.r ? s.a.c : 0, b = r === s.b.r ? s.b.c : this.cols - 1, line = '';
        for (var c = a; c <= b; c++) line += (row[c] ? row[c].ch : ' ');
        out.push(line.replace(/\s+$/, ''));
    }
    return out.join('\n');
};
CanvasRenderer.prototype._xyToCell = function (e) {
    var rect = this.canvas.getBoundingClientRect();
    return { r: Math.max(0, Math.min(this.rows - 1, Math.floor((e.clientY - rect.top) / this.chh))), c: Math.max(0, Math.min(this.cols - 1, Math.floor((e.clientX - rect.left) / this.cw))) };
};
CanvasRenderer.prototype._bindMouse = function () {
    var self = this, dragging = false;
    this.canvas.addEventListener('mousedown', function (e) { if (e.button !== 0) return; dragging = true; var p = self._xyToCell(e); self.sel = { a: p, b: p }; self.paint(); });
    window.addEventListener('mousemove', function (e) { if (!dragging) return; self.sel.b = self._xyToCell(e); self.paint(); });
    window.addEventListener('mouseup', function () { if (!dragging) return; dragging = false; var t = self.selectionText(); if (t && navigator.clipboard) try { navigator.clipboard.writeText(t); } catch (x) {} });
    this.canvas.addEventListener('wheel', function (e) {
        if (self.alt) return;
        var max = Math.max(0, self.lines.length - self.rows);
        self.view = Math.max(0, Math.min(max, self.view + (e.deltaY > 0 ? -3 : 3)));
        e.preventDefault(); self.fullRepaint();
    }, { passive: false });
};

// Map a browser keydown to the byte sequence a real terminal would send, so the
// pty (and the raw-mode ollamadev CLI inside it) gets live keystrokes.
function keyToBytes(e) {
    if (e.altKey) return null;
    if (e.metaKey) return null;   // macOS Cmd combos: don't type the letter — let Cmd+V fire the native paste, Cmd+C copy, etc.
    var k = e.key;
    if (e.ctrlKey) {
        if (k === 'c') return '\x03';
        if (k === 'd') return '\x04';
        if (k === 'z') return '\x1a';
        if (k === 'v' || k === 'V') return null;   // don't send ^V — let the browser's native paste event fire
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

// Build a "by project type" crew team (composition + domain focus).
function D(name, focus, max) {
    return { name: name, group: 'domain', max: max || 2, researcher: true, auditor: true, review: true, focus: focus };
}

// ---------- terminal pane (dependency-free, ANSI colors) ----------
function Terminal(id, model, cwd) {
    this.id = id; this.model = model; this.cwd = cwd || ''; this.offset = 0; this.polling = false;
    this.screen = null; this.line = null; this.fg = null; this.bg = null; this.bold = false;
    this.dim = false; this.italic = false; this.underline = false; this.reverse = false;
    this.status = 'idle'; this.lastData = 0; this.badgeEl = null; this.cr = false; this._tail = '';
    this.alt = false; this.grid = null; this.gridEl = null; this._cols = 0; this._rows = 0;
}
Terminal.prototype.mount = function (host) {
    var self = this;
    this.offset = 0; this.line = null; this.fg = null; this.bg = null; this.bold = false;
    this.dim = false; this.italic = false; this.underline = false; this.reverse = false; this.cr = false;
    var wasStreaming = !!this._es;                     // were we on SSE before this (re)mount?
    this._closeStream(); this._triedStream = false;   // re-attempt SSE streaming on (re)mount
    // Chat windows (kind 'chat') keep the standard draggable head but get a 💬 label and a
    // toolbar (model picker + 📎/🧠/⬇) below it — so you can open many independent chats,
    // each its own pane, just like terminals. The toolbar is wired in _wireChatBar().
    var isChat = this.kind === 'chat';
    host.innerHTML =
        '<div class="term-head"><span class="nm">' + (isChat ? '💬 Chat' : esc(this.model)) + '</span><span class="id">' + this.id.slice(-6) + '</span>' +
        '<span class="badge ' + this.status + '"><span class="b-dot"></span><span class="b-label">' + this.status + '</span></span>' +
        (isChat ? '' : '<button class="term-cd" title="Working folder — click to change">📁 ' + esc(this.cwd ? (this.cwd.split('/').filter(Boolean).pop() || '/') : 'project') + '</button>') +
        '<button class="zoom" title="Focus (make this bigger)">⤢</button>' +
        '<button class="x" title="Close">&times;</button></div>' +
        (isChat ?
          '<div class="chat-bar">' +
            '<select class="chat-model" title="Model — chat with any of your local Ollama models"></select>' +
            '<button class="chat-iconbtn chat-img" type="button" title="Attach an image (vision model) — also /image &lt;path&gt; or @&lt;path&gt;">📎</button>' +
            '<button class="chat-iconbtn chat-persona" type="button" title="Set a custom persona / system prompt — also /system &lt;text&gt;">🧠</button>' +
            '<button class="chat-iconbtn chat-export" type="button" title="Export this chat as Markdown (copy + download .md)">⬇</button>' +
          '</div>' : '') +
        '<div class="term-screen" tabindex="0" title="' + (isChat ? 'General chat — no tools. Click and type.' : 'Click and type — this is the live ollamadev CLI') + '"></div>' +
        // Touch input — shown only on small screens (web mode on a phone/tablet).
        // The raw PTY is awful with a soft keyboard, so type a line here + Enter,
        // and use the key bar for control keys a line input can't send.
        '<div class="term-touch">' +
          '<div class="term-keys">' +
            '<button data-k="tab">Tab</button><button data-k="esc">Esc</button>' +
            '<button data-k="up">↑</button><button data-k="down">↓</button>' +
            '<button data-k="cc">Ctrl-C</button><button data-k="cd">Ctrl-D</button>' +
          '</div>' +
          '<div class="term-line"><input class="term-input" enterkeyhint="send" autocapitalize="off" autocomplete="off" spellcheck="false" placeholder="type a command — Enter to send"><button class="term-send">Send</button></div>' +
        '</div>';
    this.screen = host.querySelector('.term-screen');
    // Wire the touch input: send the typed line (+newline) and the control keys.
    var sendRaw = function (s) { try { window.termWrite(self.id, strToB64(s)); } catch (e) {} };
    var tin = host.querySelector('.term-input');
    var sendLine = function () { sendRaw(tin.value + '\n'); self.markBusy(); tin.value = ''; tin.focus(); };
    tin.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); sendLine(); } });
    host.querySelector('.term-send').onclick = sendLine;
    var KEYS = { tab: '\t', esc: '\x1b', up: '\x1b[A', down: '\x1b[B', cc: '\x03', cd: '\x04' };
    host.querySelectorAll('.term-keys button').forEach(function (b) {
        b.onclick = function () { sendRaw(KEYS[b.dataset.k] || ''); tin.focus(); };
    });
    if (isChat) this._wireChatBar(host);
    this.badgeEl = host.querySelector('.badge');
    var head = host.querySelector('.term-head');
    head.title = 'Double-click to focus / restore';
    head.ondblclick = function (e) { if (e.target.classList.contains('x') || e.target.classList.contains('zoom')) return; app.toggleZoom(self.id); };
    // Focus button: enlarge this terminal to fill the area; click again to restore
    // it back into the grid with the rest. Reflects the current state per render.
    var zb = host.querySelector('.zoom');
    var focused = app.zoomed === self.id;
    zb.textContent = focused ? '⤡' : '⤢';
    zb.title = focused ? 'Restore (back to the other terminals)' : 'Focus (make this terminal bigger)';
    zb.onclick = function (e) { e.stopPropagation(); app.toggleZoom(self.id); };
    host.querySelector('.x').onclick = function () { app.closeTerminal(self.id); };
    // Folder chip: click to edit this terminal's working folder inline. Enter respawns
    // it in the new directory; Esc/blur cancels. Each terminal can run in its own folder.
    var cd = host.querySelector('.term-cd');
    if (cd) cd.onclick = function (e) {
        e.stopPropagation();
        var cur = self.cwd || (app.cwd && app.cwd !== '.' ? app.cwd : '');
        var inp = document.createElement('input');
        inp.className = 'field mono term-cd-edit'; inp.value = cur; inp.placeholder = 'folder path (~ ok)';
        cd.replaceWith(inp); inp.focus(); inp.select();
        var done = false;
        var finish = function (commit) {
            if (done) return; done = true;
            var v = inp.value.trim();
            if (commit && v && v !== cur) app.changeTermFolder(self.id, v); else app.render();
        };
        inp.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); finish(true); } else if (ev.key === 'Escape') finish(false); });
        inp.addEventListener('blur', function () { finish(false); });
    };
    // Hidden, focused textarea: it owns keyboard focus and — crucially — catches the
    // browser's NATIVE paste event (Ctrl+V / Shift+Insert / middle-click), which a
    // non-editable div never gets. That's the vanilla, dependency-free way to paste in
    // a webview that blocks navigator.clipboard.readText(). pointer-events:none lets
    // mouse clicks/drags fall through to the screen below, so selection-to-copy still
    // works. caret-color:transparent + opacity:0 keep it invisible.
    var kbd = document.createElement('textarea');
    kbd.className = 'term-kbd';
    kbd.setAttribute('autocapitalize', 'off'); kbd.setAttribute('autocomplete', 'off');
    kbd.setAttribute('autocorrect', 'off'); kbd.spellcheck = false; kbd.tabIndex = -1;
    kbd.style.cssText = 'position:absolute;left:0;top:0;width:1px;height:1px;opacity:0;border:0;padding:0;margin:0;resize:none;overflow:hidden;outline:none;pointer-events:none;white-space:nowrap;caret-color:transparent;z-index:0;';
    host.appendChild(kbd);
    this.kbd = kbd;
    // The textarea IS the terminal's keyboard: forward every keystroke to the pty.
    kbd.addEventListener('keydown', function (e) {
        // Clipboard shortcuts FIRST — before keyToBytes turns them into control bytes.
        // Ctrl+Shift+C copies the selection; Ctrl+Shift+V is a best-effort paste.
        // macOS Cmd+C (with a selection) / Cmd+V too. Plain Ctrl+C stays SIGINT; plain
        // Ctrl+V is NOT intercepted here so the browser's native paste event can fire.
        var mod = e.ctrlKey || e.metaKey;
        if (mod && e.shiftKey && (e.key === 'C' || e.key === 'c')) { e.preventDefault(); self.copySelection(); return; }
        if (mod && e.shiftKey && (e.key === 'V' || e.key === 'v')) { e.preventDefault(); self.pasteClipboard(); return; }
        if (e.metaKey && !e.ctrlKey && !e.shiftKey && (e.key === 'c' || e.key === 'C') && self.hasSelection()) { e.preventDefault(); self.copySelection(); return; }
        // macOS Cmd+V is NOT intercepted — it falls through to keyToBytes (null), so the
        // browser's native paste event fires on the textarea, like Ctrl+V on Win/Linux.
        var data = keyToBytes(e);
        if (data !== null) {
            e.preventDefault();
            try { window.termWrite(self.id, strToB64(data)); } catch (err) {}
            // Submitting a line (Enter) kicks off agent work → leave 'idle'.
            if (data.indexOf('\r') !== -1) self.markBusy();
        }
    });
    // Native paste — fires here for Ctrl+V, Shift+Insert, and X11 middle-click.
    kbd.addEventListener('paste', function (e) {
        var t = (e.clipboardData || window.clipboardData).getData('text');
        e.preventDefault();   // never let it land in the (hidden) textarea
        if (t) { try { window.termWrite(self.id, strToB64(t)); } catch (err) {} }
        kbd.value = '';
    });
    kbd.addEventListener('input', function () { kbd.value = ''; });   // belt-and-suspenders
    kbd.addEventListener('focus', function () { if (window.App) App.lastActiveTerm = self.id; });
    // Right-click → a small Copy/Paste menu. The Boson webview shows no usable
    // native context menu, and the canvas menu deliberately skips terminal panes
    // (so it has to live here). stopPropagation keeps the canvas menu from firing.
    this.screen.addEventListener('contextmenu', function (e) {
        e.preventDefault(); e.stopPropagation();
        self.showTermMenu(e.clientX, e.clientY);
    });
    // Clicking the screen routes keyboard focus to the hidden textarea. After a
    // drag-select, keep the selection (don't steal focus) so Ctrl+Shift+C can read it.
    this.screen.onclick = function () { self.kbd.focus(); if (window.App) App.lastActiveTerm = self.id; };
    this.screen.addEventListener('mouseup', function () {
        setTimeout(function () { if (!(window.getSelection && String(window.getSelection()))) self.kbd.focus(); }, 0);
    });
    this.alt = false; this.grid = null; this.gridEl = null;   // alt screen never survives a remount
    // Opt-in GPU-composited canvas renderer (App.canvasTerm). Default is the DOM
    // renderer, untouched — so this can never regress the working terminal.
    if (this.canvasR) { this.canvasR.dispose(); this.canvasR = null; }
    if (window.App && App.canvasTerm) {
        try { this.canvasR = new CanvasRenderer(this.screen, { fg: '#c9d1d9', bg: '#0d1117' }); } catch (e) { this.canvasR = null; }
    }
    if (!this.polling) this.poll();
    // Re-mount of a still-"polling" terminal that was on SSE: mount just CLOSED that stream,
    // so re-open it (offset was reset, so it re-streams from the top). Without this, a
    // terminal goes dead when another pane is added/removed (render() re-mounts everything).
    else if (wasStreaming) this.poll();
    setTimeout(function () { (self.kbd || self.screen).focus(); self.fit(); }, 0);   // size the pty once laid out
    // Re-fit whenever the pane's actual size changes (mount settle, free-pane drag,
    // zoom, window resize) — no timing guesswork. Fixes a canvas sized off a stale
    // clientWidth. Works for the DOM renderer too (keeps the pty cols/rows correct).
    if (window.ResizeObserver) {
        // Batch fit() to the next frame so resizing the (canvas) renderer inside the
        // observer callback can't synchronously retrigger the observer — which is what
        // produces the harmless "ResizeObserver loop … undelivered notifications" warning.
        try {
            if (this._ro) this._ro.disconnect();
            this._ro = new ResizeObserver(function () {
                if (self._roRaf) return;
                self._roRaf = (window.requestAnimationFrame || function (f) { return setTimeout(f, 16); })(function () { self._roRaf = 0; self.fit(); });
            });
            this._ro.observe(this.screen);
        } catch (e) {}
    }
};
// ---- Chat-window toolbar (kind 'chat'): each pane runs its own `ollamadev chat
// --session`, so opening another 💬 Chat is just another terminal — no singleton. ----
Terminal.prototype._wireChatBar = function (host) {
    var self = this;
    var sel = host.querySelector('.chat-model');
    if (sel) {
        var src = document.getElementById('modelSelect');
        var models = src ? [].slice.call(src.options).map(function (o) { return o.value; }).filter(function (m) { return m && m !== 'shell' && m !== 'chat'; }) : [];
        var want = this.model;
        if (models.indexOf(want) === -1 && models.length) want = models[0];
        sel.innerHTML = (models.length ? models : [want]).map(function (m) { return '<option' + (m === want ? ' selected' : '') + '>' + esc(m) + '</option>'; }).join('');
        if (want) sel.value = want;
        sel.onchange = function () { self.chatSetModel(sel.value); };
    }
    var ib = host.querySelector('.chat-img'); if (ib) ib.onclick = function () { self.chatImage(); };
    var pb = host.querySelector('.chat-persona'); if (pb) pb.onclick = function () { self.chatPersona(); };
    var eb = host.querySelector('.chat-export'); if (eb) eb.onclick = function () { self.chatExport(); };
};
Terminal.prototype._chatSend = function (s, noNewline) {
    try { window.termWrite(this.id, strToB64(s + (noNewline ? '' : '\n'))); } catch (e) {}
    if (this.kbd || this.screen) (this.kbd || this.screen).focus();
};
Terminal.prototype.chatSetModel = function (m) {
    if (!m || m === this.model) return;
    this.model = m;
    try { if (window.App) { App.lastModel = m; localStorage.setItem('ade.lastModel', m); localStorage.setItem('ade.chatModel', m); } } catch (e) {}
    this._chatSend('/model ' + m);   // the chat REPL switches model live — no restart
    banner('💬 chat → ' + m, 'ok');
};
Terminal.prototype.chatImage = function () {
    var p = (window.prompt ? window.prompt('Image file path (on this machine):', '') : '');
    if (p == null) return; p = (p || '').trim(); if (!p) return;
    this._chatSend('/image ' + p + ' ', true);   // no newline — type your question, then Enter
};
Terminal.prototype.chatPersona = function () {
    var p = (window.prompt ? window.prompt('Custom persona / system prompt (blank = reset to default):', '') : null);
    if (p == null) return;
    this._chatSend(p.trim() === '' ? '/system reset' : '/system ' + p.replace(/[\r\n]+/g, ' ').trim());
};
Terminal.prototype.chatExport = function () {
    if (!this.chatSession || !window.chatExport) { banner('send a message first', 'err'); return; }
    Promise.resolve(window.chatExport(this.chatSession)).then(function (r) {
        if (!r || r.error || !r.markdown) { banner('export: ' + ((r && r.error) || 'send a message first'), 'err'); return; }
        var md = r.markdown, name = ((r.title || 'chat').replace(/[^\w.-]+/g, '_').slice(0, 40) || 'chat') + '.md';
        try { if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(md); } catch (e) {}
        try {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([md], { type: 'text/markdown' }));
            a.download = name; document.body.appendChild(a); a.click();
            setTimeout(function () { try { URL.revokeObjectURL(a.href); } catch (e) {} a.remove(); }, 1500);
        } catch (e) {}
        banner('⬇ chat exported (copied + .md downloaded)', 'ok');
    }).catch(function () { banner('export failed', 'err'); });
};
Terminal.prototype.setStatus = function (s) {
    this.status = s;
    if (this.badgeEl) {
        this.badgeEl.className = 'badge ' + s;
        var lbl = this.badgeEl.querySelector('.b-label'); if (lbl) lbl.textContent = s;
    }
};
// Mark the terminal as actively working. Called when a line is submitted (Enter)
// so the badge leaves 'idle' the moment the agent starts — previously only the
// programmatic send paths set 'running', so directly-typed prompts stayed 'idle'
// the whole time the agent worked.
Terminal.prototype.markBusy = function () { this.setStatus('running'); this.lastData = Date.now(); };
// Derive the badge state from the actual output instead of a quiet-timer. The
// ollamadev CLI prints its prompt glyph (❯) only when it's waiting for input, so
// "the tail of output ends with ❯" is the authoritative idle signal: until the
// CLI prints a fresh prompt it's still working — even if it's been silent for a
// while (a long bash run, a slow model loading, tool execution). The old
// "quiet for 2.5s ⇒ done" timer flipped the badge to done while the turn was
// still running, and could leave it stuck there; this never does.
Terminal.prototype._trackStatus = function (text) {
    var clean = text.replace(/\[[0-9;?=]*[A-Za-z]/g, '').replace(/\r/g, '');
    this._tail = (this._tail + clean).slice(-400);
    // Back at a fresh prompt → idle. The CLI prints its prompt on its own line
    // (newline + ❯ + space), so require that exact shape: it's specific enough that
    // streamed answer text won't trip it, and user keystrokes echo AFTER the ❯ (so a
    // prompt with typed text doesn't match — typing at idle stays idle).
    if (/\n❯[ \t]*$/.test(this._tail)) { if (this.status !== 'idle') this.setStatus('idle'); }
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
    // Drop leftover C0 control bytes (bell, NUL, etc.) so they never render as
    // tofu/□ boxes — keep tab. \r \n \x08 \x7f \x1b are handled before emit().
    s = s.replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, '');
    if (s === '') return;
    var sp = document.createElement('span');
    // Reverse video swaps fg/bg (default fg is the screen's foreground colour).
    var fg = this.fg, bg = this.bg;
    if (this.reverse) { var t = fg; fg = bg || 'var(--term-fg, #c9d1d9)'; bg = t || 'var(--term-bg, #0d1117)'; }
    if (fg) sp.style.color = fg;
    if (bg) sp.style.background = bg;
    if (this.bold) sp.style.fontWeight = '700';
    if (this.dim) sp.style.opacity = '0.6';
    if (this.italic) sp.style.fontStyle = 'italic';
    if (this.underline) sp.style.textDecoration = 'underline';
    sp.textContent = s; this.line.appendChild(sp);
};
Terminal.prototype.sgr = function (p) { parseSgr(this, p); };
// Enter/leave the alternate screen: build/tear down the cell grid for full-screen
// TUIs. The line scrollback is hidden (not destroyed) while the grid is shown.
Terminal.prototype.enterAlt = function () {
    if (this.alt) return;
    this.alt = true;
    this.grid = new TermGrid(this._cols || 80, this._rows || 24);
    this.gridEl = document.createElement('div');
    this.gridEl.className = 'term-grid';
    this.screen.appendChild(this.gridEl);
};
Terminal.prototype.leaveAlt = function () {
    if (!this.alt) return;
    this.alt = false; this.grid = null;
    if (this.gridEl && this.gridEl.parentNode) this.gridEl.parentNode.removeChild(this.gridEl);
    this.gridEl = null;
    if (this.screen) this.screen.scrollTop = this.screen.scrollHeight;
};
Terminal.prototype.renderGrid = function () {
    if (!this.alt || !this.gridEl || !this.grid) return;
    var self = this;
    if (this._gridRaf) return;   // batch to one repaint per frame
    this._gridRaf = (window.requestAnimationFrame || function (f) { return setTimeout(f, 16); })(function () {
        self._gridRaf = 0;
        if (self.gridEl && self.grid) self.gridEl.innerHTML = self.grid.renderHtml();
    });
};
Terminal.prototype.write = function (text) {
    if (!this.screen) return;
    if (this.canvasR) { this.canvasR.write(text); return; }   // canvas mode: paint, skip the DOM path
    var bottom = this.screen.scrollHeight - this.screen.scrollTop - this.screen.clientHeight < 50;
    var i = 0;
    while (i < text.length) {
        var ch = text[i];
        if (ch === '\x1b') {
            var csi = /^\x1b\[([0-9;?]*)([A-Za-z@])/.exec(text.slice(i));
            if (csi) {
                var pp = csi[1], fin = csi[2];
                // Alt-screen toggle (?1049/?47/?1047 h|l) — switch between the line
                // scrollback and the cell grid.
                if (/^\?(1049|47|1047)$/.test(pp) && (fin === 'h' || fin === 'l')) {
                    if (fin === 'h') this.enterAlt(); else this.leaveAlt();
                } else if (this.alt && this.grid) {
                    if (fin !== 'h' && fin !== 'l') this.grid.csi(pp, fin);   // ignore other private mode sets
                } else if (fin === 'm') this.sgr(pp);
                // Cursor up N logical lines (line mode). Used by the reasoning
                // collapse (\r ESC[nA ESC[J) to climb back over a hard-wrapped
                // thinking block before erasing it. Each emitted line is one div
                // (the CLI hard-wraps so no soft-wrap splits a row), so moving N
                // divs up matches a real terminal moving N rows up.
                else if (fin === 'A' && this.line) {
                    var up = parseInt(pp, 10) || 1, L = this.line;
                    while (up-- > 0 && L && L.previousSibling && L.previousSibling.classList
                           && L.previousSibling.classList.contains('term-line')) L = L.previousSibling;
                    this.line = L;
                }
                // Erase to end of display (ESC[J / ESC[0J): clear the current line
                // and drop every line below it — wipes the thinking block so the
                // one-line summary replaces it. ESC[1J/ESC[2J keep the old
                // clear-current-line behaviour (screen clears go through alt mode).
                else if (fin === 'J' && (pp === '' || pp === '0') && this.line) {
                    this.line.innerHTML = '';
                    while (this.line.nextSibling) this.screen.removeChild(this.line.nextSibling);
                }
                else if ((fin === 'K' || fin === 'J') && this.line) this.line.innerHTML = '';
                i += csi[0].length; continue;
            }
            var osc = /^\x1b\][^\x07]*(?:\x07|\x1b\\)/.exec(text.slice(i));
            if (osc) { i += osc[0].length; continue; }
            // Bare ESC sequences (RI, etc.) in alt mode: skip the next byte.
            i++; if (this.alt && text[i] && /[A-Za-z=>]/.test(text[i])) i++; continue;
        }
        if (this.alt && this.grid) {
            if (ch === '\r') { this.grid.cr(); i++; continue; }
            if (ch === '\n') { this.grid.lf(); i++; continue; }
            if (ch === '\x08') { this.grid.bs(); i++; continue; }
            var aj = i;
            while (aj < text.length && text[aj] !== '\x1b' && text[aj] !== '\n' && text[aj] !== '\r' && text[aj] !== '\x08') aj++;
            this.grid.putText(text.slice(i, aj).replace(/[\x00-\x07\x0b-\x1f\x7f]/g, '')); i = aj; continue;
        }
        if (ch === '\r') { this.cr = true; i++; continue; }
        if (ch === '\n') { this.cr = false; this.newLine(); i++; continue; }
        if (ch === '\x08' || ch === '\x7f') { this.backspace(); i++; continue; } // erase (pty echoes \b \b / DEL)
        // Scan a run of plain text. Stop at any control byte we handle above —
        // critically \x08/\x7f, so a backspace erase isn't emitted as a literal
        // (tofu) control glyph, which both broke the erase and looked like a
        // font problem in the desktop/web terminal.
        var j = i;
        while (j < text.length && text[j] !== '\x1b' && text[j] !== '\n' && text[j] !== '\r'
               && text[j] !== '\x08' && text[j] !== '\x7f') j++;
        this.emit(text.slice(i, j)); i = j;
    }
    if (this.alt) this.renderGrid();
    else if (bottom) this.screen.scrollTop = this.screen.scrollHeight;
};
// Measure one monospace cell, compute cols×rows from the screen size, and tell the
// pty (termResize) so a TUI's layout matches what we render. Re-fits on resize/zoom.
Terminal.prototype.measure = function () {
    var probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;font:inherit;';
    probe.textContent = '0000000000000000000000000000000000000000';   // 40 chars
    this.screen.appendChild(probe);
    var rect = probe.getBoundingClientRect();
    this.screen.removeChild(probe);
    return { cw: (rect.width / 40) || 8, ch: rect.height || 16 };
};
Terminal.prototype.fit = function () {
    if (!this.screen || !this.screen.clientWidth) return;
    var m = this.measure();
    // Canvas mode: the renderer fills the container and derives cols/rows itself
    // (authoritative). Size the pty to whatever it actually fit.
    if (this.canvasR) {
        var g = this.canvasR.resize(0, 0, m.cw, m.ch, window.devicePixelRatio || 1);
        if (g && (g.cols !== this._cols || g.rows !== this._rows)) { this._cols = g.cols; this._rows = g.rows; try { window.termResize(this.id, g.cols, g.rows); } catch (e) {} }
        return;
    }
    // Were we pinned to the bottom before this reflow? (The content has already
    // re-wrapped to the new width by the time the ResizeObserver fires.)
    var atBottom = this.screen.scrollHeight - this.screen.scrollTop - this.screen.clientHeight < 60;
    var cols = Math.max(20, Math.floor((this.screen.clientWidth - 6) / m.cw));
    var rows = Math.max(6, Math.floor((this.screen.clientHeight - 4) / m.ch));
    if (cols === this._cols && rows === this._rows) return;
    this._cols = cols; this._rows = rows;
    try { window.termResize(this.id, cols, rows); } catch (e) {}
    if (this.alt && this.grid) { this.grid.resize(cols, rows); this.renderGrid(); }
    // Stay pinned to the bottom so the live prompt stays in view when the window is
    // made narrower/shorter (the reflow would otherwise leave you scrolled up).
    else if (atBottom) { var s = this.screen; (window.requestAnimationFrame || setTimeout)(function () { s.scrollTop = s.scrollHeight; }); }
};
Terminal.prototype.poll = function () {
    var self = this; this.polling = true;
    // Web mode: stream output over SSE for low latency. If the server can't stream
    // (no workers → 503) or anything errors, onError demotes us to polling.
    if (window.__odvOpenStream && !this._triedStream) {
        this._triedStream = true;
        this._es = window.__odvOpenStream(this.id, this.offset, function (data, offset) {
            var dec = b64ToStr(data);
            self.write(dec); self.offset = offset; self.lastData = Date.now();
            self._trackStatus(dec);   // back to idle only when the CLI reprints its prompt
        }, function () { self._es = null; if (self.polling) self._pollLoop(); });
        if (this._es) return;
    }
    this._pollLoop();
};
// Adaptive poll — used on the desktop (native bridge) and as the web fallback when
// SSE streaming isn't available. There's no stream here, so THIS poll is the
// terminal's refresh rate: a flat 80ms made steady output land in visible 80ms
// chunks. Instead, drain fast (12ms) while bytes are flowing so output streams
// smoothly, then ease off (→150ms) once it goes quiet to keep idle CPU/IPC low.
Terminal.prototype._pollLoop = function () {
    var self = this; this.polling = true;
    function tick() {
        if (!self.polling) return;
        Promise.resolve(window.termRead(self.id, self.offset)).then(function (r) {
            if (r && r.data) {
                var dec = b64ToStr(r.data);
                self.write(dec); self.offset = r.offset; self.lastData = Date.now();
                self._trackStatus(dec);   // back to idle only when the CLI reprints its prompt
                return true;
            }
            return false;
        }).catch(function () { return false; }).then(function (got) {
            if (!self.polling) return;
            var since = Date.now() - (self.lastData || 0);
            // got data → keep draining quickly; just-active → stay responsive; idle → back off.
            var delay = got ? 12 : (since < 300 ? 24 : (since < 2000 ? 80 : 150));
            setTimeout(tick, delay);
        });
    }
    tick();
};
Terminal.prototype._closeStream = function () { if (this._es) { try { this._es.close(); } catch (e) {} this._es = null; } };
Terminal.prototype._disposeCanvas = function () { if (this.canvasR) { try { this.canvasR.dispose(); } catch (e) {} this.canvasR = null; } if (this._ro) { try { this._ro.disconnect(); } catch (e) {} this._ro = null; } if (this._roRaf) { try { (window.cancelAnimationFrame || clearTimeout)(this._roRaf); } catch (e) {} this._roRaf = 0; } };
Terminal.prototype.close = function () { this.polling = false; this._closeStream(); this._disposeCanvas(); try { window.termKill(this.id); } catch (e) {} };
// Detach the UI but KEEP the backend pty alive (used when switching workspaces),
// so re-attaching later resumes the same running session.
Terminal.prototype.detach = function () { this.polling = false; this._closeStream(); this._disposeCanvas(); };
// ---- clipboard ----
// Is there a copyable selection? Canvas renderer has its own grid selection; the
// DOM renderer uses the native browser selection.
Terminal.prototype.hasSelection = function () {
    if (this.canvasR && this.canvasR.selectionText) { try { if (this.canvasR.selectionText()) return true; } catch (e) {} }
    return !!(window.getSelection && String(window.getSelection()));
};
// Copy the current terminal selection to the clipboard. writeText is permitted in
// the webview; falls back to a temp-textarea execCommand('copy') if it isn't.
Terminal.prototype.copySelection = function () {
    var text = '';
    if (this.canvasR && this.canvasR.selectionText) { try { text = this.canvasR.selectionText() || ''; } catch (e) {} }
    if (!text && window.getSelection) text = String(window.getSelection());
    if (!text) return;
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text); }
        else {
            var ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
        }
        if (typeof banner === 'function') banner('copied', 'ok');
    } catch (e) {}
};
// Paste clipboard text into the pty. The native `paste` event handler (middle-click,
// Shift+Insert) stays as a fallback when readText is blocked in the webview.
// Programmatic paste (Ctrl/Cmd+Shift+V, right-click menu) — cross-platform fallback
// chain, since each webview blocks JS clipboard reads differently. The native paste
// GESTURE (Ctrl+V / Cmd+V / Shift+Insert / middle-click) goes through the textarea's
// paste event and isn't affected by any of this.
//   1) execCommand('paste') — works on Linux WebKitGTK (javascript-can-access-clipboard
//      is on); fires a paste event on the hidden textarea, caught by its handler.
//   2) navigator.clipboard.readText() — works in Windows WebView2 (and web mode) in a
//      user gesture.
//   3) window.clipboardRead() native binding — macOS/Windows OS built-ins (pbpaste /
//      Get-Clipboard); the guaranteed fallback for those two. All vanilla, no installs.
Terminal.prototype.pasteClipboard = function () {
    var self = this;
    if (this.kbd) this.kbd.focus();
    var write = function (t) { if (t) { try { window.termWrite(self.id, strToB64(t)); } catch (e) {} return true; } return false; };
    var nativeRead = function () {
        if (typeof window.clipboardRead === 'function') {
            try { Promise.resolve(window.clipboardRead()).then(write).catch(function () {}); } catch (e) {}
        }
    };
    try { if (document.execCommand('paste')) return; } catch (e) {}   // (1)
    try {
        if (navigator.clipboard && navigator.clipboard.readText) {     // (2)
            navigator.clipboard.readText().then(function (t) { if (!write(t)) nativeRead(); }).catch(nativeRead);
            return;
        }
    } catch (e) {}
    nativeRead();                                                      // (3)
};
// Right-click menu: Copy (enabled only with a selection) + Paste. Reuses the
// .canvas-menu styling. Buttons preventDefault on mousedown so clicking Copy
// doesn't clear the text selection before copySelection() reads it.
Terminal.prototype.showTermMenu = function (x, y) {
    var self = this;
    var old = document.getElementById('termCtxMenu'); if (old) old.remove();
    var hasSel = this.hasSelection();
    var m = document.createElement('div');
    m.id = 'termCtxMenu'; m.className = 'canvas-menu';
    m.style.left = x + 'px'; m.style.top = y + 'px'; m.style.zIndex = '9999';
    function remove() {
        m.remove();
        document.removeEventListener('mousedown', onDoc, true);
        document.removeEventListener('keydown', onKey, true);
    }
    function onDoc(ev) { if (!m.contains(ev.target)) remove(); }
    function onKey(ev) { if (ev.key === 'Escape') remove(); }
    var mk = function (label, fn, enabled) {
        var b = document.createElement('button'); b.type = 'button'; b.textContent = label;
        if (!enabled) { b.disabled = true; b.style.opacity = '.45'; b.style.cursor = 'default'; }
        else {
            b.onmousedown = function (ev) { ev.preventDefault(); };   // keep selection + focus
            b.onclick = function () { remove(); fn(); };
        }
        m.appendChild(b);
    };
    mk('Copy', function () { self.copySelection(); }, hasSel);
    mk('Paste', function () { self.pasteClipboard(); }, true);
    document.body.appendChild(m);
    var r = m.getBoundingClientRect();
    if (r.right > window.innerWidth) m.style.left = Math.max(4, x - r.width) + 'px';
    if (r.bottom > window.innerHeight) m.style.top = Math.max(4, y - r.height) + 'px';
    setTimeout(function () {
        document.addEventListener('mousedown', onDoc, true);
        document.addEventListener('keydown', onKey, true);
    }, 0);
};

function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

// ---------- code editor (tabs + line-numbered textarea, vanilla) ----------
var Editor = {
    tabs: [], active: -1, mounted: null,
    cur: function () { return this.active >= 0 ? this.tabs[this.active] : null; },
    open: function (path, name) {
        var self = this;
        if (window.App && App.ensurePane) App.ensurePane('editor');   // editor is a canvas pane
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
    // Close every tab without prompting — used when switching workspaces (the
    // outgoing workspace's open files are already saved in its state).
    closeAll: function () { this.tabs = []; this.active = -1; this.mounted = null; this.render(); },
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
        // Separate Director: show the steering bar only while a run is active.
        var dbar = $('#directorBar'); if (dbar) dbar.hidden = !(App.crewBoard && App.crewBoard.active);
        this.wireDirector();
        this.renderInto($('#board'));
    },
    renderInto: function (board) {
        if (!board) return;
        var self = this;
        var crew = (App.crewBoard && Array.isArray(App.crewBoard.subtasks)) ? App.crewBoard.subtasks : [];
        // Crew's auto-suggested next steps (ideas) land in To-do as 💡 cards.
        var ideas = (App.crewBoard && Array.isArray(App.crewBoard.ideas)) ? App.crewBoard.ideas : [];
        board.innerHTML = this.COLS.map(function (c) {
            // Director's plan as live, read-only crew cards.
            var crewInCol = crew.filter(function (s) { return self.crewCol(s.state) === c[0]; });
            var crewCards = crewInCol.map(function (s) {
                var held = s.state === 'held';
                // The Director tags each subtask with a role (coder/tester/docs/…); show it.
                var role = (s.role && s.role !== 'coder') ? '<span class="role">' + esc(s.role) + '</span>' : '';
                return '<div class="card crew' + (held ? ' held' : '') + '">' +
                    '<div class="title">🤖 ' + esc(s.title) + role + '</div>' +
                    '<div class="cmeta">' + (held ? '⚠ held' : (s.state === 'doing' ? '● working' : s.state)) + '</div></div>';
            }).join('');
            // Suggested next-step ideas show as To-do cards you can run with one click.
            var ideaCards = c[0] !== 'todo' ? '' : ideas.map(function (idea) {
                return '<div class="card idea"><div class="title">💡 ' + esc(idea) + '</div>' +
                    '<div class="actions"><button class="run" data-act="run-idea" data-idea="' + esc(idea) + '">▶ Run</button></div></div>';
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
            var body = (crewCards + ideaCards + manual) || '<div class="board-empty">—</div>';
            var count = crewInCol.length + cards.length + (c[0] === 'todo' ? ideas.length : 0);
            return '<div class="col" data-col="' + c[0] + '"><div class="col-head"><span class="dotc"></span>' + c[1] +
                '<span class="count">' + count + '</span></div><div class="col-body">' + body + '</div></div>';
        }).join('');
        this.wire(board);
    },
    // Wire the separate-Director steering bar once. Parses "<#>: instruction"
    // (defaults to coder 1 if no number) and sends it to the running crew.
    wireDirector: function () {
        var send = $('#directorSend'), msg = $('#directorMsg');
        if (!send || !msg || send._wired) return;
        send._wired = true;
        var fire = function () {
            var v = (msg.value || '').trim(); if (!v) return;
            var m = v.match(/^(\d+|all|\*|everyone)\s*[:>\-]\s*(.+)$/i);
            var coder = m ? (/^\d+$/.test(m[1]) ? parseInt(m[1], 10) : 0) : 1;   // 0 = whole crew
            var text = m ? m[2].trim() : v;
            Promise.resolve(window.crewSteer ? window.crewSteer(coder, text) : null).then(function (r) {
                msg.value = '';
                msg.placeholder = (r && r.error) ? ('⚠ ' + r.error) : ('✓ sent to ' + (coder === 0 ? 'the crew' : 'coder ' + coder));
                setTimeout(function () { msg.placeholder = 'Steer a coder — e.g. "2: focus on tests" or "all: ..."'; }, 2500);
            });
        };
        send.onclick = fire;
        msg.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); fire(); } });
    },
    order: ['todo', 'doing', 'done'],
    wire: function (board) {
        var self = this;
        // Suggested-idea cards aren't in the manual list — hand the idea straight
        // to an agent terminal (no id lookup).
        board.querySelectorAll('[data-act="run-idea"]').forEach(function (btn) {
            btn.onclick = function () { App.runTaskInAgent(btn.dataset.idea); };
        });
        board.querySelectorAll('.card').forEach(function (card) {
            var id = card.dataset.id;
            if (!id) return; // idea/crew cards have no manual id
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

// Project memory graph — a tiny vanilla force-directed layout on a canvas.
var Graph = {
    nodes: [], edges: [], anim: null, hover: null, dragging: null, dpr: 1,
    load: function () {
        var self = this;
        Promise.resolve(window.memoryGraph ? window.memoryGraph() : { nodes: [], edges: [] }).then(function (g) {
            g = g || {}; var nodes = g.nodes || [], edges = g.edges || [];
            $('#graphStat').textContent = nodes.length + ' notes · ' + edges.length + ' links';
            $('#graphEmpty').hidden = nodes.length > 0;
            $('#graphCanvas').hidden = nodes.length === 0;
            self.build(nodes, edges);
        }).catch(function () { $('#graphEmpty').hidden = false; });
    },
    build: function (nodes, edges) {
        var cv = $('#graphCanvas'); if (!cv) return;
        var w = cv.clientWidth || 800, h = cv.clientHeight || 500;
        // seed positions on a circle (deterministic — no Math.random needed)
        this.nodes = nodes.map(function (n, i) {
            var a = (i / Math.max(1, nodes.length)) * Math.PI * 2;
            return { id: n.id, title: n.title || n.id, tags: n.tags || [], degree: n.degree || 0,
                x: w / 2 + Math.cos(a) * Math.min(w, h) * 0.3, y: h / 2 + Math.sin(a) * Math.min(w, h) * 0.3, vx: 0, vy: 0 };
        });
        var byId = {}; this.nodes.forEach(function (n) { byId[n.id] = n; });
        this.edges = (edges || []).filter(function (e) { return byId[e.from] && byId[e.to]; });
        this.byId = byId;
        this.resize();
        this.start();
    },
    resize: function () {
        var cv = $('#graphCanvas'); if (!cv) return;
        this.dpr = window.devicePixelRatio || 1;
        cv.width = cv.clientWidth * this.dpr; cv.height = cv.clientHeight * this.dpr;
    },
    start: function () {
        var self = this; if (this.anim) cancelAnimationFrame(this.anim);
        var ticks = 0;
        var step = function () {
            var settle = self.tick();
            self.draw();
            ticks++;
            // keep animating while the user interacts; otherwise stop once settled
            if (self.dragging || (ticks < 600 && settle > 0.05)) self.anim = requestAnimationFrame(step);
            else self.anim = null;
        };
        step();
    },
    tick: function () {
        var cv = $('#graphCanvas'); var w = cv.clientWidth, h = cv.clientHeight;
        var ns = this.nodes, i, j, energy = 0;
        var k = 0.012, rep = 9000, spring = 0.02, restLen = 120, damp = 0.85;
        for (i = 0; i < ns.length; i++) { ns[i].fx = (w / 2 - ns[i].x) * k; ns[i].fy = (h / 2 - ns[i].y) * k; }
        for (i = 0; i < ns.length; i++) for (j = i + 1; j < ns.length; j++) {
            var dx = ns[i].x - ns[j].x, dy = ns[i].y - ns[j].y, d2 = dx * dx + dy * dy + 0.01;
            var f = rep / d2, d = Math.sqrt(d2), ux = dx / d, uy = dy / d;
            ns[i].fx += ux * f; ns[i].fy += uy * f; ns[j].fx -= ux * f; ns[j].fy -= uy * f;
        }
        this.edges.forEach(function (e) {
            var a = this.byId[e.from], b = this.byId[e.to];
            var dx = b.x - a.x, dy = b.y - a.y, d = Math.sqrt(dx * dx + dy * dy) + 0.01;
            var f = (d - restLen) * spring, ux = dx / d, uy = dy / d;
            a.fx += ux * f; a.fy += uy * f; b.fx -= ux * f; b.fy -= uy * f;
        }, this);
        for (i = 0; i < ns.length; i++) {
            if (ns[i] === this.dragging) { ns[i].vx = 0; ns[i].vy = 0; continue; }
            ns[i].vx = (ns[i].vx + ns[i].fx) * damp; ns[i].vy = (ns[i].vy + ns[i].fy) * damp;
            ns[i].x += ns[i].vx; ns[i].y += ns[i].vy;
            energy += Math.abs(ns[i].vx) + Math.abs(ns[i].vy);
        }
        return ns.length ? energy / ns.length : 0;
    },
    draw: function () {
        var cv = $('#graphCanvas'); if (!cv) return; var ctx = cv.getContext('2d');
        var css = getComputedStyle(document.body);
        var line = css.getPropertyValue('--border') || '#444', accent = css.getPropertyValue('--accent') || '#4ea1ff';
        var fg = css.getPropertyValue('--fg') || '#ddd', panel = css.getPropertyValue('--bg3') || '#222';
        ctx.save(); ctx.scale(this.dpr, this.dpr);
        ctx.clearRect(0, 0, cv.clientWidth, cv.clientHeight);
        ctx.strokeStyle = line; ctx.lineWidth = 1; ctx.globalAlpha = 0.7;
        this.edges.forEach(function (e) {
            var a = this.byId[e.from], b = this.byId[e.to];
            ctx.beginPath(); ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y); ctx.stroke();
        }, this);
        ctx.globalAlpha = 1;
        this.nodes.forEach(function (n) {
            var r = 6 + Math.min(14, n.degree * 2);
            ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, Math.PI * 2);
            ctx.fillStyle = (n === this.hover) ? accent : panel; ctx.fill();
            ctx.lineWidth = 2; ctx.strokeStyle = accent; ctx.stroke();
            ctx.fillStyle = fg; ctx.font = '12px system-ui, sans-serif'; ctx.textAlign = 'center';
            ctx.fillText(n.title, n.x, n.y + r + 13);
        }, this);
        ctx.restore();
    },
    nodeAt: function (mx, my) {
        for (var i = this.nodes.length - 1; i >= 0; i--) {
            var n = this.nodes[i], r = 6 + Math.min(14, n.degree * 2) + 4;
            if ((mx - n.x) * (mx - n.x) + (my - n.y) * (my - n.y) <= r * r) return n;
        }
        return null;
    },
    bind: function () {
        var self = this, cv = $('#graphCanvas'); if (!cv) return;
        var pos = function (ev) { var rc = cv.getBoundingClientRect(); return { x: ev.clientX - rc.left, y: ev.clientY - rc.top }; };
        cv.addEventListener('mousedown', function (ev) { var p = pos(ev); self.dragging = self.nodeAt(p.x, p.y); if (self.dragging && !self.anim) self.start(); });
        cv.addEventListener('mousemove', function (ev) {
            var p = pos(ev);
            if (self.dragging) { self.dragging.x = p.x; self.dragging.y = p.y; }
            else { var h = self.nodeAt(p.x, p.y); if (h !== self.hover) { self.hover = h; cv.style.cursor = h ? 'pointer' : 'default'; if (!self.anim) self.draw(); } }
        });
        window.addEventListener('mouseup', function () { self.dragging = null; });
        var rb = $('#graphRefresh'); if (rb) rb.onclick = function () { self.load(); };
        window.addEventListener('resize', function () { if (App.popped && App.popped.graph) { self.resize(); if (!self.anim) self.draw(); } });
    }
};

// Live per-coder panes: one read-only terminal-style pane per crew coder,
// tailing its log so you watch the whole team build in parallel.
var CrewPanes = {
    runId: null, offsets: {}, text: {}, bodies: {}, count: 0, zoomed: null,
    // Live activity per coder ({icon,label,detail}), parsed from each coder's log
    // tail. Shared with the Topology window so both show what an agent is doing now.
    activity: {},
    // Turn the last meaningful log line into a status: editing/reading/running/etc.
    parseActivity: function (text) {
        if (!text) return null;
        var lines = text.split('\n'), ln = '';
        for (var i = lines.length - 1; i >= 0 && i >= lines.length - 8; i--) { if (lines[i].trim()) { ln = lines[i].trim(); break; } }
        if (/^✓ done/.test(ln)) return { icon: '✓', label: 'done', detail: '' };
        if (/^✗ error/.test(ln)) return { icon: '✗', label: 'error', detail: ln.slice(2, 60).trim() };
        var m = ln.match(/^→\s+(\S+)\s*(.*)$/);   // tool call:  → write path ⇒ snippet
        if (m) {
            var tool = m[1].toLowerCase(), rest = (m[2] || '').split('⇒')[0].trim();
            var first = rest.split(/\s+/)[0] || '', file = /[\/.]/.test(first) ? first.split('/').pop() : '';
            if (/write|edit|str_replace|create|apply|patch/.test(tool)) return { icon: '✎', label: 'editing', detail: file };
            if (/bash|shell|run|exec|command|test/.test(tool)) return { icon: '⚡', label: 'running', detail: rest.slice(0, 38) };
            if (/view|read|cat|open|fetch/.test(tool)) return { icon: '👁', label: 'reading', detail: file };
            if (/grep|glob|find|ls|search|list/.test(tool)) return { icon: '🔎', label: 'searching', detail: rest.slice(0, 30) };
            return { icon: '⚙', label: tool, detail: file };
        }
        if (/^·/.test(ln)) return { icon: '💭', label: 'thinking', detail: '' };
        return null;
    },
    sync: function (board) {
        var self = this;
        var host = $('#crewPanes'); if (!host) return;
        var subs = (board && Array.isArray(board.subtasks)) ? board.subtasks : [];
        if (!board || !board.runId || !subs.length) { host.hidden = true; host.innerHTML = ''; this.runId = null; this.count = 0; this.zoomed = null; return; }
        host.hidden = false;
        // (Re)build the panes when the run or the coder count changes.
        if (board.runId !== this.runId || subs.length !== this.count) {
            this.runId = board.runId; this.count = subs.length; this.offsets = {}; this.text = {}; this.bodies = {};
            var self = this;
            host.innerHTML = subs.map(function (s) {
                return '<div class="cpane" data-n="' + s.n + '">' +
                    '<div class="cpane-head"><span class="cpane-title">👷 ' + esc(s.title || ('coder ' + s.n)) + '</span>' +
                    '<span class="cpane-badge" data-n="' + s.n + '"></span>' +
                    '<button class="cpane-zoom" data-n="' + s.n + '" title="Focus this coder">⤢</button></div>' +
                    '<pre class="cpane-body" data-n="' + s.n + '"></pre></div>';
            }).join('');
            host.querySelectorAll('.cpane-body').forEach(function (el) { self.bodies[el.dataset.n] = el; });
            // Focus button (and double-click the head) enlarges one coder; click
            // again to put it back among the others.
            host.querySelectorAll('.cpane-zoom').forEach(function (btn) {
                btn.onclick = function (e) { e.stopPropagation(); self.toggleZoom(parseInt(btn.dataset.n, 10)); };
            });
            host.querySelectorAll('.cpane-head').forEach(function (h) {
                h.ondblclick = function (e) { if (e.target.classList.contains('cpane-zoom')) return; self.toggleZoom(parseInt(h.parentNode.dataset.n, 10)); };
            });
        }
        // Update state badges every sync — a working coder shows its LIVE activity
        // (editing/reading/running …) instead of a static "working" label.
        subs.forEach(function (s) {
            var badge = host.querySelector('.cpane-badge[data-n="' + s.n + '"]'); if (!badge) return;
            var map = { todo: '○ queued', doing: '● working', done: '✓ done', held: '⚠ held', flagged: '⚑ flagged' };
            var st = s.state || 'todo', act = self.activity[s.n];
            var label = map[st] || st;
            if (st === 'doing' && act && act.label !== 'done') label = act.icon + ' ' + act.label + (act.detail ? ' ' + act.detail : '');
            badge.textContent = label;
            badge.className = 'cpane-badge st-' + st;
            // Tag the whole pane with its status so the CSS can accent it (a coder's
            // state reads at a glance) without clobbering the zoom 'focused' class.
            var pane = host.querySelector('.cpane[data-n="' + s.n + '"]');
            if (pane) { pane.classList.remove('st-todo', 'st-doing', 'st-done', 'st-held', 'st-flagged'); pane.classList.add('st-' + st); }
        });
        // A focused coder that vanished on rebuild reverts to the grid.
        if (this.zoomed != null && !subs.some(function (s) { return s.n === this.zoomed; }, this)) this.zoomed = null;
        this.applyZoom();
        this.poll(subs);
    },
    toggleZoom: function (n) { this.zoomed = this.zoomed === n ? null : n; this.applyZoom(); },
    applyZoom: function () {
        var host = $('#crewPanes'); if (!host) return;
        var z = this.zoomed;
        host.classList.toggle('zoomed', z != null);
        host.querySelectorAll('.cpane').forEach(function (p) {
            var isF = z != null && parseInt(p.dataset.n, 10) === z;
            p.classList.toggle('focused', isF);
            var btn = p.querySelector('.cpane-zoom');
            if (btn) { btn.textContent = isF ? '⤡' : '⤢'; btn.title = isF ? 'Restore (back to the other coders)' : 'Focus this coder'; }
        });
    },
    poll: function (subs) {
        if (!this.runId || !window.crewCoderLog) return;
        var self = this;
        subs.forEach(function (s) {
            var n = s.n, off = self.offsets[n] || 0;
            Promise.resolve(window.crewCoderLog(self.runId, n, off)).then(function (r) {
                if (!r || !r.data) return;
                self.text[n] = (self.text[n] || '') + r.data;
                self.offsets[n] = r.size;
                self.activity[n] = self.parseActivity(self.text[n]);   // live status for the badge + topology
                var el = self.bodies[n];
                if (el) { el.textContent = self.text[n]; el.scrollTop = el.scrollHeight; }
            }).catch(function () {});
        });
    }
};

// ---------- Live crew topology ----------
// A read-only map of the running crew: the Director + Researcher + Auditor, and
// one card per coder showing the branch it owns, its model, the files it's
// touching, the Auditor's verdict, and live activity. Purely additive — it reads
// the same `crewBoard()` the kanban does (now enriched with branch/files/audit)
// plus CrewPanes' parsed activity. It changes nothing about how the crew runs.
var Topology = {
    sync: function (board) {
        var host = $('#topologyBody'); if (!host) return;
        var subs = (board && Array.isArray(board.subtasks)) ? board.subtasks : [];
        var stat = $('#topoStat');
        if (!board || !board.runId || !subs.length) {
            host.innerHTML = '<div class="topo-empty dim">No crew running. Launch one — <b>👥 Crew</b>, or say <i>“start a crew to add tests to the signup form”</i> — and the whole team appears here live: who owns which branch, the files they’re touching, and the Auditor’s verdict.</div>';
            if (stat) stat.textContent = '';
            return;
        }
        var models = board.models || {}, self = this;
        if (stat) stat.textContent = (board.active ? '● live' : '○ done') + ' · ' + subs.length + ' coder' + (subs.length > 1 ? 's' : '') + (board.parallel > 1 ? ' · ‖ ' + board.parallel + ' parallel' : '') + (board.amplify > 1 ? ' · audit ×' + board.amplify : '');
        var dir =
            '<div class="topo-dir">' +
              '<div class="topo-node dir"><div class="tn-top"><span class="tn-ico">🧭</span><span class="tn-role">Director</span>' +
              '<span class="tn-model">' + esc(models.director || board.model || '') + '</span></div>' +
              '<div class="tn-task" title="' + esc(board.task || '') + '">' + esc((board.task || '(waiting for a task)').slice(0, 90)) + '</div></div>' +
              '<div class="topo-aux">' +
                '<span class="topo-chip">🔎 Researcher <b>' + esc(models.researcher || '—') + '</b></span>' +
                '<span class="topo-chip">🔍 Auditor <b>' + esc(models.auditor || '—') + '</b></span>' +
                (board.focus ? '<span class="topo-chip">🎯 ' + esc(board.focus.slice(0, 28)) + '</span>' : '') +
              '</div>' +
            '</div>';
        var cards = subs.map(function (s) { return self.coderCard(s, models); }).join('');
        host.innerHTML = dir + '<div class="topo-coders">' + cards + '</div>';
    },
    coderCard: function (s, models) {
        var st = s.state || 'todo';
        var stMap = { todo: '○ queued', doing: '● working', done: '✓ merged', held: '⚠ held', flagged: '⚑ flagged' };
        var auditMap = {
            clean: '<span class="topo-audit clean">✓ audit clean</span>',
            flagged: '<span class="topo-audit flagged">⚑ audit flagged' + (s.issues ? ' (' + s.issues + ')' : '') + '</span>',
            empty: '<span class="topo-audit empty">no changes</span>',
            manual: '<span class="topo-audit">review manually</span>'
        };
        var act = (window.CrewPanes && CrewPanes.activity && CrewPanes.activity[s.n]) || null;
        var actLine = (st === 'doing' && act && act.label !== 'done')
            ? '<div class="topo-act">' + act.icon + ' ' + esc(act.label) + (act.detail ? ' <span class="dim">' + esc(act.detail) + '</span>' : '') + '</div>' : '';
        var files = Array.isArray(s.files) ? s.files : [];
        var fileList = files.length
            ? '<div class="topo-files">' + files.slice(0, 6).map(function (f) { return '<span class="topo-file" title="' + esc(f) + '">' + esc(f.split('/').pop()) + '</span>'; }).join('') + (files.length > 6 ? '<span class="topo-file more">+' + (files.length - 6) + '</span>' : '') + '</div>'
            : '<div class="topo-files dim">—</div>';
        return '<div class="topo-node coder st-' + st + '" data-n="' + s.n + '">' +
            '<div class="tn-top"><span class="tn-ico">👷</span><span class="tn-role">' + esc(s.role || 'coder') + ' ' + s.n + '</span>' +
            '<span class="tn-state st-' + st + '">' + (stMap[st] || st) + '</span></div>' +
            '<div class="tn-branch" title="' + esc(s.branch || '') + '">⎇ ' + esc((s.branch || '').replace(/^crew\//, '') || '—') + '</div>' +
            '<div class="tn-model">' + esc(s.model || models.coder || '') + '</div>' +
            actLine + fileList +
            (auditMap[s.audit] || '') +
            '</div>';
    }
};

// ---------- workspaces (named projects, shared with the CLI) ----------
// Always-visible project tabs over the global workspace list. Each tab is a
// project folder; clicking one opens it and restores its saved window (terminals,
// editor tabs, layout, view). Backed by window.ws* → ~/.ollamadev/workspaces.json.
var Workspaces = {
    data: { active: null, workspaces: [] },
    bind: function () {
        var self = this;
        var add = $('#wsBarAdd');
        if (add) add.onclick = function () { App.openFolderModal(false, true); };   // "+" opens a DIFFERENT project as a new tab
        // Delegated click on the strip container — attached ONCE and survives every
        // re-render, so clicking a project tab always switches (per-element handlers
        // rebuilt on each render could be missed / lost).
        var strip = $('#wsStrip');
        if (strip) strip.addEventListener('click', function (e) {
            var x = e.target.closest('.wst-x');
            if (x) { e.stopPropagation(); e.preventDefault(); self.remove(x.dataset.id); return; }
            var tab = e.target.closest('.ws-tab-item');
            if (tab && tab.dataset.id) self.switchTo(tab.dataset.id);
        });
    },
    load: function () {
        var self = this;
        if (!window.wsList) { this.render(); return Promise.resolve(this.data); }
        return Promise.resolve(window.wsList()).then(function (d) {
            self.data = (d && Array.isArray(d.workspaces)) ? { active: d.active || null, workspaces: d.workspaces } : { active: null, workspaces: [] };
            self.render();
            return self.data;
        }).catch(function () { self.render(); return self.data; });
    },
    current: function () {
        var a = this.data.active, list = this.data.workspaces || [];
        for (var i = 0; i < list.length; i++) if (list[i].id === a) return list[i];
        return null;
    },
    // Render the tabs in stable insertion order (so tabs don't jump around when
    // you switch — unlike a most-recent-first dropdown).
    render: function () {
        var strip = $('#wsStrip'); if (!strip) return;
        var self = this, act = this.data.active;
        var list = this.data.workspaces || [];
        if (!list.length) { strip.innerHTML = '<span class="ws-hint dim">No projects open — click ＋ to open one</span>'; return; }
        strip.innerHTML = list.map(function (w) {
            return '<div class="ws-tab-item' + (w.id === act ? ' active' : '') + '" data-id="' + esc(w.id) + '" title="' + esc(w.path) + '">' +
                '<span class="wst-dot"></span>' +
                '<span class="wst-name">' + esc(w.name) + '</span>' +
                '<button class="wst-x" data-id="' + esc(w.id) + '" title="Close (remove from list)">&times;</button>' +
                '</div>';
        }).join('');
        // Clicks are handled by the delegated listener in bind() — no per-element
        // handlers to (re)attach here, so a fresh render can never drop them.
    },
    switchTo: function (id) {
        if (id === this.data.active) return;
        var w = (this.data.workspaces || []).find(function (x) { return x.id === id; });
        if (w) App.openFolder(w.path, false);   // openFolder saves the outgoing ws + restores this one
    },
    addCurrent: function () {
        var self = this;
        var path = App.cwd;
        if (!path || path === '.') { banner('open a folder first', 'err'); return; }
        if (!window.wsAdd) { banner('workspaces unavailable', 'err'); return; }
        Promise.resolve(window.wsAdd(path, '')).then(function (w) {
            if (w && w.id) self.data.active = w.id;
            return self.load();
        }).then(function () { banner('workspace added', 'ok'); });
    },
    // Close a tab. If it's the active one, switch to a neighbour first so the
    // window doesn't go blank; otherwise just drop it from the list.
    remove: function (id) {
        var self = this;
        if (!window.wsRemove) return;
        var list = this.data.workspaces || [];
        var isActive = (id === this.data.active);
        var neighbour = null;
        if (isActive) {
            var i = list.findIndex(function (x) { return x.id === id; });
            var n = list[i + 1] || list[i - 1] || null;   // prefer the tab to the right
            neighbour = n ? n.id : null;
        }
        Promise.resolve(window.wsRemove(id)).then(function () {
            if (isActive && neighbour) { self.data.active = null; self.switchTo(neighbour); }
            return self.load();
        });
    },
    // Persist the current window into the active workspace (called before leaving).
    saveCurrentState: function () {
        var act = this.data.active;
        if (!act || !window.wsSaveState) return Promise.resolve();
        // Pass the window state as a JSON STRING (not a nested object) so it
        // marshals reliably across both the web bridge and the Boson FFI bridge.
        var json; try { json = JSON.stringify(App.captureState()); } catch (e) { json = '{}'; }
        return Promise.resolve(window.wsSaveState(act, json)).catch(function () {});
    }
};

// ---------- Crew roles: manage the catalog the Director assigns per subtask ----------
// Backed by the CLI (window.crewRole*), so the desktop, web, and terminal all see
// one global catalog. The Director picks a role for each subtask at plan time.
var Roles = {
    roles: [],
    bind: function () {
        var self = this;
        var open = $('#crewManageRoles'); if (open) open.onclick = function (e) { e.preventDefault(); self.open(); };
        var close = $('#rolesClose'); if (close) close.onclick = function () { self.close(); };
        var ov = $('#rolesOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.close(); };
        var save = $('#roleSave'); if (save) save.onclick = function () { self.add(); };
    },
    open: function () {
        if (!$('#rolesModal')) return;
        App.openDialog('roles');
        // Fill the optional pinned-model dropdown from the loaded models.
        var sel = $('#roleModel'), ms = $('#modelSelect');
        if (sel && ms) {
            var opts = Array.prototype.slice.call(ms.options).map(function (o) { return o.value; }).filter(function (m) { return m !== 'shell' && m !== 'chat'; });
            sel.innerHTML = '<option value="">— crew coder model —</option>' + opts.map(function (m) { return '<option>' + esc(m) + '</option>'; }).join('');
        }
        this.load();
    },
    close: function () { App.closePaneView('roles'); },
    load: function () {
        var self = this;
        if (!window.crewRoleList) { this.render({ roles: [] }); return Promise.resolve(); }
        return Promise.resolve(window.crewRoleList()).then(function (d) { self.render(d); }).catch(function () { self.render({ roles: [] }); });
    },
    render: function (d) {
        var box = $('#rolesList'); if (!box) return;
        var self = this;
        var roles = (d && Array.isArray(d.roles)) ? d.roles : [];
        this.roles = roles;
        if (!roles.length) { box.innerHTML = '<div class="board-empty">No roles found.</div>'; return; }
        box.innerHTML = roles.map(function (r) {
            var badges = '';
            if (r.builtin) badges += '<span class="rb">built-in</span>';
            if (r.model) badges += '<span class="rb">' + esc(r.model) + '</span>';
            if (r.permission === 'readonly') badges += '<span class="rb">read-only</span>';
            var del = r.builtin ? '' : '<button class="role-del" data-role="' + esc(r.name) + '" title="Remove this role">✕</button>';
            return '<div class="role-row"><div class="role-main"><span class="role-name">' + esc(r.name) + '</span> ' + badges +
                '<div class="role-desc dim">' + esc(r.desc || 'no description') + '</div></div>' + del + '</div>';
        }).join('');
        box.querySelectorAll('.role-del').forEach(function (b) { b.onclick = function () { self.remove(b.dataset.role); }; });
    },
    add: function () {
        var self = this;
        var name = (($('#roleName') || {}).value || '').trim();
        var persona = (($('#rolePersona') || {}).value || '').trim();
        if (!name) { banner('give the role a name', 'err'); return; }
        if (!persona) { banner('a role needs a persona', 'err'); return; }
        if (!window.crewRoleAdd) { banner('roles unavailable here', 'err'); return; }
        var desc = (($('#roleDesc') || {}).value || '').trim();
        var model = (($('#roleModel') || {}).value || '').trim();
        var ro = !!($('#roleReadonly') && $('#roleReadonly').checked);
        Promise.resolve(window.crewRoleAdd(name, persona, desc, model, ro)).then(function (d) {
            self.render(d);
            ['#roleName', '#rolePersona', '#roleDesc'].forEach(function (id) { var el = $(id); if (el) el.value = ''; });
            if ($('#roleModel')) $('#roleModel').value = '';
            if ($('#roleReadonly')) $('#roleReadonly').checked = false;
            var box = $('#roleAddBox'); if (box) box.open = false;
            banner('role "' + name + '" saved', 'ok');
        }).catch(function () { banner('could not save role', 'err'); });
    },
    remove: function (name) {
        var self = this;
        if (!name || !window.crewRoleRemove) return;
        Promise.resolve(window.crewRoleRemove(name)).then(function (d) { self.render(d); banner('role "' + name + '" removed', 'ok'); }).catch(function () { banner('could not remove role', 'err'); });
    }
};

// ---------- Skills: reusable instructions the agent/crew load on demand ----------
// Backed by the CLI (window.skills*) → ~/.ollamadev/skills, one catalog for all surfaces.
var SkillMgr = {
    skills: [],
    bind: function () {
        var self = this;
        var open = $('#manageSkills'); if (open) open.onclick = function (e) { e.preventDefault(); self.open(); };
        var close = $('#skillsClose'); if (close) close.onclick = function () { self.close(); };
        var ov = $('#skillsOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.close(); };
        var save = $('#skillSave'); if (save) save.onclick = function () { self.save(); };
        var nw = $('#skillNew'); if (nw) nw.onclick = function () { self.newSkill(); };
        var addt = $('#skillAddTemplates'); if (addt) addt.onclick = function () { self.addTemplateSkills(); };
    },
    editing: null,
    open: function () { if (!$('#skillsModal')) return; App.openDialog('skills'); this.clearForm(); this.load(); },
    // Open the form cleared, in CREATE mode (name editable, "Create skill").
    newSkill: function () { this.clearForm(); this.setMode('new'); var box = $('#skillAddBox'); if (box) box.open = true; var n = $('#skillName'); if (n) n.focus(); },
    // Reflect create vs edit-mine vs customize-built-in in the status line, the Save
    // button, and whether the name is locked (renaming = a NEW skill, so we lock it
    // when editing — to rename, delete and recreate). For a built-in, the name MUST
    // stay the same so the saved copy overrides it.
    setMode: function (mode, name, builtin) {
        this.editing = mode === 'new' ? null : name;
        var nm = $('#skillName'); if (nm) nm.readOnly = (mode !== 'new');
        var st = $('#skillFormStatus'), btn = $('#skillSave');
        if (mode === 'new') { if (st) st.textContent = 'Creating a new skill.'; if (btn) btn.textContent = 'Create skill'; }
        else if (builtin) { if (st) st.textContent = 'Customizing built-in “' + name + '” — Save creates your editable override.'; if (btn) btn.textContent = '💾 Save my copy'; }
        else { if (st) st.textContent = 'Editing “' + name + '” (delete & recreate to rename).'; if (btn) btn.textContent = '💾 Update skill'; }
    },
    close: function () { App.closePaneView('skills'); },
    load: function () {
        var self = this;
        if (!window.skillsList) { this.render({ skills: [] }); return Promise.resolve(); }
        return Promise.resolve(window.skillsList()).then(function (d) { self.render(d); }).catch(function () { self.render({ skills: [] }); });
    },
    render: function (d) {
        var box = $('#skillsList'); if (!box) return;
        var self = this;
        var skills = (d && Array.isArray(d.skills)) ? d.skills : [];
        this.skills = skills;
        if (!skills.length) { box.innerHTML = '<div class="board-empty">No skills yet — create one below.</div>'; return; }
        var mine = skills.filter(function (s) { return !s.builtin; });
        var built = skills.filter(function (s) { return s.builtin; });
        var row = function (s) {
            // Built-ins are read-only starters: view/customize (saving makes a disk
            // copy that overrides), and no delete. Your own skills edit/remove freely.
            var tag = s.builtin ? '<span class="skill-tag" title="Built-in team-skill — crews load it by focus or template">built-in</span>' : '';
            var editTitle = s.builtin ? 'View / customize a copy' : 'Edit this skill';
            var del = s.builtin ? '' : '<button class="role-del" data-skill="' + esc(s.name) + '" title="Remove this skill">✕</button>';
            return '<div class="role-row"><div class="role-main"><span class="role-name">' + esc(s.name) + '</span>' + tag +
                '<div class="role-desc dim">' + esc(s.description || 'no description') + '</div></div>' +
                '<button class="role-edit" data-skill="' + esc(s.name) + '" title="' + editTitle + '">' + (s.builtin ? '⎘' : '✎') + '</button>' + del + '</div>';
        };
        var html = mine.map(row).join('');
        if (built.length) html += '<div class="skill-sec dim">Built-in team-skills (' + built.length + ') — crews load these by focus or template; customize one to override it.</div>' + built.map(row).join('');
        box.innerHTML = html;
        box.querySelectorAll('.role-edit').forEach(function (b) { b.onclick = function () { self.edit(b.dataset.skill); }; });
        box.querySelectorAll('.role-del').forEach(function (b) { b.onclick = function () { self.remove(b.dataset.skill); }; });
    },
    edit: function (name) {
        if (!window.skillsGet) return;
        var self = this;
        Promise.resolve(window.skillsGet(name)).then(function (s) {
            if (!s || s.error) { banner('could not load skill', 'err'); return; }
            if ($('#skillName')) $('#skillName').value = s.name || name;
            if ($('#skillDesc')) $('#skillDesc').value = s.description || '';
            if ($('#skillBody')) $('#skillBody').value = s.body || '';
            self.setMode('edit', s.name || name, !!s.builtin);
            var box = $('#skillAddBox'); if (box) box.open = true;
            var n = $('#skillBody'); if (n) n.focus();   // name is locked in edit mode
            if (s.builtin) banner('built-in "' + (s.name || name) + '" loaded — Save creates your own editable copy that overrides it', 'ok');
        }).catch(function () { banner('could not load skill', 'err'); });
    },
    save: function () {
        var self = this;
        var name = (($('#skillName') || {}).value || '').trim();
        var desc = (($('#skillDesc') || {}).value || '').trim();
        var body = (($('#skillBody') || {}).value || '').trim();
        if (!name) { banner('give the skill a name', 'err'); return; }
        if (!body) { banner('a skill needs instructions', 'err'); return; }
        if (!window.skillsSave) { banner('skills unavailable here', 'err'); return; }
        Promise.resolve(window.skillsSave(name, desc, body)).then(function (d) {
            if (d && d.error) { banner(d.error, 'err'); return; }
            self.render(d); self.clearForm();
            var box = $('#skillAddBox'); if (box) box.open = false;
            banner('skill "' + name + '" saved', 'ok');
        }).catch(function () { banner('could not save skill', 'err'); });
    },
    remove: function (name) {
        var self = this;
        if (!name || !window.skillsRemove) return;
        Promise.resolve(window.skillsRemove(name)).then(function (d) { self.render(d); banner('skill "' + name + '" removed', 'ok'); }).catch(function () { banner('could not remove skill', 'err'); });
    },
    // Materialize every skill the crew TEMPLATES inject (testing-discipline,
    // refactor-safety, docs-writing, security-hardening, …) as your own editable
    // disk copies — so they move out of the read-only "built-in" section into your
    // skills, fully CRUD-able. Idempotent (skips ones you already have) and
    // reversible (delete a copy to revert to the engine built-in).
    addTemplateSkills: function () {
        var self = this;
        var want = {};
        (App.CREW_TEMPLATES || []).forEach(function (t) { (t.skills || []).forEach(function (s) { want[s] = true; }); });
        var list = Object.keys(want);
        if (!list.length) { banner('no crew-template skills found', 'err'); return; }
        var mine = {}; (this.skills || []).forEach(function (s) { if (!s.builtin) mine[s.name] = true; });
        var todo = list.filter(function (n) { return !mine[n]; });
        if (!todo.length) { banner('all crew-template skills are already in your skills', 'ok'); return; }
        if (!window.skillsGet || !window.skillsSave) { banner('skills unavailable here', 'err'); return; }
        var added = 0, pending = todo.length;
        todo.forEach(function (n) {
            Promise.resolve(window.skillsGet(n)).then(function (s) {
                if (s && !s.error && s.body) { added++; return window.skillsSave(n, s.description || '', s.body); }
            }).catch(function () {}).then(function () {
                if (--pending === 0) { self.load(); banner('added ' + added + ' crew-template skill(s) to your skills — now editable', 'ok'); }
            });
        });
    },
    clearForm: function () {
        ['#skillName', '#skillDesc', '#skillBody'].forEach(function (id) { var el = $(id); if (el) el.value = ''; });
        this.editing = null;
        var nm = $('#skillName'); if (nm) nm.readOnly = false;
        var st = $('#skillFormStatus'); if (st) st.textContent = '';
        var btn = $('#skillSave'); if (btn) btn.textContent = 'Save skill';
    }
};

// ---------- Hooks: shell commands fired at lifecycle points (PreToolUse can block) ----------
// Backed by the CLI (window.hooks*) → ~/.ollamadev/config.json, shared by all surfaces.
var HookMgr = {
    bind: function () {
        var self = this;
        var open = $('#manageHooks'); if (open) open.onclick = function (e) { e.preventDefault(); self.open(); };
        var close = $('#hooksClose'); if (close) close.onclick = function () { self.close(); };
        var ov = $('#hooksOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.close(); };
        var add = $('#hookSave'); if (add) add.onclick = function () { self.add(); };
    },
    open: function () { if (!$('#hooksModal')) return; App.openDialog('hooks'); this.load(); },
    close: function () { App.closePaneView('hooks'); },
    load: function () {
        var self = this;
        if (!window.hooksList) { this.render({ hooks: [], events: [] }); return Promise.resolve(); }
        return Promise.resolve(window.hooksList()).then(function (d) { self.render(d); }).catch(function () { self.render({ hooks: [], events: [] }); });
    },
    render: function (d) {
        var box = $('#hooksList'); if (!box) return;
        var self = this;
        var hooks = (d && Array.isArray(d.hooks)) ? d.hooks : [];
        var events = (d && Array.isArray(d.events)) ? d.events : [];
        // Populate the event dropdown (once we know the canonical list).
        var sel = $('#hookEvent');
        if (sel && events.length && sel.options.length !== events.length) {
            sel.innerHTML = events.map(function (e) { return '<option>' + esc(e) + '</option>'; }).join('');
        }
        if (!hooks.length) { box.innerHTML = '<div class="board-empty">No hooks yet — add one below.</div>'; return; }
        box.innerHTML = hooks.map(function (h) {
            var mx = h.matcher ? '<span class="skill-tag" title="regex on the tool name">match: ' + esc(h.matcher) + '</span>' : '';
            return '<div class="role-row"><div class="role-main"><span class="role-name">' + esc(h.event) + '</span>' + mx +
                '<div class="role-desc dim mono">' + esc(h.command) + '</div></div>' +
                '<button class="role-del" data-event="' + esc(h.event) + '" data-index="' + h.index + '" title="Remove this hook">✕</button></div>';
        }).join('');
        box.querySelectorAll('.role-del').forEach(function (b) {
            b.onclick = function () { self.remove(b.dataset.event, b.dataset.index); };
        });
    },
    add: function () {
        var self = this;
        var ev = (($('#hookEvent') || {}).value || '').trim();
        var cmd = (($('#hookCommand') || {}).value || '').trim();
        var matcher = (($('#hookMatcher') || {}).value || '').trim();
        if (!ev) { banner('pick an event', 'err'); return; }
        if (!cmd) { banner('a hook needs a command', 'err'); return; }
        if (!window.hooksAdd) { banner('hooks unavailable here', 'err'); return; }
        Promise.resolve(window.hooksAdd(ev, cmd, matcher)).then(function (d) {
            if (d && d.error) { banner(d.error, 'err'); return; }
            self.render(d);
            $('#hookCommand').value = ''; $('#hookMatcher').value = '';
            var box = $('#hookAddBox'); if (box) box.open = false;
            banner(ev + ' hook added', 'ok');
        }).catch(function () { banner('could not add hook', 'err'); });
    },
    remove: function (event, index) {
        var self = this;
        if (!event || !window.hooksRemove) return;
        Promise.resolve(window.hooksRemove(event, index)).then(function (d) { self.render(d); banner('hook removed', 'ok'); }).catch(function () { banner('could not remove hook', 'err'); });
    }
};

// ---------- Network toggles, shared with the CLI via config ----------
// 🌐 Web = web access (config web.enabled): all the agent's network tools
// (search/fetch/remote git). 🔍 Search = a finer switch for web search only
// (fetch/git unaffected). Both persist through the CLI, so terminal/desktop/web
// agree. Applies to new agent runs.
var Net = {
    on: true, search: true,
    bind: function () {
        var self = this;
        var w = $('#webToggle'); if (w) w.onclick = function () { self.toggleWeb(); };
        var s = $('#searchToggle'); if (s) s.onclick = function () { self.toggleSearch(); };
    },
    load: function () {
        var self = this, jobs = [];
        if (window.webAccess) jobs.push(Promise.resolve(window.webAccess()).then(function (on) { self.on = (on !== false); }).catch(function () {}));
        if (window.searchEnabled) jobs.push(Promise.resolve(window.searchEnabled()).then(function (on) { self.search = (on !== false); }).catch(function () {}));
        return Promise.all(jobs).then(function () { self.render(); });
    },
    toggleWeb: function () {
        var self = this;
        if (!window.setWebAccess) { banner('web toggle unavailable here', 'err'); return; }
        Promise.resolve(window.setWebAccess(!this.on)).then(function (on) {
            self.on = (on !== false); self.render();
            banner(self.on ? 'web access on' : 'web access off — agent network tools blocked (applies to new runs)', 'ok');
        }).catch(function () { banner('could not change web access', 'err'); });
    },
    toggleSearch: function () {
        var self = this;
        if (!window.setSearchEnabled) { banner('search toggle unavailable here', 'err'); return; }
        Promise.resolve(window.setSearchEnabled(!this.search)).then(function (on) {
            self.search = (on !== false); self.render();
            banner(self.search ? 'web search on' : 'web search off (fetch/git still on) — applies to new runs', 'ok');
        }).catch(function () { banner('could not change web search', 'err'); });
    },
    render: function () {
        var w = $('#webToggle');
        if (w) {
            w.textContent = this.on ? '🌐 Web' : '🚫 Web off';
            w.classList.toggle('off', !this.on);
            w.title = (this.on ? 'Web access ON — agent may search/fetch/use remote git.' : 'Web access OFF — agent network tools blocked.') + ' Click to toggle (applies to new runs).';
        }
        var s = $('#searchToggle');
        if (s) {
            s.textContent = this.search ? '🔍 Search' : '🔍 Search off';
            s.classList.toggle('off', !this.search);
            s.disabled = !this.on;
            s.title = !this.on ? 'Web access is off — search is blocked by the Web toggle.'
                : (this.search ? 'Web search ON. Click to disable search only (fetch/git stay on).' : 'Web search OFF (fetch/git still allowed). Click to enable.');
        }
    }
};

// ---------- Browser: a localhost preview pane (vanilla iframe) ----------
// Built for previewing the dev server you're coding against (localhost:3000,
// Vite's 5173, etc.) right next to the terminals. Keeps its OWN history stack
// of bar-entered URLs — the iframe's cross-origin history isn't reachable from
// here, so back/forward walk our stack and reset the frame src. Non-localhost
// URLs are gated by the same web-access toggle as the agent's network tools (Net.on).
var Browser = {
    stack: [], idx: -1, COMMON_PORTS: [3000, 5173, 8080, 8000, 4321, 41434],
    bind: function () {
        var self = this;
        var url = $('#brUrl');
        if (url) url.addEventListener('keydown', function (e) { if (e.key === 'Enter') self.go(url.value); });
        var go = $('#brGo'); if (go) go.onclick = function () { self.go($('#brUrl').value); };
        var rl = $('#brReload'); if (rl) rl.onclick = function () { self.reload(); };
        var bk = $('#brBack'); if (bk) bk.onclick = function () { self.back(); };
        var fw = $('#brFwd'); if (fw) fw.onclick = function () { self.fwd(); };
        var ext = $('#brExt'); if (ext) ext.onclick = function () { self.openExternal(); };
        // Quick-port chips for the usual dev servers.
        var ports = $('#brPorts');
        if (ports) ports.innerHTML = this.COMMON_PORTS.map(function (p) {
            return '<button class="br-port" type="button" data-port="' + p + '" title="localhost:' + p + '">:' + p + '</button>';
        }).join('');
        if (ports) ports.addEventListener('click', function (e) {
            var b = e.target.closest('.br-port'); if (b) self.go('localhost:' + b.dataset.port);
        });
        // Proxied pages bubble link clicks up here so navigation re-proxies
        // instead of dead-ending on the next site's X-Frame-Options.
        window.addEventListener('message', function (e) {
            var d = e.data;
            if (d && typeof d.__odvNav === 'string' && /^https?:/i.test(d.__odvNav)) self.go(d.__odvNav);
        });
    },
    // Bare "3000" or "localhost:3000" → http://localhost:3000; scheme-less host → http://host.
    normalize: function (raw) {
        var s = (raw || '').trim();
        if (!s) return '';
        if (/^\d{2,5}$/.test(s)) return 'http://localhost:' + s;          // just a port
        if (/^https?:\/\//i.test(s)) return s;                             // already a URL
        if (/^localhost(:\d+)?(\/|$)/i.test(s) || /^127\.0\.0\.1/.test(s)) return 'http://' + s;
        return 'http://' + s;                                              // default to http for local-first
    },
    isLocal: function (u) {
        try {
            var h = new URL(u).hostname;
            return h === 'localhost' || h === '127.0.0.1' || h === '0.0.0.0' || h === '::1' || /\.local$/i.test(h);
        } catch (e) { return false; }
    },
    go: function (raw) {
        var u = this.normalize(raw);
        if (!u) return;
        if (!this.isLocal(u) && window.Net && !Net.on) { banner('web access off — only localhost previews are allowed (toggle 🌐 Web to load external sites)', 'err'); return; }
        // Truncate forward history, push, and load.
        this.stack = this.stack.slice(0, this.idx + 1);
        this.stack.push(u); this.idx = this.stack.length - 1;
        this.load(u);
    },
    load: function (u) {
        var bar = $('#brUrl'); if (bar) bar.value = u;
        // Local dev servers load DIRECTLY (full fidelity — SPAs/APIs/websockets all
        // work). External sites go through the strip-proxy so X-Frame-Options can't
        // blank them; that path is best-effort.
        if (this.isLocal(u)) this.loadDirect(u);
        else this.loadProxied(u);
        this.syncNav();
    },
    loadDirect: function (u) {
        var f = $('#brFrame'), e = $('#brEmpty');
        if (e) e.hidden = true;
        if (f) { f.classList.remove('empty'); f.removeAttribute('srcdoc'); f.src = u; }
    },
    loadProxied: function (u) {
        var self = this, f = $('#brFrame'), e = $('#brEmpty');
        if (!window.proxyFetch) { this.loadDirect(u); return; }   // no bridge → best-effort direct
        if (e) { e.hidden = false; e.innerHTML = 'Loading ' + this.host(u) + '…'; }
        this._pending = u;
        Promise.resolve(window.proxyFetch(u)).then(function (r) {
            if (self._pending !== u) return;                       // superseded by a newer nav
            if (!r || !r.ok) { self.showFail(u, r && r.error); return; }
            if (e) e.hidden = true;
            if (r.direct) { if (f) { f.removeAttribute('srcdoc'); f.classList.remove('empty'); f.src = r.url || u; } }
            else if (f) { f.removeAttribute('src'); f.classList.remove('empty'); f.srcdoc = r.html || ''; }
            // Reflect post-redirect URL in the bar without pushing a new history entry.
            if (r.url && self.idx >= 0) { self.stack[self.idx] = r.url; var b = $('#brUrl'); if (b) b.value = r.url; }
        }).catch(function (err) { if (self._pending === u) self.showFail(u, String(err)); });
    },
    showFail: function (u, why) {
        var f = $('#brFrame'), e = $('#brEmpty');
        if (f) { f.classList.add('empty'); f.removeAttribute('srcdoc'); f.removeAttribute('src'); }
        if (e) {
            e.hidden = false;
            e.innerHTML = "Couldn't embed <b>" + this.host(u) + '</b>' + (why ? ' (' + esc(String(why)) + ')' : '') +
                '. <button id="brFailExt" class="br-port" type="button" style="margin-left:6px">↗ Open in real browser</button>';
            var btn = document.getElementById('brFailExt');
            var self = this;
            if (btn) btn.onclick = function () { self.openExternalUrl(u); };
        }
    },
    host: function (u) { try { return new URL(u).host; } catch (e) { return u; } },
    reload: function () { if (this.idx >= 0) this.load(this.stack[this.idx]); },
    back: function () { if (this.idx > 0) { this.idx--; this.load(this.stack[this.idx]); } },
    fwd: function () { if (this.idx < this.stack.length - 1) { this.idx++; this.load(this.stack[this.idx]); } },
    syncNav: function () {
        var bk = $('#brBack'), fw = $('#brFwd');
        if (bk) bk.disabled = this.idx <= 0;
        if (fw) fw.disabled = this.idx >= this.stack.length - 1;
    },
    openExternal: function () {
        var u = this.idx >= 0 ? this.stack[this.idx] : this.normalize($('#brUrl').value);
        this.openExternalUrl(u);
    },
    openExternalUrl: function (u) {
        if (!u) { banner('nothing to open', 'err'); return; }
        if (window.openExternal) { Promise.resolve(window.openExternal(u)).catch(function () { window.open(u, '_blank'); }); }
        else window.open(u, '_blank');
    },
    onShow: function () { var b = $('#brUrl'); if (b && this.idx < 0) b.focus(); }
};

// ---------- Semantic code search (sidebar panel, backed by the local index) ----------
var CodeSearch = {
    bind: function () {
        var self = this;
        var inp = $('#codeSearchInput');
        if (inp) inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') self.run(inp.value); });
    },
    onShow: function () {
        var inp = $('#codeSearchInput'); if (inp) inp.focus();
        var hint = $('#codeSearchHint');
        if (!window.codeIndexStatus || !hint) return;
        Promise.resolve(window.codeIndexStatus()).then(function (st) {
            if (!st || !st.exists) {
                hint.innerHTML = 'No index for this project yet. <button id="csBuild" class="linklike">Build it</button> — embeds the repo locally.';
                var b = $('#csBuild'); if (b) b.onclick = function () { CodeSearch.build(); };
            } else {
                hint.textContent = st.chunks + ' chunks · ' + st.files + ' files · ' + (st.model || '');
            }
        }).catch(function () {});
    },
    build: function () {
        var hint = $('#codeSearchHint'); if (hint) hint.textContent = 'Building index… this can take a moment.';
        if (!window.codeIndexBuild) return;
        Promise.resolve(window.codeIndexBuild()).then(function (st) {
            if (!hint) return;
            hint.textContent = (st && st.exists)
                ? (st.chunks + ' chunks · ' + st.files + ' files · ' + (st.model || ''))
                : 'Build failed — is the embedding model installed? (ollama pull nomic-embed-text)';
        }).catch(function () { if (hint) hint.textContent = 'Index build failed.'; });
    },
    run: function (q) {
        q = (q || '').trim(); if (!q) return;
        var box = $('#codeSearchResults'); if (!box) return;
        if (!window.codeSearch) { box.innerHTML = '<div class="board-empty">search unavailable here</div>'; return; }
        box.innerHTML = '<div class="dim">searching…</div>';
        Promise.resolve(window.codeSearch(q, 12)).then(function (r) {
            if (!r || r.error) {
                box.innerHTML = '<div class="board-empty">' + (r && r.error === 'no_index' ? 'No index yet — build it above.' : 'no results') + '</div>';
                return;
            }
            var res = r.results || [];
            if (!res.length) { box.innerHTML = '<div class="board-empty">no matches</div>'; return; }
            box.innerHTML = res.map(function (m) {
                return '<div class="cs-hit" data-file="' + esc(m.file) + '">' +
                    '<div class="cs-file">' + esc(m.file) + '<span class="cs-loc">:' + m.start + '-' + m.end + '</span><span class="cs-score">' + m.score + '</span></div>' +
                    '<div class="cs-snip dim">' + esc(String(m.snippet || '').replace(/\s+/g, ' ').slice(0, 160)) + '</div></div>';
            }).join('');
            box.querySelectorAll('.cs-hit').forEach(function (el) {
                el.onclick = function () {
                    var f = el.dataset.file; if (!f) return;
                    var abs = (App.cwd && App.cwd !== '.') ? App.cwd.replace(/\/$/, '') + '/' + f : f;
                    App.setLayout('split'); Editor.open(abs, f.split('/').pop());
                };
            });
        }).catch(function () { box.innerHTML = '<div class="board-empty">search failed</div>'; });
    }
};

// Diff — read-only review of the agent's working-tree changes. Shows a
// colorized git diff; it never applies or commits (git stays agent-driven).
var Diff = {
    bind: function () {
        var self = this;
        var b = $('#diffBtn'); if (b) b.onclick = function () { self.open(); };
        var c = $('#diffClose'); if (c) c.onclick = function () { self.close(); };
        var r = $('#diffRefresh'); if (r) r.onclick = function () { self.load(); };
        var ov = $('#diffOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.close(); };
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { var o = $('#diffOverlay'); if (o && !o.hidden) self.close(); }
        });
    },
    open: function () { if (!$('#diffModal')) return; App.openDialog('diff'); this.load(); },
    close: function () { App.closePaneView('diff'); },
    load: function () {
        var body = $('#diffBody'), files = $('#diffFiles');
        if (!body) return;
        if (!window.reviewDiff) { body.innerHTML = '<div class="diff-empty dim">diff unavailable here</div>'; if (files) files.innerHTML = ''; return; }
        body.innerHTML = '<div class="diff-empty dim">Loading…</div>'; if (files) files.innerHTML = '';
        Promise.resolve(window.reviewDiff()).then(function (r) {
            if (!r || !r.repo) { body.innerHTML = '<div class="diff-empty dim">Not a git repository.</div>'; return; }
            var text = (r.diff || '').trim();
            if (!text) { body.innerHTML = '<div class="diff-empty dim">No working-tree changes. Clean tree ✓</div>'; return; }
            Diff.render(text);
        }).catch(function () { body.innerHTML = '<div class="diff-empty dim">Could not load the diff.</div>'; });
    },
    // Parse a unified diff into per-file blocks and colorize each line. Vanilla.
    render: function (text) {
        var body = $('#diffBody'), filesEl = $('#diffFiles');
        var lines = text.split('\n');
        var blocks = [], cur = null, add = 0, del = 0;
        function push() { if (cur) { cur.add = add; cur.del = del; blocks.push(cur); } }
        for (var i = 0; i < lines.length; i++) {
            var ln = lines[i];
            if (ln.indexOf('diff --git ') === 0) {
                push(); add = 0; del = 0;
                var m = ln.match(/ b\/(.+)$/) || ln.match(/ a\/(.+?) /);
                cur = { name: m ? m[1] : ln.slice(11), rows: [] };
                continue;
            }
            if (!cur) continue;
            if (ln.indexOf('+++ ') === 0 || ln.indexOf('--- ') === 0 || ln.indexOf('index ') === 0 ||
                ln.indexOf('new file') === 0 || ln.indexOf('deleted file') === 0 || ln.indexOf('similarity ') === 0 ||
                ln.indexOf('rename ') === 0 || ln.indexOf('old mode') === 0 || ln.indexOf('new mode') === 0) continue;
            var cls = 'd-ctx';
            if (ln.indexOf('@@') === 0) cls = 'd-hunk';
            else if (ln.charAt(0) === '+') { cls = 'd-add'; add++; }
            else if (ln.charAt(0) === '-') { cls = 'd-del'; del++; }
            cur.rows.push('<div class="' + cls + '">' + (esc(ln) || '&nbsp;') + '</div>');
        }
        push();
        if (!blocks.length) { body.innerHTML = '<div class="diff-empty dim">No file changes parsed.</div>'; return; }
        // File summary chips that scroll to the block.
        if (filesEl) {
            filesEl.innerHTML = blocks.map(function (b, idx) {
                return '<button class="diff-chip" data-i="' + idx + '">' + esc(b.name) +
                    '<span class="d-stat"><span class="d-plus">+' + b.add + '</span> <span class="d-minus">-' + b.del + '</span></span></button>';
            }).join('');
        }
        body.innerHTML = blocks.map(function (b, idx) {
            return '<div class="diff-file" id="dfile-' + idx + '"><div class="diff-fname">' + esc(b.name) + '</div>' +
                '<div class="diff-code">' + b.rows.join('') + '</div></div>';
        }).join('');
        if (filesEl) filesEl.querySelectorAll('.diff-chip').forEach(function (c) {
            c.onclick = function () { var t = $('#dfile-' + c.dataset.i); if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
        });
    }
};

// Temperature dropdown — reads/writes ollama.temperature via the CLI config.
// Lower = more deterministic tool-calling; higher = more creative. Applies to new runs.
var Temp = {
    bind: function () {
        var sel = $('#tempSelect'); if (!sel) return;
        if (window.temperature) {
            Promise.resolve(window.temperature()).then(function (v) {
                v = String(v == null ? '0.3' : v);
                // Select the closest preset option to the stored value.
                var match = Array.prototype.slice.call(sel.options).filter(function (o) { return o.value === v; })[0];
                if (match) sel.value = v;
                else if (sel.querySelector('option[value="0.3"]')) sel.value = '0.3';
            }).catch(function () {});
        }
        sel.addEventListener('change', function () {
            if (window.setTemperature) Promise.resolve(window.setTemperature(sel.value)).catch(function () {});
            if (window.banner) banner('temperature → ' + sel.value, 'ok');
        });
    }
};

// Tool-approval picker — a DESKTOP-ONLY preference (stored in localStorage), passed
// to each terminal via the OLLAMADEV_PERMISSION env in launchCli. Deliberately NOT
// backed by the CLI config, so changing it here never alters the standalone CLI's
// own default. Default 'ask' (matches the CLI). Applies to terminals opened after.
var Perm = {
    bind: function () {
        var sel = $('#permSelect'); if (!sel) return;
        var saved = 'ask';
        try { saved = localStorage.getItem('ade.permMode') || 'ask'; } catch (e) {}
        if (saved !== 'auto' && saved !== 'ask' && saved !== 'readonly') saved = 'ask';
        App.permMode = saved;
        if (sel.querySelector('option[value="' + saved + '"]')) sel.value = saved;
        sel.addEventListener('change', function () {
            var m = sel.value;
            App.permMode = m;
            try { localStorage.setItem('ade.permMode', m); } catch (e) {}
            var note = m === 'auto' ? 'runs tools without asking'
                : (m === 'ask' ? 'confirms each change (type y in the terminal)' : 'blocks all changes');
            if (window.banner) banner('tool approval → ' + m + ' · ' + note + ' (applies to new terminals)', 'ok');
        });
    }
};

// Voice (STT) settings — model picker + transcription history. Reads/writes the
// shared stt.model via the CLI (window.sttModel/setSttModel), so CLI, desktop and
// web stay in lockstep. Both controls reveal only when a local STT engine exists.
var Stt = {
    bind: function () {
        var sel = $('#sttSelect'), histBtn = $('#sttHistoryBtn');
        if (!window.sttEnabled) return;
        Promise.resolve(window.sttEnabled()).then(function (on) {
            if (!on) return;
            var fld = $('#sttField'); if (fld) fld.hidden = false;   // reveal the drawer's Voice-model field
            if (sel) {
                sel.hidden = false;
                if (window.sttModel) Promise.resolve(window.sttModel()).then(function (m) {
                    m = String(m || 'base');
                    if (Array.prototype.slice.call(sel.options).some(function (o) { return o.value === m; })) sel.value = m;
                }).catch(function () {});
                sel.addEventListener('change', function () {
                    if (window.setSttModel) Promise.resolve(window.setSttModel(sel.value)).catch(function () {});
                    banner('voice model → ' + sel.value, 'ok');
                });
            }
            if (histBtn) { histBtn.hidden = false; histBtn.onclick = function () { Stt.openHistory(); }; }
        }).catch(function () {});
        var cl = $('#voiceHistClose'), cx = $('#voiceHistClear'), ov = $('#voiceOverlay');
        if (cl) cl.onclick = function () { Stt.closeHistory(); };
        if (ov) ov.onclick = function (e) { if (e.target === ov) Stt.closeHistory(); };
        if (cx) cx.onclick = function () {
            if (window.sttClearHistory) Promise.resolve(window.sttClearHistory()).then(function () { Stt.renderHistory([]); banner('voice history cleared', 'ok'); }).catch(function () {});
        };
    },
    openHistory: function () {
        var ov = $('#voiceOverlay'); if (!ov) return;
        ov.hidden = false;
        var list = $('#voiceHistoryList'); if (list) list.textContent = 'loading…';
        if (window.sttHistory) Promise.resolve(window.sttHistory(50)).then(function (rows) {
            Stt.renderHistory(Array.isArray(rows) ? rows : []);
        }).catch(function () { Stt.renderHistory([]); });
    },
    closeHistory: function () { var ov = $('#voiceOverlay'); if (ov) ov.hidden = true; },
    renderHistory: function (rows) {
        var list = $('#voiceHistoryList'); if (!list) return;
        if (!rows.length) { list.innerHTML = '<div class="dim" style="padding:12px 0;">No voice history yet — click the 🎤 mic and speak.</div>'; return; }
        list.innerHTML = '';
        rows.slice().reverse().forEach(function (r) {
            var row = document.createElement('div'); row.className = 'vh-row';
            var when = r.ts ? new Date(r.ts * 1000).toLocaleString() : '';
            var meta = document.createElement('div'); meta.className = 'vh-meta dim';
            meta.textContent = when + (r.model ? '  ·  ' + r.model : '');
            var txt = document.createElement('div'); txt.className = 'vh-text';
            txt.textContent = r.text || '';
            row.appendChild(meta); row.appendChild(txt); list.appendChild(row);
        });
    }
};


var App = {
    terminals: [], cwd: '.', layout: 'split', termLayout: 'free', zoomed: null, view: 'code', panel: 'files', crewBoard: null, crewPoll: null,
    // The last REAL model you picked in the Model dropdown (remembered across restarts).
    // So a "shell" selection still launches crew with YOUR model, and the 💬 Chat window
    // defaults to it — every installed model is usable for chat.
    lastModel: '',
    // The workspace is one infinite, pannable canvas. `panX/panY` is its scroll offset;
    // panes are positioned at (world x + panX, world y + panY). Singleton "view" panes
    // (editor/board/graph/browser) live in `popped` (view -> geometry); their real element
    // is moved onto the canvas so all state is preserved.
    panX: 0, panY: 0, zoom: 1,
    popped: {},
    // Persistent content windows (saved per-project, in the rail + Add menu).
    POP_VIEWS: ['files', 'search', 'tasks', 'voice', 'editor', 'board', 'graph', 'browser', 'topology'],
    // Tool dialogs — now canvas windows too, but transient (not persisted across restart).
    DIALOG_VIEWS: ['crew', 'roles', 'skills', 'hooks', 'diff'],
    POP_SEL: {
        files: '#filesPanel', search: '#searchPanel', tasks: '#tasksPanel', editor: '#editorPane',
        board: '#boardView', graph: '#graphView', browser: '#browserView', voice: '#voicePanel', topology: '#topologyView',
        crew: '#crewModal', roles: '#rolesModal', skills: '#skillsModal', hooks: '#hooksModal', diff: '#diffModal'
    },
    POP_TITLE: {
        files: '▤ Files', search: '🔎 Code search', tasks: '▦ Tasks', editor: '📝 Editor',
        board: '📋 Board', graph: '🕸 Graph', browser: '🌐 Browser', voice: '🎙 Voice', topology: '🛰 Crew topology',
        crew: '👥 Crew', roles: '🎭 Roles', skills: '🧩 Skills', hooks: '🪝 Hooks', diff: '⇄ Review'
    },
    // Sensible default size/spot (canvas-world coords) when a window is first opened.
    POP_DEFAULT: {
        files: { x: 16, y: 16, w: 290, h: 460 }, search: { x: 16, y: 16, w: 320, h: 460 },
        tasks: { x: 16, y: 16, w: 300, h: 340 }, editor: { x: 326, y: 16, w: 640, h: 460 },
        board: { x: 16, y: 16, w: 580, h: 430 }, graph: { x: 16, y: 16, w: 640, h: 470 },
        browser: { x: 16, y: 16, w: 720, h: 500 }, voice: { x: 16, y: 16, w: 320, h: 420 },
        topology: { x: 16, y: 16, w: 620, h: 480 },
        crew: { x: 80, y: 24, w: 700, h: 580 }, roles: { x: 100, y: 36, w: 620, h: 520 },
        skills: { x: 100, y: 36, w: 640, h: 540 }, hooks: { x: 100, y: 36, w: 640, h: 540 },
        diff: { x: 48, y: 20, w: 860, h: 640 }
    },
    // PTYs spawned this session, by id. Survives detach (workspace switch) so we
    // can RE-attach a still-running terminal; gone after a restart → respawn fresh.
    live: {},
    init: function () {
        var self = this;
        this.initThemes();
        this.startAutosave();   // persist the active workspace so it resumes on reopen
        $('#newTermBtn').onclick = function () { self.newTerminal(); };
        var ta = $('#termArrange'); if (ta) ta.onclick = function () { self.setTermLayout(self.termLayout === 'free' ? 'tiled' : 'free'); };
        // Layout mode is a global preference — reopen in whichever mode you last used.
        // Free is the default; only an explicit 'tiled' choice opts back into the grid.
        try { self.termLayout = localStorage.getItem('ade.termLayout') === 'tiled' ? 'tiled' : 'free'; } catch (e) {}
        if (ta) ta.textContent = self.termLayout === 'free' ? '⮻ Free' : '⊞ Tiled';
        // Opt-in GPU/canvas terminal renderer (off by default; DOM renderer is the safe default).
        try { self.canvasTerm = localStorage.getItem('ade.canvasTerm') === 'on'; } catch (e) { self.canvasTerm = false; }
        var tc = $('#termCanvas');
        if (tc) { tc.textContent = self.canvasTerm ? '🖼 Canvas' : '🖼 DOM'; tc.onclick = function () { self.setCanvasTerm(!self.canvasTerm); }; }
        // Restore which view panes were on the canvas (+ their geometry) and the pan offset.
        try {
            var pop = JSON.parse(localStorage.getItem('ade.popped') || '{}') || {};
            self.POP_VIEWS.forEach(function (view) {
                var g = pop[view];
                if (g && typeof g.x === 'number') self.popped[view] = { id: '__pop_' + view + '__', view: view, x: g.x, y: g.y, w: g.w, h: g.h, z: 0 };
            });
        } catch (e) {}
        try { var pan = JSON.parse(localStorage.getItem('ade.pan') || 'null'); if (pan) { self.panX = pan.x || 0; self.panY = pan.y || 0; if (pan.zoom) self.zoom = pan.zoom; } } catch (e) {}
        // Add-pane menu: the ＋ Add toolbar button and right-click on empty canvas.
        var addBtn = $('#addPaneBtn'); if (addBtn) addBtn.onclick = function (e) { self.showCanvasMenu(e.clientX, e.clientY); };
        var ctrBtn = $('#canvasResetBtn'); if (ctrBtn) ctrBtn.onclick = function () { self.centerCanvas(); };
        this.bindCanvas();
        // Open-folder modal
        var ofb = $('#openFolderBtn'); if (ofb) ofb.onclick = function () { self.openFolderModal(false); };
        var fok = $('#folderOpen'); if (fok) fok.onclick = function () { self.submitFolder(); };
        var fcx = $('#folderCancel'); if (fcx) fcx.onclick = function () { self.closeFolder(); };
        var fov = $('#folderOverlay'); if (fov) fov.onclick = function (e) { if (e.target === fov) self.closeFolder(); };
        var fpi = $('#folderPath'); if (fpi) fpi.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') self.submitFolder();
            else if (e.key === 'Escape') self.closeFolder();
        });
        this.initResponsive();
        // Voice dictation for the Crew task box (shown only if a local STT engine is configured).
        Voice.init('crewMic', 'crewTask');
        // Crew — single screen: type the task, Run. (Advanced section is optional.)
        var fb = $('#crewBtn'); if (fb) fb.onclick = function () { self.openCrew(); };
        var fc = $('#crewCancel'); if (fc) fc.onclick = function () { self.closeCrew(); };
        var fr = $('#crewRun'); if (fr) fr.onclick = function () { self.submitCrew(); };
        var ov = $('#modalOverlay'); if (ov) ov.onclick = function (e) { if (e.target === ov) self.closeCrew(); };
        var ft = $('#crewTask'); if (ft) ft.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') self.closeCrew();
            else if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') self.submitCrew();
        });
        var cps = $('#crewPreset'); if (cps) cps.onchange = function () { if (cps.value) self.applyPreset(cps.value); };
        var cpsv = $('#crewPresetSave'); if (cpsv) cpsv.onclick = function () { self.savePreset(); };
        var cpdl = $('#crewPresetDel'); if (cpdl) cpdl.onclick = function () { self.delPreset(); };
        var cmd = $('#crewModelsDefault'); if (cmd) cmd.onclick = function () { self.saveCrewModels(); };
        // Remember the last real model picked, so "💬 chat" / "🐚 shell" selections still
        // launch with YOUR model (persisted across restarts). Restored from localStorage
        // now; seeded from your configured default once models load (loadModels).
        try { this.lastModel = localStorage.getItem('ade.lastModel') || ''; } catch (e) {}
        var msel = $('#modelSelect');
        if (msel) msel.onchange = function () {
            var v = msel.value;
            if (v && v !== 'shell' && v !== 'chat') { self.lastModel = v; try { localStorage.setItem('ade.lastModel', v); } catch (e) {} }
        };
        // Activity rail: switch the sidebar between Files and Tasks.
        document.querySelectorAll('.rail-btn').forEach(function (b) {
            b.onclick = function () { self.setPanel(b.dataset.panel); };
        });
        Graph.bind();
        Workspaces.bind();
        Roles.bind();
        SkillMgr.bind();
        HookMgr.bind();
        // Re-fit every mounted terminal to its new pixel size (debounced), so the
        // pty's cols/rows track window resizes and TUIs reflow correctly.
        var self = this, fitTimer = null;
        window.addEventListener('resize', function () {
            clearTimeout(fitTimer);
            fitTimer = setTimeout(function () { (self.terminals || []).forEach(function (t) { if (t.screen) t.fit(); }); }, 120);
        });
        Net.bind(); Net.load();
        VoiceCtl.bind();
        Browser.bind();
        CodeSearch.bind();
        Diff.bind();
        Temp.bind();
        Perm.bind();
        Stt.bind();
        // Add a task from the sidebar.
        var ti = $('#taskInput');
        if (ti) ti.addEventListener('keydown', function (e) { if (e.key === 'Enter') { Tasks.add(ti.value); ti.value = ''; } });
        this.initSplitter();
        // Global Ctrl/Cmd+S saves the active editor tab from anywhere.
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) { e.preventDefault(); Editor.save(); }
        });
        Tasks.load(); Tasks.render();
        Promise.resolve(window.cliPath ? window.cliPath() : 'ollamadev').then(function (p) { self.cli = p || 'ollamadev'; }).catch(function () { self.cli = 'ollamadev'; });
        Promise.resolve(window.homeDir ? window.homeDir() : '').then(function (h) { window.HOME_DIR = h || ''; }).catch(function () {});
        this.loadModels().then(function () {
            banner('ready', 'ok');
            // Startup: reopen the active workspace (restoring its window). With no
            // workspaces yet, prompt for a folder — it becomes the first workspace.
            return Workspaces.load();
        }).then(function () {
            var cur = Workspaces.current();
            if (cur) { self.openFolder(cur.path, true); return; }
            var last = ''; try { last = localStorage.getItem('ade.folder') || ''; } catch (e) {}
            self.pendingFolder = last;
            self.openFolderModal(true);
        });
    },
    cdPrefix: function (cwd) {
        cwd = cwd || this.cwd;
        return (cwd && cwd !== '.') ? "cd '" + cwd.replace(/'/g, "'\\''") + "' && " : '';
    },
    // Expand a leading ~ to an absolute path so a typed folder works as both the pty
    // cwd (is_dir check) and the shell `cd` prefix (which doesn't expand quoted ~).
    expandHome: function (p) {
        p = (p || '').trim();
        if (p === '~') return window.HOME_DIR || p;
        if (p.indexOf('~/') === 0 && window.HOME_DIR) return window.HOME_DIR + p.slice(1);
        return p;
    },
    // blank=true opens it as "add another project" — empty path, so you don't
    // accidentally re-open the current folder (which would just no-op).
    openFolderModal: function (firstRun, blank) {
        var o = $('#folderOverlay'); if (!o) return;
        this._folderFirstRun = !!firstRun;
        var inp = $('#folderPath');
        if (inp) {
            var pre = blank ? '' : (this.pendingFolder || this.cwd || '');
            inp.value = (pre && pre !== '.') ? pre : '';
            this.pendingFolder = '';
        }
        var sub = o.querySelector('.modal-sub');
        if (sub) sub.textContent = blank
            ? 'Opens it as a new project tab — your current one stays open in its own tab.'
            : 'Points the file tree, terminals, and Crew at this folder.';
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
    // Open a folder as the active workspace: validate it, save the workspace we're
    // leaving, register/look-up this one, then restore its window (terminals,
    // editor tabs, layout, view). Used by 📂 Open, the ＋, AND tab switching.
    // Every workspace-bookkeeping step is best-effort: if any binding hiccups
    // (e.g. a marshalling quirk), the folder still opens and the tabs still
    // refresh — a workspace add must never silently "do nothing".
    openFolder: function (path, firstRun) {
        var self = this;
        Promise.resolve(window.setRoot ? window.setRoot(path) : { root: path }).then(function (r) {
            // Only an EXPLICIT error aborts. Some bridges (e.g. Boson FFI) resolve a
            // binding to undefined/empty even on success — in that case fall back to
            // the requested path so a project switch never silently does nothing.
            if (r && r.error) { banner('open failed: ' + r.error, 'err'); var fp = $('#folderPath'); if (fp) fp.focus(); return; }
            var root = (r && r.root) ? r.root : path;
            // Land on the folder immediately — this part can't fail on bindings.
            var finish = function (w) {
                self.cwd = root;
                try { localStorage.setItem('ade.folder', root); } catch (e) {}
                var o = $('#folderOverlay'); if (o) o.hidden = true;
                self._folderFirstRun = false;
                self.loadFiles(root);
                if (w && w.id) Workspaces.data.active = w.id;
                self.restoreState(w ? w.state : null);   // empty/absent state → spawns one fresh terminal
                Workspaces.load();                        // refresh the tab strip (re-reads the list)
                banner('opened ' + root, 'ok');
            };
            // Save the workspace we're leaving — but do NOT gate the switch on it.
            // captureState() runs synchronously inside saveCurrentState(), so the
            // snapshot is taken now (before we detach below); the persist binding
            // then finishes in the background. Gating here meant a hung save
            // binding made every switch AFTER the first silently do nothing
            // ("switching only works once"), since the first switch has no prior
            // workspace to save.
            if (Workspaces.data.active && self.cwd && self.cwd !== '.') {
                try { Workspaces.saveCurrentState(); } catch (e) {}
            }
            if (!firstRun) self.detachTerminals();   // keep ptys alive for re-attach
            Promise.resolve(window.wsAdd ? window.wsAdd(root, '') : null)
                .catch(function () { return null; })
                .then(finish).catch(function () { finish(null); });   // even if bookkeeping fails, show the folder
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
    // The rail buttons now open (or focus) that window on the canvas.
    setPanel: function (p) {
        this.panel = p;
        this.addPane(p);
        this._syncRail();
    },
    // Mark a rail button active when its window is open on the canvas.
    _syncRail: function () {
        var self = this;
        document.querySelectorAll('.rail-btn').forEach(function (b) { b.classList.toggle('active', !!self.popped[b.dataset.panel]); });
    },
    // There are no tabs anymore — the canvas is always shown. setView is kept for the
    // callers that "jump to" a view: a view name just opens (or focuses) that pane on
    // the canvas; 'code' is a no-op (the canvas, with the terminals, is always present).
    setView: function (v) {
        this.view = v || 'code';
        if (v && this.POP_SEL[v]) this.popView(v);
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
            var model = this.realModel();   // a task needs an agent, never the shell entry
            var id = rid(); var t = new Terminal(id, model);
            Promise.resolve(window.termCreate(id, model)).then(function () {
                self.terminals.push(t); self.render(); self.launchCli(id, model);
                type(t, 1800); // wait for the CLI to boot before sending the prompt
            });
        }
    },
    // Layout is now always the canvas; kept as tolerant no-ops for legacy callers.
    setLayout: function (name) { this.layout = name || 'split'; },
    initSplitter: function () { /* the editor/terminals split is gone — editor is a pane now */ },
    cycleLayout: function () { },
    // Recommended fast coder models, in preference order, for the crew run.
    CREW_PREFERRED: ['qwen2.5-coder', 'qwen3-coder', 'codestral', 'deepseek-coder', 'mistral', 'llama3.1'],
    // Setup templates (BridgeSpace-style) — prefill the task + suggested config.
    // Each template carries the built-in team-skill(s) the crew should load for that
    // kind of work — forced in via `--skill` regardless of focus, so e.g. picking
    // Tests always loads testing-discipline. (Domain teams add more by focus.)
    CREW_TEMPLATES: [
        { id: 'feature', label: '✨ Feature', task: 'Implement this feature: ', max: 3, review: true, skills: ['testing-discipline'] },
        { id: 'bugfix', label: '🐛 Bug fix', task: 'Find and fix this bug: ', max: 1, review: true, skills: ['testing-discipline'] },
        { id: 'tests', label: '🧪 Tests', task: 'Write thorough tests for: ', max: 2, review: true, skills: ['testing-discipline'] },
        { id: 'refactor', label: '♻️ Refactor', task: 'Refactor for clarity/maintainability (no behavior change): ', max: 2, review: true, skills: ['refactor-safety'] },
        { id: 'docs', label: '📝 Docs', task: 'Write/update documentation for: ', max: 1, review: false, skills: ['docs-writing'] },
        { id: 'audit', label: '🔍 Audit & fix', task: 'Review the codebase for bugs and security issues, and fix them: ', max: 2, review: true, skills: ['security-hardening'] },
        { id: 'blank', label: '➕ Blank', task: '', max: 2, review: true, skills: [] }
    ],
    openCrew: function () {
        var o = $('#crewModal'); if (!o) return;
        this.openDialog('crew');   // open as a canvas window
        // Step 1 default folder = the open project.
        var cf = $('#crewFolder'); if (cf) cf.value = (this.cwd && this.cwd !== '.') ? this.cwd : '';
        // Per-role model pickers default to your CONFIG (crew.coderModel etc.) so
        // whatever you set sticks; falls back to a recommended model if unset.
        var opts = Array.prototype.slice.call($('#modelSelect').options).map(function (o) { return o.value; }).filter(function (m) { return m !== 'shell' && m !== 'chat'; });
        var fallback = opts[0] || '';
        for (var i = 0; i < this.CREW_PREFERRED.length; i++) {
            var hit = opts.find(function (m) { return m.indexOf(this.CREW_PREFERRED[i]) !== -1; }, this);
            if (hit) { fallback = hit; break; }
        }
        var apply = function (cfg) {
            cfg = cfg || {};
            var byRole = { crewModelDirector: cfg.directorModel, crewModelResearcher: cfg.researcherModel, crewModelCoder: cfg.coderModel, crewModelAuditor: cfg.auditorModel };
            Object.keys(byRole).forEach(function (id) {
                var sel = $('#' + id); if (!sel) return;
                var want = byRole[id] || fallback;
                var list = (want && opts.indexOf(want) === -1) ? [want].concat(opts) : opts;   // ensure the configured model is selectable
                if (!list.length) return;
                sel.innerHTML = list.map(function (m) { return '<option' + (m === want ? ' selected' : '') + '>' + esc(m) + '</option>'; }).join('');
            });
        };
        Promise.resolve(window.crewModels ? window.crewModels() : {}).then(apply).catch(function () { apply({}); });
        this.crewFocus = "";
        this.crewTemplateSkills = [];
        this.crewTeamSkill = '';

        this.renderTemplates();
        this.populatePresets();
        var pl = $('#crewProjLine'); if (pl) pl.textContent = 'Runs in: ' + (this.cwd || '.') + ' · default team: Director + Researcher + 2 Coders + Auditor · review on';
        var adv = document.querySelector('.crew-adv'); if (adv) adv.open = false;
        var t = $('#crewTask'); if (t) { t.focus(); }
    },
    closeCrew: function () { this.closePaneView('crew'); },
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
                self.crewTemplateSkills = t.skills || [];   // forced into the run via --skill
                var ta = $('#crewTask'); ta.focus(); ta.selectionStart = ta.selectionEnd = ta.value.length;
            };
        });
    },
    mval: function (id) { var s = $('#' + id); return (s && s.value) || this.realModel(); },
    setMval: function (id, v) { var s = $('#' + id); if (!s || !v) return; if (![].some.call(s.options, function (o) { return o.value === v; })) s.innerHTML += '<option>' + esc(v) + '</option>'; s.value = v; },
    // Built-in specialized teams — by software domain (with a focus the agents
    // follow) and by task type. Each sets the crew composition.
    CREW_TEAMS: [
        // --- by kind of software you're building (D() = a domain team) ---
        D('🌐 Website', 'A website (static / marketing / content) — plain HTML/CSS/JS or the site generator (Next/Astro/Hugo/Eleventy/WordPress). Prioritize semantic markup, responsive design, SEO, fast load, and accessibility.', 3),
        D('🚀 Landing Page', 'A single high-converting landing page. Prioritize clear hero/CTA, responsive layout, fast load, SEO/meta, and a working contact/signup form.', 2),
        D('🖥 Web App', 'A web application (SPA or full-stack) — the project\'s framework (React/Vue/Svelte/Angular + backend). Prioritize components, state, routing, API integration, auth, and tests.', 3),
        D('☁️ SaaS Product', 'A SaaS product — multi-tenant app with auth, billing/subscriptions, dashboards. Prioritize tenant isolation, security, and reliable billing logic.', 3),
        D('🛒 E-commerce', 'An e-commerce site/app — catalog, cart, checkout, payments, orders. Prioritize money/tax math correctness, payment security, and inventory integrity.', 3),
        D('📊 Admin Dashboard', 'An admin dashboard / internal tool — tables, forms, charts, CRUD, role-based access. Prioritize data accuracy, pagination, and clear UX.', 2),
        D('📰 Blog / CMS', 'A blog or CMS — content models, editor, rendering, SEO, RSS. Prioritize content structure and safe rendering of user content.', 2),
        D('📚 Docs Site', 'A documentation site (Docusaurus/MkDocs/Astro). Prioritize navigation, search, code samples, and clear structure.', 2),
        D('💬 Forum / Community', 'A forum/community app — threads, posts, users, moderation. Prioritize data integrity, spam/abuse handling, and performance at scale.', 2),
        D('📱 PWA', 'A Progressive Web App — installable, offline-capable. Mind the service worker, manifest, caching strategy, and responsive UI.', 2),
        D('📱 Mobile App', 'A mobile app — the project\'s platform (iOS/Android/React Native/Flutter). Mind lifecycle, state, navigation, and platform UX guidelines.', 2),
        D('🖥 Desktop App', 'A desktop app — the project\'s toolkit (Electron/Tauri/Qt/GTK). Mind windowing, packaging, and OS integration.', 2),
        D('🔌 REST API / Backend', 'A REST API / backend service — the project\'s language & framework. Prioritize routing, validation, error handling, auth, and tests.', 3),
        D('🔗 GraphQL API', 'A GraphQL API — schema, resolvers, N+1 avoidance, auth, and pagination. Keep the schema clean and typed.', 2),
        D('⚡ Realtime / WebSocket', 'A realtime service (WebSocket/SSE) — connection lifecycle, rooms/channels, backpressure, and reconnection.', 2),
        D('λ Serverless / Functions', 'Serverless functions (Lambda/Cloud Functions/Workers). Mind cold starts, statelessness, env config, and least-privilege IAM.', 2),
        D('🧩 Microservice', 'A microservice — single responsibility, clear API contract, health checks, observability, and resilient inter-service calls.', 2),
        D('🗄 Database / Schema', 'Database work — schema, migrations, queries. Mind indexing, integrity constraints, and safe/reversible migrations.', 2),
        D('🔢 Data Pipeline / ETL', 'A data pipeline / ETL — extract, transform, load. Prioritize idempotency, schema validation, and recovery from partial failures.', 2),
        D('📊 Data / ML', 'A data/ML project (Python: pandas/numpy/scikit/torch). Prioritize reproducibility, data validation, and clear runnable scripts/notebooks.', 2),
        D('🧠 AI / LLM App', 'An AI/LLM app — prompts, agent loops, API/SDK usage, streaming, token limits, and caching.', 2),
        D('🎮 Game', 'A game — the project\'s engine (Unity/Godot/Phaser/etc.). Mind the game loop, performance, input, and assets.', 2),
        D('🧰 CLI Tool', 'A command-line tool — clear args/flags, helpful output, correct exit codes, and tests.', 2),
        D('📦 Library / SDK', 'A reusable library/package/SDK — clean public API, docs, semantic versioning, no leaked internals, and tests.', 2),
        D('🧩 Browser Extension', 'A browser extension (Chrome/Firefox, Manifest V3). Mind the manifest, content/background scripts, least-privilege permissions, and messaging.', 2),
        D('🧷 VS Code Extension', 'A VS Code extension — contribution points, activation events, commands, and the extension API. Keep activation lean.', 2),
        D('🔌 Plugin', 'A plugin (WordPress/Figma/Obsidian/etc.) — follow the host\'s plugin API, hooks/lifecycle, and packaging.', 2),
        D('🤖 Bot', 'A chat bot (Discord/Slack/Telegram). Mind the platform SDK, event/command handlers, rate limits, and token security.', 2),
        D('🤖 Automation / Script', 'An automation script — robust I/O, error handling, logging, idempotency, and safe handling of credentials.', 1),
        D('⚙️ DevOps / Infra', 'DevOps/infra (IaC, Docker, CI/CD). Prioritize idempotency, safety, least privilege, and clear config. Never hard-code secrets.', 2),
        D('🔄 CI/CD Pipeline', 'A CI/CD pipeline — build, test, deploy stages. Prioritize caching, fail-fast, secrets handling, and reproducibility.', 2),
        D('🔧 Embedded / IoT', 'Embedded/IoT/firmware (C/C++/Rust/MicroPython). Mind memory/timing constraints, interrupts, and hardware I/O.', 2),
        D('⛓ Smart Contract / Web3', 'A smart contract / web3 dApp (Solidity/etc.). Prioritize security (reentrancy, overflow), gas, and thorough tests.', 2),
        D('🔒 Security Hardening', 'Security hardening — input validation, authn/authz, secrets, injection, and dependency risks. Make minimal, safe changes.', 2),
        // --- by task type ---
        { name: '🛠 Feature Crew', group: 'task', max: 3, researcher: true, auditor: true, review: true },
        { name: '🐛 Bug Squad', group: 'task', max: 1, researcher: false, auditor: true, review: true },
        { name: '🧪 Test Crew', group: 'task', max: 2, researcher: false, auditor: true, review: true },
        { name: '♻️ Refactor Crew', group: 'task', max: 2, researcher: true, auditor: true, review: true },
        { name: '📝 Docs Crew', group: 'task', max: 1, researcher: false, auditor: false, review: false },
        { name: '🔍 Audit Crew', group: 'task', max: 2, researcher: true, auditor: true, review: true },
        { name: '⚡ Solo (fast)', group: 'task', max: 1, researcher: false, auditor: false, review: true }
    ],
    // Each by-project-type team auto-loads its matching team skill when you pick it
    // (picking the team is the opt-in — nothing changes for a plain crew run). Keyed
    // by the team name with its leading emoji stripped + lowercased, so it tolerates
    // emoji/spacing differences.
    TEAM_SKILL: {
        'website': 'website', 'landing page': 'landing-page', 'web app': 'web-app', 'saas product': 'saas',
        'e-commerce': 'ecommerce', 'admin dashboard': 'admin-dashboard', 'blog / cms': 'blog-cms',
        'docs site': 'docs-site', 'forum / community': 'forum-community', 'pwa': 'pwa-app',
        'mobile app': 'mobile', 'desktop app': 'desktop', 'rest api / backend': 'rest-api',
        'graphql api': 'graphql', 'realtime / websocket': 'realtime', 'serverless / functions': 'serverless-fn',
        'microservice': 'microservice', 'database / schema': 'database', 'data pipeline / etl': 'data-pipeline',
        'data / ml': 'data-ml-project', 'ai / llm app': 'ai-app', 'game': 'game', 'cli tool': 'cli',
        'library / sdk': 'library', 'browser extension': 'browser-ext', 'vs code extension': 'vscode-ext',
        'plugin': 'plugin', 'bot': 'chatbot', 'automation / script': 'automation', 'devops / infra': 'devops',
        'ci/cd pipeline': 'ci-cd', 'embedded / iot': 'embedded-iot', 'smart contract / web3': 'web3',
        'security hardening': 'security-project'
    },
    teamSkillFor: function (name) {
        var key = String(name || '').replace(/^[^A-Za-z]+/, '').trim().toLowerCase();
        return this.TEAM_SKILL[key] || '';
    },
    // ---- saved crew presets (team composition + per-role models, reusable) ----
    crewPresets: function () { try { var p = JSON.parse(localStorage.getItem('ade.crewPresets') || '{}'); return (p && typeof p === 'object') ? p : {}; } catch (e) { return {}; } },
    populatePresets: function () {
        var sel = $('#crewPreset'); if (!sel) return;
        var opt = function (t) { return '<option value="builtin:' + esc(t.name) + '">' + esc(t.name) + '</option>'; };
        var dom = this.CREW_TEAMS.filter(function (t) { return t.group === 'domain'; }).map(opt).join('');
        var task = this.CREW_TEAMS.filter(function (t) { return t.group !== 'domain'; }).map(opt).join('');
        var names = Object.keys(this.crewPresets());
        var saved = names.length ? '<optgroup label="Saved">' + names.map(function (n) { return '<option value="saved:' + esc(n) + '">' + esc(n) + '</option>'; }).join('') + '</optgroup>' : '';
        sel.innerHTML = '<option value="">— preset / team —</option>' +
            '<optgroup label="By project type">' + dom + '</optgroup>' +
            '<optgroup label="By task">' + task + '</optgroup>' + saved;
    },
    applyPreset: function (value) {
        var p;
        if (value.indexOf('builtin:') === 0) {
            var nm = value.slice(8);
            p = this.CREW_TEAMS.find(function (t) { return t.name === nm; });
        } else {
            p = this.crewPresets()[value.indexOf('saved:') === 0 ? value.slice(6) : value];
        }
        if (!p) return;
        var name = p.name || value.replace(/^saved:/, '');
        this.crewFocus = p.focus || ''; // domain steer the team passes to the crew
        // A by-project-type team auto-loads its matching team skill (saved presets
        // keep whatever they saved). Picking the team is the opt-in.
        this.crewTeamSkill = (p.group === 'domain') ? this.teamSkillFor(name) : (p.teamSkill || '');
        if (p.max) $('#crewMax').value = String(p.max);
        if ('review' in p && $('#crewReview')) $('#crewReview').checked = !!p.review;
        if ('researcher' in p && $('#crewResearcher')) $('#crewResearcher').checked = !!p.researcher;
        if ('auditor' in p && $('#crewAuditor')) $('#crewAuditor').checked = !!p.auditor;
        this.setMval('crewModelDirector', p.directorModel);
        this.setMval('crewModelCoder', p.coderModel);
        this.setMval('crewModelAuditor', p.auditorModel);
        this.setMval('crewModelResearcher', p.researcherModel);
        var adv = document.querySelector('.crew-adv'); if (adv) adv.open = true;
        banner('loaded preset ' + name + (this.crewTeamSkill ? ' · auto-loads the ' + this.crewTeamSkill + ' skill' : ''), 'ok');
    },
    savePreset: function () {
        var name = ($('#crewPresetName').value || '').trim() || $('#crewPreset').value;
        if (!name) { $('#crewPresetName').focus(); banner('name the preset first', 'err'); return; }
        var all = this.crewPresets();
        all[name] = {
            max: $('#crewMax').value || '2',
            review: $('#crewReview') ? $('#crewReview').checked : true,
            researcher: $('#crewResearcher') ? $('#crewResearcher').checked : true,
            auditor: $('#crewAuditor') ? $('#crewAuditor').checked : true,
            directorModel: this.mval('crewModelDirector'),
            coderModel: this.mval('crewModelCoder'),
            auditorModel: this.mval('crewModelAuditor'),
            researcherModel: this.mval('crewModelResearcher'),
            focus: this.crewFocus || ''
        };
        try { localStorage.setItem('ade.crewPresets', JSON.stringify(all)); } catch (e) {}
        this.populatePresets(); $('#crewPreset').value = 'saved:' + name; $('#crewPresetName').value = '';
        banner('saved preset ' + name, 'ok');
    },
    delPreset: function () {
        var v = $('#crewPreset').value;
        if (v.indexOf('saved:') !== 0) { banner('only your saved presets can be deleted', 'err'); return; }
        var name = v.slice(6);
        var all = this.crewPresets(); delete all[name];
        try { localStorage.setItem('ade.crewPresets', JSON.stringify(all)); } catch (e) {}
        this.populatePresets(); banner('deleted preset ' + name, 'ok');
    },
    // Persist the current per-role model picks as the global crew defaults (writes
    // crew.*Model in config.json via the bridge). The crew engine already reads
    // these, so every future run — terminal, desktop, or web — starts with them.
    saveCrewModels: function () {
        var payload = {
            directorModel: this.mval('crewModelDirector'),
            researcherModel: this.mval('crewModelResearcher'),
            coderModel: this.mval('crewModelCoder'),
            auditorModel: this.mval('crewModelAuditor')
        };
        if (!window.setCrewModels) { banner('saving defaults needs the desktop/web bridge', 'err'); return; }
        Promise.resolve(window.setCrewModels(payload)).then(function (r) {
            if (r && r.error) banner('could not save: ' + r.error, 'err');
            else banner('saved as your default crew models', 'ok');
        }).catch(function () { banner('could not save crew defaults', 'err'); });
    },
    // One screen: prompt the Director; the team handles the rest with smart
    // defaults (advanced section overrides if the user opened it).
    submitCrew: function () {
        var self = this;
        // Task is optional: leave it blank to launch the crew with the Director
        // waiting, then prompt it live in the crew terminal.
        var task = ($('#crewTask').value || '').trim();
        var go = function () {
            var opts = {
                max: $('#crewMax').value || '2',
                review: $('#crewReview') ? $('#crewReview').checked : true,
                researcher: $('#crewResearcher') ? $('#crewResearcher').checked : true,
                auditor: $('#crewAuditor') ? $('#crewAuditor').checked : true,
                directorModel: self.mval('crewModelDirector'),
                coderModel: self.mval('crewModelCoder'),
                auditorModel: self.mval('crewModelAuditor'),
                researcherModel: self.mval('crewModelResearcher'),
                focus: self.crewFocus || '',
                parallel: $('#crewParallel') && $('#crewParallel').checked,   // opt-in single-box parallel coders
                // template-forced skills + the picked team's own skill (deduped)
                skills: (self.crewTemplateSkills || []).concat(self.crewTeamSkill ? [self.crewTeamSkill] : []).filter(function (v, i, a) { return v && a.indexOf(v) === i; }),
                hosts: ($('#crewHosts') && $('#crewHosts').value || '').trim()
            };
            self.closeCrew();
            self.runCrew(task, opts);
        };
        // If the user set a different folder in Advanced, switch to it first.
        var folder = ($('#crewFolder') && $('#crewFolder').value || '').trim();
        if (folder && folder !== this.cwd) {
            Promise.resolve(window.setRoot ? window.setRoot(folder) : { root: folder }).then(function (r) {
                if (!r || r.error) { banner('not a folder: ' + folder, 'err'); var a = document.querySelector('.crew-adv'); if (a) a.open = true; $('#crewFolder').focus(); return; }
                self.cwd = r.root; try { localStorage.setItem('ade.folder', r.root); } catch (e) {}
                self.loadFiles(r.root); go();
            }).catch(function (e) { banner('folder error: ' + e, 'err'); });
        } else { go(); }
    },
    // Launch `ollamadev crew "<task>"` in a fresh terminal and show it full-screen.
    runCrew: function (task, opts) {
        opts = opts || {};
        if (this.terminals.length >= this.MAX_TERMINALS) { banner('close a terminal first (max ' + this.MAX_TERMINALS + ')', 'err'); return; }
        var base = opts.coderModel || opts.model || this.realModel();   // never the shell entry
        var cli = this.cli || 'ollamadev';
        var interactive = !String(task || '').trim();
        // With a task: run it. Without: launch interactive — the Director waits for
        // your prompt in this terminal.
        var q = interactive ? '' : " '" + String(task).replace(/'/g, "'\\''") + "'";
        var rmf = function (flag, m) { return m ? ' ' + flag + " '" + String(m).replace(/'/g, "'\\''") + "'" : ''; };
        // Run in the opened project folder, not the ADE's own directory.
        var cmd = this.cdPrefix() + cli + ' crew' + q + ' --max ' + (parseInt(opts.max, 10) || 2) + ' -m ' + base +
            (opts.review !== false ? ' --review' : '') +
            (opts.researcher === false ? ' --no-research' : '') +
            (opts.auditor === false ? ' --no-audit' : '') +
            rmf('--director-model', opts.directorModel) +
            rmf('--researcher-model', opts.researcher !== false ? opts.researcherModel : '') +
            rmf('--auditor-model', opts.auditor !== false ? opts.auditorModel : '') +
            rmf('--focus', opts.focus) +
            (opts.parallel ? ' --parallel' + (typeof opts.parallel === 'number' && opts.parallel > 0 ? ' ' + opts.parallel : '') : '') +
            (Array.isArray(opts.skills) ? opts.skills.map(function (s) { return rmf('--skill', s); }).join('') : '') +
            (opts.hosts ? ' --hosts ' + "'" + String(opts.hosts).replace(/\s+/g, '').replace(/'/g, "'\\''") + "'" : '');
        var model = base;
        // Use the REAL model (not the literal 'crew') so a saved/resumed crew terminal
        // relaunches with -m <model>, not the invalid "-m crew".
        var dir = (this.cwd && this.cwd !== '.') ? this.cwd : '';
        var id = rid(); var t = new Terminal(id, model, dir); t.kind = 'crew';
        var self = this;
        Promise.resolve(window.termCreate(id, model, dir)).then(function () {
            self.terminals.push(t); self.render();
            setTimeout(function () { try { window.termWrite(id, strToB64(cmd + '\n')); } catch (e) {} }, 400);
            // Interactive: stay on the terminal so you can prompt the Director.
            // With a task: jump to the board so the plan appears as it's made.
            self.setView(interactive ? 'code' : 'board');
            self.startCrewPoll();
            self.openDirectorTerminal();   // the Director gets its own terminal to steer from
            banner(interactive ? 'crew ready — prompt the Director in the terminal' : 'crew running…', 'ok');
        }).catch(function (e) { banner('crew launch failed: ' + e, 'err'); });
    },
    // Voice: launch a crew with sensible defaults (or open the setup if no task was
    // spoken). Reuses the exact runCrew path — same Director/coders/auditor flow.
    voiceStartCrew: function (task) {
        task = (task || '').trim();
        if (!task) { this.openCrew(); if (window.VoiceCtl) VoiceCtl.log('opened the Crew setup', 'ok'); return; }
        this.runCrew(task, { max: 2, review: true, researcher: true, auditor: true, coderModel: this.realModel() });
        if (window.VoiceCtl) VoiceCtl.log('🚀 crew launched: ' + task, 'ok');
    },
    // Voice: steer a running coder (n) or the whole crew (n=0) via the existing
    // separate-Director channel (window.crewSteer → steer.jsonl). No engine change.
    steerCrew: function (n, msg) {
        msg = (msg || '').trim(); if (!msg) return;
        var who = n === 0 ? 'the crew' : 'coder ' + n;
        Promise.resolve(window.crewSteer ? window.crewSteer(n, msg) : { error: 'crew steering unavailable here' }).then(function (r) {
            if (r && r.error) { if (window.VoiceCtl) VoiceCtl.log('⚠ ' + r.error, 'err'); banner(r.error, 'err'); }
            else { if (window.VoiceCtl) VoiceCtl.log('🧭 → ' + who + ': ' + msg, 'ok'); banner('steered ' + who, 'ok'); }
        }).catch(function () { if (window.VoiceCtl) VoiceCtl.log('steer failed', 'err'); });
    },
    // Open a dedicated terminal running the Director steering console (`crew director`),
    // so you redirect coders from its own tab instead of the coder output stream.
    openDirectorTerminal: function () {
        var self = this;
        if (this.terminals.some(function (t) { return t.kind === 'director'; })) return;   // already open
        if (this.terminals.length >= this.MAX_TERMINALS) { banner('close a terminal to open the Director', 'err'); return; }
        var cli = this.cli || 'ollamadev';
        var cmd = this.cdPrefix() + cli + ' crew director';
        var dir = (this.cwd && this.cwd !== '.') ? this.cwd : '';
        var id = rid(); var t = new Terminal(id, 'Director', dir); t.kind = 'director';
        Promise.resolve(window.termCreate(id, 'Director', dir)).then(function () {
            self.live[id] = true; self.terminals.push(t); self.render();
            setTimeout(function () { try { window.termWrite(id, strToB64(cmd + '\n')); } catch (e) {} }, 550);
        }).catch(function () {});
    },
    // Spawn a terminal in `folder` that runs a specific command. Used to RESTORE crew
    // and director terminals to their real command (e.g. `crew director`) instead of a
    // bare `-m <label>`, which is why they didn't come back after a restart.
    spawnCmd: function (model, kind, folder, cmd) {
        if (this.terminals.length >= this.MAX_TERMINALS) return;
        var dir = this.expandHome(folder) || (this.cwd && this.cwd !== '.' ? this.cwd : '');
        var id = rid(); var t = new Terminal(id, model, dir); if (kind) t.kind = kind;
        var self = this;
        Promise.resolve(window.termCreate(id, model, dir)).then(function () {
            self.live[id] = true; self.terminals.push(t); self.render();
            if (cmd) setTimeout(function () { try { window.termWrite(id, strToB64(cmd + '\n')); } catch (e) {} }, 500);
        }).catch(function () {});
    },
    // Poll the live crew board so the kanban reflects the Director's plan + progress.
    startCrewPoll: function () {
        var self = this;
        if (this.crewPoll) return;
        var idle = 0;
        this.crewPoll = setInterval(function () {
            Promise.resolve(window.crewBoard ? window.crewBoard() : null).then(function (b) {
                // A "clear the board" (agent tool / `crew clear` / CLI) writes a cleared
                // sentinel. Apply it once via a localStorage watermark — survives poll &
                // app restarts — wiping the localStorage-only manual cards the engine
                // can't reach. (Crew cards/ideas clear via the empty board below.)
                if (b && b.cleared) {
                    var seen = 0; try { seen = parseInt(localStorage.getItem('ade.boardCleared') || '0', 10) || 0; } catch (e) {}
                    if (b.cleared > seen) {
                        try { localStorage.setItem('ade.boardCleared', String(b.cleared)); } catch (e) {}
                        try { localStorage.removeItem('ade.tasks'); } catch (e) {}
                        Tasks.items = [];
                    }
                }
                self.crewBoard = (b && b.subtasks) ? b : self.crewBoard;
                // Refresh the board whenever it's open as a pane on the canvas.
                if (self.popped.board) Tasks.render();
                // CrewPanes polls the per-coder logs (→ live activity), so run it
                // whenever the board OR the topology window is open.
                if (self.popped.board || self.popped.topology) CrewPanes.sync(self.crewBoard);
                if (self.popped.topology && window.Topology) Topology.sync(self.crewBoard);
                // Stop polling a while after the run goes inactive — but keep it alive
                // while the board or topology is open so a fresh crew run shows up live.
                if (b && b.active === false && !self.popped.board && !self.popped.topology) { if (++idle > 6) { clearInterval(self.crewPoll); self.crewPoll = null; } }
                else idle = 0;
            }).catch(function () {});
        }, 1500);
    },
    // The rail + Projects sidebar are an overlay drawer (☰) at every size, so the canvas
    // is full-bleed by default. Opening a window, picking a file/project, clicking the
    // canvas, or Esc closes it again. Works the same on desktop and mobile (web).
    initResponsive: function () {
        var close = function () { document.body.classList.remove('nav-open'); };
        var nt = $('#navToggle'); if (nt) nt.onclick = function () { document.body.classList.toggle('nav-open'); };
        var ws = $('#workspace'); if (ws) ws.addEventListener('mousedown', function () { if (document.body.classList.contains('nav-open')) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        var sb = $('#sidebar'); if (sb) sb.addEventListener('click', function (e) {
            if (e.target.closest('.tree-item, .ws-tab-item, .ws-bar-add')) setTimeout(close, 80); // picked a file/project → reveal the canvas
        });
        var rail = $('#rail'); if (rail) rail.addEventListener('click', function (e) {
            if (e.target.closest('.rail-btn')) setTimeout(close, 80); // opened a window → reveal the canvas
        });
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
                var def = (s && s.default) || '';   // your configured ollama.defaultModel
                // "shell" sits at the top of the model list: pick it + "+ Terminal" for a
                // plain shell (no ollamadev). It's never the default selection. (General
                // chat lives in its own 💬 Chat window — see the Chat controller.)
                var shellOpt = '<option value="shell">🐚 shell — plain terminal</option>';
                sel.innerHTML = shellOpt + (models.map(function (m) {
                    var name = m.name || m;
                    return '<option' + (name === def ? ' selected' : '') + '>' + esc(name) + '</option>';
                }).join('') || '<option>llama3.2:latest</option>');
                // If the default isn't in the list (e.g. not pulled), still select it.
                if (def && sel.value !== def && [].some.call(sel.options, function (o) { return o.value === def; })) sel.value = def;
                // Seed the remembered chat/crew model from your configured default the first
                // time (a programmatic .value set doesn't fire onchange). Drop a stale
                // remembered model that's no longer installed so chat always has a real one.
                var real = [].slice.call(sel.options).map(function (o) { return o.value; }).filter(function (m) { return m !== 'shell' && m !== 'chat'; });
                if (App.lastModel && real.indexOf(App.lastModel) === -1) App.lastModel = '';
                if (!App.lastModel) App.lastModel = (def && real.indexOf(def) !== -1) ? def : (sel.value && sel.value !== 'shell' && sel.value !== 'chat' ? sel.value : (real[0] || ''));
            }
        }).catch(function (e) { banner('listModels failed: ' + e, 'err'); });
    },
    loadFiles: function (path) {
        var self = this;
        Promise.resolve(window.listFiles(path)).then(function (items) {
            if (items && items.error) { banner('list failed: ' + items.error, 'err'); return; }
            if (!Array.isArray(items)) return;
            self.cwd = path;
            var cwdEl = $('#cwd'); if (cwdEl) { cwdEl.textContent = shortPath(path); cwdEl.title = path; }
            var bc = $('#breadcrumb'); if (bc) { bc.textContent = shortPath(path, 60); bc.title = path; }
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
    launchCli: function (id, model, cwd) {
        // SIMPLE_INPUT: the CLI uses plain line input so the embedded terminal
        // (which the host pty echoes into) renders cleanly without raw-mode escapes.
        // Tool-approval mode (the "Tool approval" picker in the Session drawer):
        //   auto     — run tools without the blocking y/n confirmation prompts. In the
        //              desktop GUI those single-key prompts (Permission::prompt /
        //              DiffView::confirm, both fgets(STDIN)) are invisible/awkward, so
        //              the agent appeared to stall on the first write/edit/bash —
        //              "tools don't go". Auto still PRINTS every diff preview; it just
        //              doesn't gate the change behind a hidden keypress. Matches Crew.
        //   ask      — confirm each mutating tool (type y/Enter in the terminal).
        //   readonly — block all changes.
        // Default ask (matches the CLI's safe default); the picker lets you opt into auto.
        // Passed via OLLAMADEV_PERMISSION (a per-launch env), NOT a --flag — so the
        // desktop's pick governs only this terminal and never rewrites the shared
        // config that the standalone CLI reads for its own default.
        var mode = (this.permMode === 'auto' || this.permMode === 'readonly') ? this.permMode : 'ask';
        var cmd = this.cdPrefix(cwd) + 'OLLAMADEV_SIMPLE_INPUT=1 OLLAMADEV_PERMISSION=' + mode + ' ' + (this.cli || 'ollamadev') + (model ? ' -m ' + model : '') + '\n';
        // Small delay so the pty shell is ready to receive the command.
        setTimeout(function () { try { window.termWrite(id, strToB64(cmd)); } catch (e) {} }, 350);
    },
    // A real (non-shell) model for paths that must launch an agent (run-task, crew).
    realModel: function () {
        var sel = $('#modelSelect');
        var v = sel && sel.value;
        if (v && v !== 'shell' && v !== 'chat') return v;
        var opts = sel ? Array.prototype.slice.call(sel.options).map(function (o) { return o.value; }).filter(function (m) { return m !== 'shell' && m !== 'chat'; }) : [];
        // "shell"/"chat" picked → fall back to the last real model you used (remembered),
        // then the first installed one. So chat always runs on one of YOUR models.
        if (this.lastModel && opts.indexOf(this.lastModel) !== -1) return this.lastModel;
        return opts[0] || 'llama3.2:latest';
    },
    // Respawn a terminal in a new working folder (the per-terminal folder chip).
    changeTermFolder: function (id, folder) {
        var t = this.terminals.find(function (x) { return x.id === id; });
        if (!t) return;
        folder = this.expandHome(folder); if (!folder) return;
        var self = this;
        Promise.resolve(window.termKill ? window.termKill(id) : null).then(function () {
            t.cwd = folder; t.offset = 0; t.screen = null;
            return window.termCreate(id, t.model, folder);
        }).then(function () {
            self.render();
            self.launchCli(id, t.model, folder);
            banner('terminal folder → ' + folder, 'ok');
        }).catch(function () { banner('could not change folder', 'err'); });
    },
    newTerminal: function () { return this.spawnTerminal(); },
    // Spawn a brand-new pty + launch the CLI in it.
    spawnTerminal: function (model, folder) {
        if (this.terminals.length >= this.MAX_TERMINALS) { banner('maximum of ' + this.MAX_TERMINALS + ' terminals', 'err'); return; }
        model = model || $('#modelSelect').value || 'llama3.2:latest';
        var dir = this.expandHome(folder) || (this.cwd && this.cwd !== '.' ? this.cwd : '');
        var id = rid();
        var isShell = (model === 'shell');   // the "shell" entry at the top of the model list
        var t = new Terminal(id, model, dir); if (isShell) t.kind = 'shell';
        // Right-click "Terminal" drops the pane at the cursor (canvas-world coords).
        if (this._spawnGeom) { t.x = this._spawnGeom.x; t.y = this._spawnGeom.y; t.w = 540; t.h = 340; t.z = ++this._zTop; this._spawnGeom = null; }
        var self = this;
        Promise.resolve(window.termCreate(id, model, dir)).then(function () {
            self.live[id] = true;
            self.terminals.push(t);
            self.render();
            if (!isShell) self.launchCli(id, model, dir);   // shell: bare prompt, no ollamadev
        }).catch(function (e) { banner('termCreate failed: ' + e, 'err'); });
    },
    // Open a 💬 Chat window — its OWN pane (a 'chat' terminal) running `ollamadev chat
    // --session <id>`. Plain, tool-free chat with a model picker + 📎/🧠/⬇ in the header.
    // Multiple are independent, like terminals. Resuming a saved session replays it.
    spawnChatWindow: function (model, session) {
        if (this.terminals.length >= this.MAX_TERMINALS) { banner('maximum of ' + this.MAX_TERMINALS + ' windows', 'err'); return; }
        model = model || this.realModel();
        if (!model || model === 'shell' || model === 'chat') { banner('pull a model first — ollamadev models pull <name>', 'err'); return; }
        if (!/^[\w./:@-]+$/.test(model)) { banner('unsupported model name', 'err'); return; }
        session = (session && /^[\w.-]+$/.test(session)) ? session : ('chat_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8));
        var dir = (this.cwd && this.cwd !== '.') ? this.cwd : '';
        var id = rid();
        var t = new Terminal(id, model, dir); t.kind = 'chat'; t.chatSession = session;
        if (this._spawnGeom) { t.x = this._spawnGeom.x; t.y = this._spawnGeom.y; t.w = 560; t.h = 460; t.z = ++this._zTop; this._spawnGeom = null; }
        var self = this, cli = this.cli || 'ollamadev';
        Promise.resolve(window.termCreate(id, model, dir)).then(function () {
            self.live[id] = true; self.terminals.push(t); self.render();
            setTimeout(function () { try { window.termWrite(id, strToB64(cli + ' chat --session ' + session + ' -m ' + model + '\n')); } catch (e) {} }, 350);
        }).catch(function (e) { banner('termCreate failed: ' + e, 'err'); });
        try { App.lastModel = model; localStorage.setItem('ade.chatModel', model); } catch (e) {}
        banner('💬 new chat', 'ok');
    },
    // Re-attach the UI to a pty that's still alive from earlier this session
    // (workspace switch). No termCreate, no launchCli — poll resumes its buffer.
    attachTerminal: function (id, model, kind, cwd, session) {
        if (this.terminals.length >= this.MAX_TERMINALS) return;
        var t = new Terminal(id, model || $('#modelSelect').value || 'llama3.2:latest', cwd || '');
        if (kind) t.kind = kind;
        if (kind === 'chat' && session) t.chatSession = session;
        this.terminals.push(t);
    },
    closeTerminal: function (id) {
        var i = this.terminals.findIndex(function (t) { return t.id === id; });
        if (i >= 0) { this.terminals[i].close(); delete this.live[id]; this.terminals.splice(i, 1); this.render(); }
    },
    // The terminal voice/commands target: the last one you focused, else the first.
    activeTerminal: function () {
        var self = this, t = this.lastActiveTerm && this.terminals.filter(function (x) { return x.id === self.lastActiveTerm; })[0];
        return t || this.terminals[0] || null;
    },
    sendToTerminal: function (t, s) {
        if (!t) return;
        try { window.termWrite(t.id, strToB64(s)); } catch (e) {}
        if (t.setStatus) { t.setStatus('running'); t.lastData = Date.now(); }
    },
    // Keep the active workspace's window state persisted so reopening the app
    // RESUMES it. Previously state was only saved when switching away from a
    // workspace, so working in one project and just closing the app lost the
    // session. This polls captureState() and writes only when it actually
    // changed (cheap), plus a final flush when the window goes away.
    startAutosave: function () {
        var self = this;
        var flush = function () {
            // Skip the transient empty window mid-switch (terminals detached before
            // the new ones spawn) so we never persist an empty state over a project.
            if (!Workspaces.data.active || !self.terminals.length) return;
            var snap; try { snap = JSON.stringify(self.captureState()); } catch (e) { return; }
            if (snap === self._lastSnap) return;          // unchanged → skip the write
            self._lastSnap = snap;
            try { Workspaces.saveCurrentState(); } catch (e) {}
        };
        setInterval(flush, 4000);
        window.addEventListener('beforeunload', flush);
        window.addEventListener('pagehide', flush);
        document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });
    },
    // Drop every terminal's UI but leave its pty running (workspace switch).
    detachTerminals: function () {
        this.terminals.forEach(function (t) { t.detach(); });
        this.terminals = [];
        this.zoomed = null;
        this.render();
    },
    // Snapshot the current window so a workspace can be restored exactly.
    captureState: function () {
        var self = this, panes = {};
        this.POP_VIEWS.forEach(function (v) { var b = self.popped[v]; if (b) panes[v] = { x: b.x, y: b.y, w: b.w, h: b.h }; });
        return {
            terminals: this.terminals.map(function (t) { return { id: t.id, model: t.model, kind: t.kind || '', cwd: t.cwd || '', session: t.chatSession || '', x: t.x, y: t.y, w: t.w, h: t.h, z: t.z }; }),
            editorTabs: Editor.tabs.map(function (t) { return { path: t.path, name: t.name }; }),
            // The canvas layout is now per-project: which view panes are open (+ geometry)
            // and the pan offset travel with the workspace, like the terminals do.
            panes: panes,
            pan: { x: this.panX, y: this.panY },
            layout: this.layout,
            view: this.view,
            zoomed: this.zoomed || null
        };
    },
    // Rebuild the window from a saved workspace state. Re-attaches terminals whose
    // pty survived this session; respawns the rest fresh (e.g. after a restart).
    restoreState: function (state) {
        state = state || {};
        var self = this;
        // Reset the canvas to this project's saved layout: park any open view panes,
        // then rebuild the open set + pan offset from the workspace state.
        this._parkPopped();
        this.popped = {};
        this.panX = (state.pan && state.pan.x) || 0;
        this.panY = (state.pan && state.pan.y) || 0;
        if (state.panes) Object.keys(state.panes).forEach(function (v) {
            if (!self.POP_SEL[v]) return;
            var g = state.panes[v];
            self.popped[v] = { id: '__pop_' + v + '__', view: v, x: g.x, y: g.y, w: g.w, h: g.h, z: ++self._zTop };
        });
        // First time on the canvas (no saved layout): open a Files window so the file
        // tree is right there — otherwise the tree would have nowhere to show.
        if (!state.panes) { var df = this.POP_DEFAULT.files; this.popped.files = { id: '__pop_files__', view: 'files', x: df.x, y: df.y, w: df.w, h: df.h, z: ++this._zTop }; }
        this.savePopped();
        try { localStorage.setItem('ade.pan', JSON.stringify({ x: this.panX, y: this.panY })); } catch (e) {}
        Editor.closeAll();
        // Opening a file auto-adds an editor pane; only force one when the project had
        // open files but no saved editor pane (otherwise honor the saved layout exactly).
        (state.editorTabs || []).forEach(function (t) { Editor.open(t.path, t.name); });
        var terms = state.terminals || [];
        // Free-layout: the MODE is global (set in init from localStorage); here we only
        // restore each pane's saved geometry (by id for re-attached, by index for
        // respawned). applyGeom consumes these on the first free render.
        this._geomQueue = terms.map(function (g) { return { x: g.x, y: g.y, w: g.w, h: g.h, z: g.z }; });
        this._geomById = {};
        terms.forEach(function (g) { if (g.id) self._geomById[g.id] = { x: g.x, y: g.y, w: g.w, h: g.h, z: g.z }; if (g.z > self._zTop) self._zTop = g.z; });
        var attached = 0;
        terms.forEach(function (ti) { if (self.live[ti.id]) { self.attachTerminal(ti.id, ti.model, ti.kind, ti.cwd, ti.session); attached++; } });
        this.zoomed = state.zoomed || null;
        if (attached) this.render();
        // saved terminals whose pty is gone → respawn (each renders itself). Restore by
        // KIND so the Director console + crew prompt come back as themselves, not a bare
        // `-m <label>`; "shell" comes back as a plain shell; the rest relaunch ollamadev.
        var cli = self.cli || 'ollamadev';
        terms.forEach(function (ti) {
            if (self.live[ti.id]) return;
            if (ti.kind === 'director') self.spawnCmd('Director', 'director', ti.cwd, cli + ' crew director');
            else if (ti.kind === 'crew') self.spawnCmd(ti.model, 'crew', ti.cwd, cli + ' crew');
            else if (ti.kind === 'chat') self.spawnChatWindow(ti.model, ti.session);   // resumes the saved chat session
            else self.spawnTerminal(ti.model, ti.cwd);
        });
        if (!terms.length && !this.terminals.length) this.spawnTerminal();
        if (state.layout) this.setLayout(state.layout);
        // Legacy migration only: older states (no `panes`) used a single active view —
        // open it as a pane. New states fully describe the canvas via `panes`.
        if (!state.panes && state.view && this.POP_SEL[state.view]) this.setView(state.view);
        else this.render();   // ensure the restored view panes + pan are painted
    },
    render: function () {
        var wrap = $('#terminals');
        if (this.termLayout === 'free') return this.renderFree(wrap);
        // A zoomed terminal takes the whole area; otherwise the CSS responsive
        // grid fits as many readable-width panes as possible and scrolls for more.
        // Hoist zoomed into a local: the callbacks below must not read `this`
        // (a bare .filter/.some callback runs with this===undefined in strict mode).
        var self = this;
        var z = this.zoomed;
        if (z && !this._paneExists(z)) { this.zoomed = null; z = null; }
        // Panes = terminals + any popped-out views (board/graph/browser); each tiles
        // like a terminal. Park popped views home first so innerHTML='' can't destroy them.
        this._parkPopped();
        var all = this.terminals.slice().concat(this._poppedPanes());
        var list = z ? all.filter(function (t) { return t.id === z; }) : all;
        var n = list.length;
        // Fit all panes; shrink the font as the count rises so code stays legible.
        var cols = z || n <= 1 ? 1 : Math.min(4, Math.ceil(Math.sqrt(n)));
        var fs = z ? 13 : n <= 2 ? 13 : n <= 4 ? 12 : n <= 6 ? 11 : n <= 9 ? 10 : 9;
        wrap.className = (z ? 'zoomed' : '') + (!z && n > 6 ? ' dense' : '');
        wrap.style.gridTemplateColumns = 'repeat(' + cols + ', minmax(0, 1fr))';
        wrap.style.setProperty('--tfs', fs + 'px');
        // Focus = pop the terminal out to fill the whole work area (over the editor),
        // not just the terminals strip. Cleared when un-focused.
        var cv = $('#codeView'); if (cv) cv.classList.toggle('term-zoom', !!z);
        wrap.innerHTML = '';
        list.forEach(function (t) {
            var pane = document.createElement('div');
            pane.className = 'term-pane';
            wrap.appendChild(pane);
            if (t.view) self.mountPoppedPane(pane, t);
            else t.mount(pane);
        });
        this._syncRail();
    },
    // ---- Free-floating layout: drag the header, resize the corner, overlap freely ----
    // Toggle the GPU/canvas terminal renderer. Re-renders so open terminals remount
    // with the chosen renderer; the pty keeps running, only the view is rebuilt.
    setCanvasTerm: function (on) {
        this.canvasTerm = !!on;
        try { localStorage.setItem('ade.canvasTerm', on ? 'on' : 'off'); } catch (e) {}
        var btn = $('#termCanvas'); if (btn) btn.textContent = on ? '🖼 Canvas' : '🖼 DOM';
        banner(on ? 'canvas renderer on (GPU-composited) — DOM is the default' : 'DOM renderer on', 'ok');
        this.render();
    },
    setTermLayout: function (mode) {
        this.termLayout = (mode === 'free') ? 'free' : 'tiled';
        try { localStorage.setItem('ade.termLayout', this.termLayout); } catch (e) {}   // reopen here next time
        var btn = $('#termArrange'); if (btn) btn.textContent = this.termLayout === 'free' ? '⮻ Free' : '⊞ Tiled';
        this.render();   // focus/zoom now works in BOTH layouts, so it's preserved across the switch
        if (Workspaces && Workspaces.saveCurrentState) Workspaces.saveCurrentState();
    },
    renderFree: function (wrap) {
        var self = this;
        // Focus also works in free mode: a focused terminal pops out to fill the
        // whole work area (over the editor), exactly like tiled zoom. Toggling it
        // off restores the free layout — each pane's saved geometry is untouched.
        var z = this.zoomed;
        if (z && !this._paneExists(z)) { this.zoomed = null; z = null; }
        // Park popped views home before clearing so innerHTML='' can't destroy them.
        this._parkPopped();
        if (z) {
            wrap.className = 'zoomed';
            wrap.style.gridTemplateColumns = 'repeat(1, minmax(0, 1fr))';
            wrap.style.setProperty('--tfs', '13px');
            var cvz = $('#codeView'); if (cvz) cvz.classList.add('term-zoom');
            wrap.innerHTML = '';
            var fp = document.createElement('div'); fp.className = 'term-pane'; wrap.appendChild(fp);
            var zp = this._poppedPanes().filter(function (p) { return p.id === z; })[0];
            if (zp) this.mountPoppedPane(fp, zp);
            else this.terminals.filter(function (t) { return t.id === z; })[0].mount(fp);
            return;
        }
        wrap.className = 'free';
        wrap.style.gridTemplateColumns = '';
        wrap.style.setProperty('--tfs', '13px');
        var cv = $('#codeView'); if (cv) cv.classList.remove('term-zoom');
        wrap.innerHTML = '';
        // One transformed inner layer holds every pane; panning = translating it once
        // (cheap, and the canvas extends infinitely in every direction).
        var inner = document.createElement('div');
        inner.className = 'canvas-inner';
        wrap.appendChild(inner);
        this._inner = inner;
        inner.style.transform = 'translate(' + this.panX + 'px,' + this.panY + 'px) scale(' + this.zoom + ')';
        var mountPane = function (t, isView) {
            if (typeof t.x !== 'number') self.applyGeom(t, self.terminals.indexOf(t));
            var pane = document.createElement('div');
            pane.className = 'term-pane';
            pane.style.left = t.x + 'px'; pane.style.top = t.y + 'px';
            pane.style.width = t.w + 'px'; pane.style.height = t.h + 'px';
            pane.style.zIndex = t.z || 1;
            inner.appendChild(pane);
            if (isView) self.mountPoppedPane(pane, t); else t.mount(pane);
            var rh = document.createElement('div'); rh.className = 'term-resize'; pane.appendChild(rh);
            self.wireFree(t, pane, rh);
            return pane;
        };
        this.terminals.forEach(function (t) { mountPane(t, false); });
        this._poppedPanes().forEach(function (b) {
            if (typeof b.x !== 'number') { b.x = 280; b.y = 24; b.w = 520; b.h = 400; b.z = ++self._zTop; }
            mountPane(b, true);
        });
        this._syncRail();
    },
    // ---- Infinite canvas: pan by dragging empty space; right-click to add a pane ----
    bindCanvas: function () {
        var self = this, wrap = $('#terminals');
        if (!wrap || wrap._canvasBound) return;
        wrap._canvasBound = true;
        // Pan: press on empty canvas (not a pane) and drag. Middle-button drags anywhere.
        // A shared driver runs for both mouse and touch so the canvas pans on phones too.
        var startPan = function (px, py, moveEvt, endEvt, getPt) {
            var ox = self.panX, oy = self.panY;
            document.body.style.userSelect = 'none';
            function mv(ev) {
                var p = getPt(ev); if (!p) return;
                self.panX = ox + p.x - px; self.panY = oy + p.y - py;
                if (self._inner) self._inner.style.transform = 'translate(' + self.panX + 'px,' + self.panY + 'px) scale(' + self.zoom + ')';
            }
            function up() {
                document.removeEventListener(moveEvt, mv); document.removeEventListener(endEvt, up);
                document.body.style.userSelect = '';
                self._saveView();
            }
            document.addEventListener(moveEvt, mv); document.addEventListener(endEvt, up);
        };
        wrap.addEventListener('mousedown', function (e) {
            if (document.body.classList.contains('nav-open')) return;   // let the canvas click close the drawer
            var onPane = e.target.closest && e.target.closest('.term-pane');
            if (self.termLayout !== 'free') return;
            if (e.button === 1 || (e.button === 0 && !onPane)) {
                e.preventDefault();
                startPan(e.clientX, e.clientY, 'mousemove', 'mouseup', function (ev) { return { x: ev.clientX, y: ev.clientY }; });
            }
        });
        wrap.addEventListener('touchstart', function (e) {
            if (self.termLayout !== 'free' || e.touches.length !== 1) return;
            if (e.target.closest && e.target.closest('.term-pane')) return;   // let panes scroll/select
            var t = e.touches[0];
            startPan(t.clientX, t.clientY, 'touchmove', 'touchend', function (ev) {
                var u = ev.touches[0] || (ev.changedTouches && ev.changedTouches[0]); return u ? { x: u.clientX, y: u.clientY } : null;
            });
        }, { passive: true });
        // Zoom: Ctrl/⌘ + wheel, or a trackpad pinch (which the browser reports as
        // ctrl+wheel). Zooms toward the cursor. Plain wheel is left to scroll panes.
        wrap.addEventListener('wheel', function (e) {
            if (self.termLayout !== 'free') return;
            if (!(e.ctrlKey || e.metaKey)) return;
            e.preventDefault();
            self.zoomBy(e.deltaY < 0 ? 1.1 : 0.9, e.clientX, e.clientY);
        }, { passive: false });
        wrap.addEventListener('contextmenu', function (e) {
            if (e.target.closest && e.target.closest('.term-pane')) return;   // pane has its own context
            e.preventDefault(); self.showCanvasMenu(e.clientX, e.clientY);
        });
        // Click anywhere else closes the add menu.
        document.addEventListener('mousedown', function (e) {
            var m = $('#canvasMenu');
            if (m && !m.hidden && !m.contains(e.target) && e.target.id !== 'addPaneBtn') m.hidden = true;
        });
        var menu = $('#canvasMenu');
        if (menu) {
            menu.querySelectorAll('[data-add]').forEach(function (btn) {
                btn.onclick = function () {
                    var pt = menu._spawnAt || { x: 60, y: 60 };
                    menu.hidden = true;
                    self.addPane(btn.dataset.add, pt.x, pt.y);
                };
            });
            menu.querySelectorAll('[data-cmd]').forEach(function (btn) {
                btn.onclick = function () { menu.hidden = true; self.runCmd(btn.dataset.cmd); };
            });
        }
        // Floating zoom control (− 100% +)
        var zc = $('#zoomCtl');
        if (zc) zc.querySelectorAll('[data-zoom]').forEach(function (btn) {
            btn.onclick = function () {
                var k = btn.dataset.zoom;
                if (k === 'in') self.zoomBy(1.15); else if (k === 'out') self.zoomBy(1 / 1.15); else self.resetZoom();
            };
        });
        // Update the % label once at startup.
        var zl = $('#zoomLevel'); if (zl) zl.textContent = Math.round(this.zoom * 100) + '%';
    },
    // Show the add/command menu at a screen point; remember the canvas-world point to
    // drop at, and refresh the toggle labels so they show current state.
    showCanvasMenu: function (clientX, clientY) {
        var menu = $('#canvasMenu'), wrap = $('#terminals'); if (!menu || !wrap) return;
        var r = wrap.getBoundingClientRect();
        menu._spawnAt = { x: (clientX - r.left - this.panX) / this.zoom, y: (clientY - r.top - this.panY) / this.zoom };
        var lbl = function (cmd, text) { var b = menu.querySelector('[data-cmd="' + cmd + '"]'); if (b) b.textContent = text; };
        lbl('web', (window.Net && Net.on) ? '🌐 Web access: on' : '🌐 Web access: off');
        lbl('search-toggle', (window.Net && Net.search) ? '🔍 Web search: on' : '🔍 Web search: off');
        lbl('layout', this.termLayout === 'free' ? '⊞ Switch to Tiled' : '⮻ Switch to Free');
        lbl('renderer', this.canvasTerm ? '🖼 Renderer: Canvas → DOM' : '🖼 Renderer: DOM → Canvas');
        var mw = menu.offsetWidth || 190, mh = menu.offsetHeight || 360;
        menu.style.left = Math.max(4, Math.min(clientX, window.innerWidth - mw - 6)) + 'px';
        menu.style.top = Math.max(4, Math.min(clientY, window.innerHeight - mh - 6)) + 'px';
        menu.hidden = false;
    },
    // Add a pane at canvas-world coords (wx,wy). 'terminal' spawns a new pty; tool dialogs
    // run their richer opener (loads their data); the rest open/focus a singleton window.
    addPane: function (kind, wx, wy) {
        if (kind === 'terminal') { this._spawnGeom = { x: wx, y: wy }; this.spawnTerminal(); return; }
        if (kind === 'chat') { this._spawnGeom = { x: wx, y: wy }; this.spawnChatWindow(); return; }   // each ＋ Chat = its own window
        var openers = { crew: this.openCrew, diff: function () { Diff.open(); }, roles: function () { Roles.open(); }, skills: function () { SkillMgr.open(); }, hooks: function () { HookMgr.open(); } };
        if (openers[kind]) { openers[kind].call(this); return; }
        if (this.POP_SEL[kind]) {
            if (this.popped[kind]) { this.focusPane(this.popped[kind].id); return; }
            this.popView(kind, wx, wy);
        }
    },
    // Run a menu command (the actions that used to be toolbar buttons).
    runCmd: function (cmd) {
        if (cmd === 'open-project') this.openFolderModal(false);
        else if (cmd === 'web') { if (window.Net) Net.toggleWeb(); }
        else if (cmd === 'search-toggle') { if (window.Net) Net.toggleSearch(); }
        else if (cmd === 'layout') this.setTermLayout(this.termLayout === 'free' ? 'tiled' : 'free');
        else if (cmd === 'renderer') this.setCanvasTerm(!this.canvasTerm);
        else if (cmd === 'center') this.centerCanvas();
        else if (cmd === 'history') { if (window.Stt && Stt.openHistory) Stt.openHistory(); }
    },
    focusPane: function (id) {
        var p = this._poppedPanes().filter(function (x) { return x.id === id; })[0] ||
            this.terminals.filter(function (t) { return t.id === id; })[0];
        if (p) { p.z = ++this._zTop; this.render(); }
    },
    // The single source of truth for the canvas transform: pan (screen px) then zoom.
    _applyTransform: function () {
        if (this._inner) this._inner.style.transform = 'translate(' + this.panX + 'px,' + this.panY + 'px) scale(' + this.zoom + ')';
        this._saveView();
        var zl = $('#zoomLevel'); if (zl) zl.textContent = Math.round(this.zoom * 100) + '%';
    },
    _saveView: function () { try { localStorage.setItem('ade.pan', JSON.stringify({ x: this.panX, y: this.panY, zoom: this.zoom })); } catch (e) {} },
    // Set the zoom, keeping the canvas-world point under (cx,cy) screen px fixed (zoom
    // toward the cursor). Defaults to the viewport center.
    setZoom: function (z, cx, cy) {
        var wrap = $('#terminals'); if (!wrap) return;
        z = Math.max(0.2, Math.min(3, z));
        var r = wrap.getBoundingClientRect();
        if (typeof cx !== 'number') { cx = r.left + r.width / 2; cy = r.top + r.height / 2; }
        var sx = cx - r.left, sy = cy - r.top;
        // world point under the cursor before the change
        var wxp = (sx - this.panX) / this.zoom, wyp = (sy - this.panY) / this.zoom;
        this.zoom = z;
        // re-anchor pan so that same world point stays under the cursor
        this.panX = sx - wxp * z; this.panY = sy - wyp * z;
        this._applyTransform();
    },
    zoomBy: function (factor, cx, cy) { this.setZoom(this.zoom * factor, cx, cy); },
    resetZoom: function () { this.setZoom(1); banner('zoom 100%', 'ok'); },
    // Re-center the canvas so the bounding box of all panes is brought into view.
    centerCanvas: function () {
        var all = this.terminals.concat(this._poppedPanes()).filter(function (p) { return typeof p.x === 'number'; });
        var wrap = $('#terminals'); if (!wrap) return;
        if (!all.length) { this.panX = 0; this.panY = 0; }
        else {
            var minX = Math.min.apply(null, all.map(function (p) { return p.x; }));
            var minY = Math.min.apply(null, all.map(function (p) { return p.y; }));
            this.panX = (24 - minX) * this.zoom; this.panY = (24 - minY) * this.zoom;
        }
        this._applyTransform();
        banner('canvas re-centered', 'ok');
    },
    // Every open window entry (content windows + tool dialogs) — valid zoom/focus
    // targets alongside terminals.
    _poppedPanes: function () {
        var self = this;
        return Object.keys(this.popped).map(function (v) { return self.popped[v]; });
    },
    _paneExists: function (id) {
        return this._poppedPanes().some(function (p) { return p.id === id; }) ||
            this.terminals.some(function (t) { return t.id === id; });
    },
    // Pick a starting geometry: a saved one (by index, from restore) or a cascade.
    applyGeom: function (t, i) {
        var g = (this._geomById && this._geomById[t.id]) || (this._geomQueue && this._geomQueue[i]) || null;
        if (g && typeof g.x === 'number') { t.x = g.x; t.y = g.y; t.w = g.w; t.h = g.h; t.z = g.z || ++this._zTop; }
        // Cascade into the CURRENTLY VISIBLE area (subtract pan), so a new pane never
        // spawns off-screen when the canvas is panned far away.
        else { i = i < 0 ? 0 : i; t.x = (24 - this.panX) / this.zoom + (i * 30) % 240; t.y = (24 - this.panY) / this.zoom + (i * 30) % 170; t.w = 540; t.h = 340; t.z = ++this._zTop; }
    },
    _zTop: 1,
    bringFront: function (t, pane) { t.z = ++this._zTop; pane.style.zIndex = t.z; },
    saveGeomSoon: function () {
        var self = this; clearTimeout(this._geomSave);
        this._geomSave = setTimeout(function () { self.savePopped(); if (Workspaces && Workspaces.saveCurrentState) Workspaces.saveCurrentState(); }, 400);
    },
    // ---- Open a view (editor/board/graph/browser) as a pane on the canvas ----
    // Idempotent: opens the pane if it isn't already on the canvas.
    ensurePane: function (view) { if (this.POP_SEL[view] && !this.popped[view]) this.popView(view); },
    // Open a tool dialog (crew/roles/skills/hooks/diff) as a canvas window — or focus
    // it if it's already open. Returns true once it's on the canvas.
    openDialog: function (view) {
        if (this.popped[view]) this.focusPane(this.popped[view].id);
        else this.popView(view);
        return true;
    },
    popView: function (view, wx, wy) {
        if (!this.POP_SEL[view] || this.popped[view]) return;
        var d = this.POP_DEFAULT[view] || { x: 280, y: 24, w: 520, h: 400 };
        var x = (typeof wx === 'number') ? wx : (d.x - this.panX) / this.zoom, y = (typeof wy === 'number') ? wy : (d.y - this.panY) / this.zoom;
        this.popped[view] = { id: '__pop_' + view + '__', view: view, x: x, y: y, w: d.w, h: d.h, z: ++this._zTop };
        this.savePopped();
        this.render();
        if (view === 'board') { this.startCrewPoll(); Tasks.render(); }
        banner(this.POP_TITLE[view] + ' added to the canvas — drag the header, resize the corner', 'ok');
    },
    // Close a view pane (its element parks back in #paneStore, keeping all its state;
    // re-add it any time from ＋ Add / right-click).
    closePaneView: function (view) {
        if (!this.popped[view]) return;
        if (this.zoomed === this.popped[view].id) this.zoomed = null;
        delete this.popped[view];
        this.savePopped();
        // Move its element home BEFORE re-rendering, or innerHTML='' would destroy it.
        var el = $(this.POP_SEL[view]), store = $('#paneStore');
        if (el && store && el.parentNode !== store) { el.hidden = true; store.appendChild(el); }
        this.render();
    },
    savePopped: function () {
        var out = {}, self = this;
        this.POP_VIEWS.forEach(function (v) { var b = self.popped[v]; if (b) out[v] = { x: b.x, y: b.y, w: b.w, h: b.h }; });
        try { localStorage.setItem('ade.popped', JSON.stringify(out)); } catch (e) {}
    },
    // Park each open view's real element back in #paneStore (hidden) before a pending
    // innerHTML='' so it can't be destroyed; re-mounted into its pane right after.
    _parkPopped: function () {
        var store = $('#paneStore'), self = this;
        Object.keys(this.popped).forEach(function (view) {
            var el = $(self.POP_SEL[view]);
            if (el && store && el.parentNode !== store) { el.hidden = true; store.appendChild(el); }
        });
    },
    // Wrap a popped view in terminal-style chrome and move its real element into the
    // pane body (preserving all its state). `b` is the geometry entry from this.popped.
    mountPoppedPane: function (host, b) {
        var self = this, view = b.view;
        var focused = this.zoomed === b.id;
        host.classList.add('pop-pane');
        host.innerHTML =
            '<div class="term-head"><span class="nm">' + this.POP_TITLE[view] + '</span>' +
            '<button class="zoom" title="' + (focused ? 'Restore' : 'Focus (make this bigger)') + '" style="margin-left:auto">' + (focused ? '⤡' : '⤢') + '</button>' +
            '<button class="x" title="Close (re-add any time from ＋ Add)">&times;</button></div>' +
            '<div class="pop-pane-body"></div>';
        var el = $(this.POP_SEL[view]);
        if (el) { el.hidden = false; host.querySelector('.pop-pane-body').appendChild(el); }
        host.querySelector('.x').onclick = function (e) { e.stopPropagation(); self.closePaneView(view); };
        host.querySelector('.zoom').onclick = function (e) { e.stopPropagation(); self.toggleZoom(b.id); };
        host.querySelector('.term-head').ondblclick = function (e) {
            if (e.target.closest('.x, .zoom')) return;
            self.toggleZoom(b.id);
        };
        // Refresh live content once the pane has its real size (next frame), so the
        // graph canvas etc. measure correctly instead of off a 0-width host.
        setTimeout(function () {
            if (view === 'board') Tasks.renderInto($('#board'));
            else if (view === 'graph') { if (Graph.resize) Graph.resize(); Graph.load(); }
            else if (view === 'browser' && window.Browser && Browser.onShow) Browser.onShow();
            else if (view === 'search' && window.CodeSearch && CodeSearch.onShow) CodeSearch.onShow();
            else if (view === 'voice' && window.VoiceCtl) VoiceCtl.refreshTarget();
            else if (view === 'topology' && window.Topology) {
                // Kick the crew poll so a live (or finished) run renders right away.
                self.startCrewPoll();
                Topology.sync(self.crewBoard);
                if (window.crewBoard) Promise.resolve(crewBoard()).then(function (bd) {
                    if (bd && bd.subtasks) self.crewBoard = bd;
                    CrewPanes.sync(self.crewBoard); Topology.sync(self.crewBoard);
                }).catch(function () {});
            }
        }, 0);
    },
    wireFree: function (t, pane, rh) {
        var self = this;
        pane.addEventListener('mousedown', function () { self.bringFront(t, pane); });
        var head = pane.querySelector('.term-head');
        if (head) head.addEventListener('mousedown', function (e) {
            if (e.target.closest('.zoom, .x, .popback, .term-cd, .term-cd-edit, .badge')) return;   // let buttons work
            e.preventDefault();
            var sx = e.clientX, sy = e.clientY, ox = t.x, oy = t.y;
            // Divide client deltas by the canvas zoom so the pane tracks the cursor 1:1.
            function mv(ev) { var z = self.zoom || 1; t.x = ox + (ev.clientX - sx) / z; t.y = oy + (ev.clientY - sy) / z; pane.style.left = t.x + 'px'; pane.style.top = t.y + 'px'; }
            function up() { document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up); document.body.style.userSelect = ''; self.saveGeomSoon(); }
            document.body.style.userSelect = 'none';
            document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
        });
        rh.addEventListener('mousedown', function (e) {
            e.preventDefault(); e.stopPropagation();
            var sx = e.clientX, sy = e.clientY, ow = t.w, oh = t.h;
            function mv(ev) { var z = self.zoom || 1; t.w = Math.max(240, ow + (ev.clientX - sx) / z); t.h = Math.max(130, oh + (ev.clientY - sy) / z); pane.style.width = t.w + 'px'; pane.style.height = t.h + 'px'; }
            function up() { document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up); document.body.style.userSelect = ''; self.saveGeomSoon(); }
            document.body.style.userSelect = 'none';
            document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
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

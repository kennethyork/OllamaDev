#!/usr/bin/env bash
# Functional test of the tool LAYER: invoke offline tools via
# `ollamadev tool <name> '<json>'` in a real workspace and assert BOTH that they
# execute without crashing AND that they have the right effect (file written,
# content changed, commit created, etc.) — plus error/atomicity paths. This is
# the real safety net for the tools the agent calls. No Ollama required.
# Tools needing a model / network / built index are skipped (covered by eval).
#
# Usage: tests/tools.sh   (uses ../ollamadev, or $OLLAMADEV_BINARY)
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN="php ${OLLAMADEV_BINARY:-$ROOT/ollamadev}"
W="$(mktemp -d)"; trap 'rm -rf "$W"' EXIT; cd "$W" || exit 2

printf 'alpha\nbeta\ngamma\nbeta\n' > a.txt
printf 'name,age\nzoe,3\nann,7\n' > data.csv
printf '<?php\nfunction add($a,$b){ return $a+$b; }\n' > calc.php
mkdir -p sub; printf '{"a":1}\n' > sub/x.json
git init -q 2>/dev/null; git config user.email t@t; git config user.name t
git add -A 2>/dev/null; git commit -qm init 2>/dev/null
echo "hi" >> a.txt
printf '{"cells":[{"cell_type":"code","source":["print(1)"],"metadata":{},"outputs":[],"execution_count":null}],"metadata":{},"nbformat":4,"nbformat_minor":5}' > nb.ipynb

pass=0; fail=0; skip=0; fails=""
# Invoke a tool; assert no crash and (optional) expected substring in output.
t(){ # name | json | expect-substr | mode(ok|skip)
  local name="$1" json="$2" exp="${3:-}" mode="${4:-ok}"
  local out; out=$($BIN tool "$name" "$json" 2>&1)
  if [ "$mode" = skip ]; then skip=$((skip+1)); printf "  ~ %-16s skip\n" "$name"; return; fi
  local bad=0
  echo "$out" | grep -qiE "is not a valid tool|Fatal error|PHP (Warning|Notice|Parse|Fatal)|Uncaught" && bad=1
  [ -n "$exp" ] && ! echo "$out" | grep -qiF "$exp" && bad=1
  if [ "$bad" = 1 ]; then fail=$((fail+1)); fails="$fails $name"; printf "  \033[31m✗ %-16s\033[0m %s\n" "$name" "$(echo "$out"|head -1|cut -c1-58)";
  else pass=$((pass+1)); printf "  \033[32m✓ %-16s\033[0m %s\n" "$name" "$(echo "$out"|head -1|cut -c1-58)"; fi
}
# Assert a side effect (a shell test expression). Use after invoking a tool.
eff(){ # description | test-expression
  if eval "$2" >/dev/null 2>&1; then pass=$((pass+1)); printf "    \033[32m↳ %s\033[0m\n" "$1";
  else fail=$((fail+1)); fails="$fails [$1]"; printf "    \033[31m↳ ✗ %s\033[0m\n" "$1"; fi
}
inv(){ $BIN tool "$1" "$2" >/dev/null 2>&1; }  # invoke quietly

echo "— read/inspect (output asserted) —"
t view '{"file_path":"a.txt"}' "alpha";        t read '{"file_path":"a.txt"}' "alpha"
t cat '{"file_path":"a.txt"}' "alpha";          t head '{"file_path":"a.txt","n":2}' "alpha"
t tail '{"file_path":"a.txt","n":1}' "hi";       t stat '{"file_path":"a.txt"}'
t wc '{"file_path":"a.txt"}';                    t sort '{"file_path":"a.txt"}' "alpha"
t uniq '{"file_path":"a.txt"}'
echo "— list/search (output asserted) —"
t ls '{"path":"."}' "a.txt";                     t list_directory '{"path":"."}' "a.txt"
t list_files '{"path":"."}' "a.txt";             t glob '{"pattern":"*.php"}' "calc.php"
t grep '{"pattern":"beta","path":"a.txt"}' "beta"; t find '{"path":".","name":"*.php"}' "calc"
t tree '{"path":"."}' "a.txt";                   t changes '{"path":"."}'

echo "— write/edit (SIDE EFFECTS asserted) —"
inv write '{"file_path":"new.txt","content":"hello"}'
eff "write created the file with content" 'grep -qx hello new.txt'
inv write '{"file_path":"fenced.php","content":"```php\n<?php echo 1;\n```"}'
eff "write strips a stray enclosing fence" 'head -1 fenced.php | grep -q "<?php"'
inv write '{"file_path":"doc.md","content":"# T\n```\na\n```\nmid\n```\nb\n```\n"}'
fence_count=$(grep -Fc '```' doc.md)
eff "write keeps fences in a real multi-block markdown file" '[ "$fence_count" = 4 ]'
inv edit '{"file_path":"new.txt","old_string":"hello","new_string":"bye"}'
eff "edit replaced the content" 'grep -qx bye new.txt'
printf 'A\nB\nC\n' > me.txt
inv multi_edit '{"file_path":"me.txt","edits":[{"old_string":"A","new_string":"X"},{"old_string":"C","new_string":"Z"}]}'
eff "multi_edit applied ALL edits" 'grep -qx X me.txt && grep -qx Z me.txt && ! grep -qx A me.txt'
inv touch '{"path":"t2.txt"}';                   eff "touch created the file" '[ -f t2.txt ]'
inv mkdir '{"path":"d2"}';                       eff "mkdir created the directory" '[ -d d2 ]'
inv cp '{"src":"new.txt","dst":"copy.txt"}';     eff "cp produced a copy" '[ -f copy.txt ] && grep -qx bye copy.txt'
inv mv '{"src":"copy.txt","dst":"moved.txt"}';   eff "mv moved (src gone, dst present)" '[ ! -f copy.txt ] && [ -f moved.txt ]'
inv rm '{"path":"t2.txt"}';                      eff "rm deleted the file" '[ ! -f t2.txt ]'

echo "— atomicity & error paths —"
printf 'one\ntwo\n' > atom.txt
ao=$($BIN tool multi_edit '{"file_path":"atom.txt","edits":[{"old_string":"one","new_string":"1"},{"old_string":"NOPE","new_string":"x"}]}' 2>&1)
echo "$ao" | grep -qi "no edits applied" && { pass=$((pass+1)); printf "  \033[32m✓ multi_edit reports the miss\033[0m\n"; } || { fail=$((fail+1)); fails="$fails me-msg"; printf "  \033[31m✗ multi_edit reports the miss\033[0m\n"; }
eff "multi_edit is ATOMIC (no partial apply on a miss)" 'grep -qx one atom.txt && ! grep -qx 1 atom.txt'
eo=$($BIN tool edit '{"file_path":"me.txt","old_string":"DOESNOTEXIST","new_string":"y"}' 2>&1)
echo "$eo" | grep -qi "not found" && { pass=$((pass+1)); printf "  \033[32m✓ edit reports missing old_string\033[0m\n"; } || { fail=$((fail+1)); fails="$fails edit-miss"; printf "  \033[31m✗ edit reports missing old_string\033[0m\n"; }
t view '{"file_path":"does-not-exist.txt"}'   # must not crash (graceful error)

echo "— shell / background —"
t bash '{"command":"echo shelltest"}' "shelltest"; t execute_command '{"command":"echo ec"}' "ec"
t pwd '{}';                                       t calc '{"expr":"2+3*4"}' "14"
BGOUT=$($BIN tool bg '{"command":"echo bgline; sleep 1"}' 2>&1)
echo "$BGOUT" | grep -q "background job" && { pass=$((pass+1)); printf "  \033[32m✓ bg started a job\033[0m\n"; } || { fail=$((fail+1)); fails="$fails bg"; printf "  \033[31m✗ bg\033[0m\n"; }
BGID=$(echo "$BGOUT" | grep -oE 'bg_[a-f0-9]+' | head -1); sleep 1
t bash_output "{\"bg_id\":\"$BGID\"}" "bgline"
t wait_bg "{\"bg_id\":\"$BGID\",\"seconds\":3}" "finished"; t kill_bash "{\"bg_id\":\"$BGID\"}"

echo "— code intelligence —"
t diagnostics '{"file_path":"calc.php"}';        t hover '{"file_path":"calc.php","line":2,"col":10}'
t symbols '{"file_path":"calc.php"}';            t find_refs '{"file_path":"calc.php","pattern":"add"}' "add"
t format '{"file_path":"calc.php"}'

echo "— git (SIDE EFFECTS asserted) —"
t git_status '{"path":"."}';                     t git_diff '{"path":"."}'
t git_log '{"path":".","n":3}' "init";           t git_branch '{"path":"."}'
inv write '{"file_path":"committed.txt","content":"x"}'; inv git_add '{"all":true}'
inv git_commit '{"message":"tool-made commit"}'
eff "git_commit created the commit" 'git log --oneline | grep -q "tool-made commit"'
inv git_checkout '{"branch":"feature-x","new":true}'
eff "git_checkout -b switched branch" 'git branch --show-current | grep -qx feature-x'
echo "dirty" >> committed.txt; inv git_stash '{}'
eff "git_stash cleaned the working tree" '[ -z "$(git status --porcelain)" ]'
t git_show '{"ref":"HEAD"}' "commit"

echo "— memory / todo / notebook (SIDE EFFECTS asserted) —"
t remember '{"title":"t","content":"a fact"}' "Saved"
t recall '{}'
inv todo_write '{"todos":[{"content":"alpha-todo","status":"completed"}]}'
eff "todo_write persisted the list (project-local)" 'grep -rq alpha-todo .ollamadev/todos/ 2>/dev/null'
inv notebook_edit '{"notebook_path":"nb.ipynb","cell_number":0,"new_source":"print(2)"}'
eff "notebook_edit changed the cell source" 'grep -q "print(2)" nb.ipynb'

echo "— output / meta —"
t print '{"text":"p"}' "p";                      t echo '{"text":"e"}' "e"
t reply '{"text":"r"}' "r";                      t ok '{}' "ok"
t permission '{"action":"status"}';              t mcp_servers '{}'

echo "— need model / network / index (skipped here; covered by eval) —"
t code_search '{"query":"x"}' "" skip;  t run_tests '{}' "" skip
t search '{"query":"x"}' "" skip;       t fetch '{"url":"http://x"}' "" skip
t task '{"prompt":"x"}' "" skip;        t skill '{"name":"x"}' "" skip

echo
echo "tools: $pass passed, $fail failed, $skip skipped"
[ "$fail" -eq 0 ] || { echo "FAILED:$fails"; exit 1; }
echo "ALL TOOL TESTS PASSED"

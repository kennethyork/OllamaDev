// EVAL — a fixed task suite that measures whether the agent actually completes
// small, well-defined coding tasks with the current model. Turns "it works" into
// a number: pass rate over N tasks. Each task runs in an isolated temp dir with
// auto-permission, then a deterministic check (file content or a command's exit
// code/output) decides pass/fail. Vanilla PHP, fully local — it just drives the
// same agent the CLI uses and inspects the result.
class Evals {
    // Built-in suite: small, deterministic, model-agnostic tasks. Checks use the
    // running PHP binary so they work anywhere OllamaDev runs (no extra runtimes).
    public static function builtins(): array {
        return [
            [
                'name' => 'create-file',
                'prompt' => 'Create a file named greeting.txt containing exactly this text and nothing else: hello world',
                'check' => ['type' => 'file_contains', 'path' => 'greeting.txt', 'needle' => 'hello world'],
            ],
            [
                'name' => 'edit-json',
                'files' => ['settings.json' => "{\n  \"debug\": false,\n  \"name\": \"demo\"\n}\n"],
                'prompt' => 'In settings.json, change the value of "debug" from false to true. Leave everything else unchanged.',
                'check' => ['type' => 'file_contains', 'path' => 'settings.json', 'needle' => '"debug":true', 'normalize' => true],
            ],
            [
                'name' => 'fix-bug',
                'files' => ['calc.php' => "<?php\nfunction add(\$a, \$b) { return \$a - \$b; }\n"],
                'prompt' => 'calc.php has a bug: add() subtracts instead of adding. Fix add() so it returns the sum of its two arguments.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "calc.php"; echo add(2,3);\'', 'expect' => '5'],
            ],
            [
                'name' => 'new-function',
                'prompt' => 'Create a PHP file named strutil.php that defines a function slugify(string $s): string returning the input lowercased with spaces replaced by single hyphens. The file must not print anything when included.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "strutil.php"; echo slugify("Hello World");\'', 'expect' => 'hello-world'],
            ],
            [
                'name' => 'edit-two',
                'files' => ['conf.ini' => "host=localhost\nport=3000\ndebug=off\n"],
                'prompt' => 'In conf.ini, change port to 8080 and change debug to on. Leave host unchanged.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'$c=file_get_contents("conf.ini"); echo (strpos($c,"port=8080")!==false && strpos($c,"debug=on")!==false)?"OK":"NO";\'', 'expect' => 'OK'],
            ],
            [
                'name' => 'make-app',
                'prompt' => 'Create a tiny static web page: index.html with an <h1>Hello</h1> that links a stylesheet style.css, and style.css that sets the body background to black. Two files.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'echo (is_file("index.html") && is_file("style.css") && stripos(file_get_contents("index.html"),"style.css")!==false)?"OK":"NO";\'', 'expect' => 'OK'],
            ],
            // ---- algorithms / functions ----
            [
                'name' => 'fizzbuzz',
                'prompt' => 'Create fizzbuzz.php defining function fizzbuzz(int $n): string — return "FizzBuzz" if n is divisible by 15, "Fizz" if by 3, "Buzz" if by 5, otherwise the number as a string. It must not print anything when included.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "fizzbuzz.php"; echo fizzbuzz(15).",".fizzbuzz(3).",".fizzbuzz(5).",".fizzbuzz(7);\'', 'expect' => 'FizzBuzz,Fizz,Buzz,7'],
            ],
            [
                'name' => 'factorial',
                'prompt' => 'Create fact.php defining function factorial(int $n): int (with 0! = 1). No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "fact.php"; echo factorial(5);\'', 'expect' => '120'],
            ],
            [
                'name' => 'palindrome',
                'prompt' => 'Create pal.php defining function isPalindrome(string $s): bool — true if the string reads the same backward, case-insensitive. No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "pal.php"; echo (isPalindrome("Racecar") && !isPalindrome("hello"))?"OK":"NO";\'', 'expect' => 'OK'],
            ],
            [
                'name' => 'word-count',
                'prompt' => 'Create wc.php defining function wordCount(string $s): int — the number of whitespace-separated words. No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "wc.php"; echo wordCount("the quick brown fox");\'', 'expect' => '4'],
            ],
            [
                'name' => 'celsius',
                'prompt' => 'Create temp.php defining function cToF(float $c): float converting Celsius to Fahrenheit. No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "temp.php"; echo cToF(100);\'', 'expect' => '212'],
            ],
            [
                'name' => 'dedup',
                'prompt' => 'Create dedup.php defining function dedup(array $a): array — remove duplicate values, preserve first-occurrence order, and reindex from 0. No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "dedup.php"; echo json_encode(dedup([1,2,2,3,1]));\'', 'expect' => '[1,2,3]'],
            ],
            // ---- parsing / data ----
            [
                'name' => 'env-parser',
                'prompt' => 'Create env.php defining function parseEnv(string $s): array — parse lines of KEY=VALUE into an associative array, skipping blank lines. No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "env.php"; $e=parseEnv("A=1\nB=2"); echo $e["B"] ?? "missing";\'', 'expect' => '2'],
            ],
            [
                'name' => 'stack-class',
                'prompt' => 'Create Stack.php defining a class Stack with push($v) and pop() methods behaving as a LIFO stack (pop returns the most recently pushed value). No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "Stack.php"; $s=new Stack(); $s->push(1); $s->push(2); echo $s->pop();\'', 'expect' => '2'],
            ],
            // ---- multi-file ----
            [
                'name' => 'module',
                'prompt' => 'Create lib/greet.php defining function greet(string $name): string returning "Hello, " followed by the name, and main.php that includes lib/greet.php and echoes greet("World").',
                'check' => ['type' => 'command', 'cmd' => '{php} main.php', 'expect' => 'Hello, World'],
            ],
            // ---- bug fixes (seeded) ----
            [
                'name' => 'fix-loop',
                'files' => ['sumto.php' => "<?php\nfunction sumTo(\$n) { \$s = 0; for (\$i = 1; \$i < \$n; \$i++) { \$s += \$i; } return \$s; }\n"],
                'prompt' => 'sumTo($n) in sumto.php is off by one — it should return the sum of 1..n inclusive but currently excludes n. Fix it.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "sumto.php"; echo sumTo(5);\'', 'expect' => '15'],
            ],
            [
                'name' => 'fix-syntax',
                'files' => ['broken.php' => "<?php\nfunction f() {\n    return 1\n}\n"],
                'prompt' => 'broken.php has a PHP syntax error. Fix it so the file parses cleanly. Do not change what f() returns.',
                'check' => ['type' => 'command', 'cmd' => '{php} -l broken.php', 'expect' => 'No syntax errors'],
            ],
            [
                'name' => 'fix-return',
                'files' => ['mul.php' => "<?php\nfunction multiply(\$a, \$b) { return \$a + \$b; }\n"],
                'prompt' => 'multiply() in mul.php adds instead of multiplying. Fix it to return the product.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "mul.php"; echo multiply(6,7);\'', 'expect' => '42'],
            ],
            // ---- files / docs / config ----
            [
                'name' => 'gitignore',
                'prompt' => 'Create a .gitignore file that ignores the node_modules directory and all .log files.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'$g=@file_get_contents(".gitignore"); echo ($g && strpos($g,"node_modules")!==false && strpos($g,".log")!==false)?"OK":"NO";\'', 'expect' => 'OK'],
            ],
            [
                'name' => 'readme',
                'prompt' => 'Create README.md with a level-1 heading for the project title (a line starting with "# ") and a section titled "## Usage".',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'$r=@file_get_contents("README.md"); echo ($r && preg_match("/^# /m",$r) && stripos($r,"## Usage")!==false)?"OK":"NO";\'', 'expect' => 'OK'],
            ],
            // ---- harder: bigger algorithms, stateful classes, multi-file, multi-bug ----
            [
                'name' => 'binary-search',
                'prompt' => 'Create bsearch.php defining function bsearch(array $sorted, int $target): int — return the index of $target in the ascending-sorted array using binary search, or -1 if absent. No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "bsearch.php"; echo bsearch([1,3,5,7,9,11],7).",".bsearch([1,3,5,7,9,11],4);\'', 'expect' => '3,-1'],
            ],
            [
                'name' => 'bank-class',
                'prompt' => 'Create Account.php defining a class Account that starts with balance 0 and has methods deposit(int $n), withdraw(int $n) which must NOT let the balance go negative (ignore an over-withdraw), and balance(): int. No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "Account.php"; $a=new Account(); $a->deposit(100); $a->withdraw(30); $a->withdraw(1000); echo $a->balance();\'', 'expect' => '70'],
            ],
            [
                'name' => 'csv-parse',
                'prompt' => 'Create csv.php defining function parseCsv(string $s): array — split the text into rows by newline and each row into fields by comma, skipping blank lines. Returns an array of arrays. No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "csv.php"; $r=parseCsv("a,b,c\n1,2,3"); echo $r[1][2];\'', 'expect' => '3'],
            ],
            [
                'name' => 'fib-memo',
                'prompt' => 'Create fib.php defining function fib(int $n): int returning the nth Fibonacci number (fib(0)=0, fib(1)=1). It must compute fib(30) fast — use iteration or memoization, not naive recursion. No output on include.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "fib.php"; echo fib(30);\'', 'expect' => '832040'],
            ],
            [
                'name' => 'refactor-extract',
                'files' => ['app.php' => "<?php\nfunction greet(\$n) { return 'Hi ' . \$n; }\necho greet('A');\n"],
                'prompt' => 'Refactor app.php: MOVE the greet() function out into a new file helpers.php, and make app.php require/include helpers.php instead of defining greet itself. Running app.php must still print "Hi A".',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'$a=(string)@file_get_contents("app.php"); $h=(string)@file_get_contents("helpers.php"); echo (strpos($h,"function greet")!==false && strpos($a,"function greet")===false && strpos($a,"helpers.php")!==false)?"OK":"NO";\'', 'expect' => 'OK'],
            ],
            [
                'name' => 'fix-two-bugs',
                'files' => ['shape.php' => "<?php\nfunction area(\$w, \$h) { return \$w + \$h; }\nfunction perimeter(\$w, \$h) { return \$w * \$h; }\n"],
                'prompt' => 'shape.php has TWO bugs: area() should return width*height (it adds), and perimeter() should return 2*(width+height) (it multiplies). Fix both functions.',
                'check' => ['type' => 'command', 'cmd' => '{php} -r \'include "shape.php"; echo area(3,4).",".perimeter(3,4);\'', 'expect' => '12,14'],
            ],
        ];
    }

    // Directories holding optional user-authored task JSON files.
    public static function userDirs(): array {
        return [getcwd() . '/evals', Config::dataDir() . '/evals'];
    }

    // Load the suite: built-ins plus any *.json task files from user dirs.
    public static function suite(?string $only = null): array {
        $tasks = self::builtins();
        foreach (self::userDirs() as $dir) {
            if (!is_dir($dir)) continue;
            foreach (glob($dir . '/*.json') as $f) {
                $j = json_decode((string)@file_get_contents($f), true);
                if (!is_array($j)) continue;
                // Accept either a single task object or an array of tasks.
                foreach (isset($j['name']) ? [$j] : $j as $t) {
                    if (is_array($t) && !empty($t['name']) && !empty($t['prompt']) && !empty($t['check'])) $tasks[] = $t;
                }
            }
        }
        if ($only !== null && $only !== '') {
            $tasks = array_values(array_filter($tasks, fn($t) => $t['name'] === $only));
        }
        return $tasks;
    }

    // Run one task in isolation; returns ['name','pass','ms','detail'].
    public static function runOne(array $task, array $config, string $idSuffix): array {
        $origCwd = getcwd();
        $origMode = Permission::getMode();
        $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/ollamadev-eval-' . getmypid() . '-' . $idSuffix;
        self::rmrf($tmp);
        @mkdir($tmp, 0777, true);
        // Seed files.
        foreach (($task['files'] ?? []) as $rel => $content) {
            $p = $tmp . '/' . ltrim((string)$rel, '/');
            @mkdir(dirname($p), 0777, true);
            @file_put_contents($p, (string)$content);
        }

        $pass = false; $detail = '';
        $t0 = microtime(true);
        if (@chdir($tmp)) {
            Permission::autoAllow();
            Permission::setInteractive(false);
            $GLOBALS['editedFiles'] = [];
            $verbose = (bool)getenv('ODV_EVAL_VERBOSE');
            try {
                if (!$verbose) ob_start();
                (new Session($config))->runSingle((string)$task['prompt']);
                if (!$verbose) ob_end_clean();
            } catch (\Throwable $e) {
                if (!$verbose && ob_get_level() > 0) ob_end_clean();
                $detail = 'error: ' . $e->getMessage();
            }
            [$pass, $cd] = self::check($task['check'] ?? [], $tmp);
            if ($detail === '') $detail = $cd;
            chdir($origCwd);
        } else {
            $detail = 'could not enter temp dir';
        }
        Permission::setMode($origMode);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (empty($config['_eval_keep'])) self::rmrf($tmp);
        elseif ($pass === false) $detail .= "  (kept: $tmp)";
        return ['name' => $task['name'], 'pass' => $pass, 'ms' => $ms, 'detail' => $detail];
    }

    // Deterministic checks. Returns [bool pass, string detail].
    private static function check(array $check, string $dir): array {
        $type = $check['type'] ?? '';
        if ($type === 'file_exists') {
            $p = $dir . '/' . ltrim((string)($check['path'] ?? ''), '/');
            return [is_file($p), is_file($p) ? 'file present' : 'file missing: ' . ($check['path'] ?? '')];
        }
        if ($type === 'file_contains') {
            $p = $dir . '/' . ltrim((string)($check['path'] ?? ''), '/');
            if (!is_file($p)) return [false, 'file missing: ' . ($check['path'] ?? '')];
            $hay = (string)file_get_contents($p);
            $needle = (string)($check['needle'] ?? '');
            if (!empty($check['normalize'])) { $hay = preg_replace('/\s+/', '', $hay); $needle = preg_replace('/\s+/', '', $needle); }
            $ok = $needle !== '' && str_contains($hay, $needle);
            return [$ok, $ok ? 'content matched' : 'needle not found: ' . ($check['needle'] ?? '')];
        }
        if ($type === 'command') {
            $cmd = str_replace('{php}', escapeshellarg(PHP_BINARY), (string)($check['cmd'] ?? ''));
            $out = []; $code = 0;
            exec($cmd . ' 2>&1', $out, $code);
            $output = trim(implode("\n", $out));
            $expect = (string)($check['expect'] ?? '');
            $ok = $code === 0 && ($expect === '' || str_contains($output, $expect));
            return [$ok, $ok ? 'command ok' : "exit $code" . ($expect !== '' ? ", wanted \"$expect\", got \"" . substr($output, 0, 80) . "\"" : '')];
        }
        return [false, 'unknown check type'];
    }

    private static function rmrf(string $path): void {
        if (!file_exists($path)) return;
        if (is_file($path) || is_link($path)) { @unlink($path); return; }
        foreach (scandir($path) ?: [] as $e) { if ($e === '.' || $e === '..') continue; self::rmrf($path . '/' . $e); }
        @rmdir($path);
    }
}

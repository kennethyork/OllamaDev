// CREW TEAM SKILLS — starter skill packs auto-loaded per specialization.
// A domain team passes a --focus describing its stack/priorities. We match that
// text against these starter skills' trigger keywords and materialize the matches
// into each coder's git worktree (<wt>/.ollamadev/skills/<name>/SKILL.md, which is
// git-excluded). The coder agent — already chdir'd into the worktree — then
// discovers them like any project skill and loads each on demand via the `skill`
// tool. Starters live in the binary; a user skill of the same name in
// ~/.ollamadev/skills wins (we never clobber a customized one).
class CrewSkills {
    // name => ['triggers' => [...], 'description' => '...', 'body' => "..."]
    private static function library(): array {
        return [
            'responsive-design' => [
                'triggers' => ['responsive', 'website', 'landing page', 'web app', 'pwa', 'frontend', 'spa', 'dashboard'],
                'description' => 'Build layouts that work on phone, tablet, and desktop.',
                'body' => "# responsive-design\n\n- Design mobile-first; add complexity at larger breakpoints, not the reverse.\n- Use fluid units (%, rem, clamp(), min/max) and CSS grid/flex over fixed pixel widths.\n- Test at ~360px, ~768px, and ~1280px. Nothing should overflow horizontally.\n- Make tap targets >= 44px; never hide essential actions behind hover on touch.\n- Use responsive images (srcset/sizes) and lazy-load below the fold.\n",
            ],
            'semantic-html' => [
                'triggers' => ['website', 'landing page', 'blog', 'cms', 'semantic markup', 'docs site', 'forum'],
                'description' => 'Use correct, accessible HTML structure.',
                'body' => "# semantic-html\n\n- Use landmarks: <header>, <nav>, <main>, <article>, <footer> — one <main> per page.\n- Exactly one <h1>; don't skip heading levels.\n- Real <button>/<a> for actions/links — never a clickable <div>.\n- Label every form control (<label for>) and associate errors with aria-describedby.\n- Add alt text to meaningful images; alt=\"\" for decorative ones.\n",
            ],
            'seo-meta' => [
                'triggers' => ['seo', 'website', 'landing page', 'marketing', 'blog', 'cms', 'docs'],
                'description' => 'Make pages discoverable and shareable.',
                'body' => "# seo-meta\n\n- Unique <title> (<=60 chars) and <meta name=description> (<=155 chars) per page.\n- Open Graph + Twitter card tags for link previews; a canonical <link>.\n- Semantic headings, descriptive link text, and a sitemap.xml + robots.txt.\n- Server-render or pre-render content that must be indexed.\n- Add JSON-LD structured data where it fits (Article, Product, FAQ).\n",
            ],
            'web-accessibility' => [
                'triggers' => ['accessibility', 'a11y', 'website', 'web app', 'dashboard', 'forms', 'landing page'],
                'description' => 'Meet WCAG basics so everyone can use it.',
                'body' => "# web-accessibility\n\n- Keyboard: every interactive element is reachable and operable with Tab/Enter/Space; visible focus ring.\n- Color contrast >= 4.5:1 for text; never use color as the only signal.\n- Use ARIA only to fill gaps native HTML can't — prefer native elements first.\n- Respect prefers-reduced-motion; don't autoplay motion/sound.\n- Test with a screen reader path for the primary flow.\n",
            ],
            'pwa' => [
                'triggers' => ['pwa', 'progressive web', 'offline', 'service worker', 'installable', 'manifest'],
                'description' => 'Make a Progressive Web App installable and offline-capable.',
                'body' => "# pwa\n\n- Ship a web app manifest (name, icons 192/512, start_url, display) and link it from the page.\n- Register a service worker; precache the app shell, runtime-cache data with a clear strategy (stale-while-revalidate / network-first).\n- Handle offline: a fallback page and graceful degradation when fetch fails.\n- Version the cache and clean old caches on activate; don't cache POST/auth responses.\n- Test install + offline in a fresh profile; serve over HTTPS (or localhost).\n",
            ],
            'observability' => [
                'triggers' => ['observability', 'logging', 'metrics', 'tracing', 'monitoring', 'microservice', 'serverless', 'devops', 'infra'],
                'description' => 'Make the system debuggable in production.',
                'body' => "# observability\n\n- Structured logs (JSON) with a correlation/request id threaded through calls; never log secrets/PII.\n- Emit metrics for the golden signals: latency, traffic, errors, saturation.\n- Add health/readiness endpoints and meaningful startup/shutdown logs.\n- Propagate trace context across service boundaries; record spans for slow paths.\n- Make the log level configurable; fail loud on misconfig, not silently.\n",
            ],
            'frontend-state' => [
                'triggers' => ['web app', 'spa', 'react', 'vue', 'svelte', 'angular', 'state', 'components'],
                'description' => 'Structure components and state predictably.',
                'body' => "# frontend-state\n\n- Keep state minimal and derive the rest; lift shared state to the nearest common ancestor.\n- Separate server cache (fetched data) from UI state; don't duplicate the source of truth.\n- Handle loading / empty / error states explicitly for every async view.\n- Memoize expensive renders; give list items stable keys (not the index).\n- Clean up subscriptions/timers on unmount.\n",
            ],
            'rest-api-design' => [
                'triggers' => ['rest', 'api', 'backend', 'microservice', 'crud', 'saas'],
                'description' => 'Design consistent, robust HTTP endpoints.',
                'body' => "# rest-api-design\n\n- Noun resources, plural paths; HTTP verbs for actions (GET/POST/PUT/PATCH/DELETE).\n- Correct status codes: 400 validation, 401 auth, 403 forbidden, 404 missing, 409 conflict, 422 semantic.\n- Validate and sanitize every input at the boundary; never trust the client.\n- Consistent error shape ({error, message, details}); paginate list endpoints.\n- Be idempotent where the verb implies it; version the API.\n",
            ],
            'graphql-schema' => [
                'triggers' => ['graphql', 'resolver', 'schema'],
                'description' => 'Keep the schema clean and avoid N+1.',
                'body' => "# graphql-schema\n\n- Design the schema around the client's needs; non-null where truly guaranteed.\n- Batch/dataloader to kill N+1 resolver queries.\n- Paginate connections (cursor-based) instead of returning unbounded lists.\n- Enforce auth in resolvers/field guards, not just at the gateway.\n- Limit query depth/complexity to prevent abuse.\n",
            ],
            'auth-security' => [
                'triggers' => ['auth', 'login', 'saas', 'jwt', 'session', 'authn', 'authz', 'multi-tenant', 'oauth'],
                'description' => 'Implement authentication and authorization safely.',
                'body' => "# auth-security\n\n- Hash passwords with bcrypt/argon2 — never store or log plaintext.\n- Authorize every request server-side; check the resource owner, not just \"is logged in\".\n- In multi-tenant systems scope EVERY query by tenant id; deny by default.\n- Short-lived access tokens + rotation; set HttpOnly + Secure + SameSite cookies.\n- Rate-limit auth endpoints; protect state-changing requests from CSRF.\n",
            ],
            'payments-money' => [
                'triggers' => ['e-commerce', 'ecommerce', 'payment', 'billing', 'subscription', 'checkout', 'money', 'tax', 'saas', 'orders', 'cart'],
                'description' => 'Handle money, tax, and payments correctly.',
                'body' => "# payments-money\n\n- Store money as integer minor units (cents) or decimal — NEVER float. Track currency explicitly.\n- Compute totals/tax server-side from trusted prices; never trust client-sent amounts.\n- Make charge/webhook handling idempotent (dedupe by event id) — webhooks retry.\n- Verify payment provider webhook signatures; reconcile order state to provider state.\n- Guard inventory against oversell with atomic decrements/transactions.\n",
            ],
            'db-schema' => [
                'triggers' => ['database', 'schema', 'migration', 'sql', 'query', 'index', 'postgres', 'mysql'],
                'description' => 'Design schemas and write safe migrations.',
                'body' => "# db-schema\n\n- Normalize first; denormalize only with a measured reason.\n- Enforce integrity in the DB: foreign keys, NOT NULL, unique, check constraints.\n- Index the columns you filter/join/sort on; watch for missing FK indexes.\n- Migrations must be reversible and safe on live data — add columns nullable/with default, backfill, then constrain.\n- Always parameterize queries; never string-concat user input.\n",
            ],
            'etl-pipeline' => [
                'triggers' => ['etl', 'data pipeline', 'idempot', 'ingestion', 'batch job'],
                'description' => 'Build pipelines that recover from partial failure.',
                'body' => "# etl-pipeline\n\n- Make every stage idempotent so a re-run can't double-write.\n- Validate the schema/shape of incoming data; quarantine bad records, don't crash the batch.\n- Checkpoint progress so a failed run resumes instead of restarting.\n- Make transforms deterministic and unit-testable on fixture data.\n- Log row counts in/out per stage to catch silent drops.\n",
            ],
            'realtime-ws' => [
                'triggers' => ['realtime', 'websocket', 'sse', 'socket', 'reconnection', 'rooms', 'channels'],
                'description' => 'Get connection lifecycle and reconnection right.',
                'body' => "# realtime-ws\n\n- Handle the full lifecycle: connect, heartbeat/ping, disconnect, reconnect with backoff.\n- Authenticate on connect and re-check authz per message/room join.\n- Apply backpressure — drop/coalesce when a client can't keep up.\n- Make message handlers idempotent; clients may resend after reconnect.\n- Clean up room membership and timers on disconnect to avoid leaks.\n",
            ],
            'serverless' => [
                'triggers' => ['serverless', 'lambda', 'cloud function', 'workers', 'cold start'],
                'description' => 'Write stateless, fast-starting functions.',
                'body' => "# serverless\n\n- Stay stateless — persist to a store, never to local disk/memory between invocations.\n- Minimize cold starts: lean dependencies, init reusable clients outside the handler.\n- Read config/secrets from env or a secrets manager; least-privilege IAM per function.\n- Set sane timeouts and handle partial/duplicate event delivery idempotently.\n- Return structured errors; don't leak stack traces to callers.\n",
            ],
            'cli-ux' => [
                'triggers' => ['cli', 'command-line', 'command line', 'args', 'flags', 'exit code', 'terminal tool'],
                'description' => 'Make a command-line tool pleasant and scriptable.',
                'body' => "# cli-ux\n\n- Provide --help and a clear usage line; support both short and long flags.\n- Exit 0 on success, non-zero on failure; errors to stderr, data to stdout.\n- Make output greppable; offer --json for machine consumption.\n- Validate args early with actionable messages; never hang waiting silently.\n- Be quiet by default, verbose with -v; confirm destructive actions unless --force.\n",
            ],
            'library-api' => [
                'triggers' => ['library', 'sdk', 'package', 'public api', 'semantic version'],
                'description' => 'Ship a clean, stable public API.',
                'body' => "# library-api\n\n- Keep the public surface small and intentional; don't leak internals.\n- Follow semantic versioning; treat any public change as a contract change.\n- Document every exported symbol with an example.\n- Fail loudly on misuse with clear errors; validate inputs at the boundary.\n- No global mutable state or side effects on import.\n",
            ],
            'testing-discipline' => [
                'triggers' => ['test', 'tests', 'tdd', 'qa', 'unit test'],
                'description' => 'Write tests that actually catch regressions.',
                'body' => "# testing-discipline\n\n- Test behavior and edge cases (empty, boundary, error), not just the happy path.\n- One logical assertion per test; name tests by what they prove.\n- Keep tests deterministic — no real network/clock/random; inject or mock them.\n- Add a failing test that reproduces a bug BEFORE fixing it.\n- Run the suite and confirm it passes before declaring done.\n",
            ],
            'security-hardening' => [
                'triggers' => ['security', 'hardening', 'injection', 'secrets', 'vulnerability', 'csrf', 'xss', 'sql injection'],
                'description' => 'Eliminate the common vulnerability classes.',
                'body' => "# security-hardening\n\n- Validate/escape all input; parameterize SQL; escape output to prevent XSS.\n- Never commit secrets — use env/secret stores; scan the diff for keys/tokens before committing.\n- Enforce authz on every sensitive action; deny by default.\n- Avoid shelling out with user input; if unavoidable, use an arg array, never string interpolation.\n- Keep dependencies updated; avoid known-vulnerable versions.\n",
            ],
            'devops-iac' => [
                'triggers' => ['devops', 'infra', 'docker', 'ci/cd', 'terraform', 'iac', 'deploy', 'kubernetes'],
                'description' => 'Write safe, idempotent infrastructure & pipelines.',
                'body' => "# devops-iac\n\n- Make everything idempotent and declarative; the same apply twice = no change.\n- Never hard-code secrets — inject from a secret store; least-privilege everywhere.\n- Pin versions/images; small, cache-friendly, fail-fast pipeline stages.\n- Make changes reversible (plan/diff before apply); guard production behind review.\n- Add health checks and surface logs/metrics.\n",
            ],
            'llm-app' => [
                'triggers' => ['llm', 'ai app', 'prompt', 'agent loop', 'token', 'streaming', 'embedding', 'rag'],
                'description' => 'Build reliable LLM-powered features.',
                'body' => "# llm-app\n\n- Budget tokens explicitly; truncate/summarize context before you hit the limit.\n- Stream responses for UX; handle partial output and mid-stream errors.\n- Validate/parse model output defensively — assume it can be malformed (use schema/JSON mode).\n- Cache deterministic calls; add retries with backoff for transient failures.\n- Never trust model output in privileged actions without a guard/confirmation.\n",
            ],
            'smart-contract' => [
                'triggers' => ['solidity', 'smart contract', 'web3', 'dapp', 'reentrancy', 'gas'],
                'description' => 'Write secure on-chain code.',
                'body' => "# smart-contract\n\n- Checks-effects-interactions order; guard against reentrancy.\n- Use safe math / a vetted library; watch for overflow and rounding.\n- Minimize and audit external calls; never trust their return blindly.\n- Restrict privileged functions with access control; emit events for state changes.\n- Write exhaustive tests including adversarial cases before deploy.\n",
            ],
            'game-loop' => [
                'triggers' => ['game', 'unity', 'godot', 'phaser', 'game loop'],
                'description' => 'Keep the game loop smooth and inputs responsive.',
                'body' => "# game-loop\n\n- Separate update (fixed timestep) from render; scale movement by delta time.\n- Pool/reuse objects instead of allocating each frame; avoid GC spikes.\n- Keep per-frame work bounded; profile before optimizing.\n- Handle input in the loop, not via blocking calls.\n- Load assets async; never stall the loop on I/O.\n",
            ],
            'data-ml' => [
                'triggers' => ['ml', 'data/ml', 'pandas', 'numpy', 'torch', 'scikit', 'notebook', 'machine learning'],
                'description' => 'Make data/ML work reproducible.',
                'body' => "# data-ml\n\n- Set and record random seeds; pin library versions for reproducibility.\n- Validate data (shapes, nulls, ranges) before training; never leak test into train.\n- Keep preprocessing in code, not manual notebook steps; make scripts re-runnable.\n- Track metrics and the params that produced them.\n- Separate data loading, transform, model, and eval into testable pieces.\n",
            ],
            'mobile-app' => [
                'triggers' => ['mobile app', 'ios', 'android', 'react native', 'flutter'],
                'description' => 'Respect platform lifecycle and UX guidelines.',
                'body' => "# mobile-app\n\n- Handle the app lifecycle (background/foreground, low memory) and save state.\n- Keep the main/UI thread free; do I/O and heavy work off it.\n- Follow each platform's navigation and UX conventions.\n- Handle offline and flaky networks gracefully; cache and retry.\n- Request permissions just-in-time with clear rationale.\n",
            ],
            'desktop-app' => [
                'triggers' => ['desktop app', 'electron', 'tauri', 'qt', 'gtk'],
                'description' => 'Mind windowing, packaging, and OS integration.',
                'body' => "# desktop-app\n\n- Keep heavy work off the UI thread/process; keep the window responsive.\n- Sandbox/limit privileges of any web/renderer layer; validate IPC messages.\n- Handle multi-window, focus, and OS lifecycle (sleep/quit) cleanly.\n- Persist user data in the OS-appropriate location; clean up child processes on exit.\n- Test packaging on the target OS.\n",
            ],
            'browser-extension' => [
                'triggers' => ['browser extension', 'chrome extension', 'manifest v3', 'content script', 'background script'],
                'description' => 'Follow Manifest V3 and least privilege.',
                'body' => "# browser-extension\n\n- Request the minimum permissions and host_permissions; justify each.\n- Keep the service worker lean and event-driven (MV3 has no persistent background).\n- Validate messages between content/background scripts; never trust page content.\n- Don't inject more into pages than needed; clean up on disable.\n- Avoid remote code; bundle everything.\n",
            ],
            'bot-platform' => [
                'triggers' => ['bot', 'discord', 'slack', 'telegram'],
                'description' => 'Build a chat bot that respects platform limits.',
                'body' => "# bot-platform\n\n- Keep the platform token secret (env, not code); rotate if leaked.\n- Respect rate limits — queue/backoff instead of hammering the API.\n- Acknowledge events fast; do slow work async to avoid timeouts.\n- Validate and authorize commands; sanitize anything echoed back.\n- Handle reconnects and missed events idempotently.\n",
            ],
            'embedded' => [
                'triggers' => ['embedded', 'iot', 'firmware', 'microcontroller', 'interrupt', 'micropython'],
                'description' => 'Respect memory, timing, and hardware constraints.',
                'body' => "# embedded\n\n- Avoid dynamic allocation in hot/interrupt paths; bound all buffers.\n- Keep ISRs tiny — set a flag, defer work to the main loop.\n- Mind timing and watchdogs; don't block on I/O.\n- Validate hardware register access; handle peripheral failure.\n- Be explicit about integer widths and endianness.\n",
            ],
            'refactor-safety' => [
                'triggers' => ['refactor', 'restructure', 'clean up', 'tech debt'],
                'description' => 'Change structure without changing behavior.',
                'body' => "# refactor-safety\n\n- Ensure tests cover the behavior BEFORE refactoring; add them if missing.\n- Make small, reversible steps; keep it green between each.\n- Don't mix refactor + behavior change in one commit.\n- Preserve the public interface unless the task is to change it.\n- Re-run the suite after each step to prove behavior is unchanged.\n",
            ],
            'docs-writing' => [
                'triggers' => ['docs', 'documentation', 'readme', 'docusaurus', 'mkdocs', 'docs site'],
                'description' => 'Write docs people can actually follow.',
                'body' => "# docs-writing\n\n- Lead with what it does and a copy-paste quickstart that works.\n- Show runnable examples; keep them tested/accurate.\n- Document the why and the gotchas, not just the API surface.\n- Use clear headings and consistent terminology; link related pages.\n- Keep docs next to the code and update them with the change.\n",
            ],
        ];
    }

    // Starter skills whose triggers appear in the focus text (most-specific first, capped).
    public static function forFocus(string $focus, int $cap = 5): array {
        $f = strtolower(trim($focus));
        if ($f === '') return [];
        $hits = [];
        foreach (self::library() as $name => $s) {
            $best = 0;
            foreach ($s['triggers'] as $t) {
                if (strpos($f, strtolower($t)) !== false) $best = max($best, strlen($t));
            }
            if ($best > 0) $hits[$name] = ['name' => $name, 'description' => $s['description'], 'body' => $s['body'], 'match' => $best];
        }
        uasort($hits, fn($a, $b) => $b['match'] <=> $a['match']);
        return array_slice(array_values($hits), 0, max(0, $cap));
    }

    // Write skills into <baseDir>/.ollamadev/skills/<name>/SKILL.md.
    // Skips any skill the user already defines globally (don't clobber a custom one)
    // or that already exists at the target. Returns the names actually present.
    public static function materialize(array $skills, string $baseDir): array {
        $home = getenv('HOME') ?: '';
        $present = [];
        foreach ($skills as $s) {
            $name = $s['name'] ?? '';
            if ($name === '') continue;
            if ($home !== '' && is_file($home . '/.ollamadev/skills/' . $name . '/SKILL.md')) { $present[] = $name; continue; }
            $dir = $baseDir . '/.ollamadev/skills/' . $name;
            $md = $dir . '/SKILL.md';
            if (is_file($md)) { $present[] = $name; continue; }
            @mkdir($dir, 0755, true);
            $out = "---\nname: $name\ndescription: " . ($s['description'] ?? '') . "\n---\n\n" . ($s['body'] ?? '');
            if (@file_put_contents($md, $out) !== false) $present[] = $name;
        }
        return $present;
    }
}

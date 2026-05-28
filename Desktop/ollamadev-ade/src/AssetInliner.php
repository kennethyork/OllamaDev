<?php

declare(strict_types=1);

namespace OllamaDev;

class AssetInliner
{
    private string $baseDir;
    /** @var array<string, string> */
    private array $themes = [];

    public function __construct(string $baseDir)
    {
        $this->baseDir = $baseDir;
        $this->loadThemes();
    }

    private function loadThemes(): void
    {
        $themesDir = $this->baseDir . '/public/css/themes';
        $files = glob($themesDir . '/*.css');
        foreach ($files as $file) {
            $name = basename($file, '.css');
            $this->themes[$name] = file_get_contents($file);
        }
    }

    public function inlineAssets(string $html): string
    {
        $cssDir = $this->baseDir . '/public/css';
        $jsDir = $this->baseDir . '/public/js';

        $variables = file_get_contents($cssDir . '/variables.css');
        $layout = file_get_contents($cssDir . '/layout.css');
        $terminal = file_get_contents($cssDir . '/terminal.css');
        $kanban = file_get_contents($cssDir . '/kanban.css');
        $memory = file_get_contents($cssDir . '/memory.css');
        $appJs = file_get_contents($jsDir . '/app.js');

        $allCss = $variables . "\n" . $layout . "\n" . $terminal . "\n" . $kanban . "\n" . $memory . "\n";

        $themesJson = json_encode($this->themes);

        $script = <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.css">
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-web-links@0.9.0/lib/xterm-addon-web-links.js"></script>
<style id="ollamadev-inlined-css">{$allCss}</style>
<style id="ollamadev-theme"></style>
<script>
window.OLLAMADEV_THEMES = {$themesJson};
window.setTheme = function(theme) {
    document.documentElement.dataset.theme = theme;
    var style = document.getElementById('ollamadev-theme');
    if (style && window.OLLAMADEV_THEMES && window.OLLAMADEV_THEMES[theme]) {
        style.textContent = window.OLLAMADEV_THEMES[theme];
    }
    localStorage.setItem('ollamadev-theme', theme);
    var select = document.getElementById('themeSelect');
    if (select) select.value = theme;
};
document.addEventListener('DOMContentLoaded', function() {
    var saved = localStorage.getItem('ollamadev-theme') || 'void';
    window.setTheme(saved);
});
</script>
HTML;

        $html = preg_replace('/<link[^>]*href="[^"]*\.css"[^>]*>/', '', $html);
        $html = preg_replace('#<script src="https://cdn\.jsdelivr\.net/[^"]*"></script>#', '', $html);
        $html = preg_replace('#</head>#', $script . "\n</head>", $html, 1);

        $html = preg_replace('#<script src="/js/app\.js"></script>#', '<script>' . $appJs . '</script>', $html);

        return $html;
    }
}

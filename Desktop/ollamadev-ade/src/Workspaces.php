<?php

declare(strict_types=1);

namespace OllamaDev;

// Desktop/web mirror of the CLI's Workspaces store. Reads and writes the SAME
// file ($HOME/.ollamadev/workspaces.json) the `ollamadev workspace` command uses,
// so a workspace added in the terminal shows up in the app and vice-versa. The
// `state` blob (terminals, editor tabs, layout, view) is written by the GUI here
// and ignored by the CLI, which always preserves it.
final class Workspaces
{
    public static function file(): string
    {
        $home = getenv('HOME') ?: sys_get_temp_dir();
        return $home . '/.ollamadev/workspaces.json';
    }

    public static function load(): array
    {
        $f = self::file();
        $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (!is_array($d)) $d = [];
        $d['workspaces'] = array_values(array_filter($d['workspaces'] ?? [], 'is_array'));
        if (!array_key_exists('active', $d)) $d['active'] = null;
        return $d;
    }

    private static function save(array $d): void
    {
        $f = self::file();
        $dir = dirname($f);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private static function resolve(string $path): string
    {
        $path = trim($path);
        if ($path === '') return getcwd() ?: $path;
        if ($path[0] === '~') $path = (getenv('HOME') ?: '') . substr($path, 1);
        $real = realpath($path);
        return $real !== false ? $real : $path;
    }

    // Add (or update) a workspace for $path, mark it active, return the entry.
    public static function add(string $path, string $name = ''): array
    {
        $abs = self::resolve($path);
        $d = self::load();
        foreach ($d['workspaces'] as &$w) {
            if (($w['path'] ?? '') === $abs) {
                if ($name !== '') $w['name'] = $name;
                $w['lastOpened'] = date('c');
                $d['active'] = $w['id'];
                $entry = $w;
                self::save($d);
                return $entry;
            }
        }
        unset($w);
        $entry = [
            'id'         => 'ws_' . substr(sha1($abs), 0, 10),
            'name'       => $name !== '' ? $name : (basename($abs) ?: $abs),
            'path'       => $abs,
            'lastOpened' => date('c'),
            'state'      => new \stdClass(),
        ];
        $d['workspaces'][] = $entry;
        $d['active'] = $entry['id'];
        self::save($d);
        return $entry;
    }

    public static function remove(string $id): bool
    {
        $d = self::load();
        $before = count($d['workspaces']);
        $d['workspaces'] = array_values(array_filter($d['workspaces'], fn($x) => ($x['id'] ?? '') !== $id));
        if (count($d['workspaces']) === $before) return false;
        if (($d['active'] ?? null) === $id) $d['active'] = $d['workspaces'][0]['id'] ?? null;
        self::save($d);
        return true;
    }

    // Mark active + bump lastOpened (called when the GUI switches to a workspace).
    public static function setActive(string $id): bool
    {
        $d = self::load();
        $hit = false;
        foreach ($d['workspaces'] as &$w) {
            if (($w['id'] ?? '') === $id) { $w['lastOpened'] = date('c'); $hit = true; break; }
        }
        unset($w);
        if (!$hit) return false;
        $d['active'] = $id;
        self::save($d);
        return true;
    }

    // Persist the GUI window state (terminals, editor tabs, layout, view).
    public static function saveState(string $id, array $state): bool
    {
        $d = self::load();
        $hit = false;
        foreach ($d['workspaces'] as &$w) {
            if (($w['id'] ?? '') === $id) { $w['state'] = $state; $hit = true; break; }
        }
        unset($w);
        if ($hit) self::save($d);
        return $hit;
    }
}

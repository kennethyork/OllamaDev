<?php

namespace OllamaDev;

class FileBrowser
{
    private string $root;

    public function __construct()
    {
        $this->root = getcwd();
    }

    public function setRoot(string $path): void
    {
        if (is_dir($path)) {
            $this->root = $path;
        }
    }

    public function getRoot(): string
    {
        return $this->root;
    }

    public function listDir(string $path = null): array
    {
        $dir = $path ?? $this->root;
        if (!is_dir($dir)) {
            return ['error' => "Not a directory: $dir"];
        }

        $items = [];
        try {
            $iterator = new \DirectoryIterator($dir);
            foreach ($iterator as $file) {
                $name = $file->getFilename();
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $items[] = [
                    'name' => $name,
                    'path' => $file->getPathname(),
                    'type' => $file->isDir() ? 'dir' : 'file',
                    'size' => $file->getSize(),
                    'modified' => date('c', $file->getMTime()),
                ];
            }

            usort($items, function ($a, $b) {
                if ($a['type'] !== $b['type']) {
                    return $a['type'] === 'dir' ? -1 : 1;
                }
                return strcmp($a['name'], $b['name']);
            });

            return $items;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function readFile(string $path): array
    {
        if (!file_exists($path)) {
            return ['error' => "File not found: $path"];
        }

        if (is_dir($path)) {
            return ['error' => "Path is a directory"];
        }

        $content = file_get_contents($path);
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return [
            'path' => $path,
            'content' => $content,
            'size' => filesize($path),
            'modified' => date('c', filemtime($path)),
            'extension' => $ext,
        ];
    }

    public function writeFile(string $path, string $content): array
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents($path, $content);
        if ($result === false) {
            return ['error' => "Failed to write file"];
        }

        return ['success' => true, 'size' => $result];
    }

    public function createDir(string $path): array
    {
        if (is_dir($path)) {
            return ['error' => "Directory already exists"];
        }

        $result = mkdir($path, 0755, true);
        if (!$result) {
            return ['error' => "Failed to create directory"];
        }

        return ['success' => true];
    }

    public function delete(string $path): array
    {
        if (!file_exists($path)) {
            return ['error' => "Path not found"];
        }

        if (is_dir($path)) {
            $result = $this->deleteDir($path);
        } else {
            $result = unlink($path);
        }

        if (!$result) {
            return ['error' => "Failed to delete"];
        }

        return ['success' => true];
    }

    private function deleteDir(string $dir): bool
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }

    public function search(string $query, string $path = null): array
    {
        $dir = $path ?? $this->root;
        $results = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) continue;
            if (stripos($file->getFilename(), $query) !== false) {
                $results[] = [
                    'name' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'type' => 'file',
                ];
            }
            if (count($results) >= 100) break;
        }

        return $results;
    }
}
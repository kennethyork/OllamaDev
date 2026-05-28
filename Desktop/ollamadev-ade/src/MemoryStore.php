<?php

namespace OllamaDev;

class MemoryStore
{
    private string $dir;
    private array $notes = [];

    public function __construct()
    {
        $home = getenv('HOME') ?: '/tmp';
        $this->dir = $home . '/.ollamadev/memory';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
    }

    private function loadIndex(): void
    {
        $indexFile = $this->dir . '/index.json';
        if (file_exists($indexFile)) {
            $this->notes = json_decode(file_get_contents($indexFile), true) ?: [];
        }
    }

    private function saveIndex(): void
    {
        $indexFile = $this->dir . '/index.json';
        file_put_contents($indexFile, json_encode($this->notes, JSON_PRETTY_PRINT));
    }

    public function listNotes(): array
    {
        $this->loadIndex();
        return array_map(fn($n) => [
            'id' => $n['id'],
            'title' => $n['title'],
            'created' => $n['created'],
            'updated' => $n['updated'],
            'links' => $this->countLinks($n['id']),
        ], $this->notes);
    }

    public function get(string $id): ?array
    {
        $file = $this->dir . '/' . $id . '.md';
        if (!file_exists($file)) {
            return null;
        }
        $content = file_get_contents($file);
        return [
            'id' => $id,
            'content' => $content,
            'backlinks' => $this->getBacklinks($id),
        ];
    }

    public function create(string $title, string $content = ''): string
    {
        $id = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $title)));
        $id = $id . '_' . substr(md5(mt_rand()), 0, 6);

        $file = $this->dir . '/' . $id . '.md';
        $header = "# $title\n\n";
        $content = $header . $content;

        file_put_contents($file, $content);

        $this->loadIndex();
        $this->notes[] = [
            'id' => $id,
            'title' => $title,
            'created' => date('c'),
            'updated' => date('c'),
        ];
        $this->saveIndex();

        return $id;
    }

    public function update(string $id, array $data): bool
    {
        if (isset($data['content'])) {
            $file = $this->dir . '/' . $id . '.md';
            if (file_exists($file)) {
                file_put_contents($file, $data['content']);
            }
        }

        if (isset($data['title'])) {
            $this->loadIndex();
            foreach ($this->notes as &$note) {
                if ($note['id'] === $id) {
                    $note['title'] = $data['title'];
                    $note['updated'] = date('c');
                    break;
                }
            }
            $this->saveIndex();
        }

        return true;
    }

    public function delete(string $id): bool
    {
        $file = $this->dir . '/' . $id . '.md';
        if (file_exists($file)) {
            unlink($file);
        }

        $this->loadIndex();
        $this->notes = array_filter($this->notes, fn($n) => $n['id'] !== $id);
        $this->notes = array_values($this->notes);
        $this->saveIndex();

        return true;
    }

    public function search(string $query): array
    {
        $results = [];
        foreach (glob($this->dir . '/*.md') as $file) {
            $content = file_get_contents($file);
            if (stripos($content, $query) !== false) {
                $id = basename($file, '.md');
                $title = $this->getNoteTitle($content);
                $results[] = ['id' => $id, 'title' => $title];
            }
        }
        return $results;
    }

    private function getNoteTitle(string $content): string
    {
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) {
            return $m[1];
        }
        return 'Untitled';
    }

    private function countLinks(string $id): int
    {
        $file = $this->dir . '/' . $id . '.md';
        if (!file_exists($file)) {
            return 0;
        }
        $content = file_get_contents($file);
        preg_match_all('/\[\[([^\]]+)\]\]/', $content, $matches);
        return count($matches[1]);
    }

    private function getBacklinks(string $id): array
    {
        $backlinks = [];
        $searchTerm = '[[' . $id . ']]';
        foreach (glob($this->dir . '/*.md') as $file) {
            $content = file_get_contents($file);
            if (stripos($content, $searchTerm) !== false) {
                $backlinks[] = basename($file, '.md');
            }
        }
        return $backlinks;
    }
}
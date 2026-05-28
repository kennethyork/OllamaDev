<?php

namespace OllamaDev;

class TaskStore
{
    private string $file;
    private array $tasks = [];

    public function __construct()
    {
        $home = getenv('HOME') ?: '/tmp';
        $dir = $home . '/.ollamadev';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->file = $dir . '/tasks.json';
        $this->load();
    }

    private function load(): void
    {
        if (file_exists($this->file)) {
            $data = json_decode(file_get_contents($this->file), true);
            $this->tasks = is_array($data) ? $data : [];
        }
    }

    private function save(): void
    {
        file_put_contents($this->file, json_encode($this->tasks, JSON_PRETTY_PRINT));
    }

    public function all(): array
    {
        return $this->tasks;
    }

    public function get(string $id): ?array
    {
        foreach ($this->tasks as $task) {
            if ($task['id'] === $id) {
                return $task;
            }
        }
        return null;
    }

    public function create(array $data): string
    {
        $id = 'task_' . time() . '_' . substr(md5(mt_rand()), 0, 6);
        $task = array_merge([
            'id' => $id,
            'title' => 'New Task',
            'description' => '',
            'status' => 'todo',
            'agent' => 'builder',
            'created' => date('c'),
            'updated' => date('c'),
        ], $data);
        $this->tasks[] = $task;
        $this->save();
        return $id;
    }

    public function update(string $id, array $data): bool
    {
        foreach ($this->tasks as $i => $task) {
            if ($task['id'] === $id) {
                $this->tasks[$i] = array_merge($task, $data, ['updated' => date('c')]);
                $this->save();
                return true;
            }
        }
        return false;
    }

    public function delete(string $id): bool
    {
        $this->tasks = array_filter($this->tasks, fn($t) => $t['id'] !== $id);
        $this->tasks = array_values($this->tasks);
        $this->save();
        return true;
    }

    public function move(string $id, string $status): bool
    {
        return $this->update($id, ['status' => $status]);
    }
}
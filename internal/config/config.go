package config

import (
	"encoding/json"
	"os"
	"path/filepath"
)

type Config struct {
	Data    Data     `json:"data"`
	Ollama  Ollama   `json:"ollama"`
	Agents  Agents   `json:"agents"`
	Shell   Shell    `json:"shell"`
}

type Data struct {
	Directory string `json:"directory"`
}

type Ollama struct {
	Host         string `json:"host"`
	DefaultModel string `json:"defaultModel"`
}

type Agents struct {
	Coder       Agent `json:"coder"`
	Summarizer  Agent `json:"summarizer"`
}

type Agent struct {
	Model       string  `json:"model"`
	Temperature float64 `json:"temperature"`
	MaxTokens   int     `json:"maxTokens"`
}

type Shell struct {
	Path string   `json:"path"`
	Args []string `json:"args"`
}

func ConfigDir() string {
	home, _ := os.UserHomeDir()
	return filepath.Join(home, ".ollamadev")
}

func ConfigPath() string {
	return filepath.Join(ConfigDir(), "config.json")
}

func LocalConfigPath() string {
	return ".ollamadev.json"
}

func Load() (*Config, error) {
	cfg := Default()

	if localData, err := os.ReadFile(LocalConfigPath()); err == nil {
		json.Unmarshal(localData, cfg)
	}

	if data, err := os.ReadFile(ConfigPath()); err == nil {
		json.Unmarshal(data, cfg)
	}

	return cfg, nil
}

func Save(cfg *Config) error {
	if err := os.MkdirAll(ConfigDir(), 0755); err != nil {
		return err
	}
	data, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(ConfigPath(), data, 0644)
}

func Default() *Config {
	return &Config{
		Data: Data{
			Directory: ".ollamadev",
		},
		Ollama: Ollama{
			Host:         "http://localhost:11434",
			DefaultModel: "codellama",
		},
		Agents: Agents{
			Coder: Agent{
				Model:       "codellama",
				Temperature: 0.7,
				MaxTokens:   4096,
			},
			Summarizer: Agent{
				Model:     "codellama",
				MaxTokens: 2048,
			},
		},
		Shell: Shell{
			Path: "/bin/bash",
			Args: []string{"-l"},
		},
		AutoCompact: true,
	}
}

type AutoCompact bool
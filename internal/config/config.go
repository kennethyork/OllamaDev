package config

import (
	"fmt"
	"os"
	"path/filepath"

	"github.com/spf13/viper"
)

type Config struct {
	Data        Data                 `mapstructure:"data"`
	Ollama      OllamaConfig         `mapstructure:"ollama"`
	Agents      map[string]AgentConfig `mapstructure:"agents"`
	Shell       ShellConfig           `mapstructure:"shell"`
	McpServers  map[string]McpServer  `mapstructure:"mcpServers"`
	LSP         map[string]LSPConfig  `mapstructure:"lsp"`
	AutoCompact bool                 `mapstructure:"autoCompact"`
	Debug       bool                 `mapstructure:"debug"`
}

type Data struct {
	Directory string `mapstructure:"directory"`
}

type OllamaConfig struct {
	Host         string `mapstructure:"host"`
	DefaultModel string `mapstructure:"defaultModel"`
}

type AgentConfig struct {
	Model       string  `mapstructure:"model"`
	Temperature float64 `mapstructure:"temperature"`
	MaxTokens   int     `mapstructure:"maxTokens"`
}

type ShellConfig struct {
	Path string   `mapstructure:"path"`
	Args []string `mapstructure:"args"`
}

type McpServer struct {
	Type     string            `mapstructure:"type"`
	Command  string            `mapstructure:"command"`
	Env      []string          `mapstructure:"env"`
	Args     []string          `mapstructure:"args"`
	URL      string            `mapstructure:"url"`
	Headers  map[string]string `mapstructure:"headers"`
}

type LSPConfig struct {
	Disabled bool   `mapstructure:"disabled"`
	Command  string `mapstructure:"command"`
	Args     []string `mapstructure:"args"`
}

func DefaultConfig() *Config {
	return &Config{
		Data: Data{
			Directory: ".ollamadev",
		},
		Ollama: OllamaConfig{
			Host:         "http://localhost:11434",
			DefaultModel: "codellama",
		},
		Agents: map[string]AgentConfig{
			"coder": {
				Model:       "codellama",
				Temperature: 0.7,
				MaxTokens:   4096,
			},
			"summarizer": {
				Model:     "codellama",
				MaxTokens: 2048,
			},
			"task": {
				Model:       "codellama",
				MaxTokens:   4096,
			},
			"title": {
				Model:     "codellama",
				MaxTokens: 80,
			},
		},
		Shell: ShellConfig{
			Path: "/bin/bash",
			Args: []string{"-l"},
		},
		AutoCompact: true,
	}
}

func Load() (*Config, error) {
	cfg := DefaultConfig()

	home, _ := os.UserHomeDir()
	configPaths := []string{
		filepath.Join(home, ".ollamadev", "config.json"),
		filepath.Join(home, ".config", "ollamadev", "config.json"),
		".ollamadev.json",
	}

	v := viper.New()
	for _, p := range configPaths {
		if _, err := os.Stat(p); err == nil {
			v.SetConfigFile(p)
			if err := v.ReadInConfig(); err == nil {
				break
			}
		}
	}

	v.SetDefault("data.directory", ".ollamadev")
	v.SetDefault("ollama.host", "http://localhost:11434")
	v.SetDefault("ollama.defaultModel", "codellama")
	v.SetDefault("agents.coder.model", "codellama")
	v.SetDefault("agents.coder.temperature", 0.7)
	v.SetDefault("agents.coder.maxTokens", 4096)
	v.SetDefault("shell.path", "/bin/bash")
	v.SetDefault("autoCompact", true)

	if err := v.Unmarshal(cfg); err != nil {
		return nil, fmt.Errorf("failed to unmarshal config: %w", err)
	}

	return cfg, nil
}

func (c *Config) DataDir() string {
	dir := c.Data.Directory
	if !filepath.IsAbs(dir) {
		wd, _ := os.Getwd()
		dir = filepath.Join(wd, dir)
	}
	return dir
}

func (c *Config) DbPath() string {
	return filepath.Join(c.DataDir(), "ollamadev.db")
}
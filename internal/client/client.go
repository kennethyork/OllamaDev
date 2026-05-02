package client

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"

	"ollamadev/config"
)

type OllamaClient struct {
	host   string
	client *http.Client
}

type GenerateRequest struct {
	Model       string  `json:"model"`
	Prompt      string  `json:"prompt"`
	System      string  `json:"system,omitempty"`
	Temperature float64 `json:"temperature,omitempty"`
	MaxTokens   int     `json:"max_tokens,omitempty"`
	Stream      bool    `json:"stream"`
}

type ChatRequest struct {
	Model       string   `json:"model"`
	Messages    []Message `json:"messages"`
	System      string   `json:"system,omitempty"`
	Temperature float64  `json:"temperature,omitempty"`
	MaxTokens   int      `json:"max_tokens,omitempty"`
	Stream      bool     `json:"stream"`
}

type Message struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

type GenerateResponse struct {
	Model     string `json:"model"`
	Response  string `json:"response"`
	Done      bool   `json:"done"`
	TotalDur  int64  `json:"total_duration,omitempty"`
	EvalCount int    `json:"eval_count,omitempty"`
}

type ChatResponse struct {
	Model      string   `json:"model"`
	Message    Message  `json:"message"`
	Done       bool     `json:"done"`
	TotalDur   int64    `json:"total_duration,omitempty"`
	EvalCount  int      `json:"eval_count,omitempty"`
}

type ModelInfo struct {
	Name       string `json:"name"`
	Model      string `json:"model"`
	Size       int64  `json:"size"`
	ModifiedAt string `json:"modified_at"`
}

type ListResponse struct {
	Models []ModelInfo `json:"models"`
}

func New(cfg *config.Config) *OllamaClient {
	return &OllamaClient{
		host: cfg.Ollama.Host,
		client: &http.Client{
			Timeout: 120 * time.Second,
		},
	}
}

func (c *OllamaClient) CheckConnection() error {
	resp, err := c.client.Get(c.host + "/api/tags")
	if err != nil {
		return fmt.Errorf("cannot connect to Ollama at %s: %v\nMake sure Ollama is running with: ollama serve", c.host, err)
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("Ollama returned status %d", resp.StatusCode)
	}
	return nil
}

func (c *OllamaClient) ListModels() ([]ModelInfo, error) {
	resp, err := c.client.Get(c.host + "/api/tags")
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	var list ListResponse
	if err := json.NewDecoder(resp.Body).Decode(&list); err != nil {
		return nil, err
	}
	return list.Models, nil
}

func (c *OllamaClient) Generate(req GenerateRequest, handler func(string)) error {
	body, err := json.Marshal(req)
	if err != nil {
		return err
	}

	resp, err := c.client.Post(c.host+"/api/generate", "application/json", bytes.NewReader(body))
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	reader := io.MultiReader(resp.Body)
	decoder := json.NewDecoder(reader)
	for decoder.More() {
		var r GenerateResponse
		if err := decoder.Decode(&r); err != nil {
			break
		}
		if handler != nil {
			handler(r.Response)
		}
		if r.Done {
			break
		}
	}
	return nil
}

func (c *OllamaClient) Chat(req ChatRequest, handler func(Message)) error {
	body, err := json.Marshal(req)
	if err != nil {
		return err
	}

	resp, err := c.client.Post(c.host+"/api/chat", "application/json", bytes.NewReader(body))
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	decoder := json.NewDecoder(resp.Body)
	for decoder.More() {
		var r ChatResponse
		if err := decoder.Decode(&r); err != nil {
			break
		}
		if handler != nil {
			handler(r.Message)
		}
		if r.Done {
			break
		}
	}
	return nil
}

func (c *OllamaClient) PullModel(name string) error {
	reqBody, _ := json.Marshal(map[string]string{"name": name})
	resp, err := c.client.Post(c.host+"/api/pull", "application/json", bytes.NewReader(reqBody))
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	return nil
}
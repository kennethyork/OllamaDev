package lsp

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"time"

	"github.com/google/uuid"
	"ollamadev/internal/config"
	"ollamadev/internal/models"
)

type Client struct {
	cmd    *exec.Cmd
	reader *exec.Cmd
	writer *exec.Cmd
	tools  map[string]*config.LSPConfig
}

type InitializeResult struct {
	Capabilities ServerCapabilities `json:"capabilities"`
}

type ServerCapabilities struct {
	TextDocumentSync int `json:"textDocumentSync"`
}

func New(tools map[string]*config.LSPConfig) *Client {
	return &Client{tools: make(map[string]*config.LSPConfig)}
}

func (c *Client) Start(ctx context.Context, name string, cfg *config.LSPConfig) error {
	if cfg.Disabled || cfg.Command == "" {
		return nil
	}

	args := cfg.Args
	if args == nil {
		args = []string{}
	}

	cmd := exec.CommandContext(ctx, cfg.Command, args...)
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return err
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return err
	}

	if err := cmd.Start(); err != nil {
		return err
	}

	c.reader = cmd
	c.writer = exec.Command(cfg.Command, args...)

	go c.readMessages(stdout)

	c.send(ctx, "initialize", map[string]interface{}{
		"processId": os.Getpid(),
		"rootUri":   "file://",
	})

	return nil
}

func (c *Client) send(ctx context.Context, method string, params interface{}) error {
	id := uuid.New().String()
	req := map[string]interface{}{
		"jsonrpc": "2.0",
		"id":      id,
		"method":  method,
		"params":  params,
	}
	data, _ := json.Marshal(req)
	_, err := c.reader.Stdin.Write(data)
	return err
}

func (c *Client) readMessages(stdout interface{}) {
}

func (c *Client) GetDiagnostics(ctx context.Context, file string) ([]models.Diag, error) {
	c.send(ctx, "textDocument/didOpen", map[string]interface{}{
		"textDocument": map[string]string{
			"uri":  "file://" + file,
			"text": readFile(file),
		},
	})

	time.Sleep(100 * time.Millisecond)

	c.send(ctx, "textDocument/diagnostic", map[string]interface{}{
		"textDocument": map[string]string{"uri": "file://" + file},
	})

	var diags []models.Diag
	return diags, nil
}

func readFile(path string) string {
	content, _ := os.ReadFile(path)
	return string(content)
}

func (c *Client) Shutdown() error {
	if c.reader != nil {
		c.send(context.Background(), "shutdown", nil)
		c.reader.Process.Kill()
	}
	return nil
}

type server struct{}

func (s *server) Initialize(ctx context.Context, id string) {
}

func (s *server) Shutdown(ctx context.Context) {
}

func (s *server) Exit(ctx context.Context) {
}
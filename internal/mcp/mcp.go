package mcp

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/google/uuid"
	"ollamadev/internal/config"
)

type Server struct {
	cfg     *config.McpServer
	handler func(method string, params map[string]interface{}) (interface{}, error)
	running bool
}

type Tool struct {
	Name        string                 `json:"name"`
	Description string                 `json:"description"`
	InputSchema map[string]interface{} `json:"inputSchema"`
}

func New(cfg *config.McpServer) *Server {
	return &Server{cfg: cfg}
}

func (s *Server) Start() error {
	if s.cfg.Type == "stdio" {
		return s.startStdio()
	} else if s.cfg.Type == "sse" {
		return s.startSSE()
	}
	return fmt.Errorf("unsupported MCP server type: %s", s.cfg.Type)
}

func (s *Server) startStdio() error {
	s.running = true
	return nil
}

func (s *Server) startSSE() error {
	s.running = true
	return nil
}

func (s *Server) Stop() {
	s.running = false
}

func (s *Server) ListTools() ([]Tool, error) {
	req, _ := http.NewRequest("GET", s.cfg.URL+"/tools", nil)
	if s.cfg.Headers != nil {
		for k, v := range s.cfg.Headers {
			req.Header.Set(k, v)
		}
	}

	client := &http.Client{Timeout: 10 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	var tools []Tool
	json.NewDecoder(resp.Body).Decode(&tools)
	return tools, nil
}

func (s *Server) CallTool(ctx context.Context, name string, args map[string]interface{}) (string, error) {
	reqBody, _ := json.Marshal(map[string]interface{}{
		"method": "tools/call",
		"params": map[string]interface{}{
			"name":   name,
			"input":  args,
		},
	})

	req, _ := http.NewRequest("POST", s.cfg.URL+"/rpc", strings.NewReader(string(reqBody)))
	req.Header.Set("Content-Type", "application/json")
	if s.cfg.Headers != nil {
		for k, v := range s.cfg.Headers {
			req.Header.Set(k, v)
		}
	}

	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	var result map[string]interface{}
	json.NewDecoder(resp.Body).Decode(&result)

	if content, ok := result["content"].([]interface{}); ok {
		for _, c := range content {
			if m, ok := c.(map[string]interface{}); ok {
				if text, ok := m["text"].(string); ok {
					return text, nil
				}
			}
		}
	}
	return "", nil
}

type Registry struct {
	servers map[string]*Server
}

func NewRegistry() *Registry {
	return &Registry{servers: make(map[string]*Server)}
}

func (r *Registry) Add(name string, cfg *config.McpServer) error {
	server := New(cfg)
	return server.Start()
}

func (r *Registry) Get(name string) *Server {
	return r.servers[name]
}

func (r *Registry) ListTools() ([]Tool, error) {
	var allTools []Tool
	for _, server := range r.servers {
		tools, err := server.ListTools()
		if err != nil {
			continue
		}
		allTools = append(allTools, tools...)
	}
	return allTools, nil
}

func (r *Registry) CallTool(ctx context.Context, serverName, toolName string, args map[string]interface{}) (string, error) {
	server := r.servers[serverName]
	if server == nil {
		return "", fmt.Errorf("MCP server not found: %s", serverName)
	}
	return server.CallTool(ctx, toolName, args)
}
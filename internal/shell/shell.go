package shell

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"sync"
	"time"
)

type Manager struct {
	cmd    *exec.Cmd
	stdin  chan string
	stdout chan string
	stderr chan string
	mu     sync.Mutex
	done   chan struct{}
}

var (
	bashBanned = []string{
		"curl", "wget", "chmod", "sudo", "rm -rf /", "mkfs",
		":(){ :|:& };:", "dd if=/dev/zero", "shutdown", "reboot",
	}
	bashReadonly = []string{
		"ls", "pwd", "cat", "head", "tail", "grep", "find", "git", "echo",
		"wc", "sort", "uniq", "awk", "sed", "cut", "tr", "tee", "xargs",
		"which", "type", "file", "stat", "diff", "tree",
	}
)

func New(shellPath string, args []string) (*Manager, error) {
	cmd := exec.Command(shellPath, args...)
	cmd.Stdin = os.Stdin
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr

	if err := cmd.Start(); err != nil {
		return nil, err
	}

	m := &Manager{
		cmd:    cmd,
		stdin:  make(chan string, 10),
		stdout: make(chan string, 100),
		stderr: make(chan string, 100),
		done:   make(chan struct{}),
	}

	go m.readOutputs()
	return m, nil
}

func (m *Manager) readOutputs() {
	scanner := bufio.NewScanner(m.cmd.Stdout)
	for scanner.Scan() {
		m.stdout <- scanner.Text()
	}
	m.cmd.Wait()
	close(m.done)
}

func (m *Manager) Execute(cmd string, timeout time.Duration) (string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()

	if isBanned(cmd) {
		return "", fmt.Errorf("command not allowed")
	}

	if !isReadOnly(cmd) {
		return "", fmt.Errorf("command requires permission: %s", cmd)
	}

	done := make(chan struct{})
	var output strings.Builder
	var err error

	go func() {
		c := exec.Command("bash", "-c", cmd)
		c.Dir, _ = os.Getwd()
		out, e := c.CombinedOutput()
		if e != nil {
			err = e
		}
		output.Write(out)
		close(done)
	}()

	select {
	case <-done:
	case <-time.After(timeout):
		return "", fmt.Errorf("command timed out")
	}

	return output.String(), err
}

func (m *Manager) Close() error {
	if m.cmd != nil && m.cmd.Process != nil {
		return m.cmd.Process.Kill()
	}
	return nil
}

func isBanned(cmd string) bool {
	lower := strings.ToLower(cmd)
	for _, b := range bashBanned {
		if strings.Contains(lower, b) {
			return true
		}
	}
	return false
}

func isReadOnly(cmd string) bool {
	lower := strings.ToLower(cmd)
	for _, allowed := range bashReadonly {
		if strings.HasPrefix(lower, allowed) {
			return true
		}
	}
	return false
}
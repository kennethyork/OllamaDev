package permission

import (
	"sync"
	"time"

	"github.com/google/uuid"
	"ollamadev/internal/db"
	"ollamadev/internal/models"
)

type Service struct {
	db        *db.DB
	pending   map[string]chan bool
	allowed   map[string]bool
	mu        sync.RWMutex
}

func New(db *db.DB) *Service {
	return &Service{
		db:      db,
		pending: make(map[string]chan bool),
		allowed: make(map[string]bool),
	}
}

func (s *Service) Request(tool, command, sessionID string) (bool, error) {
	id := uuid.New().String()

	if err := s.db.CreatePermission(id, tool, command, sessionID); err != nil {
		return false, err
	}

	ch := make(chan bool, 1)
	s.mu.Lock()
	s.pending[id] = ch
	s.mu.Unlock()

	defer func() {
		s.mu.Lock()
		delete(s.pending, id)
		s.mu.Unlock()
	}()

	select {
	case approved := <-ch:
		s.db.UpdatePermissionStatus(id, "approved")
		return approved, nil
	case <-time.After(60 * time.Second):
		s.db.UpdatePermissionStatus(id, "denied")
		return false, nil
	}
}

func (s *Service) Approve(id string) {
	s.mu.RLock()
	ch, ok := s.pending[id]
	s.mu.RUnlock()

	if ok {
		ch <- true
	}

	s.mu.Lock()
	s.allowed[id] = true
	s.mu.Unlock()
}

func (s *Service) Deny(id string) {
	s.mu.RLock()
	ch, ok := s.pending[id]
	s.mu.RUnlock()

	if ok {
		ch <- false
	}
}

func (s *Service) IsAllowed(tool, command string) bool {
	key := tool + ":" + command
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.allowed[key]
}

func (s *Service) AllowTool(tool, command string) {
	key := tool + ":" + command
	s.mu.Lock()
	s.allowed[key] = true
	s.mu.Unlock()
}

func (s *Service) DenyTool(tool, command string) {
	key := tool + ":" + command
	s.mu.Lock()
	delete(s.allowed, key)
	s.mu.Unlock()
}

func (s *Service) GetPending(sessionID string) ([]models.Permission, error) {
	return nil, nil
}
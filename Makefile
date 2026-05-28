# OllamaDev build/test/install workflow.
# Edit src/*.php, then `make` to build, test, and install.

INSTALL_PATH ?= $(HOME)/.local/bin/ollamadev

.PHONY: all build test install lint clean

all: build test

build:
	@./build.sh

lint:
	@php -l ollamadev >/dev/null && echo "✓ lint ok"

# Run the smoke suite against the freshly built binary.
test: build
	@php tests/smoke.php

# Install only if the build + tests pass (test depends on build).
install: test
	@cp ollamadev "$(INSTALL_PATH)" && chmod +x "$(INSTALL_PATH)" && echo "✓ installed to $(INSTALL_PATH)"

clean:
	@rm -f /tmp/ollamadev.orig && echo "✓ cleaned"

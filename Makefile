SHELL := /bin/sh
.DEFAULT_GOAL := help

ENV_FILE ?= .env.docker.local
COMPOSER ?= composer
NPM ?= npm
PYTHON ?= python3
IMAGE ?= visual-release-review:local
ARGS ?= about
COMPOSE = docker compose --env-file "$(ENV_FILE)"

.PHONY: help init require-env build up down restart logs ps shell console install dev test test-unit test-functional test-e2e test-docker quality fix audit

help: ## Show available commands.
	@awk 'BEGIN {FS = ":.*## "; printf "Usage: make <target>\n\n"} /^[a-zA-Z0-9_-]+:.*## / {printf "  %-18s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

$(ENV_FILE):
	@if test -e "$(ENV_FILE)"; then exit 0; fi; \
	umask 077; \
	secret="$$(openssl rand -hex 32)" || exit $$?; \
	tmp="$$(mktemp "$(ENV_FILE).XXXXXX")" || exit $$?; \
	trap 'rm -f "$$tmp"' 0; \
	trap 'exit 1' 1 2 15; \
	sed "s/^APP_SECRET=$$/APP_SECRET=$$secret/" .env.docker.example > "$$tmp" && \
	ln "$$tmp" "$(ENV_FILE)"

init: $(ENV_FILE) ## Create a private Docker env with a random secret; preserve existing values.
	@chmod 600 "$(ENV_FILE)"
	@printf 'Runtime configuration ready: %s\n' "$(ENV_FILE)"

require-env:
	@test -f "$(ENV_FILE)" || { printf 'Missing %s. Run make init first.\n' "$(ENV_FILE)" >&2; exit 1; }

build: init ## Build the production Docker image.
	$(COMPOSE) build

up: init ## Build and start Docker, then wait for a healthy app.
	$(COMPOSE) up -d --build --wait

down: require-env ## Stop and remove the app container.
	$(COMPOSE) down

restart: require-env ## Recreate the app to apply runtime configuration changes.
	$(COMPOSE) up -d --force-recreate --wait

logs: require-env ## Follow the last 100 lines of container logs.
	$(COMPOSE) logs --follow --tail=100 app

ps: require-env ## Show the container status and published port.
	$(COMPOSE) ps

shell: require-env ## Open a shell as the unprivileged app user.
	$(COMPOSE) exec app sh

console: require-env ## Run Symfony in Docker, e.g. make console ARGS='debug:router'.
	$(COMPOSE) exec app php bin/console $(ARGS)

install: ## Install local PHP and Node development dependencies.
	$(COMPOSER) install
	$(NPM) ci

dev: ## Start the local PHP development server on port 8088.
	$(COMPOSER) start

test: ## Run all PHPUnit tests locally.
	$(COMPOSER) test

test-unit: ## Run the PHPUnit unit suite locally.
	$(COMPOSER) test:unit

test-functional: ## Run the PHPUnit HTTP integration suite locally.
	$(COMPOSER) test:functional

test-e2e: ## Run Playwright desktop and mobile browser tests locally.
	$(NPM) run test:e2e

test-docker: ## Check Docker security and MCP with fake keys; override IMAGE if needed.
	$(PYTHON) scripts/test-docker.py --image "$(IMAGE)"

quality: ## Run PHP linting, formatting checks, PHPStan, PHPUnit and Twig checks.
	$(COMPOSER) quality

fix: ## Apply the PHP coding style.
	$(COMPOSER) cs:fix

audit: ## Audit production Composer and npm dependencies.
	$(COMPOSER) audit --locked --no-dev
	$(NPM) audit --omit=dev

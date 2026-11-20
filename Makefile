# Developer entrypoints. Every target runs inside the Compose stack so the host
# only needs Docker. Run `make help` for the list.

SHELL := /bin/bash
.DEFAULT_GOAL := help

COMPOSE := docker compose
APP     := $(COMPOSE) exec app
ARTISAN := $(APP) php artisan

.PHONY: help setup up down restart build sh logs ps \
        migrate fresh seed test test-coverage check lint lint-fix stan rector deptrac hooks

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

setup: ## First-time setup: env file, build, dependencies, key, migrations, git hooks
	@test -f .env || cp .env.example .env
	$(COMPOSE) build
	$(COMPOSE) up -d
	$(APP) composer install --no-interaction
	$(ARTISAN) key:generate --no-interaction
	$(ARTISAN) migrate --no-interaction
	$(MAKE) hooks

up: ## Start the stack in the background
	$(COMPOSE) up -d

down: ## Stop the stack (volumes are kept)
	$(COMPOSE) down

restart: ## Restart application containers
	$(COMPOSE) restart app worker scheduler

build: ## Rebuild images without cache
	$(COMPOSE) build --no-cache

sh: ## Shell into the app container
	$(APP) bash

logs: ## Tail logs of every service
	$(COMPOSE) logs -f --tail=100

ps: ## Show container status
	$(COMPOSE) ps

migrate: ## Run pending migrations
	$(ARTISAN) migrate --no-interaction

fresh: ## Drop everything, migrate and seed
	$(ARTISAN) migrate:fresh --seed --no-interaction

seed: ## Run seeders
	$(ARTISAN) db:seed --no-interaction

test: ## Run the test suite
	$(ARTISAN) test --parallel

test-coverage: ## Run tests with coverage (pcov)
	$(ARTISAN) test --coverage --min=85

check: lint stan rector deptrac ## Run every quality gate (what CI runs)

lint: ## Check code style
	$(APP) vendor/bin/pint --test

lint-fix: ## Fix code style
	$(APP) vendor/bin/pint

stan: ## Static analysis
	$(APP) vendor/bin/phpstan analyse --memory-limit=1G

rector: ## Rector dry run
	$(APP) vendor/bin/rector process --dry-run

deptrac: ## Module dependency rules
	$(APP) vendor/bin/deptrac analyse --no-progress

hooks: ## Point git at the versioned hooks directory
	git config core.hooksPath .githooks

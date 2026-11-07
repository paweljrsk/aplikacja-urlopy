.DEFAULT_GOAL := help
COMPOSE ?= docker compose
SERVICE ?=
WAIT_TIMEOUT ?= 240

.PHONY: help env init start stop restart down status logs db-init db-migrate db-validate test-prepare demo verify test lint check workers delete reset db-delete db-reset confirm-destructive

help: ## List available commands
	@awk 'BEGIN {FS = ":.*## "; printf "Use make <target> [SERVICE=name]\n\n"} /^[a-zA-Z_-]+:.*## / {printf "  %-20s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

env: ## Create local Compose settings without overwriting existing configuration
	@if [ ! -f .env ]; then \
		sed -e "s/^LOCAL_UID=.*/LOCAL_UID=$$(id -u)/" \
		    -e "s/^LOCAL_GID=.*/LOCAL_GID=$$(id -g)/" .env.example > .env; \
	fi

init: env ## Build, start, wait for healthy services and apply Doctrine migrations
	$(COMPOSE) config --quiet
	$(COMPOSE) up -d --build --wait --wait-timeout $(WAIT_TIMEOUT)
	$(MAKE) db-init
	$(MAKE) db-migrate

start: env ## Start existing services and wait; preserves database volumes
	$(COMPOSE) up -d --wait --wait-timeout $(WAIT_TIMEOUT) $(SERVICE)

stop: ## Stop containers without deleting data
	$(COMPOSE) stop $(SERVICE)

restart: ## Restart running services without deleting data
	$(COMPOSE) restart $(SERVICE)

down: ## Remove containers and network; preserve database volumes
	$(COMPOSE) down --remove-orphans

status: ## Show service health and state
	$(COMPOSE) ps -a

logs: ## Show logs; optionally SERVICE=php
	$(COMPOSE) logs --tail=100 $(SERVICE)

db-init: ## Create the configured database with Doctrine if it does not exist
	$(COMPOSE) exec -T php php bin/console doctrine:database:create --if-not-exists --no-interaction

db-migrate: ## Apply Doctrine migrations without resetting data
	$(COMPOSE) exec -T php php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

verify: ## Check configuration, Symfony, DB connection and both HTTP endpoints
	$(COMPOSE) config --quiet
	$(COMPOSE) exec -T php php bin/console about
	$(COMPOSE) exec -T php php bin/console doctrine:query:sql 'SELECT 1'
	$(COMPOSE) exec -T nginx wget -q -O /dev/null http://127.0.0.1/
	$(COMPOSE) exec -T nginx wget -q -O - http://127.0.0.1/api/health

db-validate: ## Check Doctrine mapping and database schema synchronization
	$(COMPOSE) exec -T php php bin/console doctrine:schema:validate

test-prepare: ## Grant/create/migrate the dedicated test database; never resets it
	$(COMPOSE) exec -T mysql sh -s < .docker/scripts/prepare-test-database.sh
	$(COMPOSE) exec -T -e APP_ENV=test php php bin/console doctrine:database:create --if-not-exists --no-interaction
	$(COMPOSE) exec -T -e APP_ENV=test php php bin/console doctrine:migrations:migrate --no-interaction

test: test-prepare ## Run backend tests on the dedicated test database
	$(COMPOSE) exec -T php php bin/phpunit

demo: ## Explicitly load synthetic data into an empty development database (no passwords)
	$(COMPOSE) exec -T php php bin/console app:demo:load --confirm-demo

lint: ## Run backend lint/static analysis and frontend lint/type check
	$(COMPOSE) exec -T php php bin/console lint:yaml config
	$(COMPOSE) exec -T php php bin/console lint:container
	$(COMPOSE) exec -T php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
	$(COMPOSE) exec -T node npm run lint
	$(COMPOSE) exec -T node npx --no-install tsc --noEmit

check: verify db-validate lint test ## Run the application verification suite

workers: env ## Start optional Messenger and Scheduler workers
	$(COMPOSE) --profile workers up -d --build supervisor

confirm-destructive:
	@test "$(CONFIRM)" = "DELETE_LOCAL_DATA" || { printf '%s\n' 'Refusing to delete data. For a disposable LOCAL database only, set CONFIRM=DELETE_LOCAL_DATA.'; exit 1; }

delete: confirm-destructive ## DESTRUCTIVE: remove local containers and volumes (requires CONFIRM)
	$(COMPOSE) --profile workers down --volumes --remove-orphans

reset: confirm-destructive ## DESTRUCTIVE: delete local data and initialize again (requires CONFIRM)
	$(MAKE) delete CONFIRM=DELETE_LOCAL_DATA
	$(MAKE) init

db-delete: confirm-destructive ## DESTRUCTIVE: drop configured database with Doctrine (requires CONFIRM)
	$(COMPOSE) exec -T php php bin/console doctrine:database:drop --force --if-exists --no-interaction

db-reset: confirm-destructive ## DESTRUCTIVE: drop/recreate configured database and migrate (requires CONFIRM)
	$(MAKE) db-delete CONFIRM=DELETE_LOCAL_DATA
	$(MAKE) db-init
	$(MAKE) db-migrate

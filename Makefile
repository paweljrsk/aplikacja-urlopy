.DEFAULT_GOAL := help
.PHONY: help

help: ## This help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sed -e "s/^Makefile://" | awk 'BEGIN {FS = ": ## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'


init: ## Initialize project
	@docker pull php:8.3-fpm || true
	@exec echo "APP_ENV=dev" > backend/.env
	$(MAKE) -s start
	$(MAKE) -s db-init || true
	$(MAKE) -s db-migrate || true

start: ## Start project
	@docker compose up -d $(filter-out $@,$(MAKECMDGOALS)) --remove-orphans

stop: ## Stop project
	@docker compose stop $(filter-out $@,$(MAKECMDGOALS))

delete: ## Removed project containers
	@if [  "" = "$(filter-out $@,$(MAKECMDGOALS))" ]; then\
		$(MAKE) db-delete || true;\
	fi

	@docker compose down -v $(filter-out $@,$(MAKECMDGOALS))

	@if [ "node" = "$(filter-out $@,$(MAKECMDGOALS))" ] || [ "" = "$(filter-out $@,$(MAKECMDGOALS))" ]; then\
		rm -rf frontend/node_modules frontend/.next;\
	fi

	@if [ "php" = "$(filter-out $@,$(MAKECMDGOALS))" ] || [ "" = "$(filter-out $@,$(MAKECMDGOALS))" ]; then\
		rm -rf backend/vendor backend/var backend/public/bundles;\
	fi

reset: ## Reset project
	$(MAKE) -s delete $(filter-out $@,$(MAKECMDGOALS)) || true

	@if [ -z "$(filter-out $@,$(MAKECMDGOALS))" ]; then\
		$(MAKE) -s init;\
	else\
		$(MAKE) -s start $(filter-out $@,$(MAKECMDGOALS));\
	fi

db-init: ## Initialize database
	@docker compose exec -it mysql bash -c 'mysql -u notus -ppass1234 -e "CREATE DATABASE IF NOT EXISTS \`calendar\`"'

db-delete: ## Delete database
	@docker compose exec -it mysql bash -c 'mysql -u notus -ppass1234 -e "DROP DATABASE \`calendar\`"'

db-migrate: ## Migrate database
	@docker compose exec -it php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration;

db-reset: ## Reset database
	$(MAKE) -s db-delete || true
	$(MAKE) -s db-init
	$(MAKE) -s db-migrate

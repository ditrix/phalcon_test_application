COMPOSE = docker compose

.PHONY: up down logs shell rebuild reset

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f --tail=100

shell:
	$(COMPOSE) exec web bash

rebuild:
	$(COMPOSE) up -d --build --force-recreate

reset:
	$(COMPOSE) down -v

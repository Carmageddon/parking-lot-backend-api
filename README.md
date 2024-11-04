# Example portfolio application using PHP with Laravel
Demonstrating:
- RESTful API
- Caching using Redis
- DB and Eloquent ORM usage
- PHP unit tests

## Installation

1. Install docker compose https://docs.docker.com/compose/install/#scenario-one-install-docker-desktop
2. Clone the repository
2. Run `docker-compose up`
4. Run `docker-compose exec my-app composer install -o`
3. Run `docker-compose exec my-app php artisan migrate`
4. Load in browser http://localhost:8081

# The Absence Run

Employees request time off — vacation, sick days, and more. We need a script
that processes the **pending** requests for a period: for each one, check the
employee's remaining entitlement, decide **approve** or **reject**, update their
balance, and post the decision to our HR API.

---

## What's in the box

# Documentation for this application is found in 
Architecture: docs/architecture.md
Functionalities: docs/functionalities.md

## Setup: 
# Start the stack (database + app + worker + hr-api)
```
bin/console docker compose up -d
#get inside container
docker compose exec app bash

#Run composer
composer install

# Create the database
bin/console doctrine:schema:create
```
# Setup the queue transport
bin/console messenger:setup-transports

# Load the sample period
bin/console doctrine:fixtures:load -n

# Run the absence script
bin/console app:absence:run --date=2025-04-15

# Consume messages
bin/console messenger:consume async

# Watch decisions being made. Run outside the container:
docker compose logs -f worker

# Stop the stack
docker compose down
```
# Getting Started

Welcome to PromptlyAgent! This guide will help you get the project running locally using Laravel Sail.

## Prerequisites

Before you begin, ensure you have the following installed:

- **Docker Desktop** (or Docker Engine + Docker Compose)
  - [Download Docker Desktop](https://www.docker.com/products/docker-desktop)
  - Ensure Docker is running before proceeding
- **Git** for cloning the repository
- **Terminal** access (Terminal on macOS/Linux, PowerShell/WSL on Windows)

## Quick Start

```bash
# 1. Clone and navigate
git clone https://github.com/promptlyagentai/promptlyagent.git
cd promptlyagent

# 2. Configure environment
cp .env.example .env
# Edit .env and set:
# - Your AI provider API key (OpenAI, Anthropic, Google, or AWS)
# - WWWUSER=$(id -u) and WWWGROUP=$(id -g) to match your host user for proper file permissions

# 3. Install Composer dependencies (first-time setup)
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html:z" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs --no-scripts

# 4. Start Docker containers
./vendor/bin/sail up -d
# Note: The initial build can take 10-15 minutes depending on hardware
# Subsequent starts are much faster using cached images

# 5. Complete Composer setup
./vendor/bin/sail composer install

# 6. Install npm dependencies
./vendor/bin/sail npm install

# 7. Initialize application
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate

# 8. Create admin user
./vendor/bin/sail artisan make:admin

# 9. Seed database (creates default agents)
./vendor/bin/sail artisan db:seed

# 10. Build frontend
./vendor/bin/sail npm run build

# 11. Access the application
# Open http://localhost in your browser
```

---

## Detailed Installation

This section provides step-by-step explanations for each installation step.

### Step 1: Clone the Repository

```bash
git clone https://github.com/promptlyagentai/promptlyagent.git
cd promptlyagent
```

### Step 2: Environment Configuration

Copy the example environment file:

```bash
cp .env.example .env
```

**Required:** Add at least one AI provider API key to `.env`:

```bash
# AI Provider (choose one or configure multiple)
# OpenAI
OPENAI_API_KEY=your-openai-api-key

# OR Anthropic Claude
ANTHROPIC_API_KEY=your-anthropic-api-key

# OR Google Gemini
GOOGLE_API_KEY=your-google-api-key

# OR AWS Bedrock (both required)
AWS_ACCESS_KEY_ID=your-aws-access-key
AWS_SECRET_ACCESS_KEY=your-aws-secret-key
AWS_DEFAULT_REGION=us-east-1
```

**Required:** Set user/group IDs to match your host user for proper file permissions:

```bash
# Docker Sail User Mapping
# Set these to your host user's UID and GID
WWWUSER=$(id -u)
WWWGROUP=$(id -g)
```

> **💡 Why?** Docker containers need to create files with the same ownership as your host user to avoid permission issues with `vendor/`, `storage/`, etc.

> **✅ Everything else uses sensible defaults!** The `.env.example` file already includes working configuration for:
> - Database (MySQL via Sail)
> - Redis (cache and queues)
> - Meilisearch (search engine)
> - Email (Mailpit for development)
>
> You only need to change these if you have specific requirements.

<details>
<summary><strong>Optional: Advanced Configuration</strong> (click to expand)</summary>

If you need to customize the default services:

```bash
# Application
APP_NAME="PromptlyAgent"
APP_URL=http://localhost

# Database (default Sail settings - usually no changes needed)
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=promptlyagent
DB_USERNAME=sail
DB_PASSWORD=password

# Redis (default Sail settings - usually no changes needed)
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Meilisearch (defaults work for development)
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=masterKey
```

</details>

### Step 3: Install Dependencies

**First-time setup:** If `vendor/bin/sail` doesn't exist yet, use Docker to install Composer dependencies:

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html:z" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs --no-scripts
```

**Note:** The `:z` flag is needed for SELinux systems. The `--no-scripts` flag prevents errors from missing PHP extensions (Redis, etc.) that will be available once Sail is running.

### Step 4: Start Laravel Sail

```bash
./vendor/bin/sail up -d
```

**⏱️ First Build:** The initial build can take 10-15 minutes depending on hardware. Subsequent starts are much faster using cached images.

**What this does:** Starts all Docker services:

**Main Application Container (`laravel.test`):**
- **PHP-FPM 8.4** - PHP application server
- **Nginx** - Web server
- **Laravel Scheduler** - Automated task scheduling
- **Supervisor** - Process manager

**Infrastructure Services:**
- **MySQL 8.0** - Primary database
- **Redis Alpine** - Cache & queue backend
- **Meilisearch v1.15** - Semantic search engine
- **Mailpit** - Development email testing (SMTP: 1025, UI: 8025)

**Specialized Services (Load Balanced):**
- **MarkItDown** (2 instances + Nginx) - Document-to-markdown conversion
- **SearXNG** (2 instances + Nginx) - Meta-search engine for web search

**Background Workers:**
- **Horizon** - Dedicated queue processing container
- **Reverb** - WebSocket server container

### Step 5: Complete Composer Setup

Now that Sail is running with all PHP extensions, complete the Composer setup:

```bash
./vendor/bin/sail composer install
```

### Step 6: Initialize Application

Generate the application encryption key:

```bash
./vendor/bin/sail artisan key:generate
```

Run database migrations to create all tables:

```bash
./vendor/bin/sail artisan migrate
```

### Step 7: Create Admin User

For security, admin users must be created via CLI:

```bash
./vendor/bin/sail artisan make:admin
```

Follow the interactive prompts, or use flags for non-interactive setup:

```bash
./vendor/bin/sail artisan make:admin \
  --name="Admin User" \
  --email="admin@example.com" \
  --password="secure-password"
```

### Step 8: Seed Database

**Important:** This step is required as it creates the default agents:

```bash
./vendor/bin/sail artisan db:seed
```

**What this creates:**
- Default agents (Direct Chat, Research Agent, etc.)
- Sample knowledge documents
- Demo integrations

### Step 9: Build Frontend

```bash
./vendor/bin/sail npm run build
```

**For development:** Use hot module replacement for faster development:

```bash
./vendor/bin/sail npm run dev
```

### Step 10: Start Background Workers (Optional)

In a new terminal, start Horizon for queue processing:

```bash
./vendor/bin/sail artisan horizon
```

Or run the convenient dev script that starts everything:

```bash
./vendor/bin/sail composer dev
```

This starts:
- Laravel development server
- Queue worker (Horizon)
- Log viewer (Pail)
- Vite dev server

## Accessing the Application

Once everything is running, you can access:

- **Web Application**: http://localhost
- **API Documentation**: http://localhost/docs
- **Horizon Dashboard**: http://localhost/horizon (queue monitoring)
- **Meilisearch Dashboard**: http://localhost:7700 (search engine)
- **Mailpit Dashboard**: http://localhost:8025 (email testing)
- **MarkItDown Service**: http://localhost:8000 (document processing)
- **SearXNG Service**: http://localhost:4000 (meta-search)

## Login

Use the credentials you created with `make:admin` command to log in at http://localhost

## Stopping the Application

To stop all services:

```bash
./vendor/bin/sail down
```

To stop and remove all volumes (⚠️ this deletes all data):

```bash
./vendor/bin/sail down -v
```

## Troubleshooting

### Port Conflicts

If ports 80, 3306, or 6379 are already in use, you can customize them in `.env`:

```bash
APP_PORT=8000
FORWARD_DB_PORT=3307
FORWARD_REDIS_PORT=6380
```

Then access the app at http://localhost:8000

### Permission Issues

If you encounter permission errors:

```bash
./vendor/bin/sail artisan storage:link
sudo chown -R $USER:$USER storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

### Database Connection Errors

Ensure the database container is running:

```bash
./vendor/bin/sail ps
```

If MySQL is not healthy, restart it:

```bash
./vendor/bin/sail restart mysql
```

### Meilisearch Not Indexing

Reindex knowledge documents:

```bash
./vendor/bin/sail artisan knowledge:reindex
```

## Next Steps

Now that you have PromptlyAgent running, here's what to explore next:

**📚 Learn the Concepts:**
- **[Introduction](00-introduction.md)** - Understand core concepts (agents, knowledge, workflows)
- **[Architecture](03-architecture.md)** - Deep dive into system architecture

**🛠️ Start Developing:**
- **[Development Guide](02-development.md)** - Day-to-day development workflow and commands
- **[Workflows](04-workflows.md)** - Build multi-agent workflows
- **[Package Development](07-package-development.md)** - Create custom integrations

**🎨 Customize:**
- **[Theming Guide](06-theming.md)** - Customize colors and UI theme

## Getting Help

If you encounter issues:

1. Check the [Troubleshooting](#troubleshooting) section above
2. Review the logs: `./vendor/bin/sail artisan pail`
3. Visit our [GitHub Issues](https://github.com/promptlyagentai/promptlyagent/issues)
4. Email: security@promptlyagent.ai (security issues)

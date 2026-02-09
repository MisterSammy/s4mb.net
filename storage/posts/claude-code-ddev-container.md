---
title: "Running Claude Code Safely in a DDEV Container"
date: 2026-02-09
excerpt: "Use DDEV and VS Code Dev Containers to run Claude Code with --dangerously-skip-permissions safely. Full Laravel stack with Docker isolation — no VM required."
tags: [local-development, claude-code, php, docker, ddev]
slug: claude-code-ddev-container
---

Claude Code is powerful but once you start using it regularly for development, the constant permissions checks start to be a drag. They pause execution while waiting for your input, so it can disrupt the flow of your work.

Running Claude with the `--dangerously-skip-permissions` flag means you have the option to YOLO it as needed and let the agent do all the work it needs to without pestering you for permission to run things. However (and it's a biggie); it has unrestricted access to your entire filesystem  -  SSH keys, dotfiles, credentials, other projects, everything. 

I've seen some unfortunate incidents posted online where Claude has gone haywire and deleted precious family photos, entire repositories etc.

One approach to containing this is running Claude Code inside an isolated environment. Emil Burzo wrote about doing this with a Vagrant VM (https://blog.emilburzo.com/2026/01/running-claude-code-dangerously-safely/). Thanks for the inspiration, Emil. This guide uses Docker containers via DDEV, with VS Code attached directly to the container.

---

## Why Use This?

**The problem**: Claude Code with `--dangerously-skip-permissions` can read, write, and execute anything on your Mac. That includes your `~/.ssh` keys, `~/.zshrc`, browser data, and every other project in your home directory.

**The solution**: Run Claude Code inside a DDEV Docker container where:
- Your project directory is mounted into the container, but nothing else from your host
- Claude Code can only affect files within that mount
- You can safely use `--dangerously-skip-permissions` for uninterrupted workflows
- VS Code attaches directly to the container, so your editor experience feels native

**What you get**:
- Full Laravel stack: PHP 8.4, MariaDB, Nginx, Mailpit  -  managed by DDEV
- Trusted HTTPS on `.ddev.site` domains with zero certificate configuration
- Claude Code CLI and the VS Code extension working inside the container
- No VirtualBox, no Vagrant, no VM overhead  -  just Docker containers
- Container rebuilds in seconds, not minutes

DDEV handles SSL, DNS, database management, and multi-project support out of the box. And since you're developing inside a Linux container, the environment is closer to your production target than macOS ever will be.

---

## Prerequisites

### 1. Install Docker Desktop

```bash
brew install --cask docker
```

Open Docker Desktop and let it finish starting. On Apple Silicon Macs, it runs natively.

### 2. Install DDEV

```bash
brew install ddev/ddev/ddev
```

Verify the installation:

```bash
ddev version
```

### 3. Install VS Code Extensions

You need two extensions:

- **Dev Containers** (`ms-vscode-remote.remote-containers`)  -  lets VS Code attach to running Docker containers
- **Claude Code** (`anthropic.claude-code`)  -  the official Claude Code extension

Install both from the VS Code Extensions panel.

### 4. Get an Anthropic API Key

You'll need an API key from https://console.anthropic.com. Keep it handy for the setup steps below.

---

## Quick Start

### 1. Configure DDEV for your project

For an existing Laravel project:

```bash
cd ~/code/myproject
ddev config --project-type=laravel --docroot=public
```

Open `.ddev/config.yaml` and set the Node.js version. Claude Code requires Node.js 20 or higher:

```yaml
nodejs_version: "20"
```

Your config should look something like this:

```yaml
name: myproject
type: laravel
docroot: public
php_version: "8.4"
webserver_type: nginx-fpm
database:
    type: mariadb
    version: "11.8"
nodejs_version: "20"
composer_version: "2"
```

### 2. Install Claude Code in the container

DDEV lets you extend its web container image with custom Dockerfiles. Create the file `.ddev/web-build/Dockerfile`:

```dockerfile
RUN curl -fsSL https://claude.ai/install.sh | bash
```

That's it  -  one line. This uses the official installer recommended by Anthropic (npm installation is deprecated). The Dockerfile gets appended to DDEV's own image build during `ddev start`.

### 3. Set your API key

If you have a Claude Pro/Max plan you might not need to do this, the extension can log in via web. However, if you do want to set system variables in the container, here's how you do it.

Create `.ddev/config.local.yaml` for environment variables you don't want in version control:

```yaml
web_environment:
    - ANTHROPIC_API_KEY=sk-ant-your-key-here
```

> **Note**: `.ddev/config.local.yaml` is gitignored by DDEV's default `.ddev/.gitignore`. Your API key stays out of version control.

### 4. Start DDEV

```bash
ddev start
```

The first start takes longer because Docker builds the custom image with Claude Code. Subsequent starts are fast.

### 5. Verify the setup

```bash
ddev ssh
claude --version
node --version
exit
```

Confirm Claude Code is installed and Node.js is version 20 or higher.

### 6. Attach VS Code to the container

1. Open VS Code
2. Open the Command Palette (`Cmd+Shift+P`)
3. Type **Dev Containers: Attach to Running Container**
4. Select the DDEV web container  -  it will be named something like `ddev-myproject-web`
5. VS Code reopens with its workspace inside the container

Now your VS Code terminal runs inside the container, the file explorer shows the project inside the container, and extensions run inside the container.

### 7. Install the Claude Code extension inside the container

Once VS Code is attached to the container, open the Extensions panel and install the **Claude Code** extension (`anthropic.claude-code`). VS Code will install it inside the container. This gives you the full Claude Code sidebar experience alongside the CLI.

### 8. Run Claude Code

From VS Code's integrated terminal (which is now inside the container):

```bash
cd /var/www/html
claude --dangerously-skip-permissions
```

`/var/www/html` is where DDEV mounts your project. Claude Code can freely modify your project files but cannot escape the container to reach your host filesystem.

---

## Daily Workflow

### Start your day

```bash
cd ~/code/myproject
ddev start
```

Then attach VS Code to the container (Command Palette → **Dev Containers: Attach to Running Container**). If VS Code remembers the previous session, it may reconnect automatically.

### Work with Claude Code

From VS Code's integrated terminal:

```bash
claude --dangerously-skip-permissions
```

Or use the Claude Code sidebar panel in VS Code  -  it works inside the container just like it does on your host.

### Common commands

Since your terminal is inside the container, you don't need the `ddev` prefix:

```bash
php artisan migrate
composer require some/package
npm run build
```

But from your Mac's terminal, DDEV provides shortcuts:

```bash
ddev artisan migrate
ddev composer require some/package
ddev npm run build
ddev launch                    # Open the site in your browser
```

### Access in browser

Visit `https://myproject.ddev.site`. DDEV handles HTTPS with a locally trusted certificate  -  no Keychain imports, no mkcert setup, no browser warnings.

### End your day

```bash
ddev stop              # Stop this project's containers (preserves database)
# or
ddev poweroff          # Stop all DDEV projects
```

---

## Configuring Vite

If your Laravel project uses Vite for frontend assets, you need to expose its dev server port through DDEV. Add this to `.ddev/config.yaml`:

```yaml
web_extra_exposed_ports:
    - name: vite
      container_port: 5173
      http_port: 5172
      https_port: 5173
```

Then update `vite.config.js` so the dev server binds correctly inside the container:

```javascript
export default defineConfig({
    server: {
        host: '0.0.0.0',
        hmr: {
            host: 'myproject.ddev.site',
        },
    },
    // ... rest of your config
});
```

Run the dev server from inside the container or via DDEV:

```bash
ddev npm run dev
```

Hot module replacement will work at `https://myproject.ddev.site:5173`.

---

## DDEV Commands Reference

| Command | Description |
|---------|-------------|
| `ddev start` | Start the project containers |
| `ddev stop` | Stop the project containers |
| `ddev restart` | Restart containers (applies config changes) |
| `ddev ssh` | Shell into the web container |
| `ddev artisan` | Run Laravel Artisan commands |
| `ddev composer` | Run Composer commands |
| `ddev npm` | Run npm commands |
| `ddev launch` | Open the site in your browser |
| `ddev describe` | Show project info, URLs, and ports |
| `ddev poweroff` | Stop all DDEV projects |
| `ddev delete` | Remove the project containers (keeps files) |

---

## Database Access

### Using DDEV commands

```bash
ddev mysql                                           # Interactive MySQL shell
ddev mysql -uroot -proot                             # As root user
ddev exec mysql -u db -pdb -e "SHOW DATABASES;"     # One-off query
```

### Create a database

DDEV creates a default `db` database automatically. For additional databases:

```bash
ddev mysql -uroot -proot -e "CREATE DATABASE myother_db;"
```

### Laravel .env settings

DDEV automatically configures your `.env` database settings for Laravel projects. The defaults are:

```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=db
DB_USERNAME=db
DB_PASSWORD=db
```

### External database tools

Tools like TablePlus, DBeaver, or Sequel Ace can connect to the database from your Mac. Run `ddev describe` to see the host port:

```bash
ddev describe
```

Look for the database port mapping  -  it will show something like `127.0.0.1:59002 → 3306`. Use that host port in your database client.

---

## Running Queue Workers

### Option 1: DDEV daemons (recommended)

DDEV can run background processes that survive container restarts. Add this to `.ddev/config.yaml`:

```yaml
web_extra_daemons:
    - name: queue-worker
      command: "php /var/www/html/artisan queue:work --sleep=3 --tries=3"
      directory: /var/www/html
```

Then `ddev restart` to apply. The queue worker starts automatically with the container.

### Option 2: tmux

From inside the container:

```bash
tmux new -s worker
php artisan queue:work
# Ctrl+B, D to detach
# tmux attach -t worker to reattach
```

Note that tmux sessions do not survive `ddev restart`.

---

## Security Notes

### What is isolated

- **Filesystem**: Claude Code runs inside the container and can only access files in `/var/www/html` (your project mount). It cannot read your Mac's home directory, SSH keys, browser data, or other projects.
- **Safe to use `--dangerously-skip-permissions`**: The "danger" is contained within the container. Claude can modify project files freely but nothing else on your host.
- **Database data lives in Docker volumes**: Destroyed with `ddev delete`. Back up with `ddev export-db` if needed.

### What is not fully isolated

- **Shared kernel**: Docker containers share the host's kernel, unlike a VM which runs its own. A container escape vulnerability (rare, but theoretically possible) could compromise the host. For most development scenarios, this is an acceptable trade-off.
- **Project directory is fully accessible**: If you have `.env` files with production credentials in your project, Claude Code can read them. Don't put production secrets in your local environment.
- **Network access is unrestricted**: By default, the container can make outbound network requests. The official Claude Code devcontainer setup includes a firewall script that restricts outbound connections to whitelisted domains only. You can adapt this for DDEV if you need network-level isolation, but it requires adding `NET_ADMIN` and `NET_RAW` capabilities to the container.

### Recommendations

- Never store production credentials in your local `.env`
- Don't mount additional host directories into DDEV
- Use `.ddev/config.local.yaml` (gitignored) for your API key, not `config.yaml`
- For maximum isolation, consider the official Claude Code devcontainer with firewall rules: https://github.com/anthropics/claude-code/tree/main/.devcontainer

---

## Troubleshooting

### Docker not running

```bash
docker info
# If Docker is not running:
open -a Docker
```

Wait for Docker Desktop to finish starting before running `ddev start`.

### DDEV container won't start

```bash
ddev poweroff          # Stop everything cleanly
ddev start             # Try again
```

If issues persist, check for port conflicts with `ddev describe` or try `ddev restart`.

### Claude Code not found in container

```bash
ddev ssh
which claude
node --version         # Must be 20+
```

If `claude` is missing, the custom Dockerfile may not have been built. Rebuild:

```bash
ddev stop
ddev start
```

### Port conflicts

```bash
ddev poweroff          # Stop all DDEV projects
ddev start             # Restart just this one
```

### VS Code cannot attach to container

- Ensure Docker Desktop is running
- Ensure the container is running: `ddev describe` should show status "running"
- Try the Command Palette → **Dev Containers: Attach to Running Container** again
- If the Dev Containers extension hangs, update it to the latest version  -  older versions have a known bug that causes hangs

### Slow file performance on macOS

Docker's file sharing on macOS can be slow with large `vendor/` or `node_modules/` directories. Enable Mutagen for near-native performance:

```bash
ddev config --performance-mode=mutagen
ddev restart
```

---

## Services Included

| Service | Details | Access |
|---------|---------|--------|
| Nginx | Latest | `https://myproject.ddev.site` |
| PHP-FPM | 8.4 | Via Nginx |
| MariaDB | 11.8 | Host: `db`, User: `db`, Pass: `db` |
| Node.js | 20+ | Configured via `nodejs_version` |
| Mailpit | Included | `https://myproject.ddev.site:8026` |
| Claude Code | Latest | `claude` command in container |

> Redis is not included by default. Add it with `ddev get ddev/ddev-redis && ddev restart`.

---

## Customization

### Adding Redis

```bash
ddev get ddev/ddev-redis
ddev restart
```

### Changing PHP version

In `.ddev/config.yaml`:

```yaml
php_version: "8.3"
```

Then `ddev restart`.

### Changing Node.js version

```yaml
nodejs_version: "22"
```

Then `ddev restart`. Make sure it's 20 or higher for Claude Code.

### Multiple projects

DDEV supports running multiple projects simultaneously. Each project gets its own `.ddev.site` subdomain, its own database, and its own isolated container. Just run `ddev config` and `ddev start` in each project directory.

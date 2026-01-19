---
title: "Isolated Vagrant Development Environment for Claude Code"
date: 2026-01-19
excerpt: "An alternative to Laravel Herd for local development that provides complete filesystem isolation for Claude Code. Run Claude Code with `--dangerously-skip-permissions` safely, knowing it can only affect files inside the VM."
tags: [local-development, claude-code, php]
slug: claude-code-isolated-vm
---

Credit to Emil Burzo for this idea (https://blog.emilburzo.com/2026/01/running-claude-code-dangerously-safely/), I have adapted this workflow for Laravel/PHP development.

## Why Use This?

**The problem**: Claude Code is powerful but requires careful permission management. Running it directly on your Mac means it has access to your entire filesystem.

**The solution**: Run Claude Code inside a Vagrant VM where:
- Your `~/code` directory is shared bidirectionally with the VM
- Claude Code can only affect files within that shared folder
- You can safely use `claude --dangerously-skip-permissions` for uninterrupted workflows
- Your host system, dotfiles, SSH keys, and other sensitive data remain protected

**What you get**:
- Local `.test` domains with trusted HTTPS (e.g., `https://myproject.test`)
- Full Laravel stack: PHP 8.4, MySQL 8, Redis, Nginx, Composer
- Node.js via nvm, Docker, and all common dev tools
- SSH access for running commands, queue workers, etc.

I actually prefer this method of local development, as it's closer to my preferred production deployment target (Bare metal Linux host).

---

## Prerequisites

### 1. Install Homebrew (if not already installed)

```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

### 2. Install VirtualBox

```bash
brew install --cask virtualbox
```

> **Note**: You may need to allow the kernel extension in System Settings → Privacy & Security after installation. A restart might be required.

### 3. Install Vagrant

```bash
brew install vagrant
```

### 4. Install the hostsupdater plugin (for automatic `/etc/hosts` management)

```bash
vagrant plugin install vagrant-hostsupdater
```

---

## Quick Start

### 1. Create the Vagrant directory

```bash
mkdir -p ~/vagrant-dev
cd ~/vagrant-dev
```

### 2. Create the Vagrantfile

Save the Vagrantfile (provided separately) to `~/vagrant-dev/Vagrantfile`.

### 3. Start the VM

```bash
vagrant up
```

First-time provisioning takes 10-15 minutes.

### 4. Access the VM

```bash
vagrant ssh
```

### 5. Set your Anthropic API key

```bash
echo 'export ANTHROPIC_API_KEY="your-key-here"' >> ~/.bashrc
source ~/.bashrc
```

### 6. Run Claude Code safely

```bash
cd ~/code/myproject
claude --dangerously-skip-permissions
```

---

## Setting Up Local `.test` Domains

### Create a new site

From inside the VM:

```bash
sudo site-create myproject.test /home/vagrant/code/myproject/public
```

### Add the domain to your Vagrantfile

Edit `~/vagrant-dev/Vagrantfile` on your Mac:

```ruby
config.hostsupdater.aliases = [
  "claude-dev.test",
  "myproject.test",  # Add your new domain
]
```

### Reload Vagrant to update hosts

```bash
vagrant reload
```

### Visit in browser

Open `https://myproject.test` — you'll see a certificate warning until you trust the CA (see below).

---

## Trusting SSL Certificates on macOS

To avoid browser warnings, install the VM's root CA on your Mac:

### 1. Copy the CA certificate from the VM

```bash
vagrant ssh -c "cat ~/.local/share/mkcert/rootCA.pem" > ~/vagrant-dev/rootCA.pem
```

### 2. Install to macOS Keychain

```bash
sudo security add-trusted-cert -d -r trustRoot \
  -k /Library/Keychains/System.keychain \
  ~/vagrant-dev/rootCA.pem
```

Now `https://myproject.test` will show as secure.

---

## Daily Workflow

### Start your day

```bash
cd ~/vagrant-dev
vagrant up
vagrant ssh
```

### Work on a project with Claude Code

```bash
cd ~/code/myproject
claude --dangerously-skip-permissions
```

### Access in browser

Visit `https://myproject.test`

### End your day

```bash
exit                 # Exit SSH
vagrant halt         # Stop the VM (preserves state)
# or
vagrant suspend      # Suspend (faster resume)
```

---

## Site Management Commands

Run these inside the VM:

| Command | Description |
|---------|-------------|
| `sudo site-create <domain> [webroot]` | Create a new site with HTTPS |
| `sudo site-remove <domain>` | Remove a site |
| `site-list` | List all configured sites |

**Examples:**

```bash
# Laravel project
sudo site-create myapp.test /home/vagrant/code/myapp/public

# Static site
sudo site-create docs.test /home/vagrant/code/docs

# Custom webroot
sudo site-create api.test /home/vagrant/code/api-project/public
```

---

## Vagrant Commands Reference

Run these from `~/vagrant-dev` on your Mac:

| Command | Description |
|---------|-------------|
| `vagrant up` | Start the VM |
| `vagrant ssh` | SSH into the VM |
| `vagrant halt` | Stop the VM |
| `vagrant suspend` | Suspend (fast resume) |
| `vagrant resume` | Resume suspended VM |
| `vagrant reload` | Restart (applies Vagrantfile changes) |
| `vagrant reload --provision` | Restart and re-run provisioning |
| `vagrant destroy` | Delete the VM completely |
| `vagrant status` | Check VM status |

---

## Running Queue Workers

### Option 1: Supervisor (recommended)

```bash
sudo apt install supervisor

sudo nano /etc/supervisor/conf.d/laravel-worker.conf
```

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/vagrant/code/myproject/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=vagrant
numprocs=2
redirect_stderr=true
stdout_logfile=/home/vagrant/code/myproject/storage/logs/worker.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

### Option 2: tmux

```bash
tmux new -s worker
php artisan queue:work
# Ctrl+B, D to detach
# tmux attach -t worker to reattach
```

---

## Database Access

### From inside the VM

```bash
mysql -u vagrant -psecret
```

### Create a database for your project

```bash
mysql -u vagrant -psecret -e "CREATE DATABASE myproject;"
```

### Laravel .env settings

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myproject
DB_USERNAME=vagrant
DB_PASSWORD=secret
```

---

## Troubleshooting

### VM won't start

```bash
# Check VirtualBox is installed
VBoxManage --version

# May need to allow kernel extension
# System Settings → Privacy & Security → Allow Oracle/VirtualBox
```

### "Connection refused" in browser

```bash
# Check Nginx is running
vagrant ssh -c "sudo systemctl status nginx"

# Check site is configured
vagrant ssh -c "site-list"

# Check hosts file on Mac
cat /etc/hosts | grep test
```

### 502 Bad Gateway

```bash
# Check PHP-FPM is running
vagrant ssh -c "sudo systemctl status php8.4-fpm"

# Restart both services
vagrant ssh -c "sudo systemctl restart nginx php8.4-fpm"
```

### SSL certificate not trusted

1. Ensure you've exported and installed the rootCA.pem (see above)
2. Restart your browser after adding the certificate

### Slow file access

VirtualBox shared folders can be slower than native. For large `vendor/` or `node_modules/`:

```bash
# Run composer/npm inside the VM
cd ~/code/myproject
composer install
npm install
```

---

## Services Installed

| Service | Version | Port | Credentials |
|---------|---------|------|-------------|
| Nginx | Latest | 80, 443 | — |
| PHP-FPM | 8.4 | socket | — |
| MySQL | 8.x | 3306 | vagrant / secret |
| Redis | Latest | 6379 | — |
| Docker | Latest | — | — |
| Node.js | LTS (nvm) | — | — |
| Claude Code | Latest | — | Requires API key |

---

## Security Notes

- **Isolation**: Claude Code runs inside the VM and can only access `/home/vagrant/code` (your `~/code` on Mac)
- **No host access**: The VM cannot access your Mac's home directory, SSH keys, or system files
- **Safe to use `--dangerously-skip-permissions`**: The "danger" is contained within the VM
- **Database data lives in VM**: Destroyed with `vagrant destroy` — back up if needed

---

## Customization

### Change VM resources

Edit the Vagrantfile:

```ruby
vb.memory = "16384"  # 16GB RAM
vb.cpus = 8          # 8 CPUs
```

Then `vagrant reload`.

### Change shared folder location

```ruby
PROJECTS_PATH = File.expand_path("~/projects")  # Change from ~/code
```

### Add more forwarded ports

```ruby
config.vm.network "forwarded_port", guest: 6379, host: 6379  # Redis
config.vm.network "forwarded_port", guest: 3306, host: 3306  # MySQL
```
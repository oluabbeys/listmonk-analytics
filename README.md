# Listmonk Analytics Dashboard

A full real-time analytics dashboard for [Listmonk](https://listmonk.app) self-hosted email platform.

## Features

- 📊 **Overview** — Live subscriber stats, campaign performance, domain breakdown
- 📧 **Campaigns** — Full campaign list with open rates, click rates, individual tracking
- 🔴 **Live Feed** — Real-time opens and clicks in the last 60 minutes
- 📈 **Trends** — 30-day open/click/growth trends
- 📋 **Lists** — List health and subscriber breakdown
- 🔍 **Subscriber Lookup** — Full campaign history + engagement score per subscriber
- 🌐 **Domains** — Email domain analysis
- 🔥 **Send Heatmap** — Best day/time to send based on actual open data
- 📥 **CSV Export** — Export individual campaign tracking data

## Requirements

- PHP 7.4+
- PostgreSQL (Listmonk's database)
- Web server (Apache/Nginx)

## Installation

### 1. Clone the repo

```bash
git clone https://github.com/YOUR_USERNAME/listmonk-analytics.git
cd listmonk-analytics
```

### 2. Configure database

```bash
cp includes/config.php.example includes/config.php
nano includes/config.php
```

Update with your Listmonk database credentials:

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '9432');
define('DB_NAME', 'listmonk');
define('DB_USER', 'listmonk');
define('DB_PASS', 'your-password-here');
```

### 3. Set up web server

**Nginx config:**
```nginx
server {
    listen 80;
    server_name analytics.yourdomain.com;
    root /path/to/listmonk-analytics;
    index index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 4. Deploy on server

```bash
ssh user@your-server
cd /var/www
git clone https://github.com/YOUR_USERNAME/listmonk-analytics.git
cp listmonk-analytics/includes/config.php.example listmonk-analytics/includes/config.php
nano listmonk-analytics/includes/config.php
```

## Deployment with GitHub

```bash
# On server — pull latest changes
cd /var/www/listmonk-analytics
git pull origin main
```

## Security

- Keep `includes/config.php` out of version control (already in .gitignore)
- Add HTTP Basic Auth to the web server config for production
- Only accessible from trusted IPs recommended

## License

MIT

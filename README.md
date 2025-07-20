# WP Rocket Mobile Cache Preloader

Crawl sitemap URLs with a mobile user-agent to trigger mobile cache creation (useful when using WP Rocket and separate mobile caching is enabled). This plugin allows scheduled sitemap crawling up to **three times daily**, including a configurable fallback run, to ensure your mobile pages are cached even if primary runs fail.

---

## 🚀 Features

- Triggers mobile cache creation by simulating requests with a mobile user-agent.
- Admin-defined cron times (2 primary + 1 optional fallback).
- Manual triggering via secure secret key (for system cron).
- Sitemap URL input with full support for XML parsing.
- Email notifications when crawl starts or fails to start.
- Adjustable delay between page requests (0–10 seconds).
- View log output from the WordPress admin panel.
- Clear debug log directly from plugin settings.
- Works with **WP-Cron** or **external system cron** (recommended).

---

## 🧩 Requirements

- WordPress 6.0 or higher  
- PHP 8.0 or higher  
- WP Rocket (if you're using this to warm mobile caches)

---

## 📦 Installation

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins > Installed Plugins** menu in WordPress.
3. Navigate to **Mobile Cache** in the admin menu.
4. Add your sitemap URLs and configure settings.

---

## 🛠️ Usage

1. **Enter Sitemap URLs**: Paste sitemap URLs into the settings textarea (one per line).
2. **Set Request Delay**: Define a delay (in seconds) between page requests (0–10).
3. **Configure Cron Times**:
   - `Run Time #1` (daily)
   - `Run Time #2` (daily)
   - Optional `Fallback Run Time` (if earlier runs fail)
4. **Enable Notifications**: Check the box to get email alerts on start/failure.
5. **Add System Cron Job (optional)**: Use this for reliability:
   ```bash
   wget -q -O - https://yourdomain.com/?mcp_cron=YOUR_SECRET_KEY >/dev/null 2>&1


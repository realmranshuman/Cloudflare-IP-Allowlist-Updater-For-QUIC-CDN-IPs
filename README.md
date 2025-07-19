# QUIC.cloud Service IP Allowlist Updater for Cloudflare

**A zero-configuration, "drop-in" PHP script to automatically allowlist QUIC.cloud services in your Cloudflare firewall, enabling seamless integration between LiteSpeed Cache and Cloudflare.**

---

## 🚀 The Problem This Solves

You want to use the best of both worlds:
1.  **LiteSpeed Cache's** powerful on-server features, powered by **QUIC.cloud's online services** (like Unused CSS Removal, Critical CSS Generation, Image Optimization, etc.).
2.  **Cloudflare's** world-class CDN, WAF, and edge caching features.

The conflict arises when Cloudflare's security measures (like the WAF or "Bot Fight Mode") see frequent requests from QUIC.cloud's servers and incorrectly challenge or block them. This can prevent critical optimization tasks from completing successfully. Manually allowlisting IPs is not a solution, as QUIC.cloud's server IPs can and do change over time.

## 🛡️ The Solution

This script acts as an intelligent bridge between the two services. It runs on your WordPress server and performs the following actions:
- It securely fetches your Cloudflare API credentials from your LiteSpeed Cache plugin settings.
- It gets the latest list of official IP addresses used by QUIC.cloud's online services.
- It connects to your Cloudflare account and intelligently synchronizes your **IP Access Rules**.
- It **only adds** IPs that are new and required.
- It **only removes** IPs that it previously added but are no longer in use by QUIC.cloud.

**Most importantly, it will never touch any firewall rules that you have added manually.** This ensures maximum safety and reliability.

## ⚙️ How It Works: Smart & Stable

This script is built to be robust in any hosting environment.
-   **Dual-Mode Execution:** It automatically detects if it's being run from a server's command line (for a cron job) or in a web browser.
    -   **Cron Job Mode:** Processes all required changes in a single, efficient run. This is the recommended "set and forget" method.
    -   **Browser Mode:** To prevent server timeouts on shared hosting, the script cleverly breaks the task into small batches. It will process a few IPs and then automatically refresh the page to start the next batch, continuing until the job is complete.
-   **Safe Rule Management:** The script adds a unique note (`Managed by QUIC.cloud Sync Script`) to every rule it creates. This is how it safely identifies which rules to manage, leaving your other settings untouched.

## ✅ Prerequisites

Before you begin, please ensure you have the following:
1.  A live WordPress website.
2.  The **LiteSpeed Cache** plugin installed and activated.
3.  Your Cloudflare account connected within the LiteSpeed Cache plugin settings (`LiteSpeed Cache > General > Cloudflare API`). You must use the **Global API Key** method, as this is what the script uses to authenticate.
4.  The ability to place a PHP file in the root directory of your WordPress installation (the same folder as `wp-config.php`).

## 🛠️ Installation & Usage

### Step 1: Download the Script
Download the `quic-cloud-allowlist-sync.php` script from this repository.

### Step 2: (Recommended) Rename the Script
For security, it is highly recommended to rename the file to something random and unpredictable. This prevents unauthorized discovery and execution.
-   **Good:** `qcs-cf-sync-a9b7c3d2e1f.php`
-   **Bad:** `script.php`

### Step 3: Upload the Script
Place the renamed PHP file into the **root directory** of your WordPress installation.

### Usage
You can run the sync process in two ways:

#### Method 1: Manual Sync via Browser (Easy, one-time run)
1.  Log into your WordPress site as an **Administrator**.
2.  In your browser, navigate directly to the script's URL. Example: `https://your-domain.com/qcs-cf-sync-a9b7c3d2e1f.php`
3.  The script will start. If many changes are needed, you will see the page automatically refresh as it processes each batch. Wait for the "Sync Complete!" message.

#### Method 2: Automated Sync via Cron Job (Recommended for "Set & Forget")
This is the best method for keeping the allowlist updated automatically.
1.  Log into your hosting control panel (cPanel, Plesk, etc.) and find the "Cron Jobs" section.
2.  Create a new cron job. It is recommended to run it once per day.
3.  Use the following command, making sure to replace the path and filename with your own.

**Sample Cron Command (runs once daily at 3:00 AM):**
```bash
0 3 * * * /usr/bin/php /home/your_username/public_html/qcs-cf-sync-a9b7c3d2e1f.php >/dev/null 2>&1

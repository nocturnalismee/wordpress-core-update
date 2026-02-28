# Modern WordPress Core Updater & Reinstaller

A robust, standalone PHP script designed to safely update or reinstall WordPress core files directly from your server. Featuring a modern, responsive UI and advanced safety mechanisms, this tool is perfect for fixing corrupted WordPress installations, resolving malware infections, or forcing an update without relying on the WordPress dashboard.

---

## ✨ Features

*   🛡️ **Ultra-Safe Core Replacement:** Unlike older scripts that wipe the entire root directory, this script **only deletes `wp-admin` and `wp-includes`**. It leaves `wp-content`, `wp-config.php`, and any custom folders (like subdomains, Laravel apps, or Google verification files) completely untouched.
*   🎨 **Modern UI/UX:** Features a clean, Tailwind-inspired design with sleek typography, SVG icons, and a beautiful loading overlay. No more looking at ugly, raw HTML logs!
*   🌍 **Bilingual Support:** Easily switch between English and Indonesian (`?lang=en` or `?lang=id`).
*   🔒 **Built-in Security Checks:** Scans your core files for dangerous `0777` permissions and warns you immediately. Also includes CSRF token protection to prevent malicious automated executions.
*   ⚡ **Resilient on Shared Hosting:** Configured with a 10-minute timeout limit and SSL verification bypass to ensure downloads and extractions succeed even on low-end servers or servers with expired CA certificates.
*   📋 **Real-time Execution Logs:** Displays a neat, terminal-like log dashboard upon completion so you know exactly what happened behind the scenes.

---

## ⚙️ Server Requirements

Before running this script, ensure your server meets the following requirements:
*   **PHP:** Version 7.2 or higher (PHP 8.x fully supported).
*   **Extensions Enabled:** `cURL`, `ZipArchive`, and `session`.
*   **Permissions:** The web server must have write permissions to the WordPress root directory.

---

## 📖 How to Use

> **⚠️ EXTREMELY IMPORTANT:** Always perform a full backup of your files and database before running this script.

1. **Download the Script:** Save the provided PHP code into a file named `reinstall-wp-core.php`.
2. **Upload to Server:** Upload `reinstall-wp-core.php` into your WordPress root directory (the same folder where your `wp-config.php` is located).
3. **Run the Script:** Open your web browser and navigate to the script URL:
   ```text
   https://yourdomain.com/reinstall-wp-core.php
   ```
4. **Follow the UI:** 
   * The script will automatically detect your current WordPress version and the latest available version.
   * If there are `0777` permission warnings, take note of them.
   * Click **Start Update Process** (or **Reinstall Core** if forcing an update).
5. **Wait for Completion:** Do not close the tab while the loading overlay is active. The script will download, extract, and replace the core files.
6. **🧹 Clean Up (Crucial Step):** Once the process is successfully completed, **DELETE** the `reinstall-wp-core.php` file from your server to prevent unauthorized access.

---

## 🔍 How It Works (Under the Hood)

When you click the update button, the script performs the following steps sequentially:
1. Validates the CSRF token to ensure the request is legitimate.
2. Downloads the latest official `latest.zip` directly from `https://wordpress.org`.
3. Extracts the ZIP file into a temporary secure folder.
4. **Safely deletes** the old `wp-admin` and `wp-includes` directories.
5. **Copies** the new core files into your root directory, strictly bypassing the `wp-content` folder to ensure your themes, plugins, and uploads remain perfectly intact.
6. Cleans up all temporary ZIP files and extraction folders.

---

## ⚠️ Disclaimer

This tool is provided "as is" without any warranties. While it has been specifically engineered to prevent accidental data loss, server environments vary greatly. The author is not responsible for any data loss, server downtime, or damage caused by the use of this script. **Always back up your site.**

---

*Designed with ❤️ for System Administrators, Web Developers, and WordPress Enthusiasts.*


# WordPress Plugin Recovery Manager

A standalone PHP script for managing WordPress plugins when the admin area is inaccessible.

## Overview

This tool provides a web-based interface for listing, activating, and deactivating WordPress plugins directly through database manipulation, bypassing the WordPress admin area. It's particularly useful when you're locked out of WordPress due to plugin conflicts or errors.

## Features

- Direct access to plugin management when WordPress admin is unavailable
- Lists all installed plugins with metadata (name, version, author)
- Shows active/inactive status for each plugin
- Allows activation/deactivation of multiple plugins at once
- Works independently of WordPress core
- IP-based access restriction for security

## Requirements

- PHP 7.4+ (uses arrow functions)
- MySQL/MariaDB database
- Direct server file access
- Database credentials (automatically extracted from wp-config.php)

## Installation

1. Download `wpprm.php` to your WordPress root directory (same location as wp-config.php)
2. Edit the `ALLOWED_IPS` array to include your IP address
3. Access the script directly in your browser: `https://your-site.com/wpprm.php`

## Security Warning

This script provides direct access to your WordPress database and plugin system. Always:

- Update the `ALLOWED_IPS` array with your specific IP address
- Remove the script from your server after use
- Never leave this accessible on a production site
- Rename the script for extra security

## Usage

1. Access the script URL in your browser
2. You'll see a table of all plugins with their current status
3. Check the boxes next to plugins you want to modify
4. Select "Activate" or "Deactivate" from the dropdown
5. Click "Apply to Selected" to perform the action

## Deactivating a Problematic Plugin

If a plugin is causing your site to crash:
1. Upload and configure this script
2. Access it directly via your browser
3. Find the problematic plugin in the list
4. Select it and choose "Deactivate"
5. Apply changes and remove the script

## Bulk-Activating Essential Plugins

After a clean WordPress installation:
1. Check boxes next to all your essential plugins
2. Select "Activate" from the dropdown
3. Apply changes

## How It Works

The script:
1. Reads your wp-config.php file to get database credentials
2. Connects directly to your database
3. Scans the plugins directory to find all installed plugins
4. Reads the active_plugins option from the options table
5. Provides a UI to modify this list
6. Updates the database when changes are submitted

## Troubleshooting

- **Access Denied**: Verify your IP address and update the `ALLOWED_IPS` array
- **No Plugins Found**: Ensure the script is in the WordPress root directory
- **Database Connection Failed**: Check that wp-config.php is accessible and contains valid credentials

## Authors

- Jonathan Moore - [JMooreWV](https://jmoorewv.com)

## License

This script is released under the MIT License. See the LICENSE file for details.

## Acknowledgements

This tool was developed as an emergency recovery solution and is inspired by WordPress's own plugin management system.

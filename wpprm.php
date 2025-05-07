<?php

/**
 * WordPress Plugin Recovery Manager
 *
 * Author: Jonathan Moore ( https://jmoorewv.com )
 * Updated: May 7, 2025
 *
 * Description:
 *
 * This standalone script provides a web-based interface for listing,
 * activating, and deactivating WordPress plugins directly from the
 * database. It is especially useful when the main WordPress interface
 * is unavailable due to critical errors caused by plugin conflicts.
 *
 * Features:
 *
 * - Reads plugin metadata from the plugin directory
 * - Retrieves and updates the active_plugins option in the database
 * - Allows plugin activation and deactivation via a form
 * - Enforces IP-based access restriction for security
 * - Designed to run independently of WordPress
 *
 * Requirements:
 *
 * - Must reside in the root of a WordPress installation
 * - Access to wp-config.php to extract DB credentials
 * - Direct MySQL access via mysqli
 *
 * Intended Use:
 *
 * Use this tool as a last resort recovery utility when locked out of the
 * WordPress admin area or experiencing fatal plugin-related issues.
 */

define('WP_ROOT', './');                                 // Set the WordPress root to current directory
define('PLUGINS_DIR', WP_ROOT . 'wp-content/plugins/');  // Path to plugins directory
define('WP_CONFIG_PATH', WP_ROOT . 'wp-config.php');     // Path to wp-config.php
define('ALLOWED_IPS', ['127.0.0.1']);                    // Array of allowed IPs for access (add your IP here)

/**
 * Get the client's IP address from server variables
 *
 * @return string The visitor's IP address or a fallback value
 */
function get_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Check if the client's IP address is allowed
 *
 * @param string $ip The visitor's IP address
 * @return bool True if the IP is allowed, false otherwise
 */
function is_ip_allowed( string $ip ): bool {
    return in_array( $ip, ALLOWED_IPS );
}

/**
 * Parse the wp-config.php file to extract database credentials
 *
 * @param string $path Path to the wp-config.php file
 * @return array An associative array containing database credentials
 */
function parse_wp_config( string $path ): array {
    $config = file_exists( $path ) ? file_get_contents( $path ) : '';
    $find   = fn( $pattern ) => preg_match( $pattern, $config, $m ) ? $m[1] : '';
    return [
        'db_name'   => $find( "/define\(\s*'DB_NAME',\s*'([^']+)'\s*\)/" ),                 // Extract database name
        'db_user'   => $find( "/define\(\s*'DB_USER',\s*'([^']+)'\s*\)/" ),                 // Extract database user
        'db_pass'   => $find( "/define\(\s*'DB_PASSWORD',\s*'([^']*)'\s*\)/" ),             // Extract database password
        'db_host'   => $find( "/define\(\s*'DB_HOST',\s*'([^']+)'\s*\)/" ) ?: 'localhost',  // Extract host or default to localhost
        'db_prefix' => $find( "/\\\$table_prefix\s*=\s*'([^']+)'/" ) ?: 'wp_',              // Extract table prefix or default to wp_
    ];
}

/**
 * Connect to the MySQL database using mysqli
 *
 * @param array $config An associative array containing database credentials
 * @return mysqli|null The mysqli connection object or null on failure
 */
function connect_db( array $config ): ?mysqli {
    $conn = @new mysqli( $config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name'] );
    return $conn->connect_error ? null : $conn;
}

/**
 * Get plugin headers from a PHP file
 *
 * @param string $file Path to the plugin file
 * @return array An associative array containing plugin metadata
 */
function get_plugin_headers( string $file ): array {
    // Define all possible header fields with their corresponding comment header text
    $default_headers = [
        'Name'        => 'Plugin Name',
        'PluginURI'   => 'Plugin URI',
        'Version'     => 'Version',
        'Description' => 'Description',
        'Author'      => 'Author',
        'AuthorURI'   => 'Author URI',
        'TextDomain'  => 'Text Domain',
        'DomainPath'  => 'Domain Path',
        'Network'     => 'Network',
        'RequiresWP'  => 'Requires at least',
        'RequiresPHP' => 'Requires PHP',
        'IsValid'     => false
    ];

    // Return default headers if file doesn't exist
    if ( !file_exists( $file ) ) return $default_headers;

    // Read the first 8KB of the file (headers are typically at the top
    $fp = fopen( $file, 'r' );
    $file_data = fread( $fp, 8192 );
    fclose( $fp );

    // Normalize line endings
    $file_data = str_replace( "\r", "\n", $file_data );

    // Extract each header field using regex patterns
    foreach ( $default_headers as $field => $regex ) {
        if ( $field === 'IsValid' ) continue;

        // Look for the header pattern in the file data
        if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) ) {
            // Clean up the match by removing trailing comment markers
            $default_headers[$field] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
        } else {
            $default_headers[$field] = '';
        }
    }

    // A plugin is considered valid if it has a name
    $default_headers['IsValid'] = !empty( $default_headers['Name'] );
    return $default_headers;
}

/**
 * Scan the plugins directory for valid plugin files
 *
 * @return array An associative array of valid plugins with their metadata
 */
function scan_plugins(): array {
    $plugins = [];

    // Return empty array if plugins directory doesn't exist
    if ( !is_dir( PLUGINS_DIR ) ) {
        return $plugins;
    }

    // Loop through all items in the plugins directory
    foreach ( scandir( PLUGINS_DIR ) as $item ) {
        // Skip directory navigation entries
        if ( $item === '.' || $item === '..' ) continue;

        $path = PLUGINS_DIR . $item;

        if ( is_dir( $path ) ) {
            // For directories, look for the main plugin file (same name as directory)
            $main = "$path/$item.php";

            if ( !file_exists( $main ) ) {
                // If the standard naming convention isn't followed, search for any PHP file with valid headers
                $found = false;
                $php_files = glob( "$path/*.php" );

                // Sort files to prioritize certain files (not index.php) and by directory depth
                usort( $php_files, function ( $a, $b ) {
                    if ( basename( $a ) === 'index.php' ) return -1;
                    if ( basename( $b ) === 'index.php' ) return 1;
                    $a_depth = substr_count( $a, '/' );
                    $b_depth = substr_count( $b, '/' );
                    if ( $a_depth !== $b_depth ) return $a_depth - $b_depth;
                    return strcmp( $a, $b );
                });

                // Check each PHP file until we find one with valid plugin headers
                foreach ( $php_files as $php ) {
                    $data = get_plugin_headers( $php );
                    if ( $data['IsValid'] ) {
                        $rel = str_replace( PLUGINS_DIR, '', $php );
                        $plugins[$rel] = $data;
                        $found = true;
                        break;
                    }
                }

                if ( !$found ) continue;
            } else {
                // Check the main plugin file if it exists
                $data = get_plugin_headers( $main );
                if ( $data['IsValid'] ) {
                    $rel = str_replace( PLUGINS_DIR, '', $main );
                    $plugins[$rel] = $data;
                }
            }
        } elseif ( substr( $item, -4 ) === '.php' ) {
            // For standalone plugin files (not in subdirectory)
            $data = get_plugin_headers( PLUGINS_DIR . $item );
            if ( $data['IsValid'] ) {
                $plugins[$item] = $data;
            }
        }
    }

    return $plugins;
}

/**
 * Get the list of active plugins from the database
 *
 * @param mysqli $db The mysqli connection object
 * @param string $prefix The database table prefix
 * @return array An array of active plugin file names
 */
function get_active_plugins( mysqli $db, string $prefix ): array {
    // Query the options table for the active_plugins option
    $res = $db->query( "SELECT option_value FROM {$prefix}options WHERE option_name = 'active_plugins'" );
    if ( !$res || !( $row = $res->fetch_assoc() ) ) return [];

    // Unserialize the option value (WordPress stores this as serialized PHP)
    $val = @unserialize( $row['option_value'] );
    return is_array( $val ) ? $val : [];
}

/**
 * Update the list of active plugins in the database
 *
 * @param mysqli $db The mysqli connection object
 * @param string $prefix The database table prefix
 * @param array $plugins An array of active plugin file names
 * @return bool True on success, false on failure
 */
function update_active_plugins( mysqli $db, string $prefix, array $plugins ): bool {
    // Serialize the plugins array for stora
    $val = serialize( array_values( $plugins ) );

    // Escape special characters for safe SQL insertion
    $escaped = $db->real_escape_string( $val );

    // Update the active_plugins option in the database
    return $db->query( "UPDATE {$prefix}options SET option_value = '{$escaped}' WHERE option_name = 'active_plugins'" );
}

// Check if the client's IP is allowed to access this tool
if ( !is_ip_allowed( get_client_ip() ) ) {
    die( "Access denied. Your IP: " . htmlspecialchars( get_client_ip() ) );
}

// Parse WordPress configuration and connect to database
$config         = parse_wp_config( WP_CONFIG_PATH );
$conn           = connect_db( $config );
$active_plugins = $conn ? get_active_plugins( $conn, $config['db_prefix'] ) : [];
$all_plugins    = scan_plugins();
$message        = '';

// Process form submission for plugin activation/deactivation
if ( $conn && isset( $_POST['submit'], $_POST['plugins'], $_POST['action'] ) && is_array( $_POST['plugins'] ) ) {
    $action   = $_POST['action'];
    $selected = $_POST['plugins'];
    $updated  = false;

    if ( $action === 'activate' ) {
        // Add selected plugins to active list if not already active
        foreach ( $selected as $plugin ) {
            if ( !in_array( $plugin, $active_plugins ) ) {
                $active_plugins[] = $plugin;
                $updated = true;
            }
        }
    } elseif ( $action === 'deactivate' ) {
        // Remove selected plugins from active list
        $active_plugins = array_values( array_diff( $active_plugins, $selected ) );
        $updated = true;
    }

    // Update the database if changes were made
    if ( $updated && update_active_plugins( $conn, $config['db_prefix'], $active_plugins ) ) {
        $message = ucfirst( $action ) . "d " . count( $selected ) . " plugin(s).";
    } else {
        $message = "No changes made or update failed.";
    }
}

// Close database connection when done
if ($conn) $conn->close();
?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="UTF-8">
        <title>WordPress Plugin Recovery Manager</title>
        <style>
            body {
                font-family: sans-serif;
                margin: 20px;
            }

            .message {
                background: #e0f7fa;
                padding: 10px;
                margin: 10px 0;
                border-left: 4px solid #00acc1;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }

            th,
            td {
                padding: 8px;
                border-bottom: 1px solid #ccc;
            }

            th {
                background: #f5f5f5;
                text-align: left;
            }

            .active {
                color: green;
            }

            .inactive {
                color: red;
            }

            .plugin-name {
                font-weight: 400;
            }

            .plugin-description {
                color: #666;
                font-size: 0.9em;
                margin-top: 5px;
            }

            .debug {
                background: #fff3e0;
                padding: 8px;
                margin: 10px 0;
                border: 1px solid #ffcc80;
            }
        </style>
        <script>
            // Function to toggle the checked state of all checkboxes
            function toggleAll( state ) {
                document.querySelectorAll( 'input[name="plugins[]"]' ).forEach( cb => cb.checked = state );
            }
        </script>
    </head>

    <body>
        <h1>WordPress Plugin Recovery Manager</h1>
        <p>Use this tool to manage plugins when the WordPress admin area is inaccessible.</p>
<?php if ( $message ): ?>
        <div class="message"><?php echo htmlspecialchars( $message ); ?></div>
<?php endif; ?>
<?php if ( empty( $all_plugins ) ): ?>
        <div class="debug">
            <strong>Warning:</strong> No plugins found. Check that the script is placed in the WordPress root directory.
        </div>
<?php endif; ?>

        <form method="post">
            <label><input type="checkbox" onclick="toggleAll( this.checked )"> Select/Deselect All</label>
            <br /><br />
            <label for="action">Action:</label>

            <select name="action" id="action">
                <option value="activate">Activate</option>
                <option value="deactivate">Deactivate</option>
            </select>

            <input type="submit" name="submit" value="Apply to Selected">

            <table>
                <tr>
                    <th>Plugin</th>
                    <th>Version</th>
                    <th>Author</th>
                    <th>Status</th>
                    <th>Select</th>
                </tr>
<?php foreach ( $all_plugins as $file => $info ): ?>
<?php $is_active = in_array( $file, $active_plugins ); ?>
                <tr>
                    <td><div class="plugin-name"><?php echo htmlspecialchars( $info['Name'] ?: 'Unknown' ); ?></div></td>
                    <td><?php echo htmlspecialchars( $info['Version'] ?: 'Unknown' ); ?></td>
                    <td><?php echo htmlspecialchars( $info['Author'] ?: 'Unknown' ); ?></td>
                    <td class="<?php echo $is_active ? 'active' : 'inactive'; ?>"><?php echo $is_active ? 'Active' : 'Inactive'; ?></td>
                    <td><input type="checkbox" name="plugins[]" value="<?php echo htmlspecialchars( $file ); ?>"></td>
                </tr>
<?php endforeach; ?>
            </table>
        </form>

    </body>
</html>

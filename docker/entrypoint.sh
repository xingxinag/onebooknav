#!/bin/bash
set -e

# OneBookNav Docker Entrypoint Script
echo "Starting OneBookNav..."

# Function to update config.php with environment variables
update_config() {
    local config_file="/var/www/html/config/config.php"

    # Config.php should already exist, no need to copy from sample
    if [ ! -f "$config_file" ]; then
        echo "Warning: config.php not found at $config_file"
        return 1
    fi

    # Update configuration with environment variables
    if [ ! -z "$SITE_TITLE" ]; then
        sed -i "s/define('SITE_TITLE', '.*');/define('SITE_TITLE', '$SITE_TITLE');/" "$config_file"
    fi

    if [ ! -z "$SITE_URL" ]; then
        sed -i "s/define('SITE_URL', '.*');/define('SITE_URL', '$SITE_URL');/" "$config_file"
    fi

    if [ ! -z "$DB_TYPE" ]; then
        sed -i "s/define('DB_TYPE', '.*');/define('DB_TYPE', '$DB_TYPE');/" "$config_file"
    fi

    if [ ! -z "$DEBUG_MODE" ]; then
        sed -i "s/define('DEBUG_MODE', .*);/define('DEBUG_MODE', $DEBUG_MODE);/" "$config_file"
    fi

    # Default Admin Account configuration
    if [ ! -z "$DEFAULT_ADMIN_USERNAME" ]; then
        sed -i "s/define('DEFAULT_ADMIN_USERNAME', '.*');/define('DEFAULT_ADMIN_USERNAME', '$DEFAULT_ADMIN_USERNAME');/" "$config_file"
    fi

    if [ ! -z "$DEFAULT_ADMIN_PASSWORD" ]; then
        sed -i "s/define('DEFAULT_ADMIN_PASSWORD', '.*');/define('DEFAULT_ADMIN_PASSWORD', '$DEFAULT_ADMIN_PASSWORD');/" "$config_file"
    fi

    if [ ! -z "$DEFAULT_ADMIN_EMAIL" ]; then
        sed -i "s/define('DEFAULT_ADMIN_EMAIL', '.*');/define('DEFAULT_ADMIN_EMAIL', '$DEFAULT_ADMIN_EMAIL');/" "$config_file"
    fi

    if [ ! -z "$AUTO_CREATE_ADMIN" ]; then
        sed -i "s/define('AUTO_CREATE_ADMIN', .*);/define('AUTO_CREATE_ADMIN', $AUTO_CREATE_ADMIN);/" "$config_file"
    fi

    echo "Configuration updated from environment variables"
}

# Create necessary directories
mkdir -p /var/www/html/data/{logs,cache,backups,uploads}

# Set permissions
chown -R www-data:www-data /var/www/html/data
chmod -R 755 /var/www/html
chmod -R 777 /var/www/html/data

# Update configuration
update_config

echo "OneBookNav initialization completed"
echo "=================================="
echo "Default Admin Credentials:"
echo "Username: ${DEFAULT_ADMIN_USERNAME:-admin}"
echo "Password: ${DEFAULT_ADMIN_PASSWORD:-admin679}"
echo "Email: ${DEFAULT_ADMIN_EMAIL:-admin@example.com}"
echo "=================================="
echo "Please change the default password after first login!"
echo "Access your site at: ${SITE_URL:-http://localhost:3080}"

# Execute the original command
exec "$@"
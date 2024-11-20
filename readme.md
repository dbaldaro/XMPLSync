# XMPL Sync

XMPL Sync is a WordPress plugin that synchronizes user registrations with XMPie campaigns in Circle. When new users register on your WordPress site, their information is automatically sent to your XMPie campaign.

## Features

- Automatic synchronization of new WordPress user registrations with XMPie
- Customizable field mapping between WordPress and XMPie
- Detailed logging system for tracking synchronization events
- Admin interface for configuration and monitoring
- Test functionality for API connection and logging system
- Support for custom GUID generation
- Prevents duplicate synchronizations

## Installation

1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Click "Install Now" and then "Activate"

## Configuration

1. Navigate to Settings > XMPL Sync in your WordPress admin panel
2. Enter your XMPie endpoint URL (e.g., https://marketingx.xmpie.net)
3. Enter your XMPie access token
4. Configure field mappings between WordPress user fields and XMPie API fields

### Available WordPress Fields

- Email Address (user_email)
- Username (user_login)
- First Name (first_name)
- Last Name (last_name)
- Display Name (display_name)
- Website (user_url)
- Registration Date (user_registered)
- User ID (ID)
- Generated GUID (guid)

## Usage

Once configured, the plugin automatically syncs new user registrations with your XMPie campaign. No additional action is required.

### Testing the Integration

The plugin provides several testing tools in the admin interface:

1. Test API Connection - Verifies connectivity with XMPie
2. Test Logging System - Checks if the logging system is working properly
3. Create/Repair Logs Table - Ensures the logging database table exists and is properly structured

### Viewing Sync Logs

1. Go to Settings > XMPL Sync Logs
2. View detailed logs of all synchronization attempts
3. Click "Show Details" on any log entry to see the full request and response data

## Development

### Hooks and Filters

The plugin primarily uses the `user_register` hook to trigger synchronization.

### Database

The plugin creates a custom table `{prefix}xmpl_sync_logs` to store synchronization logs with the following structure:

- id (bigint)
- timestamp (datetime)
- user_id (bigint)
- action (varchar)
- request_data (text)
- response_data (text)
- status (varchar)
- error_message (text)

## Troubleshooting

1. Check the XMPL Sync Logs page for detailed error messages
2. Verify your XMPie endpoint and access token
3. Ensure field mappings are correctly configured
4. Use the test buttons in the settings page to verify connectivity

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- Valid XMPie Circle account with API access

## License

This plugin is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Author

David Baldaro

## Version

1.0.7

## Support

For support, please create an issue in the GitHub repository or contact the author.

## Contributing

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

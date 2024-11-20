<?php
/*
Plugin Name: XMPL Sync
Description: Syncs WordPress user registrations with an XMPie campaign in Circle
Version: 1.0.7
Author: David Baldaro
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin class
class XMPLSync {
    private static $instance = null;
    private $options;
    private $is_syncing = false; // Flag to prevent duplicate syncs
    private $available_wp_fields = array(
        'user_email' => 'Email Address',
        'user_login' => 'Username',
        'first_name' => 'First Name',
        'last_name' => 'Last Name',
        'display_name' => 'Display Name',
        'user_url' => 'Website',
        'user_registered' => 'Registration Date',
        'ID' => 'User ID',
        'guid' => 'Generated GUID' // Special field that will generate a GUID
    );

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = get_option('xmpl_sync_options');
        add_action('admin_menu', array($this, 'addAdminMenu'));
        add_action('admin_init', array($this, 'registerSettings'));
        
        // Remove multiple hooks and use just one primary hook
        add_action('user_register', array($this, 'syncNewUser'), 10, 1);
        
        // Debug logging for registration process
        add_action('init', array($this, 'debugRegistrationProcess'));
        
        add_action('show_user_profile', array($this, 'showRecipientId'));
        add_action('edit_user_profile', array($this, 'showRecipientId'));
        
        // Add AJAX handlers
        add_action('wp_ajax_test_xmpl_logging', array($this, 'testLogging'));
        add_action('wp_ajax_create_xmpl_logs_table', array($this, 'createLogsTableAjax'));

        // Register activation hook properly
        register_activation_hook(__FILE__, array($this, 'pluginActivation'));
    }

    public function pluginActivation() {
        $this->createLogsTable();
    }

    public function createLogsTable() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'xmpl_sync_logs';
        
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            user_id bigint(20),
            action varchar(50) NOT NULL,
            request_data text,
            response_data text,
            status varchar(20) NOT NULL,
            error_message text,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);

        // Return creation result
        return array(
            'success' => true,
            'message' => 'Table creation attempted',
            'details' => $result
        );
    }

    public function createLogsTableAjax() {
        $result = $this->createLogsTable();
        wp_send_json_success($result);
    }

    public function addAdminMenu() {
        add_options_page(
            'XMPL Sync Settings',
            'XMPL Sync',
            'manage_options',
            'xmpl-sync',
            array($this, 'renderSettingsPage')
        );

        // Add new logs page
        add_submenu_page(
            'options-general.php',
            'XMPL Sync Logs',
            'XMPL Sync Logs',
            'manage_options',
            'xmpl-sync-logs',
            array($this, 'renderLogsPage')
        );
    }

    public function registerSettings() {
        register_setting(
            'xmpl_sync_options', 
            'xmpl_sync_options',
            array($this, 'validateSettings')
        );

        // Existing settings section
        add_settings_section(
            'xmpl_sync_main',
            'XMPie Integration Settings',
            null,
            'xmpl-sync'
        );
        
        // Existing fields
        add_settings_field(
            'xmpl_endpoint',
            'XMPie Endpoint',
            array($this, 'endpointCallback'),
            'xmpl-sync',
            'xmpl_sync_main'
        );
        
        add_settings_field(
            'xmpl_access_token',
            'Access Token',
            array($this, 'accessTokenCallback'),
            'xmpl-sync',
            'xmpl_sync_main'
        );

        // New field mappings section
        add_settings_section(
            'xmpl_sync_mappings',
            'Field Mappings',
            array($this, 'renderMappingsDescription'),
            'xmpl-sync'
        );

        add_settings_field(
            'xmpl_field_mappings',
            'API Field Mappings',
            array($this, 'fieldMappingsCallback'),
            'xmpl-sync',
            'xmpl_sync_mappings'
        );
    }

    public function renderMappingsDescription() {
        echo '<p>Map WordPress user fields to XMPie API fields. The "API Field Name" will be used as the key in the API request.</p>';
    }

    public function fieldMappingsCallback() {
        $mappings = $this->options['field_mappings'] ?? array();
        ?>
        <div id="field-mappings-container">
            <?php
            if (!empty($mappings)) {
                foreach ($mappings as $index => $mapping) {
                    $this->renderMappingRow($index, $mapping['api_field'], $mapping['wp_field']);
                }
            }
            ?>
        </div>
        <button type="button" class="button" id="add-mapping">Add New Mapping</button>

        <script type="text/template" id="mapping-row-template">
            <?php $this->renderMappingRow('{{index}}', '', ''); ?>
        </script>

        <script>
        jQuery(document).ready(function($) {
            var container = $('#field-mappings-container');
            var template = $('#mapping-row-template').html();
            var index = <?php echo !empty($mappings) ? max(array_keys($mappings)) + 1 : 0; ?>;

            $('#add-mapping').on('click', function() {
                var newRow = template.replace(/{{index}}/g, index);
                container.append(newRow);
                index++;
            });

            $(document).on('click', '.remove-mapping', function() {
                $(this).closest('.mapping-row').remove();
            });
        });
        </script>
        <style>
        .mapping-row { margin-bottom: 10px; }
        .mapping-row input { margin-right: 10px; }
        .remove-mapping { color: #dc3232; text-decoration: none; }
        </style>
        <?php
    }

    private function renderMappingRow($index, $api_field, $wp_field) {
        ?>
        <div class="mapping-row">
            <input type="text" 
                   name="xmpl_sync_options[field_mappings][<?php echo esc_attr($index); ?>][api_field]" 
                   value="<?php echo esc_attr($api_field); ?>" 
                   placeholder="API Field Name"
                   class="regular-text">
            
            <select name="xmpl_sync_options[field_mappings][<?php echo esc_attr($index); ?>][wp_field]">
                <option value="">Select WordPress Field</option>
                <?php foreach ($this->available_wp_fields as $field_key => $field_label): ?>
                    <option value="<?php echo esc_attr($field_key); ?>" 
                            <?php selected($wp_field, $field_key); ?>>
                        <?php echo esc_html($field_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <a href="#" class="remove-mapping">Remove</a>
        </div>
        <?php
    }

    public function validateSettings($input) {
        $validated = array();
        
        $validated['endpoint'] = esc_url_raw($input['endpoint']);
        $validated['access_token'] = sanitize_text_field($input['access_token']);
        
        if (isset($input['field_mappings']) && is_array($input['field_mappings'])) {
            foreach ($input['field_mappings'] as $index => $mapping) {
                if (!empty($mapping['api_field']) && !empty($mapping['wp_field'])) {
                    $validated['field_mappings'][$index] = array(
                        'api_field' => sanitize_text_field($mapping['api_field']),
                        'wp_field' => sanitize_text_field($mapping['wp_field'])
                    );
                }
            }
        }
        
        return $validated;
    }

    public function syncNewUser($user_id) {
        // Prevent duplicate syncs
        if ($this->is_syncing) {
            $this->logSync($user_id, 'SYNC_SKIPPED', array(
                'reason' => 'Duplicate sync prevention',
                'hook' => current_filter()
            ), '', 'INFO');
            return;
        }

        $this->is_syncing = true;

        // Initial debug log
        $this->logSync($user_id, 'SYNC_START', array(
            'user_id' => $user_id,
            'hook' => current_filter(),
            'timestamp' => current_time('mysql')
        ), '', 'INFO');

        $user = get_userdata($user_id);
        if (!$user) {
            $this->logSync($user_id, 'SYNC_ERROR', '', '', 'ERROR', 'Unable to get user data');
            $this->is_syncing = false;
            return;
        }

        // Check if user has already been synced
        $recipient_id = get_user_meta($user_id, 'xmpl_recipient_id', true);
        if (!empty($recipient_id)) {
            $this->logSync($user_id, 'SYNC_SKIPPED', array(
                'reason' => 'User already synced',
                'recipient_id' => $recipient_id
            ), '', 'INFO');
            $this->is_syncing = false;
            return;
        }

        $endpoint = $this->options['endpoint'] ?? '';
        $access_token = $this->options['access_token'] ?? '';
        $mappings = $this->options['field_mappings'] ?? array();

        // Log configuration status
        $this->logSync($user_id, 'CONFIG_CHECK', array(
            'has_endpoint' => !empty($endpoint),
            'has_token' => !empty($access_token),
            'has_mappings' => !empty($mappings),
            'endpoint' => $endpoint,
            'mappings' => $mappings
        ), '', 'INFO');

        if (empty($endpoint) || empty($access_token) || empty($mappings)) {
            $this->logSync($user_id, 'SYNC_ERROR', '', '', 'ERROR', 'Missing configuration');
            $this->is_syncing = false;
            return;
        }

        $api_url = trailingslashit($endpoint) . "XMPieXMPL_REST_API/v1/projects/{$access_token}/adorvalues";

        // Build the newRecipientValues object
        $recipient_values = array();
        foreach ($mappings as $mapping) {
            $api_field = $mapping['api_field'];
            $wp_field = $mapping['wp_field'];

            if ($wp_field === 'guid') {
                $recipient_values[$api_field] = wp_generate_uuid4();
            } else {
                $recipient_values[$api_field] = $user->$wp_field;
            }
        }

        $body = array(
            'newRecipientValues' => $recipient_values
        );

        // Log the request
        $this->logSync($user_id, 'API_REQUEST', array(
            'url' => $api_url,
            'method' => 'POST',
            'body' => $body,
            'user_data' => array(
                'email' => $user->user_email,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name
            )
        ), '', 'INFO');

        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));

        // Log raw response
        $this->logSync($user_id, 'RAW_RESPONSE', array(
            'is_wp_error' => is_wp_error($response),
            'response' => $response
        ), '', 'INFO');

        if (is_wp_error($response)) {
            $this->logSync($user_id, 'API_ERROR', '', '', 'ERROR', $response->get_error_message());
            $this->is_syncing = false;
            return;
        }

        $response_body = json_decode(wp_remote_retrieve_body($response), true);
        $response_code = wp_remote_retrieve_response_code($response);

        // Log response details
        $this->logSync($user_id, 'API_RESPONSE_DETAILS', array(
            'code' => $response_code,
            'body' => $response_body,
            'headers' => wp_remote_retrieve_headers($response)
        ), '', 'INFO');

        if ($response_code >= 200 && $response_code < 300 && isset($response_body['recipientID'])) {
            update_user_meta($user_id, 'xmpl_recipient_id', $response_body['recipientID']);
            $this->logSync($user_id, 'SYNC_SUCCESS', '', $response_body, 'SUCCESS');
        } else {
            $this->logSync($user_id, 'API_ERROR', '', $response_body, 'ERROR', 
                'Invalid response (Code: ' . $response_code . ') or missing recipientID');
        }

        $this->is_syncing = false;
    }

    // Settings page callbacks
    public function renderSettingsPage() {
        ?>
        <div class="wrap">
            <h2>XMPL Sync Settings</h2>
            
            <div class="test-buttons" style="margin: 20px 0;">
                <button type="button" class="button button-secondary" id="test-xmpl-connection" style="margin-right: 10px;">
                    Test API Connection
                </button>
                <button type="button" class="button button-secondary" id="test-xmpl-logging" style="margin-right: 10px;">
                    Test Logging System
                </button>
                <button type="button" class="button button-secondary" id="create-logs-table">
                    Create/Repair Logs Table
                </button>
                <div id="test-result" style="margin-top: 10px;"></div>
                <div id="log-test-result" style="margin-top: 10px;"></div>
                <div id="table-create-result" style="margin-top: 10px;"></div>
            </div>

            <form action="options.php" method="post">
                <?php
                settings_fields('xmpl_sync_options');
                do_settings_sections('xmpl-sync');
                submit_button();
                ?>
            </form>
        </div>

        <style>
        .test-details {
            background: #f8f9fa;
            padding: 10px;
            border: 1px solid #ddd;
            margin-top: 10px;
            max-height: 500px;
            overflow-y: auto;
        }
        .test-details pre {
            margin: 0;
            white-space: pre-wrap;
        }
        .test-section {
            margin-bottom: 15px;
        }
        .test-section h4 {
            margin: 0 0 5px 0;
        }
        .log-error {
            color: red;
            margin-top: 5px;
        }
        .log-success {
            color: green;
            margin-top: 5px;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#test-xmpl-connection').on('click', function() {
                var button = $(this);
                var result = $('#test-result');
                
                button.prop('disabled', true);
                result.html('<div style="color: blue;">Testing connection...</div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_xmpl_connection'
                    },
                    success: function(response) {
                        var html = '';
                        if (response.success) {
                            html += '<div style="color: green;">Connection successful!</div>';
                            html += '<div class="test-details">';
                            
                            // Request Details
                            html += '<div class="test-section">';
                            html += '<h4>Request Details:</h4>';
                            html += '<pre>' + JSON.stringify(response.data.request, null, 2) + '</pre>';
                            html += '</div>';

                            // Response Details
                            html += '<div class="test-section">';
                            html += '<h4>API Response:</h4>';
                            html += '<pre>' + JSON.stringify(response.data.response, null, 2) + '</pre>';
                            html += '</div>';
                            
                            html += '</div>';
                        } else {
                            html += '<div style="color: red;">Connection failed: ' + response.data.message + '</div>';
                            html += '<div class="test-details">';
                            
                            // Error Details
                            if (response.data.details) {
                                html += '<div class="test-section">';
                                html += '<h4>Request Details:</h4>';
                                html += '<pre>' + JSON.stringify(response.data.details, null, 2) + '</pre>';
                                html += '</div>';
                            }
                            
                            html += '</div>';
                        }
                        result.html(html);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        var html = '<div style="color: red;">Test failed!</div>';
                        html += '<div class="test-details">';
                        html += '<pre>Status: ' + textStatus + '\nError: ' + errorThrown + '</pre>';
                        html += '</div>';
                        result.html(html);
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            $('#test-xmpl-logging').on('click', function() {
                var button = $(this);
                var result = $('#log-test-result');
                
                button.prop('disabled', true);
                result.html('<div style="color: blue;">Testing logging system...</div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_xmpl_logging'
                    },
                    success: function(response) {
                        var html = '';
                        if (response.success) {
                            html += '<div class="log-success">Logging system test successful!</div>';
                            html += '<div class="test-details">';
                            html += '<div class="test-section">';
                            html += '<h4>Test Results:</h4>';
                            html += '<pre>' + JSON.stringify(response.data, null, 2) + '</pre>';
                            html += '</div>';
                            html += '</div>';
                        } else {
                            html += '<div class="log-error">Logging system test failed!</div>';
                            html += '<div class="test-details">';
                            html += '<div class="test-section">';
                            html += '<h4>Error Details:</h4>';
                            html += '<pre>' + JSON.stringify(response.data, null, 2) + '</pre>';
                            html += '</div>';
                            html += '</div>';
                        }
                        result.html(html);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        var html = '<div class="log-error">Logging system test failed!</div>';
                        html += '<div class="test-details">';
                        html += '<pre>Status: ' + textStatus + '\nError: ' + errorThrown + '</pre>';
                        html += '</div>';
                        result.html(html);
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            $('#create-logs-table').on('click', function() {
                var button = $(this);
                var result = $('#table-create-result');
                
                button.prop('disabled', true);
                result.html('<div style="color: blue;">Creating/repairing logs table...</div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'create_xmpl_logs_table'
                    },
                    success: function(response) {
                        if (response.success) {
                            var html = '<div class="log-success">Logs table creation/repair attempted!</div>';
                            html += '<div class="test-details">';
                            html += '<pre>' + JSON.stringify(response.data, null, 2) + '</pre>';
                            html += '</div>';
                            result.html(html);
                            
                            // Automatically run the logging test after table creation
                            $('#test-xmpl-logging').trigger('click');
                        } else {
                            result.html('<div class="log-error">Failed to create/repair logs table!</div>');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        result.html('<div class="log-error">Failed to create/repair logs table: ' + errorThrown + '</div>');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function endpointCallback() {
        $value = $this->options['endpoint'] ?? '';
        echo '<input type="url" name="xmpl_sync_options[endpoint]" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">Enter the XMPie endpoint URL (e.g., https://marketingx.xmpie.net)</p>';
    }

    public function accessTokenCallback() {
        $value = $this->options['access_token'] ?? '';
        echo '<input type="text" name="xmpl_sync_options[access_token]" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">Enter your XMPie access token</p>';
    }

    public function showRecipientId($user) {
        $recipient_id = get_user_meta($user->ID, 'xmpl_recipient_id', true);
        if ($recipient_id) {
            ?>
            <h3>XMPL Sync Information</h3>
            <table class="form-table">
                <tr>
                    <th><label>Recipient ID</label></th>
                    <td><?php echo esc_html($recipient_id); ?></td>
                </tr>
            </table>
            <?php
        }
    }

    public function renderLogsPage() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'xmpl_sync_logs';
        
        // Add pagination
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $items_per_page = 20;
        $offset = ($page - 1) * $items_per_page;
        
        // Get total items for pagination
        $total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name");
        $total_pages = ceil($total_items / $items_per_page);

        // Get logs with pagination
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY timestamp DESC LIMIT %d OFFSET %d",
                $items_per_page,
                $offset
            )
        );

        ?>
        <div class="wrap">
            <h1>XMPL Sync Logs</h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->timestamp); ?></td>
                            <td>
                                <?php 
                                $user = get_userdata($log->user_id);
                                echo $user ? esc_html($user->user_email) : 'N/A';
                                ?>
                            </td>
                            <td><?php echo esc_html($log->action); ?></td>
                            <td>
                                <span class="status-<?php echo strtolower($log->status); ?>">
                                    <?php echo esc_html($log->status); ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="button show-details" 
                                        onclick="toggleDetails(this, <?php echo esc_attr($log->id); ?>)">
                                    Show Details
                                </button>
                                <div id="details-<?php echo esc_attr($log->id); ?>" class="log-details" style="display:none;">
                                    <pre><?php 
                                    echo "Request: " . esc_html($log->request_data) . "\n\n";
                                    echo "Response: " . esc_html($log->response_data) . "\n\n";
                                    if ($log->error_message) {
                                        echo "Error: " . esc_html($log->error_message);
                                    }
                                    ?></pre>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo;'),
                            'next_text' => __('&raquo;'),
                            'total' => $total_pages,
                            'current' => $page
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <style>
        .log-details { margin-top: 10px; }
        .log-details pre { white-space: pre-wrap; }
        .status-success { color: green; }
        .status-error { color: red; }
        .status-pending { color: orange; }
        </style>

        <script>
        function toggleDetails(button, logId) {
            var details = document.getElementById('details-' + logId);
            if (details.style.display === 'none') {
                details.style.display = 'block';
                button.textContent = 'Hide Details';
            } else {
                details.style.display = 'none';
                button.textContent = 'Show Details';
            }
        }
        </script>
        <?php
    }

    public function debugRegistrationProcess() {
        if (isset($_POST['user_login']) && isset($_POST['user_email'])) {
            $this->logSync(0, 'REGISTRATION_DEBUG', array(
                'post_data' => $_POST,
                'current_action' => current_action(),
                'current_filter' => current_filter()
            ), '', 'INFO');
        }
    }

    // Add this helper method to make it easier to check logs in WordPress
    public function clearLogs() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'xmpl_sync_logs';
        $wpdb->query("TRUNCATE TABLE $table_name");
    }

    // Add the AJAX handler for the test connection
    public function testConnection() {
        global $current_user;
        
        // Log start of test
        $this->logSync(0, 'TEST_START', array(
            'timestamp' => current_time('mysql'),
            'initiated_by' => $current_user->ID
        ), '', 'INFO');

        $endpoint = $this->options['endpoint'] ?? '';
        $access_token = $this->options['access_token'] ?? '';
        $mappings = $this->options['field_mappings'] ?? array();

        if (empty($endpoint) || empty($access_token)) {
            $error_details = array(
                'error' => 'Missing configuration',
                'endpoint_set' => !empty($endpoint),
                'token_set' => !empty($access_token)
            );
            $this->logSync(0, 'TEST_ERROR', $error_details, '', 'ERROR');
            wp_send_json_error($this->formatErrorResponse('Missing configuration', $error_details));
            return;
        }

        $api_url = trailingslashit($endpoint) . "XMPieXMPL_REST_API/v1/projects/{$access_token}/adorvalues";

        // Build test data using current user and mappings
        $recipient_values = array();
        foreach ($mappings as $mapping) {
            $api_field = $mapping['api_field'];
            $wp_field = $mapping['wp_field'];

            if ($wp_field === 'guid') {
                $recipient_values[$api_field] = wp_generate_uuid4();
            } else {
                $recipient_values[$api_field] = $current_user->$wp_field;
            }
        }

        $body = array(
            'newRecipientValues' => $recipient_values
        );

        $request_details = array(
            'url' => $api_url,
            'method' => 'POST',
            'headers' => array('Content-Type' => 'application/json'),
            'body' => $body
        );

        // Log the request details
        $this->logSync(0, 'TEST_REQUEST', $request_details, '', 'INFO');

        $response = wp_remote_post($api_url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logSync(0, 'TEST_ERROR', $request_details, '', 'ERROR', $error_message);
            wp_send_json_error($this->formatErrorResponse($error_message, $request_details));
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        $response_details = array(
            'code' => $response_code,
            'body' => $response_body,
            'headers' => $response_headers,
            'parsed_body' => json_decode($response_body, true)
        );

        // Log detailed response
        $this->logSync(0, 'TEST_RESPONSE_DETAILS', $response_details, '', 'INFO');

        if ($response_code < 200 || $response_code >= 300) {
            $error_message = sprintf('API returned status code: %d', $response_code);
            $error_details = array_merge($request_details, $response_details);
            $this->logSync(0, 'TEST_ERROR', $error_details, '', 'ERROR', $error_message);
            wp_send_json_error($this->formatErrorResponse($error_message, $error_details));
            return;
        }

        // Log success
        $this->logSync(0, 'TEST_SUCCESS', $request_details, json_decode($response_body, true), 'SUCCESS');
        wp_send_json_success(array(
            'message' => 'Connection successful',
            'request' => $request_details,
            'response' => json_decode($response_body, true)
        ));
    }

    private function formatErrorResponse($message, $details) {
        return array(
            'message' => $message,
            'details' => $details
        );
    }

    public function testLogging() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'xmpl_sync_logs';
        $results = array();
        
        // Test 1: Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        $results['table_check'] = array(
            'test' => 'Table Existence',
            'status' => $table_exists ? 'SUCCESS' : 'FAILED',
            'message' => $table_exists ? 'Logs table exists' : 'Logs table does not exist'
        );

        if (!$table_exists) {
            wp_send_json_error($results);
            return;
        }

        // Test 2: Check table structure
        $table_structure = $wpdb->get_results("DESCRIBE $table_name");
        $results['table_structure'] = array(
            'test' => 'Table Structure',
            'status' => 'INFO',
            'columns' => array_map(function($col) {
                return array(
                    'name' => $col->Field,
                    'type' => $col->Type,
                    'null' => $col->Null,
                    'key' => $col->Key,
                    'default' => $col->Default,
                    'extra' => $col->Extra
                );
            }, $table_structure)
        );

        // Test 3: Try to write a test log
        $test_data = array(
            'test_time' => current_time('mysql'),
            'test_id' => wp_generate_uuid4()
        );

        try {
            $write_result = $wpdb->insert(
                $table_name,
                array(
                    'user_id' => 0,
                    'action' => 'LOG_SYSTEM_TEST',
                    'request_data' => json_encode($test_data),
                    'response_data' => '',
                    'status' => 'TEST',
                    'error_message' => '',
                    'timestamp' => current_time('mysql')
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            $results['write_test'] = array(
                'test' => 'Write Log Entry',
                'status' => $write_result !== false ? 'SUCCESS' : 'FAILED',
                'message' => $write_result !== false ? 'Successfully wrote test log entry' : 'Failed to write test log entry',
                'error' => $wpdb->last_error,
                'data' => $test_data
            );

            if ($write_result === false) {
                throw new Exception($wpdb->last_error ?: 'Unknown database error');
            }

            // Test 4: Try to read the test log
            $last_log = $wpdb->get_row("SELECT * FROM $table_name WHERE action = 'LOG_SYSTEM_TEST' ORDER BY id DESC LIMIT 1");
            
            $results['read_test'] = array(
                'test' => 'Read Log Entry',
                'status' => $last_log ? 'SUCCESS' : 'FAILED',
                'message' => $last_log ? 'Successfully read test log entry' : 'Failed to read test log entry',
                'error' => $wpdb->last_error,
                'data' => $last_log
            );

        } catch (Exception $e) {
            $results['error'] = array(
                'test' => 'Database Operation',
                'status' => 'FAILED',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            );
        }

        // Add WordPress database debug info
        $results['debug_info'] = array(
            'test' => 'System Info',
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'wp_debug_log' => defined('WP_DEBUG_LOG') && WP_DEBUG_LOG,
            'db_last_error' => $wpdb->last_error,
            'db_last_query' => $wpdb->last_query,
            'table_name' => $table_name,
            'prefix' => $wpdb->prefix
        );

        // Determine overall success
        $has_error = false;
        foreach ($results as $result) {
            if (isset($result['status']) && $result['status'] === 'FAILED') {
                $has_error = true;
                break;
            }
        }

        if ($has_error) {
            wp_send_json_error($results);
        } else {
            wp_send_json_success($results);
        }
    }

    private function logSync($user_id, $action, $request_data = '', $response_data = '', $status = '', $error_message = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'xmpl_sync_logs';

        try {
            // Ensure data is properly formatted for database storage
            $request_data = is_array($request_data) ? json_encode($request_data) : $request_data;
            $response_data = is_array($response_data) ? json_encode($response_data) : $response_data;

            $data = array(
                'user_id' => $user_id,
                'action' => $action,
                'request_data' => $request_data,
                'response_data' => $response_data,
                'status' => $status,
                'error_message' => $error_message,
                'timestamp' => current_time('mysql')
            );

            $result = $wpdb->insert(
                $table_name,
                $data,
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            if ($result === false) {
                throw new Exception($wpdb->last_error);
            }

            return true;

        } catch (Exception $e) {
            error_log('XMPL Sync Log Error: ' . $e->getMessage());
            return false;
        }
    }
}

// Initialize the plugin
add_action('plugins_loaded', array('XMPLSync', 'getInstance')); 
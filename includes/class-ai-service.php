<?php
/**
 * Museder AI Service
 * 
 * Core AI service class for Museder RestoreOne AI features.
 * Currently uses demo responses (no external API calls yet).
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Museder AI Service class.
 * 
 * This class provides AI functionality for Museder RestoreOne.
 * 
 * Two modes are available:
 * - Demo mode: Uses demo_response() to return fixed demo data (no external API calls)
 * - Live mode: Uses send_request() to call OpenAI Chat Completions API (requires API key)
 * 
 * Future: send_request() will be replaced to call Museder's own backend API.
 */
class Museder_AI_Service {

    /**
     * Option name for storing the last backup report.
     */
    const LAST_BACKUP_REPORT_OPTION = 'museder_ai_last_backup_report';

    /**
     * Option name for storing the last site scan.
     */
    const LAST_SITE_SCAN_OPTION = 'museder_ai_last_site_scan';

    /**
     * Option name for storing the last error log report.
     */
    const LAST_ERROR_LOG_REPORT_OPTION = 'museder_ai_last_error_log_report';

    /**
     * Option name for tracking free tier error log analysis last run.
     */
    const LAST_ERROR_LOG_FREE_RUN_OPTION = 'museder_ai_error_log_free_last_run';

    /**
     * Option name for storing the last restore guide.
     */
    const LAST_RESTORE_GUIDE_OPTION = 'museder_ai_last_restore_guide';

    /**
     * Option name for tracking free tier restore guide last run.
     */
    const LAST_RESTORE_GUIDE_FREE_RUN_OPTION = 'museder_ai_restore_guide_free_last_run';

    /**
     * Get AI settings.
     * 
     * @return array Settings array with defaults.
     */
    public static function get_settings() {
        $defaults = [
            'license_tier'   => 'free',
            'ai_api_endpoint' => '',
            'openai_api_key'  => '',
            'alert_email'   => get_option( 'admin_email' ),
            'dev_mode'      => false,
            'force_high_risk' => false,
        ];

        $stored = get_option( 'museder_ai_settings', [] );
        
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        return wp_parse_args( $stored, $defaults );
    }

    /**
     * Get license tier.
     * 
     * @return string License tier: free, pro, or agency.
     * @deprecated Use backup_lite_get_effective_license_tier() instead.
     */
    public static function get_license_tier() {
        // Use global helper if available
        if ( function_exists( 'backup_lite_get_effective_license_tier' ) ) {
            return backup_lite_get_effective_license_tier();
        }
        // Fallback to old method
        $settings = self::get_settings();
        return isset( $settings['license_tier'] ) ? $settings['license_tier'] : 'free';
    }

    /**
     * Check if AI is enabled.
     * 
     * Currently returns true if license_tier is set.
     * Later will check endpoint / api key.
     * 
     * @return bool
     */
    public static function is_ai_enabled() {
        $settings = self::get_settings();
        return ! empty( $settings['license_tier'] );
    }

    /**
     * Get demo AI response (fake data, no external HTTP calls).
     * 
     * @param string $action Action type (e.g., 'site_scan').
     * @param array  $payload Optional payload data.
     * @return array Demo response array.
     */
    public static function demo_response( $action, $payload = [] ) {
        switch ( $action ) {
            case 'site_scan':
                $result = [
                    'status'  => 'success',
                    'summary' => __( 'Demo AI Scan: Your backups look okay, but you should enable automatic schedules.', 'museder-restoreone' ),
                    'risk'    => 'medium',
                    'details' => [
                        'backup_count'     => isset( $payload['backup_count'] ) ? $payload['backup_count'] : 0,
                        'last_backup_days' => isset( $payload['last_backup_days'] ) ? $payload['last_backup_days'] : 0,
                        'schedule_active'  => isset( $payload['schedule_active'] ) ? $payload['schedule_active'] : false,
                    ],
                    'recommendations' => [
                        __( 'Enable automatic backup schedules', 'museder-restoreone' ),
                        __( 'Keep at least 3 recent backups', 'museder-restoreone' ),
                        __( 'Review backup retention settings', 'museder-restoreone' ),
                    ],
                ];
                return $result;

            case 'health_check':
                return [
                    'status'  => 'success',
                    'score'   => 75,
                    'message' => __( 'Demo Health Check: Site backup health is moderate.', 'museder-restoreone' ),
                    'risks'   => [
                        [
                            'type'     => 'no_schedule',
                            'severity' => 'medium',
                            'message'  => __( 'No automatic backup schedule configured', 'museder-restoreone' ),
                        ],
                    ],
                ];

            case 'analyze_log':
                return [
                    'status'      => 'success',
                    'explanation' => __( 'Demo Log Analysis: This appears to be a standard backup operation log.', 'museder-restoreone' ),
                    'suggestions' => [
                        __( 'Monitor backup completion times', 'museder-restoreone' ),
                        __( 'Check disk space availability', 'museder-restoreone' ),
                    ],
                ];

            case 'backup_report':
                $result = [
                    'status'         => 'success',
                    'mode'           => 'demo',
                    'type'           => 'backup_report',
                    'summary'        => __( 'Demo Backup Report: Your backup strategy shows moderate health. You have recent backups, but automatic scheduling could be improved. Consider implementing daily backups and off-site storage for better protection.', 'museder-restoreone' ),
                    'overall_score' => 65,
                    'risk_level'    => 'medium',
                    'risk_factors'  => [
                        [
                            'name'     => __( 'No automatic backup schedule', 'museder-restoreone' ),
                            'severity' => 'high',
                            'details'  => __( 'Manual backups are prone to being forgotten. Automated schedules ensure consistent protection.', 'museder-restoreone' ),
                        ],
                        [
                            'name'     => __( 'Limited backup retention', 'museder-restoreone' ),
                            'severity' => 'medium',
                            'details'  => __( 'Keeping only recent backups may limit recovery options for older content.', 'museder-restoreone' ),
                        ],
                        [
                            'name'     => __( 'No off-site backup storage', 'museder-restoreone' ),
                            'severity' => 'medium',
                            'details'  => __( 'Local-only backups are vulnerable to server failures or disasters.', 'museder-restoreone' ),
                        ],
                    ],
                    'recommendations' => [
                        __( 'Enable automatic backup schedules (daily recommended for active sites)', 'museder-restoreone' ),
                        __( 'Implement backup retention policy (keep at least 7-30 days of backups)', 'museder-restoreone' ),
                        __( 'Add cloud storage integration for off-site backups', 'museder-restoreone' ),
                        __( 'Perform regular restore tests to verify backup integrity', 'museder-restoreone' ),
                        __( 'Monitor backup completion and set up email alerts for failures', 'museder-restoreone' ),
                    ],
                ];
                return $result;

            case 'log_analysis':
                $result = [
                    'status'         => 'success',
                    'mode'           => 'demo',
                    'type'           => 'log_analysis',
                    'summary'        => __( 'Demo Log Analysis: The backup log shows normal operations with occasional warnings. Most backup tasks completed successfully, but some operations took longer than expected.', 'museder-restoreone' ),
                    'risk_level'     => 'medium',
                    'main_causes'    => [
                        [
                            'title'       => __( 'Backup operations taking longer than usual', 'museder-restoreone' ),
                            'description' => __( 'Some backup jobs exceeded expected completion time, which may indicate resource constraints or large file sizes.', 'museder-restoreone' ),
                        ],
                        [
                            'title'       => __( 'Occasional timeout warnings', 'museder-restoreone' ),
                            'description' => __( 'PHP execution time limits may need adjustment for large sites.', 'museder-restoreone' ),
                        ],
                    ],
                    'recommendations' => [
                        __( 'Monitor server resources during backup operations', 'museder-restoreone' ),
                        __( 'Consider increasing PHP max_execution_time for large sites', 'museder-restoreone' ),
                        __( 'Review backup file sizes and consider excluding unnecessary files', 'museder-restoreone' ),
                        __( 'Set up email notifications for backup completion status', 'museder-restoreone' ),
                    ],
                ];
                return $result;

            case 'restore_guide':
                $result = [
                    'status'     => 'success',
                    'mode'       => 'demo',
                    'action'     => 'restore_guide',
                    'summary'    => __( 'Demo Restore Guide: This backup appears to be from a recent backup. Before restoring, ensure you have sufficient disk space and consider backing up your current site first.', 'museder-restoreone' ),
                    'risk_level' => 'medium',
                    'steps'      => [
                        [
                            'title'       => __( 'Create a fresh backup before restore', 'museder-restoreone' ),
                            'description' => __( 'Make sure you have a new backup of the current site in case this restore fails or you need to roll back.', 'museder-restoreone' ),
                            'priority'    => 'high',
                        ],
                        [
                            'title'       => __( 'Verify disk space availability', 'museder-restoreone' ),
                            'description' => __( 'Ensure you have at least 2x the backup file size available on your server to accommodate extraction and temporary files.', 'museder-restoreone' ),
                            'priority'    => 'high',
                        ],
                        [
                            'title'       => __( 'Temporarily disable conflicting plugins', 'museder-restoreone' ),
                            'description' => __( 'Disable plugins that may conflict during restore, such as Elementor, WooCommerce, or other page builders. You can re-enable them after restore.', 'museder-restoreone' ),
                            'priority'    => 'normal',
                        ],
                        [
                            'title'       => __( 'Select and analyze the backup file', 'museder-restoreone' ),
                            'description' => __( 'Go to Museder RestoreOne → Restore page, select the backup file, and click "Step 1 – Load Info" to analyze the backup.', 'museder-restoreone' ),
                            'priority'    => 'high',
                        ],
                        [
                            'title'       => __( 'Review restore options', 'museder-restoreone' ),
                            'description' => __( 'Review the backup summary and configure restore options in Step 2, such as overwrite existing files, restore database, etc.', 'museder-restoreone' ),
                            'priority'    => 'normal',
                        ],
                        [
                            'title'       => __( 'Start the restore process', 'museder-restoreone' ),
                            'description' => __( 'Click "Step 3 – Start Restore" to begin the restore process. Monitor the progress and wait for completion.', 'museder-restoreone' ),
                            'priority'    => 'high',
                        ],
                        [
                            'title'       => __( 'Verify site functionality', 'museder-restoreone' ),
                            'description' => __( 'Visit your site homepage and key pages to verify no PHP fatal errors or white screens. Test critical functionality like login, checkout, and form submissions.', 'museder-restoreone' ),
                            'priority'    => 'normal',
                        ],
                        [
                            'title'       => __( 'Re-enable plugins gradually', 'museder-restoreone' ),
                            'description' => __( 'Re-enable previously disabled plugins one by one and check for issues. Check WordPress admin dashboard for any error messages.', 'museder-restoreone' ),
                            'priority'    => 'optional',
                        ],
                    ],
                    'warnings'   => [
                        __( 'Make sure the backup archive is not corrupted before starting the restore.', 'museder-restoreone' ),
                        __( 'This restore will overwrite existing files and database. Ensure you have a current backup.', 'museder-restoreone' ),
                    ],
                    'notes'      => [
                        __( 'Demo mode only – no real AI call was made.', 'museder-restoreone' ),
                        __( 'For production sites, consider testing the restore on a staging environment first.', 'museder-restoreone' ),
                    ],
                ];
                return $result;

            default:
                return [
                    'status'  => 'success',
                    'message' => __( 'Demo AI Response: This is a placeholder response.', 'museder-restoreone' ),
                    'data'    => $payload,
                ];
        }
    }

    /**
     * Send request to OpenAI Chat Completions API.
     * 
     * This method calls the OpenAI API to get real AI responses.
     * If no API key is configured, returns an error.
     * 
     * @param string $action Action type (e.g., 'site_scan').
     * @param array  $payload Optional payload data.
     * @return array Response array with status, summary, risk, and recommendations.
     */
    public static function send_request( $action, $payload = [] ) {
        $settings = self::get_settings();
        $api_key = $settings['openai_api_key'] ?? '';
        $endpoint = $settings['ai_api_endpoint'] ?? '';

        // Use default OpenAI endpoint if not specified
        if ( empty( $endpoint ) ) {
            $endpoint = 'https://api.openai.com/v1/chat/completions';
        }

        // Check if API key is configured
        if ( empty( $api_key ) ) {
            return [
                'status'  => 'error',
                'code'    => 'missing_api_key',
                'message' => 'OpenAI API key is not configured.',
            ];
        }

        // Handle different actions
        if ( $action !== 'site_scan' && $action !== 'backup_report' && $action !== 'log_analysis' && $action !== 'restore_guide' ) {
            return [
                'status'  => 'error',
                'code'    => 'unsupported_action',
                'message' => 'This action is not yet supported.',
            ];
        }

        // Prepare prompt for OpenAI
        $site_url = $payload['site_url'] ?? home_url();
        $php_version = $payload['php_version'] ?? PHP_VERSION;
        $wp_version = $payload['wp_version'] ?? get_bloginfo( 'version' );
        $plugins = $payload['plugins'] ?? [];
        $backups_summary = $payload['backups_summary'] ?? [];

        // Format plugins list
        $plugins_text = '';
        if ( ! empty( $plugins ) && is_array( $plugins ) ) {
            $plugins_list = [];
            foreach ( $plugins as $plugin ) {
                if ( is_array( $plugin ) && isset( $plugin['name'] ) ) {
                    $plugins_list[] = $plugin['name'] . ( isset( $plugin['version'] ) ? ' (' . $plugin['version'] . ')' : '' );
                } elseif ( is_string( $plugin ) ) {
                    $plugins_list[] = $plugin;
                }
            }
            $plugins_text = implode( ', ', $plugins_list );
        }

        // Format backups summary
        $backups_text = '';
        if ( ! empty( $backups_summary ) && is_array( $backups_summary ) ) {
            $backups_text = wp_json_encode( $backups_summary );
        } else {
            $backup_count = $payload['backup_count'] ?? 0;
            $last_backup_days = $payload['last_backup_days'] ?? 0;
            $schedule_active = $payload['schedule_active'] ?? false;
            $backups_text = sprintf(
                'Total backups: %d, Last backup: %.1f days ago, Schedule active: %s',
                $backup_count,
                $last_backup_days,
                $schedule_active ? 'Yes' : 'No'
            );
        }

        // Build prompt based on action type
        if ( $action === 'backup_report' ) {
            // Backup Report: More comprehensive analysis
            $system_prompt = "You are an AI consultant specializing in WordPress backup strategy and restore risk analysis. Provide detailed, actionable insights.";
            
            $user_prompt = sprintf(
                "Analyze this WordPress site's backup strategy and restore risks:\n\n" .
                "Site URL: %s\n" .
                "PHP Version: %s\n" .
                "WordPress Version: %s\n" .
                "Plugins: %s\n" .
                "Backups Summary: %s\n\n" .
                "Please respond ONLY in valid JSON (no other text) with the following structure:\n" .
                "{\n" .
                "  \"summary\": \"Brief summary (2-3 sentences)\",\n" .
                "  \"overall_score\": 0-100 (integer),\n" .
                "  \"risk_level\": \"low\" | \"medium\" | \"high\",\n" .
                "  \"risk_factors\": [\n" .
                "    { \"name\": \"Factor name\", \"severity\": \"low\" | \"medium\" | \"high\", \"details\": \"Description\" }\n" .
                "  ],\n" .
                "  \"recommendations\": [\n" .
                "    \"Actionable recommendation 1\",\n" .
                "    \"Actionable recommendation 2\"\n" .
                "  ]\n" .
                "}\n\n" .
                "Note: Include at most 5 risk factors. Focus on backup schedule, retention, restore readiness, and disaster recovery.",
                $site_url,
                $php_version,
                $wp_version,
                $plugins_text ?: 'None listed',
                $backups_text
            );
        } elseif ( $action === 'log_analysis' ) {
            // Log Analysis: Analyze error logs
            $system_prompt = "You are an expert WordPress and PHP backup plugin specialist. You read error logs and explain issues in simple English, providing clear troubleshooting steps.";
            
            $site_info = $payload['site_info'] ?? [];
            $log_excerpt = $payload['log_excerpt'] ?? '';
            
            $user_prompt = sprintf(
                "Analyze this WordPress backup plugin error log and provide insights:\n\n" .
                "Site URL: %s\n" .
                "WordPress Version: %s\n" .
                "PHP Version: %s\n" .
                "Plugin Version: %s\n\n" .
                "Recent Log Content:\n%s\n\n" .
                "Please respond ONLY in valid JSON (no other text) with the following structure:\n" .
                "{\n" .
                "  \"summary\": \"Overall summary of log analysis (2-3 sentences)\",\n" .
                "  \"risk_level\": \"low\" | \"medium\" | \"high\",\n" .
                "  \"main_causes\": [\n" .
                "    { \"title\": \"Issue title\", \"description\": \"Detailed description\" },\n" .
                "    ...\n" .
                "  ],\n" .
                "  \"recommendations\": [\n" .
                "    \"Actionable recommendation 1\",\n" .
                "    \"Actionable recommendation 2\",\n" .
                "    ...\n" .
                "  ]\n" .
                "}\n\n" .
                "Focus on identifying backup/restore errors, PHP warnings, timeout issues, and file permission problems. Provide practical solutions.",
                $site_info['home_url'] ?? home_url(),
                $site_info['wp_version'] ?? get_bloginfo( 'version' ),
                $site_info['php_version'] ?? PHP_VERSION,
                $site_info['plugin_version'] ?? ( defined( 'BACKUP_LITE_VERSION' ) ? BACKUP_LITE_VERSION : '' ),
                substr( $log_excerpt, 0, 15000 ) // Limit log content to avoid token limits
            );
        } elseif ( $action === 'restore_guide' ) {
            // Restore Guide: Generate step-by-step restore guide
            $system_prompt = "You are an expert WordPress backup and restore specialist. You analyze backup files and recent logs to provide safe, step-by-step restore guidance. Focus on preventing data loss and conflicts.";
            
            $backup_summary = $payload['backup_summary'] ?? [];
            $logs = $payload['logs'] ?? '';
            $restore_options = $payload['restore_options'] ?? [];
            $environment = $payload['environment'] ?? [];
            
            // Format backup summary
            $backup_info = '';
            if ( ! empty( $backup_summary ) && is_array( $backup_summary ) ) {
                $backup_info = sprintf(
                    "Backup File: %s\n" .
                    "Created: %s\n" .
                    "Size: %s\n" .
                    "Source: %s\n",
                    $backup_summary['name'] ?? $backup_summary['filename'] ?? 'Unknown',
                    $backup_summary['created'] ?? 'Unknown',
                    isset( $backup_summary['size'] ) ? size_format( $backup_summary['size'] ) : ( isset( $backup_summary['bytes'] ) ? size_format( $backup_summary['bytes'] ) : 'Unknown' ),
                    isset( $backup_summary['source'] ) ? ucfirst( $backup_summary['source'] ) : 'Unknown'
                );
            }
            
            // Format restore options
            $options_text = '';
            if ( ! empty( $restore_options ) && is_array( $restore_options ) ) {
                $options_list = [];
                foreach ( $restore_options as $key => $value ) {
                    if ( is_bool( $value ) ) {
                        $options_list[] = $key . ': ' . ( $value ? 'Yes' : 'No' );
                    } else {
                        $options_list[] = $key . ': ' . ( is_array( $value ) ? wp_json_encode( $value ) : (string) $value );
                    }
                }
                $options_text = implode( "\n", $options_list );
            } else {
                $options_text = 'No specific restore options configured.';
            }
            
            // Format environment info
            $env_text = '';
            if ( ! empty( $environment ) && is_array( $environment ) ) {
                $env_text = sprintf(
                    "WordPress Version: %s\n" .
                    "PHP Version: %s\n" .
                    "Site URL: %s\n",
                    $environment['wp_version'] ?? get_bloginfo( 'version' ),
                    $environment['php_version'] ?? PHP_VERSION,
                    $environment['home_url'] ?? home_url()
                );
                
                if ( ! empty( $environment['plugins'] ) && is_array( $environment['plugins'] ) ) {
                    $plugins_list = [];
                    foreach ( $environment['plugins'] as $plugin ) {
                        if ( is_array( $plugin ) && isset( $plugin['name'] ) ) {
                            $plugins_list[] = $plugin['name'] . ( isset( $plugin['version'] ) ? ' (' . $plugin['version'] . ')' : '' );
                        } elseif ( is_string( $plugin ) ) {
                            $plugins_list[] = $plugin;
                        }
                    }
                    if ( ! empty( $plugins_list ) ) {
                        $env_text .= "Installed Plugins: " . implode( ', ', array_slice( $plugins_list, 0, 20 ) ) . "\n";
                    }
                }
            } else {
                $env_text = sprintf(
                    "WordPress Version: %s\n" .
                    "PHP Version: %s\n" .
                    "Site URL: %s\n",
                    get_bloginfo( 'version' ),
                    PHP_VERSION,
                    home_url()
                );
            }
            
            // Format logs (limit to last 50 lines or 10000 chars)
            $logs_text = '';
            if ( is_string( $logs ) ) {
                $logs_text = substr( $logs, -10000 ); // Last 10000 chars
            } elseif ( is_array( $logs ) ) {
                $logs_text = implode( "\n", array_slice( $logs, -50 ) ); // Last 50 lines
            }
            
            $user_prompt = sprintf(
                "Generate a comprehensive restore guide for this WordPress site:\n\n" .
                "Environment Information:\n%s\n\n" .
                "Backup Information:\n%s\n\n" .
                "Restore Options:\n%s\n\n" .
                "Recent Logs (backup/restore related):\n%s\n\n" .
                "Please respond ONLY in valid JSON (no other text) with the following structure:\n" .
                "{\n" .
                "  \"summary\": \"Brief summary of restore readiness and key considerations (2-3 sentences)\",\n" .
                "  \"risk_level\": \"low\" | \"medium\" | \"high\",\n" .
                "  \"steps\": [\n" .
                "    {\n" .
                "      \"title\": \"Step title\",\n" .
                "      \"description\": \"Detailed step description\",\n" .
                "      \"priority\": \"high\" | \"normal\" | \"optional\"\n" .
                "    },\n" .
                "    ...\n" .
                "  ],\n" .
                "  \"warnings\": [\n" .
                "    \"Warning message 1\",\n" .
                "    \"Warning message 2\",\n" .
                "    ...\n" .
                "  ],\n" .
                "  \"notes\": [\n" .
                "    \"Note 1\",\n" .
                "    \"Note 2\",\n" .
                "    ...\n" .
                "  ]\n" .
                "}\n\n" .
                "Focus on:\n" .
                "- Disk space requirements\n" .
                "- Plugin conflicts to avoid (especially WooCommerce, Elementor, page builders)\n" .
                "- Database backup recommendations\n" .
                "- Step-by-step restore process with clear priorities\n" .
                "- Important warnings about potential data loss or conflicts\n" .
                "- Post-restore verification steps\n" .
                "Provide practical, actionable guidance. Use Traditional Chinese or English based on the site language.",
                $env_text,
                $backup_info,
                $options_text,
                substr( $logs_text, 0, 10000 ) // Limit log content to avoid token limits
            );
        } else {
            // Site Scan: Quick analysis
            $system_prompt = "You are an AI assistant that analyzes WordPress backup and restore health. Analyze the provided site information and provide recommendations.";
            
            $user_prompt = sprintf(
                "Analyze this WordPress site's backup and restore health:\n\n" .
                "Site URL: %s\n" .
                "PHP Version: %s\n" .
                "WordPress Version: %s\n" .
                "Plugins: %s\n" .
                "Backups Summary: %s\n\n" .
                "Please respond ONLY in valid JSON with the following keys:\n" .
                "- summary (string): A brief summary of the site's backup health\n" .
                "- risk (string): One of 'low', 'medium', or 'high'\n" .
                "- recommendations (array of strings): List of actionable recommendations",
                $site_url,
                $php_version,
                $wp_version,
                $plugins_text ?: 'None listed',
                $backups_text
            );
        }

        // Prepare request body
        $request_body = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $system_prompt,
                ],
                [
                    'role' => 'user',
                    'content' => $user_prompt,
                ],
            ],
            'response_format' => [
                'type' => 'json_object',
            ],
        ];

        // Make API request
        $response = wp_remote_post(
            $endpoint,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode( $request_body ),
                'timeout' => 30,
            ]
        );

        // Handle HTTP errors
        if ( is_wp_error( $response ) ) {
            return [
                'status'  => 'error',
                'code'    => 'http_error',
                'message' => 'Unable to reach OpenAI API: ' . $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            $response_body = wp_remote_retrieve_body( $response );
            $error_data = json_decode( $response_body, true );
            
            // Get error message from API response if available
            $error_message = '';
            if ( isset( $error_data['error']['message'] ) ) {
                $error_message = $error_data['error']['message'];
            } elseif ( isset( $error_data['error'] ) && is_string( $error_data['error'] ) ) {
                $error_message = $error_data['error'];
            }
            
            // Provide user-friendly error messages based on status code
            $user_message = '';
            switch ( $response_code ) {
                case 429:
                    $user_message = __( 'OpenAI API rate limit exceeded. Please wait a moment and try again, or check your API usage limits.', 'museder-restoreone' );
                    if ( $error_message ) {
                        $user_message .= ' ' . esc_html( $error_message );
                    }
                    break;
                case 401:
                    $user_message = __( 'OpenAI API authentication failed. Please check your API key in AI Settings.', 'museder-restoreone' );
                    break;
                case 403:
                    $user_message = __( 'OpenAI API access forbidden. Please check your API key permissions.', 'museder-restoreone' );
                    break;
                case 500:
                case 502:
                case 503:
                case 504:
                    $user_message = __( 'OpenAI API is temporarily unavailable. Please try again later.', 'museder-restoreone' );
                    break;
                default:
                    $user_message = sprintf(
                        __( 'OpenAI API returned error %d.', 'museder-restoreone' ),
                        $response_code
                    );
                    if ( $error_message ) {
                        $user_message .= ' ' . esc_html( $error_message );
                    }
                    break;
            }
            
            return [
                'status'  => 'error',
                'code'    => 'api_error',
                'message' => $user_message,
            ];
        }

        // Parse response
        $response_body = wp_remote_retrieve_body( $response );
        $response_data = json_decode( $response_body, true );

        if ( ! $response_data || ! isset( $response_data['choices'][0]['message']['content'] ) ) {
            return [
                'status'  => 'error',
                'code'    => 'invalid_response',
                'message' => 'AI response could not be parsed.',
            ];
        }

        // Extract content and parse JSON
        $content = $response_data['choices'][0]['message']['content'];
        $parsed = json_decode( $content, true );

        if ( ! $parsed || ! is_array( $parsed ) ) {
            return [
                'status'  => 'error',
                'code'    => 'invalid_json',
                'message' => 'AI response could not be parsed as JSON.',
            ];
        }

        // Handle different response formats based on action
        if ( $action === 'backup_report' ) {
            // Backup Report response format
            $summary = isset( $parsed['summary'] ) ? $parsed['summary'] : '';
            $overall_score = isset( $parsed['overall_score'] ) ? (int) $parsed['overall_score'] : 50;
            $overall_score = max( 0, min( 100, $overall_score ) ); // Clamp to 0-100
            
            $risk_level = isset( $parsed['risk_level'] ) ? strtolower( $parsed['risk_level'] ) : 'medium';
            if ( ! in_array( $risk_level, [ 'low', 'medium', 'high' ], true ) ) {
                $risk_level = 'medium';
            }

            $risk_factors = [];
            if ( isset( $parsed['risk_factors'] ) && is_array( $parsed['risk_factors'] ) ) {
                foreach ( $parsed['risk_factors'] as $factor ) {
                    if ( is_array( $factor ) && isset( $factor['name'] ) ) {
                        $risk_factors[] = [
                            'name'     => $factor['name'],
                            'severity' => isset( $factor['severity'] ) ? strtolower( $factor['severity'] ) : 'medium',
                            'details'  => isset( $factor['details'] ) ? $factor['details'] : '',
                        ];
                    }
                }
                // Limit to 5 factors
                $risk_factors = array_slice( $risk_factors, 0, 5 );
            }

            $recommendations = isset( $parsed['recommendations'] ) && is_array( $parsed['recommendations'] ) 
                ? $parsed['recommendations'] 
                : [];

            $result = [
                'status'         => 'success',
                'mode'           => 'live',
                'type'           => 'backup_report',
                'summary'        => $summary,
                'overall_score'  => $overall_score,
                'risk_level'     => $risk_level,
                'risk_factors'   => $risk_factors,
                'recommendations' => $recommendations,
            ];

            return $result;
        } elseif ( $action === 'log_analysis' ) {
            // Log Analysis response format
            $summary = isset( $parsed['summary'] ) ? $parsed['summary'] : '';
            
            $risk_level = isset( $parsed['risk_level'] ) ? strtolower( $parsed['risk_level'] ) : 'medium';
            if ( ! in_array( $risk_level, [ 'low', 'medium', 'high' ], true ) ) {
                $risk_level = 'medium';
            }

            $main_causes = [];
            if ( isset( $parsed['main_causes'] ) && is_array( $parsed['main_causes'] ) ) {
                foreach ( $parsed['main_causes'] as $cause ) {
                    if ( is_array( $cause ) && isset( $cause['title'] ) ) {
                        $main_causes[] = [
                            'title'       => $cause['title'],
                            'description' => isset( $cause['description'] ) ? $cause['description'] : '',
                        ];
                    }
                }
            }

            $recommendations = isset( $parsed['recommendations'] ) && is_array( $parsed['recommendations'] ) 
                ? $parsed['recommendations'] 
                : [];

            $result = [
                'status'         => 'success',
                'mode'           => 'live',
                'type'           => 'log_analysis',
                'summary'        => $summary,
                'risk_level'     => $risk_level,
                'main_causes'    => $main_causes,
                'recommendations' => $recommendations,
            ];

            return $result;
        } elseif ( $action === 'restore_guide' ) {
            // Restore Guide response format
            $summary = isset( $parsed['summary'] ) ? $parsed['summary'] : '';
            
            $risk_level = isset( $parsed['risk_level'] ) ? strtolower( $parsed['risk_level'] ) : 'medium';
            if ( ! in_array( $risk_level, [ 'low', 'medium', 'high' ], true ) ) {
                $risk_level = 'medium';
            }

            // Parse steps array
            $steps = [];
            if ( isset( $parsed['steps'] ) && is_array( $parsed['steps'] ) ) {
                foreach ( $parsed['steps'] as $step ) {
                    if ( is_array( $step ) && isset( $step['title'] ) ) {
                        $steps[] = [
                            'title'       => $step['title'],
                            'description' => isset( $step['description'] ) ? $step['description'] : '',
                            'priority'    => isset( $step['priority'] ) && in_array( strtolower( $step['priority'] ), [ 'high', 'normal', 'optional' ], true )
                                ? strtolower( $step['priority'] )
                                : 'normal',
                        ];
                    }
                }
            }

            // Parse warnings array
            $warnings = [];
            if ( isset( $parsed['warnings'] ) && is_array( $parsed['warnings'] ) ) {
                foreach ( $parsed['warnings'] as $warning ) {
                    if ( is_string( $warning ) && ! empty( $warning ) ) {
                        $warnings[] = $warning;
                    }
                }
            }

            // Parse notes array
            $notes = [];
            if ( isset( $parsed['notes'] ) && is_array( $parsed['notes'] ) ) {
                foreach ( $parsed['notes'] as $note ) {
                    if ( is_string( $note ) && ! empty( $note ) ) {
                        $notes[] = $note;
                    }
                }
            }

            $result = [
                'status'     => 'success',
                'mode'       => 'live',
                'action'     => 'restore_guide',
                'summary'    => $summary,
                'risk_level' => $risk_level,
                'steps'      => $steps,
                'warnings'   => $warnings,
                'notes'      => $notes,
            ];

            return $result;
        } else {
            // Site Scan response format (existing)
            $summary = isset( $parsed['summary'] ) ? $parsed['summary'] : '';
            $risk = isset( $parsed['risk'] ) ? strtolower( $parsed['risk'] ) : 'medium';
            $recommendations = isset( $parsed['recommendations'] ) && is_array( $parsed['recommendations'] ) 
                ? $parsed['recommendations'] 
                : [];

            // Validate risk value
            if ( ! in_array( $risk, [ 'low', 'medium', 'high' ], true ) ) {
                $risk = 'medium';
            }

            $result = [
                'status'  => 'success',
                'summary' => $summary,
                'risk'    => $risk,
                'recommendations' => $recommendations,
            ];

            return $result;
        }
    }

    /**
     * Force high risk in dev mode for testing AI Alerts.
     * 
     * This method only works when both Developer Mode and Force High Risk are enabled.
     * Checks both PHP constants (priority) and options.
     * 
     * @param array $result AI result array (passed by reference).
     * @return void
     */
    public static function maybe_force_high_risk( &$result ) {
        // Check if dev mode is enabled (constant or option)
        $dev_mode_enabled = false;
        if ( defined( 'MUSERDER_DEV_MODE' ) && MUSERDER_DEV_MODE ) {
            $dev_mode_enabled = true;
        } else {
            $settings = self::get_settings();
            $dev_mode_enabled = ! empty( $settings['dev_mode'] );
        }
        
        if ( ! $dev_mode_enabled ) {
            return;
        }
        
        // Check if force high risk is enabled (constant or option)
        $force_high_risk_enabled = false;
        if ( defined( 'MUSERDER_FORCE_HIGH_RISK' ) && MUSERDER_FORCE_HIGH_RISK ) {
            $force_high_risk_enabled = true;
        } else {
            $settings = self::get_settings();
            $force_high_risk_enabled = ! empty( $settings['force_high_risk'] );
        }
        
        if ( $force_high_risk_enabled ) {
            // Force both risk_level and risk to 'high' for compatibility
            $result['risk_level'] = 'high';
            $result['risk']       = 'high';
        }
    }

    /**
     * Log AI usage for tracking and limiting.
     * 
     * @param string $action Action type (e.g., 'site_scan').
     * @param string $status Status: 'success' or 'error'.
     * @return void
     */
    public static function log_usage( $action, $status ) {
        $log = get_option( 'museder_ai_usage_log', [] );
        
        if ( ! is_array( $log ) ) {
            $log = [];
        }

        // Add new entry
        $log[] = [
            'action'    => $action,
            'status'    => $status,
            'timestamp' => current_time( 'timestamp' ),
        ];

        // Clean up old entries (keep only last 6 months)
        $six_months_ago = current_time( 'timestamp' ) - ( 6 * MONTH_IN_SECONDS );
        $log = array_filter( $log, function( $entry ) use ( $six_months_ago ) {
            return isset( $entry['timestamp'] ) && $entry['timestamp'] >= $six_months_ago;
        } );

        // Re-index array
        $log = array_values( $log );

        update_option( 'museder_ai_usage_log', $log );
    }

    /**
     * Count successful usage since a given timestamp.
     * 
     * @param string $action Action type (e.g., 'site_scan').
     * @param int    $from_timestamp Starting timestamp.
     * @return int Number of successful uses.
     */
    public static function count_usage_since( $action, $from_timestamp ) {
        $log = get_option( 'museder_ai_usage_log', [] );
        
        if ( ! is_array( $log ) ) {
            return 0;
        }

        $count = 0;
        foreach ( $log as $entry ) {
            if ( isset( $entry['action'] ) && $entry['action'] === $action &&
                 isset( $entry['status'] ) && $entry['status'] === 'success' &&
                 isset( $entry['timestamp'] ) && $entry['timestamp'] >= $from_timestamp ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the timestamp for the start of the current month (00:00:00).
     * 
     * Uses WordPress timezone settings.
     * 
     * @return int Timestamp for the first day of current month at 00:00:00.
     */
    public static function get_month_start_timestamp() {
        // Get current time in WordPress timezone
        $current_timestamp = current_time( 'timestamp' );
        
        // Get date components in WordPress timezone
        $year  = (int) current_time( 'Y' );
        $month = (int) current_time( 'm' );
        
        // Create date string for first day of current month
        $date_string = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
        
        // Get WordPress timezone
        $timezone_string = get_option( 'timezone_string' );
        if ( $timezone_string ) {
            $timezone = new DateTimeZone( $timezone_string );
        } else {
            // Use GMT offset
            $gmt_offset = (float) get_option( 'gmt_offset' );
            $hours = (int) $gmt_offset;
            $minutes = (int) ( ( $gmt_offset - $hours ) * 60 );
            $offset_string = sprintf( '%+03d:%02d', $hours, abs( $minutes ) );
            $timezone = timezone_open( $offset_string );
            if ( ! $timezone ) {
                $timezone = new DateTimeZone( 'UTC' );
            }
        }
        
        // Create DateTime object and get timestamp
        try {
            $date = new DateTime( $date_string, $timezone );
            return $date->getTimestamp();
        } catch ( Exception $e ) {
            // Fallback: use mktime with WordPress timezone offset
            $gmt_offset = (float) get_option( 'gmt_offset' );
            return mktime( 0, 0, 0, $month, 1, $year ) + ( $gmt_offset * HOUR_IN_SECONDS );
        }
    }

    /**
     * Check if an AI action can be run based on license tier and usage limits.
     * 
     * Usage limits:
     * - Free: 1 action per month (shared across site_scan and backup_report)
     * - Pro / Agency: Unlimited
     * 
     * @param string $action Action type (e.g., 'site_scan', 'backup_report').
     * @return array {
     *     @type bool   $allowed   Whether the action can be run.
     *     @type string $reason    Reason code (empty if allowed).
     *     @type string $message   User-friendly error message (if not allowed).
     *     @type int    $remaining Remaining actions this month (if free tier).
     * }
     */
    public static function can_run_ai_action( $action ) {
        // Development mode: Bypass all free tier limits
        if ( function_exists( 'backup_lite_is_developer_mode' ) && backup_lite_is_developer_mode() ) {
            return [
                'allowed' => true,
                'reason'  => 'dev_mode',
            ];
        }

        // Use global helper for license tier (considers Developer Mode)
        $license_tier = function_exists( 'backup_lite_get_effective_license_tier' ) 
            ? backup_lite_get_effective_license_tier() 
            : ( self::get_settings()['license_tier'] ?? 'free' );

        // Pro / Agency: Unlimited
        if ( in_array( $license_tier, [ 'pro', 'agency' ], true ) ) {
            return [
                'allowed' => true,
                'reason'  => '',
            ];
        }

        // Free: Determine limit based on action
        $limit = 1; // Default: 1 per month
        switch ( $action ) {
            case 'site_scan':
            case 'backup_report':
                // Both share the same limit (1 per month total)
                $limit = 1;
                break;
            default:
                $limit = 1;
                break;
        }

        // Count usage: For free tier, site_scan and backup_report share the same quota
        $from = self::get_month_start_timestamp();
        
        // Count both site_scan and backup_report for free tier (shared quota)
        $used_site_scan = self::count_usage_since( 'site_scan', $from );
        $used_backup_report = self::count_usage_since( 'backup_report', $from );
        $used = $used_site_scan + $used_backup_report;
        
        $remaining = max( 0, $limit - $used );

        if ( $remaining <= 0 ) {
            // Generate action-specific error message
            $action_name = '';
            switch ( $action ) {
                case 'site_scan':
                    $action_name = __( 'AI Site Scan', 'museder-restoreone' );
                    break;
                case 'backup_report':
                    $action_name = __( 'Backup AI Report', 'museder-restoreone' );
                    break;
                default:
                    $action_name = __( 'AI action', 'museder-restoreone' );
                    break;
            }
            
            return [
                'allowed' => false,
                'reason'  => 'limit_reached',
                'message' => sprintf(
                    __( 'You have used your free %s for this month. Upgrade to Pro for unlimited usage.', 'museder-restoreone' ),
                    $action_name
                ),
            ];
        }

        return [
            'allowed'   => true,
            'reason'    => '',
            'remaining' => $remaining,
        ];
    }

    /**
     * Check if site scan can be run based on license tier and usage limits.
     * 
     * @return array See can_run_ai_action() for return format.
     */
    public static function can_run_site_scan() {
        return self::can_run_ai_action( 'site_scan' );
    }

    /**
     * Check if backup report can be run based on license tier and usage limits.
     * 
     * @return array See can_run_ai_action() for return format.
     */
    public static function can_run_backup_report() {
        return self::can_run_ai_action( 'backup_report' );
    }

    /**
     * Store the last successful backup report.
     * 
     * @param array $report Backup report result array from send_request() or demo_response().
     * @return void
     */
    public static function store_last_backup_report( $report ) {
        if ( ! is_array( $report ) ) {
            return;
        }

        // Extract and sanitize data for dashboard display
        $data = [
            'summary'        => isset( $report['summary'] ) ? (string) $report['summary'] : '',
            'overall_score'  => isset( $report['overall_score'] ) ? (int) $report['overall_score'] : null,
            'risk_level'     => isset( $report['risk_level'] ) ? (string) $report['risk_level'] : '',
            'risk_factors'   => isset( $report['risk_factors'] ) && is_array( $report['risk_factors'] ) 
                ? $report['risk_factors'] 
                : [],
            'recommendations' => isset( $report['recommendations'] ) && is_array( $report['recommendations'] ) 
                ? $report['recommendations'] 
                : [],
            'updated_at'     => current_time( 'timestamp' ),
            'mode'           => isset( $report['mode'] ) ? $report['mode'] : 'live', // 'demo' or 'live'
        ];

        // Ensure overall_score is within valid range
        if ( $data['overall_score'] !== null ) {
            $data['overall_score'] = max( 0, min( 100, $data['overall_score'] ) );
        }

        update_option( self::LAST_BACKUP_REPORT_OPTION, $data, false );
    }

    /**
     * Get the last stored backup report.
     * 
     * @return array {
     *     @type string $summary        Summary text.
     *     @type int|null $overall_score Overall score (0-100) or null.
     *     @type string $risk_level     Risk level: 'low', 'medium', or 'high'.
     *     @type array  $risk_factors   Array of risk factors.
     *     @type array  $recommendations Array of recommendations.
     *     @type int    $updated_at     Timestamp of last update.
     *     @type string $mode           'demo' or 'live'.
     * }
     */
    public static function get_last_backup_report() {
        $stored = get_option( self::LAST_BACKUP_REPORT_OPTION, [] );

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        // Return with safe defaults
        return [
            'summary'        => isset( $stored['summary'] ) ? (string) $stored['summary'] : '',
            'overall_score'  => isset( $stored['overall_score'] ) ? (int) $stored['overall_score'] : null,
            'risk_level'     => isset( $stored['risk_level'] ) ? (string) $stored['risk_level'] : '',
            'risk_factors'   => isset( $stored['risk_factors'] ) && is_array( $stored['risk_factors'] ) 
                ? $stored['risk_factors'] 
                : [],
            'recommendations' => isset( $stored['recommendations'] ) && is_array( $stored['recommendations'] ) 
                ? $stored['recommendations'] 
                : [],
            'updated_at'     => isset( $stored['updated_at'] ) ? (int) $stored['updated_at'] : 0,
            'mode'           => isset( $stored['mode'] ) ? (string) $stored['mode'] : 'live',
        ];
    }

    /**
     * Store the last successful site scan result.
     * 
     * @param array $result Site scan result array from send_request() or demo_response().
     * @return void
     */
    public static function store_last_site_scan( $result ) {
        if ( ! is_array( $result ) ) {
            return;
        }

        // Extract and sanitize data for dashboard display
        $data = [
            'summary'        => isset( $result['summary'] ) ? (string) $result['summary'] : '',
            'risk_level'     => isset( $result['risk'] ) ? (string) $result['risk'] : '',
            'recommendations' => isset( $result['recommendations'] ) && is_array( $result['recommendations'] ) 
                ? $result['recommendations'] 
                : [],
            'updated_at'     => current_time( 'timestamp' ),
            'mode'           => isset( $result['mode'] ) ? $result['mode'] : 'live',
        ];

        // Normalize risk_level (site_scan uses 'risk', backup_report uses 'risk_level')
        if ( empty( $data['risk_level'] ) && isset( $result['risk'] ) ) {
            $data['risk_level'] = (string) $result['risk'];
        }

        update_option( self::LAST_SITE_SCAN_OPTION, $data, false );
    }

    /**
     * Get the last stored site scan result.
     * 
     * @return array {
     *     @type string $summary        Summary text.
     *     @type string $risk_level     Risk level: 'low', 'medium', or 'high'.
     *     @type array  $recommendations Array of recommendations.
     *     @type int    $updated_at     Timestamp of last update.
     *     @type string $mode           'demo' or 'live'.
     * }
     */
    public static function get_last_site_scan() {
        $stored = get_option( self::LAST_SITE_SCAN_OPTION, [] );

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        // Return with safe defaults
        return [
            'summary'        => isset( $stored['summary'] ) ? (string) $stored['summary'] : '',
            'risk_level'     => isset( $stored['risk_level'] ) ? (string) $stored['risk_level'] : '',
            'recommendations' => isset( $stored['recommendations'] ) && is_array( $stored['recommendations'] ) 
                ? $stored['recommendations'] 
                : [],
            'updated_at'     => isset( $stored['updated_at'] ) ? (int) $stored['updated_at'] : 0,
            'mode'           => isset( $stored['mode'] ) ? (string) $stored['mode'] : 'live',
        ];
    }

    /**
     * Store the last successful error log analysis result.
     * 
     * @param array $result Error log analysis result array from send_request() or demo_response().
     * @return void
     */
    public static function store_last_error_log_report( $result ) {
        if ( ! is_array( $result ) ) {
            return;
        }

        // Extract and sanitize data for display
        $data = [
            'summary'        => isset( $result['summary'] ) ? (string) $result['summary'] : '',
            'risk_level'     => isset( $result['risk_level'] ) ? (string) $result['risk_level'] : '',
            'main_causes'    => isset( $result['main_causes'] ) && is_array( $result['main_causes'] ) 
                ? $result['main_causes'] 
                : [],
            'recommendations' => isset( $result['recommendations'] ) && is_array( $result['recommendations'] ) 
                ? $result['recommendations'] 
                : [],
            'updated_at'     => current_time( 'timestamp' ),
            'mode'           => isset( $result['mode'] ) ? $result['mode'] : 'live',
        ];

        update_option( self::LAST_ERROR_LOG_REPORT_OPTION, $data, false );
    }

    /**
     * Get the last stored error log analysis result.
     * 
     * @return array {
     *     @type string $summary        Summary text.
     *     @type string $risk_level     Risk level: 'low', 'medium', or 'high'.
     *     @type array  $main_causes    Array of main causes with title and description.
     *     @type array  $recommendations Array of recommendations.
     *     @type int    $updated_at     Timestamp of last update.
     *     @type string $mode           'demo' or 'live'.
     * }
     */
    public static function get_last_error_log_report() {
        $stored = get_option( self::LAST_ERROR_LOG_REPORT_OPTION, [] );

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        // Return with safe defaults
        return [
            'summary'        => isset( $stored['summary'] ) ? (string) $stored['summary'] : '',
            'risk_level'     => isset( $stored['risk_level'] ) ? (string) $stored['risk_level'] : '',
            'main_causes'    => isset( $stored['main_causes'] ) && is_array( $stored['main_causes'] ) 
                ? $stored['main_causes'] 
                : [],
            'recommendations' => isset( $stored['recommendations'] ) && is_array( $stored['recommendations'] ) 
                ? $stored['recommendations'] 
                : [],
            'updated_at'     => isset( $stored['updated_at'] ) ? (int) $stored['updated_at'] : 0,
            'mode'           => isset( $stored['mode'] ) ? (string) $stored['mode'] : 'live',
        ];
    }

    /**
     * Store the last successful restore guide result.
     * 
     * @param string $backup_id Backup identifier (filename).
     * @param array  $result    Restore guide result array from send_request() or demo_response().
     *                          Expected keys: summary, risk_level, steps, warnings, notes.
     * @param string $mode      'demo' or 'live'.
     * @return void
     */
    public static function store_last_restore_guide( $backup_id, $result, $mode ) {
        if ( ! is_array( $result ) || empty( $backup_id ) ) {
            return;
        }

        // Extract and sanitize data for display (new format: steps, warnings, notes)
        $data = [
            'backup_id'  => sanitize_file_name( $backup_id ),
            'summary'    => isset( $result['summary'] ) ? (string) $result['summary'] : '',
            'risk_level' => isset( $result['risk_level'] ) ? (string) $result['risk_level'] : '',
            'steps'      => isset( $result['steps'] ) && is_array( $result['steps'] ) 
                ? $result['steps'] 
                : [],
            'warnings'   => isset( $result['warnings'] ) && is_array( $result['warnings'] ) 
                ? $result['warnings'] 
                : [],
            'notes'      => isset( $result['notes'] ) && is_array( $result['notes'] ) 
                ? $result['notes'] 
                : [],
            'updated_at' => current_time( 'timestamp' ),
            'mode'       => $mode,
        ];

        update_option( self::LAST_RESTORE_GUIDE_OPTION, $data, false );
    }

    /**
     * Get the last stored restore guide result.
     * 
     * @return array {
     *     @type string $backup_id    Backup identifier.
     *     @type string $summary      Summary text.
     *     @type string $risk_level   Risk level: 'low', 'medium', or 'high'.
     *     @type array  $prechecks    Array of pre-check items.
     *     @type array  $step_by_step Array of step-by-step instructions.
     *     @type array  $post_checks  Array of post-check items.
     *     @type int    $updated_at   Timestamp of last update.
     *     @type string $mode         'demo' or 'live'.
     * }
     */
    public static function get_last_restore_guide() {
        $stored = get_option( self::LAST_RESTORE_GUIDE_OPTION, [] );

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        // Return with safe defaults (new format: steps, warnings, notes)
        return [
            'backup_id'  => isset( $stored['backup_id'] ) ? (string) $stored['backup_id'] : '',
            'summary'    => isset( $stored['summary'] ) ? (string) $stored['summary'] : '',
            'risk_level' => isset( $stored['risk_level'] ) ? (string) $stored['risk_level'] : '',
            'steps'      => isset( $stored['steps'] ) && is_array( $stored['steps'] ) 
                ? $stored['steps'] 
                : [],
            'warnings'   => isset( $stored['warnings'] ) && is_array( $stored['warnings'] ) 
                ? $stored['warnings'] 
                : [],
            'notes'      => isset( $stored['notes'] ) && is_array( $stored['notes'] ) 
                ? $stored['notes'] 
                : [],
            'updated_at' => isset( $stored['updated_at'] ) ? (int) $stored['updated_at'] : 0,
            'mode'       => isset( $stored['mode'] ) ? (string) $stored['mode'] : 'live',
        ];
    }

    /**
     * Check if high risk is detected and send alert if needed.
     * 
     * @param string $context Context identifier: 'site_scan', 'backup_report', 'error_log', 'restore_guide'.
     * @param array  $result  AI result array with status, risk_level, summary, etc.
     * @return array Alert information array to be included in response, or empty array if no alert.
     */
    public static function maybe_send_alert( $context, $result ) {
        // Only process if result is successful
        if ( ! isset( $result['status'] ) || $result['status'] !== 'success' ) {
            return [];
        }

        // Check risk level (case-insensitive)
        // site_scan uses 'risk', others use 'risk_level'
        $risk_level = '';
        if ( isset( $result['risk_level'] ) ) {
            $risk_level = strtolower( $result['risk_level'] );
        } elseif ( isset( $result['risk'] ) ) {
            $risk_level = strtolower( $result['risk'] );
        }
        
        if ( $risk_level !== 'high' ) {
            return [];
        }

        // Get settings
        $settings = self::get_settings();
        // Use global helper for license tier (considers Developer Mode)
        $license_tier = function_exists( 'backup_lite_get_effective_license_tier' ) 
            ? backup_lite_get_effective_license_tier() 
            : ( isset( $settings['license_tier'] ) ? $settings['license_tier'] : 'free' );
        $alert_email = isset( $settings['alert_email'] ) ? sanitize_email( $settings['alert_email'] ) : '';

        // Free tier: Only add alert flag, no email
        if ( $license_tier === 'free' ) {
            return [
                'type'    => 'high_risk_free',
                'context' => $context,
            ];
        }

        // Pro / Agency tier
        if ( empty( $alert_email ) ) {
            // No email configured
            return [
                'type'    => 'high_risk_no_email',
                'context' => $context,
            ];
        }

        // Check rate limit: Only send email if last alert was sent more than 24 hours ago
        $last_alert_option = 'museder_ai_last_alert_' . $context;
        $last_alert_time = get_option( $last_alert_option, 0 );
        $current_time = current_time( 'timestamp' );
        $hours_since_last_alert = ( $current_time - $last_alert_time ) / HOUR_IN_SECONDS;
        $can_send_email = ( $hours_since_last_alert >= 24 || $last_alert_time === 0 );

        // Send email (only if rate limit allows)
        if ( ! $can_send_email ) {
            // Rate limit: Show alert but don't send email
            return [
                'type'        => 'high_risk_email_sent',
                'context'     => $context,
                'alert_email' => $alert_email,
                'rate_limited' => true,
            ];
        }

        // Send email
        $site_name = get_bloginfo( 'name' );
        $site_url = home_url();

        // Map context to display name and admin URL
        $context_map = [
            'site_scan'     => [
                'name' => __( 'AI Site Scan', 'museder-restoreone' ),
                'url'  => admin_url( 'admin.php?page=backup-lite-dashboard' ),
            ],
            'backup_report' => [
                'name' => __( 'Backup AI Report', 'museder-restoreone' ),
                'url'  => admin_url( 'admin.php?page=backup-lite-dashboard' ),
            ],
            'error_log'     => [
                'name' => __( 'Error Log AI', 'museder-restoreone' ),
                'url'  => admin_url( 'admin.php?page=backup-lite-logs' ),
            ],
            'restore_guide' => [
                'name' => __( 'Restore AI Guide', 'museder-restoreone' ),
                'url'  => admin_url( 'admin.php?page=backup-lite-restore' ),
            ],
        ];

        $context_info = isset( $context_map[ $context ] ) ? $context_map[ $context ] : [
            'name' => ucfirst( $context ),
            'url'  => admin_url(),
        ];

        // Prepare email subject
        $subject = sprintf(
            /* translators: 1: Site name */
            __( '[Museder RestoreOne] High Risk Alert – %s', 'museder-restoreone' ),
            $site_name
        );

        // Prepare email body
        $summary = isset( $result['summary'] ) ? $result['summary'] : '';
        // Extract first 1-2 sentences from summary
        $summary_excerpt = '';
        if ( ! empty( $summary ) ) {
            $sentences = preg_split( '/([.!?]+)/', $summary, 3, PREG_SPLIT_DELIM_CAPTURE );
            $summary_excerpt = implode( '', array_slice( $sentences, 0, 3 ) );
            if ( strlen( $summary_excerpt ) < strlen( $summary ) ) {
                $summary_excerpt .= '...';
            }
        }

        $email_body = sprintf(
            /* translators: 1: Site name, 2: Site URL, 3: Context name, 4: Summary excerpt, 5: Admin URL */
            __( "High Risk Alert\n\n" .
                "Site: %s\n" .
                "URL: %s\n\n" .
                "Risk Source: %s\n" .
                "Risk Level: High\n\n" .
                "Summary:\n%s\n\n" .
                "Please log in to your WordPress admin to view the full report:\n%s\n\n" .
                "---\n" .
                "This is an automated alert from Museder RestoreOne.", 'museder-restoreone' ),
            $site_name,
            $site_url,
            $context_info['name'],
            $summary_excerpt ?: __( 'No summary available.', 'museder-restoreone' ),
            $context_info['url']
        );

        // Send email
        $mail_sent = wp_mail( $alert_email, $subject, $email_body );

        if ( $mail_sent ) {
            // Update last alert timestamp
            update_option( $last_alert_option, $current_time, false );
            
            return [
                'type'        => 'high_risk_email_sent',
                'context'     => $context,
                'alert_email' => $alert_email,
            ];
        } else {
            // Email failed
            return [
                'type'    => 'high_risk_email_failed',
                'context' => $context,
            ];
        }
    }

}


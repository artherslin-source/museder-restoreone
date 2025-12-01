# AI Alerts Developer Documentation

## Overview

The AI Alerts system in Museder RestoreOne automatically detects high-risk situations from AI analysis results and sends notifications to administrators. This document explains the implementation details for developers.

## Trigger Conditions

AI Alerts are triggered when:

1. **AI analysis completes successfully** (`status === 'success'`)
2. **Risk level is 'high'** (case-insensitive)
   - For `site_scan`: Checks `result['risk']` field
   - For other actions: Checks `result['risk_level']` field
   - Both fields are normalized to lowercase before comparison

## Supported AI Features

The following four AI features support alerts:

1. **AI Site Scan** (`site_scan`)
   - Context: `'site_scan'`
   - Admin URL: Dashboard page

2. **Backup AI Report** (`backup_report`)
   - Context: `'backup_report'`
   - Admin URL: Dashboard page

3. **Error Log AI** (`error_log`)
   - Context: `'error_log'`
   - Admin URL: Logs page

4. **Restore AI Guide** (`restore_guide`)
   - Context: `'restore_guide'`
   - Admin URL: Restore page

## License Tier Behavior

### Free Tier

- **No email sent**
- **UI Display**: Red alert box with message:
  > "High risk detected. Upgrade to Pro to enable email alerts."

### Pro / Agency Tier

#### Without Alert Email Configured

- **No email sent**
- **UI Display**: Red alert box with message:
  > "High risk detected. Please set an alert email address in Museder AI Settings."

#### With Alert Email Configured

- **Email sent** (subject to rate limiting)
- **UI Display**: Green alert box with message:
  > "High risk detected. An alert email has been sent to {email}."

## Rate Limiting

To prevent email spam, alerts are rate-limited:

- **Limit**: One email per 24 hours per AI feature
- **Storage**: WordPress options (`museder_ai_last_alert_{context}`)
- **Behavior**:
  - If last alert was sent < 24 hours ago: Show success message but don't send email
  - Success message includes: "(Alerts are limited to once per day)"
  - UI still displays the green alert box

### Implementation Details

```php
// Check rate limit
$last_alert_option = 'museder_ai_last_alert_' . $context;
$last_alert_time = get_option( $last_alert_option, 0 );
$current_time = current_time( 'timestamp' );
$hours_since_last_alert = ( $current_time - $last_alert_time ) / HOUR_IN_SECONDS;
$can_send_email = ( $hours_since_last_alert >= 24 || $last_alert_time === 0 );

// Update timestamp after successful email
if ( $mail_sent ) {
    update_option( $last_alert_option, $current_time, false );
}
```

## Email Content

### Subject
```
[Museder RestoreOne] High Risk Alert – {Site Name}
```

### Body
```
High Risk Alert

Site: {Site Name}
URL: {Site URL}

Risk Source: {AI Feature Name}
Risk Level: High

Summary:
{First 1-2 sentences of AI summary}...

Please log in to your WordPress admin to view the full report:
{Admin URL}

---
This is an automated alert from Museder RestoreOne.
```

## Code Flow

### Backend (PHP)

1. AI analysis completes → `Museder_AI_Service::send_request()` or `demo_response()`
2. Result stored → `store_last_*()` methods
3. Alert check → `Museder_AI_Service::maybe_send_alert( $context, $result )`
4. Alert data added to response → `$result['alert'] = $alert`
5. AJAX response sent → `wp_send_json_success( $response_data )`

### Frontend (JavaScript)

1. AJAX success callback receives `response.data.alert`
2. `displayAIAlert( container, alert )` called
3. Alert box displayed based on `alert.type`:
   - `high_risk_free`: Red box
   - `high_risk_no_email`: Red box
   - `high_risk_email_sent`: Green box (may include rate limit message)
   - `high_risk_email_failed`: Red box

## Alert Types

| Type | License | Email Configured | Email Sent | UI Color |
|------|---------|------------------|------------|----------|
| `high_risk_free` | Free | N/A | No | Red |
| `high_risk_no_email` | Pro/Agency | No | No | Red |
| `high_risk_email_sent` | Pro/Agency | Yes | Yes* | Green |
| `high_risk_email_failed` | Pro/Agency | Yes | Failed | Red |

*Subject to 24-hour rate limiting

## Integration Points

### AJAX Handlers

All four AI feature AJAX handlers call `maybe_send_alert()`:

- `Backup_Lite_UI::ajax_ai_demo_site_scan()`
- `Backup_Lite_UI::ajax_ai_backup_report()`
- `Backup_Lite_UI::ajax_ai_analyze_error_logs()`
- `Backup_Lite_UI::ajax_ai_restore_guide()`

### Frontend Initialization

- `initAISiteScan()` - Dashboard page
- `initAIBackupReport()` - Dashboard page
- `initAILogAnalysis()` - Logs page
- `initAIRestoreGuide()` - Restore page

## Testing

### Manual Testing Checklist

1. **Free Tier**
   - [ ] Run AI feature with high risk
   - [ ] Verify red alert box appears
   - [ ] Verify no email sent

2. **Pro Tier - No Email**
   - [ ] Set license to Pro
   - [ ] Leave alert email empty
   - [ ] Run AI feature with high risk
   - [ ] Verify red alert box appears
   - [ ] Verify no email sent

3. **Pro Tier - With Email**
   - [ ] Set license to Pro
   - [ ] Configure alert email
   - [ ] Run AI feature with high risk
   - [ ] Verify green alert box appears
   - [ ] Verify email received
   - [ ] Run same feature again within 24 hours
   - [ ] Verify green alert box with rate limit message
   - [ ] Verify no second email sent

4. **All Four Features**
   - [ ] Test each feature independently
   - [ ] Verify correct context mapping
   - [ ] Verify correct admin URLs in email

## Notes

- Risk level detection is case-insensitive
- Both `risk` and `risk_level` fields are supported for compatibility
- Rate limiting is per-feature (not global)
- Email failures are logged but don't prevent UI alerts
- All strings are translatable via WordPress i18n


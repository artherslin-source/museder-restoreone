# Cloud Storage Developer Documentation

## Overview

The Cloud Storage feature in Museder RestoreOne allows Pro and Agency users to automatically upload backup files to Amazon S3 or S3-compatible storage after successful local backup creation.

## Supported Providers

Currently, only **Amazon S3 / S3-compatible** storage is supported.

Future providers (Google Drive, Dropbox, etc.) are planned but not yet implemented.

## Configuration Fields

Cloud Storage settings are stored in the `museder_cloud_settings` WordPress option:

```php
[
    'enabled'       => bool,      // Whether cloud backups are enabled
    'provider'      => 'none'|'s3', // Storage provider
    's3_access_key' => string,    // S3 Access Key ID
    's3_secret_key' => string,    // S3 Secret Access Key (never displayed in UI)
    's3_region'     => string,    // S3 region (e.g., 'ap-northeast-1')
    's3_bucket'     => string,    // S3 bucket name
    's3_endpoint'   => string,    // Custom endpoint (optional, defaults to AWS)
]
```

## Backup Flow

### Manual Backup

1. User creates backup via Dashboard → Backups page
2. User optionally checks "Also upload to cloud storage (S3)" checkbox
3. Backup is created locally first (always)
4. If checkbox is checked AND cloud storage is enabled:
   - `Museder_Cloud_Service::maybe_upload_backup()` is called
   - File is uploaded to S3 using AWS Signature V4
   - Result is logged (success or error)

### Scheduled Backup

1. Cron triggers scheduled backup
2. Backup is created with `cloud_destination => true` if cloud storage is enabled
3. Same upload flow as manual backup

## Code Flow

### Backend (PHP)

1. **Backup Creation** → `Backup_Lite_Backup::backup_site( $options )`
   - Options may include `'cloud_destination' => true`
   
2. **Backup Success** → Cloud upload triggered:
   ```php
   $cloud_context = [
       'type'       => 'manual'|'schedule',
       'site_url'   => home_url(),
       'created_at' => time(),
       'backup_id'  => basename( $archive_path ),
   ];
   $cloud_result = Museder_Cloud_Service::maybe_upload_backup( 
       $archive_path, 
       $cloud_context, 
       $should_upload_cloud 
   );
   ```

3. **Upload Logic** → `Museder_Cloud_Service::upload_to_s3()`
   - Validates settings
   - Generates S3 object key: `museder/{site_hash}/backups/{Y}/{m}/{filename}`
   - Builds AWS Signature V4 authorization
   - Uses `wp_remote_request()` (WordPress HTTP API only)
   - Returns success/error status

4. **Logging** → Results logged via `backup_lite_log()`
   - Success: `Cloud: uploaded to s3://{bucket}/{key}`
   - Error: `Cloud: upload failed: {message}`

### Frontend (JavaScript)

- Backup form includes checkbox: `backup_lite_cloud_destination`
- Checkbox only visible if:
  - License tier is Pro/Agency
  - Cloud storage is enabled (`Museder_Cloud_Service::is_enabled()`)

## S3 Upload Implementation

### AWS Signature V4

The implementation uses AWS Signature V4 with `UNSIGNED-PAYLOAD`:

1. **Canonical Request**:
   ```
   PUT
   /{bucket}/{object_key}
   (query string)
   host:{host}
   x-amz-content-sha256:UNSIGNED-PAYLOAD
   x-amz-date:{timestamp}
   
   host;x-amz-content-sha256;x-amz-date
   UNSIGNED-PAYLOAD
   ```

2. **String to Sign**:
   ```
   AWS4-HMAC-SHA256
   {timestamp}
   {date_stamp}/{region}/s3/aws4_request
   {hashed_canonical_request}
   ```

3. **Signature Calculation**:
   - `kDate = HMAC-SHA256(date_stamp, "AWS4" + secret_key)`
   - `kRegion = HMAC-SHA256(region, kDate)`
   - `kService = HMAC-SHA256("s3", kRegion)`
   - `kSigning = HMAC-SHA256("aws4_request", kService)`
   - `signature = HMAC-SHA256(string_to_sign, kSigning)`

4. **Authorization Header**:
   ```
   AWS4-HMAC-SHA256 Credential={access_key}/{credential_scope}, SignedHeaders={signed_headers}, Signature={signature}
   ```

### Object Key Format

```
museder/{site_hash}/backups/{YYYY}/{MM}/{filename}
```

- `site_hash`: MD5 hash of `home_url()`
- `YYYY`: Year (4 digits)
- `MM`: Month (2 digits)
- `filename`: Original backup filename

### Endpoint Handling

- If custom endpoint provided: Use as-is
- If empty: Default to `https://s3.{region}.amazonaws.com`
- Supports path-style URLs: `https://{endpoint}/{bucket}/{key}`

## License Tier Behavior

### Free Tier

- Cloud Storage settings page shows upgrade message only
- No configuration fields displayed
- Backup form shows: "Backups are stored locally. Upgrade to Pro to enable cloud storage."
- No upload attempts (skipped in `maybe_upload_backup()`)

### Pro / Agency Tier

- Full Cloud Storage settings page available
- Can configure S3 credentials
- Backup form shows checkbox (if cloud enabled)
- Uploads proceed if checkbox is checked

## Security Considerations

1. **Secret Key Handling**:
   - Never displayed in UI (password field, no value attribute)
   - Only updated if new value provided (preserves existing if blank)
   - Stored in WordPress options (encrypted at database level if available)

2. **HTTP Requests**:
   - Only WordPress HTTP API used (`wp_remote_request()`)
   - No direct `curl_*` or `file_get_contents()` for external URLs
   - All headers properly escaped

3. **File Access**:
   - Only reads local backup files (already created and validated)
   - No sensitive data sent to third parties (only to user's S3 bucket)

4. **Capability Checks**:
   - Settings page: `manage_options` required
   - Backup operations: `manage_options` required
   - All AJAX handlers verify nonce and capability

## Log Messages

### Success
```
Cloud: uploaded to s3://bucket-name/museder/abc123/backups/2025/11/backup.zip
```

### Error
```
Cloud: upload failed: HTTP 403: Access Denied
Cloud: upload failed: WP_Error: cURL error 28: Operation timed out
```

### Skipped (not logged)
- User didn't check checkbox
- Cloud storage not enabled
- Free tier license
- File not found

## Testing Scenarios

### 1. Pro + Cloud Disabled
- **Expected**: Backup succeeds, no Cloud log messages

### 2. Pro + Cloud Enabled + S3 Config Invalid
- **Expected**: Backup succeeds, log shows: `Cloud: upload failed: {error}`

### 3. Pro + Cloud Enabled + S3 Config Valid
- **Expected**: 
  - Backup succeeds
  - Log shows: `Cloud: uploaded to s3://{bucket}/{key}`
  - File visible in S3 bucket

### 4. Free Tier
- **Expected**: 
  - Settings page shows upgrade message
  - Backup form shows upgrade text
  - No upload attempts

## Integration Points

### Settings Page
- `includes/admin/class-cloud-settings-page.php`
- Route: `admin.php?page=backup-lite-cloud`
- Capability: `manage_options`

### Service Class
- `includes/class-cloud-service.php`
- Main methods:
  - `get_settings()`: Read configuration
  - `update_settings()`: Save configuration
  - `is_enabled()`: Check if fully configured
  - `maybe_upload_backup()`: Main upload entry point
  - `upload_to_s3()`: S3-specific upload logic

### Backup Integration
- `includes/class-backup.php`:
  - `backup_site()`: Sync backup method
  - `finalize_async_job()`: Async backup method
- `includes/class-schedule-handler.php`:
  - `run_scheduled_backup()`: Scheduled backups

### UI Templates
- `templates/page-backups.php`: Backup form with checkbox
- `templates/page-pro-features.php`: Cloud Storage card

## Notes

- All strings are translatable via WordPress i18n
- Error messages are user-friendly and actionable
- Upload failures don't affect local backup success
- Rate limiting or retry logic not implemented (future enhancement)
- Large file uploads may timeout (consider chunked uploads for future)


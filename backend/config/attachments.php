<?php

$office = 'application/vnd.openxmlformats-officedocument';

return [
    // Private disk only. The `public` disk is refused at runtime: uploads must never have a public URL.
    'disk' => env('ATTACHMENTS_DISK', 'local'),

    'max_file_kb' => (int) env('ATTACHMENTS_MAX_FILE_KB', 10240),
    'max_files_per_upload' => (int) env('ATTACHMENTS_MAX_FILES_PER_UPLOAD', 10),
    'max_files_per_ticket' => (int) env('ATTACHMENTS_MAX_FILES_PER_TICKET', 50),

    // On disks that support signed URLs (S3) downloads may redirect to a short-lived URL (max 5 minutes).
    // Off by default: files are streamed through Laravel after the authorization check.
    'temporary_urls' => (bool) env('ATTACHMENTS_TEMPORARY_URLS', false),
    'temporary_url_minutes' => min(5, (int) env('ATTACHMENTS_TEMPORARY_URL_MINUTES', 5)),

    // extension => MIME types the file CONTENT may be detected as. Extension and content must agree.
    // svg, html, js, php and executables are deliberately absent.
    'allowed' => [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        // Office files are zip containers; some generators are detected as plain zip.
        'docx' => ["{$office}.wordprocessingml.document", 'application/zip', 'application/octet-stream'],
        'xlsx' => ["{$office}.spreadsheetml.sheet", 'application/zip', 'application/octet-stream'],
        'pptx' => ["{$office}.presentationml.presentation", 'application/zip', 'application/octet-stream'],
    ],

    // Archives are off unless explicitly enabled.
    'allow_archives' => (bool) env('ATTACHMENTS_ALLOW_ARCHIVES', false),
    'archives' => [
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ],

    // Only these may be displayed inline (image previews); everything else is always a download.
    'inline_mimes' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],
];

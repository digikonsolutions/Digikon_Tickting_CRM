<?php

$office = 'application/vnd.openxmlformats-officedocument';

return [
    // Private disk only. The `public` disk is refused at runtime: uploads must never have a public URL.
    'disk' => env('ATTACHMENTS_DISK', 'local'),

    'max_file_kb' => (int) env('ATTACHMENTS_MAX_FILE_KB', 20480),
    // Video files may be larger; Loom or another link is recommended for anything long.
    'max_video_kb' => (int) env('ATTACHMENTS_MAX_VIDEO_KB', 102400),
    'video_extensions' => ['mp4', 'mov', 'webm'],
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
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/octet-stream'],
        'log' => ['text/plain'],
        'mp4' => ['video/mp4'],
        'mov' => ['video/quicktime', 'video/mp4'],
        'webm' => ['video/webm'],
    ],

    // Archives are off unless explicitly enabled.
    'allow_archives' => (bool) env('ATTACHMENTS_ALLOW_ARCHIVES', false),
    'archives' => [
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ],

    // Project briefs accept zip files (design bundles, exports). Downloads are always forced, never inline.
    'brief_allow_archives' => (bool) env('ATTACHMENTS_BRIEF_ALLOW_ARCHIVES', true),
    'max_files_per_project' => (int) env('ATTACHMENTS_MAX_FILES_PER_PROJECT', 50),

    // Only these may be displayed inline (image previews); everything else is always a download.
    'inline_mimes' => ['image/png', 'image/jpeg', 'image/gif', 'image/webp'],

    // Signed contract files on an agency. Private disk, admin upload, pdf/jpg/png, up to 10 MB.
    'contract_documents' => [
        'max_kb' => (int) env('CONTRACT_DOCUMENT_MAX_KB', 10240),
        'max_per_contract' => (int) env('CONTRACT_DOCUMENT_MAX_PER_CONTRACT', 20),
        'allowed' => [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
        ],
    ],

    // HR documents on an employee record (CNIC, CV, contract...). Private disk, admin-only, pdf/jpg/png.
    'employee_documents' => [
        'max_kb' => (int) env('EMPLOYEE_DOCUMENT_MAX_KB', 5120),
        'max_per_employee' => (int) env('EMPLOYEE_DOCUMENT_MAX_PER_EMPLOYEE', 40),
        'allowed' => [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
        ],
    ],

    // Profile photos: small images only, on the PUBLIC disk (they are shown in the header, sidebar
    // and employee timeline, so unlike every other attachment they must have a public URL).
    'avatar_max_kb' => (int) env('AVATAR_MAX_KB', 4096),
    'avatar_allowed' => [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
    ],
];

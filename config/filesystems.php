<?php

/*
|--------------------------------------------------------------------------
| Document storage driver
|--------------------------------------------------------------------------
|
| The `documents` disk below holds uploaded originals, review revisions, and
| every later artifact. It is always private and is always streamed through
| the application (docs/BLOB_STORAGE.md): nothing presigns it, nothing links
| to it, and no public symlink points at it.
|
| The disk *name* is stable; only its driver changes between deployments, so
| application code never branches on where the bytes live. The two shapes are
| written out separately because `root` means different things to the two
| drivers: an absolute filesystem path for `local`, and a key prefix inside
| the bucket for `s3`.
|
*/

$documentsDriver = env('ESIGN_DOCUMENTS_DRIVER', 'local') === 's3' ? 's3' : 'local';

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Private document storage. See $documentsDriver above.
        //
        // The local root lives under storage/app, which .github/workflows/deploy.yml
        // already excludes from its `rsync --delete`; adding a root outside that
        // prefix means adding an exclude in the same commit, or a green deploy will
        // silently delete every uploaded agreement.
        'documents' => $documentsDriver === 's3' ? [
            'driver' => 's3',
            'key' => env('ESIGN_DOCUMENTS_AWS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('ESIGN_DOCUMENTS_AWS_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('ESIGN_DOCUMENTS_AWS_DEFAULT_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('ESIGN_DOCUMENTS_AWS_BUCKET', env('AWS_BUCKET')),
            'endpoint' => env('ESIGN_DOCUMENTS_AWS_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('ESIGN_DOCUMENTS_AWS_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            // A key prefix inside the bucket, not a filesystem path.
            'root' => env('ESIGN_DOCUMENTS_PREFIX', ''),
            'visibility' => 'private',
            // A failed write must raise, never return false: intake treats a silent
            // false as a successful store and would record a row for absent bytes.
            'throw' => true,
            'report' => false,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/documents'),
            'visibility' => 'private',
            // No `serve`: the framework's storage route would hand out bytes without
            // the workspace policy ever running.
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

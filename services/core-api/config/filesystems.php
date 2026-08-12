<?php

declare(strict_types=1);

use App\Support\Kb\KbSecrets;

return [

    'default' => env('FILESYSTEM_DISK', 's3'),

    'disks' => [

        // Scratch space inside the container only. Nothing tenant-owned lives here: it is not
        // shared between the api, worker and scheduler containers and it does not survive a deploy.
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'throw' => true,
            'serve' => false,
        ],

        // SeaweedFS behind its S3 gateway. ONE bucket, `kb`, forever — tenancy is the key prefix
        // (org id as the leading segment), enforced in our code. A bucket per organization
        // multiplies filer collections without bound and does not port to any managed store
        // (seaweedfs-s3, kb-tenancy-isolation).
        's3' => [
            'driver' => 's3',
            // The access key ID is an identifier, not key material — it is safe in an env var and
            // safe in a `docker compose config` dump. The paired secret is not, and is resolved
            // file-first: AWS_SECRET_ACCESS_KEY_FILE=/run/secrets/s3_secret_key.
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => KbSecrets::get('AWS_SECRET_ACCESS_KEY'),

            // SeaweedFS ignores the region; SigV4 signs it. It must be the IDENTICAL string on the
            // boto3 side in services/ai-service or the signatures disagree.
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BUCKET', 'kb'),

            // Reached directly on the internal network, never through Traefik: SeaweedFS mistakes
            // HTTP chunked transfer framing for Content-Encoding: aws-chunked and stores the
            // chunk-size lines AS FILE CONTENT — silent corruption, no error (seaweedfs-s3 #6583).
            'endpoint' => env('AWS_ENDPOINT', 'http://seaweedfs-s3:8333'),

            // TRUE, non-negotiable. Virtual-host addressing is the SDK default and needs wildcard
            // DNS for `kb.seaweedfs-s3`, which a Compose network does not have; the failure is an
            // EndpointConnectionError/NXDOMAIN that reads as the storage tier being down.
            'use_path_style_endpoint' => (bool) env('AWS_USE_PATH_STYLE_ENDPOINT', true),

            // Never public. No browser ever holds a presigned URL: uploads stream through Laravel so
            // the MIME allow-list, size cap, decompression-ratio cap and malware scan run BEFORE the
            // bytes land, and downloads are a streamed Laravel route that runs the six checks.
            'visibility' => 'private',

            // Throw, do not return false. A swallowed storage failure becomes a source stuck in a
            // non-terminal state with no error_class anywhere (kb-error-taxonomy: `storage`).
            'throw' => true,

            // botocore/aws-sdk-php made flexible checksums default-on and SeaweedFS has been
            // patching that surface continuously. Integrity here is our own SHA-256 in PostgreSQL,
            // never an ETag, so the dependency buys nothing and costs `InternalError` after a
            // routine SDK bump (seaweedfs-s3).
            'request_checksum_calculation' => 'when_required',
        ],

    ],

    // No `links` entry: there is no public disk and no storage symlink. User content is served from
    // a separate origin through a Laravel route with Content-Disposition: attachment and
    // X-Content-Type-Options: nosniff — never from the app origin's document root.
    'links' => [],

];

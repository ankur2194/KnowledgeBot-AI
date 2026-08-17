<?php

declare(strict_types=1);

use App\Support\Kb\KbSecrets;

return [

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
     * EXACTLY TWO MAILERS, AND THE OMISSIONS ARE THE DESIGN — the same reasoning config/queue.php
     * records for connections: a mailer that exists is a mailer something will silently be sent
     * through.
     *
     * THERE IS NO `log` MAILER. `MAIL_MAILER=log` writes the rendered message — including the FULL
     * password-reset URL, which is a live credential until it is used — into storage/logs, where no
     * redaction fixture covers it and where kb-security-baseline forbids it outright. Mailpit is the
     * local-development path (infrastructure/docker runs it), so `log` buys nothing and costs that.
     * The consequence is deliberate: someone who sets MAIL_MAILER=log to "see the email" gets an
     * InvalidArgumentException naming an undefined mailer, which is the correct answer.
     *
     * There is no `sendmail` (a shell-invoking transport), no `ses`/`postmark`/`resend` (no such
     * credential exists in this system, and adding the mailer before the credential is how a
     * misconfigured environment silently picks it), and no `failover`/`roundrobin` (there is one
     * transport, and a failover list of one is a comment pretending to be configuration).
     */
    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'mailpit'),
            'port' => (int) env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME'),

            /*
             * THROUGH KbSecrets, NOT env(). 'PASSWORD' is in SECRET_NAME_FRAGMENTS
             * (tests/Arch/SecretsResolverTest.php), so a bare env('MAIL_PASSWORD') FAILS the arch
             * suite — and the reason the rule exists applies here exactly: `make prod-config` runs
             * `docker compose config`, which renders every interpolated value in full into a
             * terminal, a CI log, and whatever ticket that output was pasted into. A mounted
             * MAIL_PASSWORD_FILE=/run/secrets/<name> wins over an inline value.
             */
            'password' => KbSecrets::get('MAIL_PASSWORD'),

            /*
             * EXPLICIT, and 5 seconds rather than the default. Symfony's SMTP transport falls back
             * to PHP's default_socket_timeout — commonly 60 s — so a wedged relay would hold a
             * `notify` worker for a full minute per message and the queue would look like a leak
             * rather than a dependency failure. This is not part of any request budget: every mail
             * this application sends is queued, precisely so no SMTP handshake ever happens on a
             * request thread (see App\Notifications\ResetPassword).
             */
            'timeout' => 5,

            /*
             * The EHLO name. Left unset, Symfony sends the container's hostname, which a strict
             * relay rejects with a 550 that reads like an authentication problem.
             */
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        /*
         * The test suite's transport. phpunit.xml pins MAIL_MAILER=array so notification assertions
         * never open a socket to `mailpit`, which the `test` Compose profile does not run.
         */
        'array' => [
            'transport' => 'array',
        ],

    ],

    /*
     * The envelope and From identity. MAIL_FROM_ADDRESS must be on a domain whose SPF and DKIM
     * records you control, or every reset mail lands in spam and the failure presents as "the link
     * never arrived" — indistinguishable from a broken token.
     */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@localhost'),
        'name' => env('MAIL_FROM_NAME', 'KnowledgeBot'),
    ],

];

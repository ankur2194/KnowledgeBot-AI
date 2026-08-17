<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Email;

/**
 * The mail the suite actually produced, read out of the `array` transport.
 *
 * WHY THIS RATHER THAN Notification::fake(). Every capability token in this system reaches its owner
 * ONLY through an email — password reset, email verification, invitation. Faking the notification
 * proves that an object was constructed; reading the rendered mail proves the whole chain the user
 * depends on: the notification, App\Support\Kb\FrontendUrl, the query-string shape, and that the
 * token in the link is the one the endpoint will accept. Three of those four are exactly where a
 * silent break lands an email that goes out and does nothing — the failure nobody notices, because
 * the request returned 200.
 *
 * phpunit.xml pins MAIL_MAILER=array (config/mail.php's default is `smtp` at `mailpit`, which the
 * test profile does not run) and QUEUE_CONNECTION=sync, so a ShouldQueue notification is dispatched
 * inline and lands here.
 *
 * THE HTML BODY IS READ FROM THE MIME OBJECT, NOT FROM ->toString(). A rendered message is
 * quoted-printable, which soft-wraps at 76 columns and will break a 64-character token across two
 * lines with an `=` continuation — a regex over the serialised message therefore fails to find a
 * token that is perfectly correct. getHtmlBody() is the pre-encoding body.
 */
final class Mailbox
{
    /**
     * @return list<Email>
     */
    public static function all(): array
    {
        $transport = Mail::mailer()->getSymfonyTransport();

        if (! $transport instanceof ArrayTransport) {
            throw new RuntimeException(
                'The default mailer is not the array transport, so no mail can be read back. '
                .'phpunit.xml must pin MAIL_MAILER=array; without it this test opens a socket to '
                .'`mailpit`, which the test Compose profile does not run.',
            );
        }

        $messages = [];

        foreach ($transport->messages() as $sent) {
            $message = $sent->getOriginalMessage();

            if ($message instanceof Email) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    public static function count(): int
    {
        return count(self::all());
    }

    /**
     * The most recent message, as the recipient would see it.
     */
    public static function latest(): Email
    {
        $messages = self::all();

        if ($messages === []) {
            throw new RuntimeException(
                'No mail was sent. If the endpoint answered 200 anyway, that is the point of the '
                .'assertion: several of these endpoints acknowledge identically whether or not mail '
                .'was queued, so the mailbox is the only evidence that the send branch ran.',
            );
        }

        return $messages[count($messages) - 1];
    }

    /**
     * Recipient addresses of the most recent message.
     *
     * @return list<string>
     */
    public static function latestRecipients(): array
    {
        return array_values(array_map(
            static fn ($address): string => $address->getAddress(),
            self::latest()->getTo(),
        ));
    }

    public static function latestBody(): string
    {
        $email = self::latest();

        return html_entity_decode(
            (string) $email->getHtmlBody().' '.(string) $email->getTextBody(),
            ENT_QUOTES | ENT_HTML5,
        );
    }

    /**
     * The 64-hex capability token out of the link in the most recent mail.
     *
     * This is the assertion that matters: it is the token the recipient will POST back, so a test
     * that mints its own token through a repository proves the storage layer and not the flow.
     */
    public static function latestToken(): string
    {
        $body = self::latestBody();

        if (preg_match('/[?&]token=([0-9a-f]{64})/', $body, $matches) !== 1) {
            throw new RuntimeException(
                'The latest mail carries no `token=<64 hex>` query parameter. Either FrontendUrl '
                .'built the wrong URL, or the base is empty and the link reads `https:///…` — an '
                .'email that goes out and does nothing.',
            );
        }

        return $matches[1];
    }
}

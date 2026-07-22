<?php

namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

use Concrete\Core\Application\Application;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Outbound email: notify Brad on every new request, and send the guest a
 * claim/verify link so they can finish an account and track the request.
 *
 * Status-change emails to the customer are intentionally NOT here — deferred to
 * Phase 2 (Express has no native on-update event; needs a custom handler).
 */
class Notifier
{
    public const BRAD = 'brad@cornercad.com';

    public function __construct(protected Application $app)
    {
    }

    public function notifyBrad(int $entryId, array $f, string $sourceLabel): void
    {
        $mail = $this->app->make('mail'); // VERIFY ON DEPLOY: Concrete\Core\Mail\Service
        $mail->to(self::BRAD);
        $mail->setSubject(t('New custom request (#%s, %s)', $entryId, $sourceLabel));
        $mail->setBody(t(
            "From: %s <%s>\nSource: %s\n\n%s\n\nDashboard -> Express -> Custom Request -> entry %s",
            $f['cr_name'] ?? '',
            $f['cr_email'] ?? '',
            $sourceLabel,
            $f['cr_description'] ?? '',
            $entryId
        ));
        $mail->sendMail();
    }

    public function sendClaimEmail(string $toEmail, string $toName, int $entryId, string $token): void
    {
        if ($toEmail === '') {
            return;
        }
        // VERIFY ON DEPLOY: url/manager resolve + query building on 9.5.2
        $url = (string) $this->app->make('url/manager')->resolve(['/my-requests', 'claim'])
            . '?e=' . $entryId . '&token=' . rawurlencode($token);

        $mail = $this->app->make('mail');
        $mail->to($toEmail, $toName);
        $mail->setSubject(t('Finish your account to track your custom request'));
        $mail->setBody(t(
            "Thanks for your request.\n\nCreate an account (or log in) here to track it:\n%s",
            $url
        ));
        $mail->sendMail();
    }
}

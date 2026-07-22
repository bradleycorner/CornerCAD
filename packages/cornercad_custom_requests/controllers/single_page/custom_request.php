<?php

namespace Concrete\Package\CornercadCustomRequests\Controller\SinglePage;

use Concrete\Core\Page\Controller\PageController;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\FileValidator;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\Notifier;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\RequestRepository;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\SourceResolver;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Submit single-page at /custom-request.
 *
 * view()   renders the form (source from ?source=) and auto-claims any
 *          session-held guest entry once the visitor is logged in.
 * submit() validates (token + captcha + fields + files), imports files,
 *          creates the entry, notifies Brad, and (for guests) emails a claim
 *          link + stashes the entry id for session-claim.
 * thanks() renders the post-submit confirmation.
 */
class CustomRequest extends PageController
{
    public function view()
    {
        $sourceParam = strtolower((string) $this->request->query->get('source')) === 'business' ? 'business' : 'individual';
        $this->set('sourceParam', $sourceParam);
        $this->set('sourceLabel', SourceResolver::label($sourceParam));
        $this->set('allowedExt', FileValidator::allowedExtensions());

        $captcha = $this->app->make('captcha'); // active library = Turnstile // VERIFY ON DEPLOY
        $this->set('captcha', $captcha);

        $u = $this->app->make(\Concrete\Core\User\User::class);
        $this->set('isLoggedIn', $u->isRegistered());

        // Guest who just registered and returned: claim the session-held entry.
        if ($u->isRegistered()) {
            $sessionEntryId = (int) $this->request->getSession()->get('crEntryId');
            if ($sessionEntryId > 0) {
                $this->app->make(RequestRepository::class)->claim($sessionEntryId, (int) $u->getUserID());
                $this->request->getSession()->remove('crEntryId');
            }
        }
    }

    public function submit()
    {
        $token = $this->app->make('token');
        if (!$token->validate('cr_submit')) {
            return $this->fail(t('Session expired — please try again.'));
        }

        $captcha = $this->app->make('captcha');
        if (is_object($captcha) && method_exists($captcha, 'check') && !$captcha->check()) { // VERIFY ON DEPLOY
            return $this->fail(t('Captcha check failed — please try again.'));
        }

        $post = $this->request->request;
        foreach (['cr_name', 'cr_email', 'cr_description'] as $req) {
            if (trim((string) $post->get($req)) === '') {
                return $this->fail(t('Please complete the required fields.'));
            }
        }

        // Files: validate + import (max 5).
        $fileIds = [];
        $files = $this->request->files->get('cr_files') ?: [];
        $files = is_array($files) ? array_slice($files, 0, 5) : [];
        $importer = $this->app->make(\Concrete\Core\File\Import\FileImporter::class); // VERIFY ON DEPLOY
        foreach ($files as $file) {
            if (!$file) {
                continue;
            }
            $err = FileValidator::check($file->getClientOriginalName(), (int) $file->getSize());
            if ($err) {
                return $this->fail($err);
            }
            $imported = $importer->importUploadedFile($file); // VERIFY ON DEPLOY
            if (is_object($imported) && method_exists($imported, 'getFile') && is_object($imported->getFile())) {
                $fileIds[] = (int) $imported->getFile()->getFileID();
            }
        }

        $fields = [];
        foreach (['cr_name', 'cr_email', 'cr_description', 'cr_dimensions', 'cr_material', 'cr_timeline', 'cr_budget'] as $k) {
            $fields[$k] = trim((string) $post->get($k));
        }
        $sourceLabel = SourceResolver::label($post->get('source'));

        $u = $this->app->make(\Concrete\Core\User\User::class);
        $ownerUid = $u->isRegistered() ? (int) $u->getUserID() : 0;

        $repo = $this->app->make(RequestRepository::class);
        $result = $repo->create($fields, $ownerUid, $sourceLabel, $fileIds);

        $this->app->make(Notifier::class)->notifyBrad($result['entryId'], $fields, $sourceLabel);

        $session = $this->request->getSession();
        if ($ownerUid === 0) {
            $session->set('crEntryId', $result['entryId']); // claim after register/login
            $this->app->make(Notifier::class)->sendClaimEmail($fields['cr_email'], $fields['cr_name'], $result['entryId'], $result['claimToken']);
            $session->set('crNeedsAccount', true);
        } else {
            $session->set('crNeedsAccount', false);
        }

        return $this->redirect('/custom-request/thanks');
    }

    public function thanks()
    {
        $session = $this->request->getSession();
        $this->set('needsAccount', (bool) $session->get('crNeedsAccount'));
        $this->set('myRequestsUrl', (string) $this->app->make('url/manager')->resolve(['/my-requests']));
        $session->remove('crNeedsAccount');
    }

    protected function fail(string $message)
    {
        $this->flash('error', $message);

        return $this->redirect('/custom-request');
    }
}

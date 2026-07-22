<?php

namespace Concrete\Package\CornercadCustomRequests\Controller\SinglePage;

use Concrete\Core\Page\Controller\PageController;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\ClaimToken;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\RequestRepository;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * My Requests single-page at /my-requests. Login-required.
 *
 * view()  lists the current user's requests with their status.
 * claim() (linked from the claim email) associates an unclaimed entry to the
 *         now-authenticated user, then adds them to the customer group.
 */
class MyRequests extends PageController
{
    public function on_start()
    {
        parent::on_start();
        $u = $this->app->make(\Concrete\Core\User\User::class);
        if (!$u->isRegistered()) {
            // Bounce to login, returning here afterward. // VERIFY ON DEPLOY: redirect signature
            $this->redirect('/login', 'forward', $this->request->getUri());
        }
    }

    public function view()
    {
        $u = $this->app->make(\Concrete\Core\User\User::class);
        $repo = $this->app->make(RequestRepository::class);
        $rows = [];
        foreach ($repo->listForUser((int) $u->getUserID()) as $e) {
            $rows[] = [
                'id' => (int) $e->getID(),
                'description' => (string) $e->getCrDescription(),
                'status' => (string) $e->getCrStatus(),
                'source' => (string) $e->getCrSource(),
            ];
        }
        usort($rows, fn ($a, $b) => $b['id'] <=> $a['id']);
        $this->set('rows', $rows);
    }

    public function claim()
    {
        $u = $this->app->make(\Concrete\Core\User\User::class);
        $uid = (int) $u->getUserID();
        $repo = $this->app->make(RequestRepository::class);

        $entryId = (int) $this->request->query->get('e');
        $candidate = (string) $this->request->query->get('token');
        $entry = $repo->findByClaimToken($candidate);
        if (is_object($entry) && (int) $entry->getID() === $entryId && ClaimToken::matches((string) $entry->getCrClaimToken(), $candidate)) {
            if ($repo->claim($entryId, $uid)) {
                $this->addToCustomerGroup($u);
            }
        }

        return $this->redirect('/my-requests');
    }

    protected function addToCustomerGroup(\Concrete\Core\User\User $u): void
    {
        $g = \Concrete\Core\User\Group\Group::getByName('Custom Request Customers');
        if (is_object($g) && $g->getGroupID() > 0 && !$u->inGroup($g)) {
            $u->enterGroup($g); // VERIFY ON DEPLOY
        }
    }
}

<?php

namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

use Concrete\Core\Application\Application;
use Concrete\Core\Express\ObjectManager;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Create / find / claim / list Custom Request Express entries.
 *
 * Ownership is tracked in our own cr_owner_uid attribute (0 = unclaimed), NOT
 * Express entry-author semantics, so guest submissions and later claims are
 * fully under our control.
 *
 * The getCr*/setCr* accessors are Express's generated per-attribute methods
 * (handle -> camelCase). If they differ on 9.5.2, swap to
 * $entry->getAttribute('cr_name') / setAttribute(...). Marked VERIFY ON DEPLOY.
 */
class RequestRepository
{
    public function __construct(protected Application $app)
    {
    }

    protected function object()
    {
        return $this->app->make(ObjectManager::class)->getObjectByHandle('custom_request');
    }

    /**
     * @return array{entryId:int, claimToken:string}
     */
    public function create(array $f, int $ownerUid, string $sourceLabel, array $fileIds): array
    {
        $token = ClaimToken::generate();
        $manager = $this->app->make(ObjectManager::class);

        // VERIFY ON DEPLOY: entry builder + setters + save on 9.5.2
        $entry = $manager->getEntryBuilder()->createEntry($this->object());
        $entry->setCrName($f['cr_name'] ?? '');
        $entry->setCrEmail($f['cr_email'] ?? '');
        $entry->setCrDescription($f['cr_description'] ?? '');
        $entry->setCrDimensions($f['cr_dimensions'] ?? '');
        $entry->setCrMaterial(InputSanitizer::material($f['cr_material'] ?? ''));
        $entry->setCrTimeline($f['cr_timeline'] ?? '');
        $entry->setCrBudget(InputSanitizer::budget($f['cr_budget'] ?? ''));
        $entry->setCrAttachmentFids(implode(',', array_map('intval', $fileIds)));
        $entry->setCrStatus(StatusLadder::default());
        $entry->setCrSource($sourceLabel);
        $entry->setCrOwnerUid($ownerUid);
        $entry->setCrClaimToken($token);
        $manager->saveEntry($entry);

        return ['entryId' => (int) $entry->getID(), 'claimToken' => $token];
    }

    public function findById(int $id)
    {
        return $this->app->make(ObjectManager::class)->getEntry($id); // VERIFY ON DEPLOY
    }

    public function findByClaimToken(string $token)
    {
        if ($token === '') {
            return null;
        }
        foreach ($this->listAll() as $e) {
            if (ClaimToken::matches((string) $e->getCrClaimToken(), $token)) {
                return $e;
            }
        }

        return null;
    }

    public function claim(int $entryId, int $uid): bool
    {
        $e = $this->findById($entryId);
        if (!is_object($e) || (int) $e->getCrOwnerUid() > 0) {
            return false; // missing or already claimed
        }
        $e->setCrOwnerUid($uid);
        $this->app->make(ObjectManager::class)->saveEntry($e); // VERIFY ON DEPLOY

        return true;
    }

    public function listForUser(int $uid): array
    {
        if ($uid <= 0) {
            return [];
        }

        return array_values(array_filter(
            $this->listAll(),
            fn ($e) => (int) $e->getCrOwnerUid() === $uid
        ));
    }

    protected function listAll(): array
    {
        // VERIFY ON DEPLOY: entry list retrieval for an Express object on 9.5.2
        $list = $this->app->make(ObjectManager::class)->getEntryList($this->object());

        return is_array($list) ? $list : (method_exists($list, 'getResults') ? $list->getResults() : iterator_to_array($list));
    }
}

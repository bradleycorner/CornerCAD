<?php

namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

use Concrete\Core\Application\Application;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Support\Facade\Express;
use Concrete\Core\User\Group\Group;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * Idempotent install steps. Creates the customer group, the Custom Request
 * Express object (+ attributes + select options), and the two single-pages.
 *
 * Every step existence-checks first, so install() and upgrade() can both call
 * run() without duplicating anything.
 *
 * NOTE: lines marked "VERIFY ON DEPLOY" use live 9.5.2 APIs that were authored
 * without server access — sanity-check them on first staging install.
 */
class Installer
{
    public function __construct(protected Application $app)
    {
    }

    public function run(Package $pkg): void
    {
        $this->installGroup($pkg);
        $this->installExpressObject($pkg);
        $this->installSinglePages($pkg);
    }

    protected function installGroup(Package $pkg): void
    {
        $existing = Group::getByName('Custom Request Customers');
        if (is_object($existing) && $existing->getGroupID() > 0) {
            return;
        }
        // VERIFY ON DEPLOY: Group::add signature on 9.5.2
        Group::add('Custom Request Customers', t('Customers who submitted a custom request'), false, false, $pkg);
    }

    protected function installExpressObject(Package $pkg): void
    {
        $manager = $this->app->make(\Concrete\Core\Express\ObjectManager::class);
        if (is_object($manager->getObjectByHandle('custom_request'))) {
            return; // already installed
        }

        // VERIFY ON DEPLOY: ObjectBuilder API on 9.5.2
        $object = Express::buildObject('custom_request', 'custom_requests', 'Custom Request', $pkg);
        $object->addAttribute('text', 'Name', 'cr_name');
        $object->addAttribute('email', 'Email', 'cr_email');
        $object->addAttribute('textarea', 'Description', 'cr_description');
        $object->addAttribute('text', 'Dimensions', 'cr_dimensions');
        $object->addAttribute('text', 'Material/Process', 'cr_material');
        $object->addAttribute('text', 'Timeline', 'cr_timeline');
        $object->addAttribute('text', 'Budget', 'cr_budget');
        $object->addAttribute('textarea', 'Attachment File IDs', 'cr_attachment_fids');
        $object->addAttribute('number', 'Owner User ID', 'cr_owner_uid');
        $object->addAttribute('text', 'Claim Token', 'cr_claim_token');
        $object->addAttribute('textarea', 'Internal Notes', 'cr_internal_notes');
        $object->addAttribute('select', 'Status', 'cr_status');
        $object->addAttribute('select', 'Source', 'cr_source');
        $object->save();

        // Seed select options. If this proves unreliable on 9.5.2, the fallback
        // (see README / plan Task 3) is to make cr_status/cr_source text
        // attributes; StatusLadder stays the single source of truth regardless.
        $this->seedSelectOptions('cr_status', StatusLadder::options()); // VERIFY ON DEPLOY
        $this->seedSelectOptions('cr_source', ['Individual', 'Business']); // VERIFY ON DEPLOY
    }

    /**
     * Populate a select attribute key's option list, once.
     *
     * VERIFY ON DEPLOY: exact SelectValueOption + option-list persistence on
     * 9.5.2. Guarded so a partial/failed run does not fatal the install.
     */
    protected function seedSelectOptions(string $handle, array $labels): void
    {
        try {
            $key = \Concrete\Core\Attribute\Key\Key::getByHandle($handle);
            if (!is_object($key)) {
                return;
            }
            $settings = $key->getController()->getAttributeKeySettings();
            $list = $settings->getOptionList();
            if (count($list) > 0) {
                return; // already seeded
            }
            foreach ($labels as $i => $label) {
                $opt = new \Concrete\Core\Entity\Attribute\Value\Value\SelectValueOption();
                $opt->setDisplayOrder($i);
                $opt->setValue($label);
                $list->add($opt);
            }
            $em = $this->app->make(\Doctrine\ORM\EntityManagerInterface::class);
            $em->persist($settings);
            $em->flush();
        } catch (\Throwable $e) {
            \Log::addWarning('[custom_requests] seedSelectOptions(' . $handle . ') failed: ' . $e->getMessage());
        }
    }

    protected function installSinglePages(Package $pkg): void
    {
        foreach (['/custom-request', '/my-requests'] as $path) {
            $existing = Page::getByPath($path);
            if (is_object($existing) && !$existing->isError()) {
                continue;
            }
            SinglePage::add($path, $pkg); // VERIFY ON DEPLOY
        }
    }
}

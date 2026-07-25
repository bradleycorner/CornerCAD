<?php

namespace Concrete\Package\CornercadCatalogImport;

use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Type\Type as PageType;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\File\Import\FileImporter;
use Concrete\Core\Tree\Node\Type\Category as TopicCategoryNode;
use Concrete\Core\Tree\Type\Topic as TopicTree;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * CornerCAD Catalog Bulk Import
 * ------------------------------------------------------------------
 * Creates catalog `Product` pages IN BULK from a generated manifest.json,
 * doing the things the REST API and Composer gate block:
 *   - create pages of the `product` page type (Page::add — no composer gate)
 *   - set product_* attribute values (short_desc, price, cta_type, ...)
 *   - assign Product Categories topics
 *   - import + attach a featured image from the bundled images/ folder
 *
 * WHY A PACKAGE: install()/upgrade() run server-side PHP with full core
 * access, unlike the scope-limited REST API. See
 * docs/catalog-bulk-import-findings.md.
 *
 * PREREQUISITE: `cornercad_setup` must already be installed (it creates the
 * `product` page type, the product_* attributes, and the Product Categories
 * topic tree that this package writes into).
 *
 * DEPLOY: see README.md. Bundle images/ + manifest.json, upload the folder to
 * <site>/packages/cornercad_catalog_import, then install via
 *   ./concrete/bin/concrete c5:package:install cornercad_catalog_import
 * or Dashboard → Extend Concrete.
 *
 * RE-RUN: idempotent — a product whose /catalog/<slug> page already exists is
 * skipped. Re-run after growing the manifest (batch 1, then the full 400).
 *
 * // VERIFY ON DEPLOY markers flag core API calls whose exact signature should
 * // be sanity-checked against the live 9.5.2 instance (this was authored from a
 * // workstation with no server access).
 */
class Controller extends Package
{
    protected $pkgHandle = 'cornercad_catalog_import';
    protected $appVersionRequired = '9.0.0';
    protected $pkgVersion = '0.1.0';

    /** Parent page under which Product pages are created. */
    const CATALOG_PATH = '/catalog';

    /** The Product Categories topic tree name (from cornercad_setup/install.xml). */
    const TOPIC_TREE_NAME = 'Product Categories';

    public function getPackageName()
    {
        return t('CornerCAD Catalog Bulk Import');
    }

    public function getPackageDescription()
    {
        return t('Bulk-creates Product pages from manifest.json (h3lio + personal prints), with attributes, categories, and featured images.');
    }

    public function install()
    {
        $pkg = parent::install();
        $this->runImport($pkg);
        return $pkg;
    }

    /**
     * Re-import on upgrade too, so bumping $pkgVersion + redeploying a larger
     * manifest tops up the catalog. Idempotent (existing slugs are skipped).
     */
    public function upgrade()
    {
        parent::upgrade();
        $pkg = Package::getByHandle($this->pkgHandle);
        $this->runImport($pkg);
    }

    // ------------------------------------------------------------------ //

    /**
     * Load the manifest and create/patch one Product page per entry.
     */
    protected function runImport($pkg)
    {
        $manifestPath = __DIR__ . '/manifest.json';
        if (!is_file($manifestPath)) {
            \Log::addWarning('[catalog_import] manifest.json not found — nothing to import.');
            return;
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['products'])) {
            \Log::addWarning('[catalog_import] manifest.json empty or malformed.');
            return;
        }

        $catalog = Page::getByPath($manifest['catalog_parent_path'] ?? self::CATALOG_PATH);
        if (!is_object($catalog) || $catalog->isError()) {
            \Log::addError('[catalog_import] Catalog parent page not found — create /catalog first.');
            return;
        }

        $productType = PageType::getByHandle('product');   // VERIFY ON DEPLOY
        if (!is_object($productType)) {
            \Log::addError('[catalog_import] `product` page type missing — install cornercad_setup first.');
            return;
        }
        $template = PageTemplate::getByHandle('full');     // VERIFY ON DEPLOY (default template)

        $topicMap = $this->buildTopicMap();   // ["Home & Garden" => nodeID, ...]

        $created = 0; $skipped = 0; $failed = 0;
        foreach ($manifest['products'] as $p) {
            try {
                $slug = $p['slug'];
                $existing = Page::getByPath(rtrim(self::CATALOG_PATH, '/') . '/' . $slug);
                if (is_object($existing) && !$existing->isError()) {
                    $skipped++;
                    continue; // idempotent — leave already-created pages alone
                }

                // 1. Create the Product page under /catalog.
                $page = $catalog->add(                       // VERIFY ON DEPLOY (add signature)
                    $productType,
                    [
                        'cName'        => $p['name'],
                        'cHandle'      => $slug,
                        'cDescription' => $p['short_desc'] ?? '',
                        'pkgID'        => $pkg->getPackageID(),
                    ],
                    $template
                );

                // 2. Scalar / select attributes.
                $this->setIf($page, 'product_short_desc', $p['short_desc'] ?? null);
                $this->setIf($page, 'product_price', $p['price'] ?? null);
                $this->setIf($page, 'product_cta_type', $p['cta_type'] ?? null);     // buy|download|inquire
                $this->setIf($page, 'product_cta_target', $p['cta_target'] ?? null);
                $this->setIf($page, 'product_external_links', $p['external_links'] ?? null);

                // 3. Category topics (array of topic node IDs).
                if (!empty($p['categories'])) {
                    $ids = [];
                    foreach ($p['categories'] as $catName) {
                        if (isset($topicMap[$catName])) {
                            $ids[] = $topicMap[$catName];
                        } else {
                            \Log::addWarning("[catalog_import] {$slug}: unknown category '{$catName}'.");
                        }
                    }
                    if ($ids) {
                        $page->setAttribute('product_categories', $ids);   // VERIFY ON DEPLOY (topics setter shape)
                    }
                }

                // 4. Featured image — import the bundled file, attach by File object.
                if (!empty($p['featured_image'])) {
                    $file = $this->importImage($p['featured_image']);
                    if ($file) {
                        $page->setAttribute('product_featured_image', $file);
                    }
                }

                // 5. Publish the version (programmatic adds may be an unapproved draft).
                $v = $page->getVersionObject();               // VERIFY ON DEPLOY
                if (is_object($v) && method_exists($v, 'approve')) {
                    $v->approve();
                }

                $created++;
            } catch (\Throwable $e) {
                $failed++;
                \Log::addError('[catalog_import] ' . ($p['slug'] ?? '?') . ': ' . $e->getMessage());
            }
        }

        \Log::addInfo("[catalog_import] done — created {$created}, skipped {$skipped}, failed {$failed}.");
    }

    /** Set an attribute only when a non-empty value is supplied. */
    protected function setIf(Page $page, string $handle, $value)
    {
        if ($value !== null && $value !== '') {
            $page->setAttribute($handle, $value);
        }
    }

    /**
     * Resolve category NAME → topic node ID for the Product Categories tree.
     * Returns e.g. ["Automotive" => 12, "Home & Garden" => 13, ...].
     */
    protected function buildTopicMap(): array
    {
        $map = [];
        $tree = TopicTree::getByName(self::TOPIC_TREE_NAME);   // VERIFY ON DEPLOY
        if (!is_object($tree)) {
            \Log::addWarning('[catalog_import] Product Categories topic tree not found.');
            return $map;
        }
        $root = $tree->getRootTreeNodeObject();
        $root->populateDirectChildrenOnly();
        foreach ($root->getChildNodes() as $node) {
            // Topic nodes expose their label via getTreeNodeDisplayName().
            $map[$node->getTreeNodeDisplayName()] = (int) $node->getTreeNodeID();
        }
        return $map;
    }

    /**
     * Import a bundled image (path relative to this package's images/ folder)
     * into the file manager. Returns a File entity or null.
     */
    protected function importImage(string $relPath)
    {
        $abs = __DIR__ . '/images/' . ltrim($relPath, '/');
        if (!is_file($abs)) {
            \Log::addWarning("[catalog_import] image missing: {$relPath}");
            return null;
        }
        $importer = new FileImporter();                          // VERIFY ON DEPLOY
        $result = $importer->import($abs, basename($abs));
        // FileImporter::import() returns a Version on success, or an int error code.
        if (is_object($result) && method_exists($result, 'getFile')) {
            return $result->getFile();
        }
        \Log::addWarning("[catalog_import] image import failed ({$relPath}): code " . var_export($result, true));
        return null;
    }
}

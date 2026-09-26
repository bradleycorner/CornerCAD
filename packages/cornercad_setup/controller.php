<?php

namespace Concrete\Package\CornercadSetup;

use Concrete\Core\Package\Package;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * CornerCAD Site Setup
 *
 * Installs the content MODEL for the CornerCAD site opening structure — the
 * pieces the Concrete CMS REST API cannot create (page types, attributes, topic
 * trees). Pages and blocks are built afterward over the API.
 *
 * Deploy: upload this folder to <site>/packages/cornercad_setup, then install
 * via Dashboard → Extend Concrete → "CornerCAD Site Setup" (or CLI
 * `concrete c5:package:install cornercad_setup`). Install runs install.xml once.
 *
 * Reuse: the same package installs Lisa's site — edit only the topic tree in
 * install.xml (see <trees>), bump $pkgVersion if needed, redeploy.
 */
class Controller extends Package
{
    protected $pkgHandle = 'cornercad_setup';
    protected $appVersionRequired = '9.0.0';
    protected $pkgVersion = '1.0.0';

    public function getPackageName()
    {
        return t('CornerCAD Site Setup');
    }

    public function getPackageDescription()
    {
        return t('Installs the Product page type, product attributes, and the Product Categories topic tree for the CornerCAD site structure.');
    }

    public function install()
    {
        $pkg = parent::install();
        // Imports topic tree, attribute keys, and the Product page type.
        $this->installContentFile('install.xml');

        return $pkg;
    }

    /**
     * Intentionally does NOT re-import install.xml.
     *
     * Per the CIF docs, page types are re-imported with no existence check, so
     * re-running would duplicate the Product page type (attributes and topics
     * skip if they already exist, but page types do not). If the model changes,
     * adjust it in the dashboard, or uninstall + reinstall on a fresh site.
     */
    public function upgrade()
    {
        parent::upgrade();
    }
}

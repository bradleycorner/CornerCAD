<?php

namespace Concrete\Package\CornercadCustomRequests;

use Concrete\Core\Package\Package;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\Installer;

defined('C5_EXECUTE') or die('Access Denied.');

/**
 * CornerCAD Custom Requests
 *
 * Login-gated custom-work request intake with status tracking, shared across
 * both multisite front doors (cornercad.com + cornercadworks.com).
 *
 * Guest fills the form (never lost) -> request saved immediately (owner=0) ->
 * register/login -> claim -> track status under "My Requests". Brad manages
 * every request from one Dashboard Express queue.
 *
 * Deploy: upload to <site>/packages/cornercad_custom_requests, install via
 * Dashboard -> Extend Concrete (or `concrete c5:package:install
 * cornercad_custom_requests`). See README.md for the full runbook.
 *
 * Spec:  docs/superpowers/specs/2026-07-20-login-gated-custom-request-design.md
 * Plan:  docs/superpowers/plans/2026-07-21-login-gated-custom-request.md
 */
class Controller extends Package
{
    protected $pkgHandle = 'cornercad_custom_requests';
    protected $appVersionRequired = '9.0.0';
    protected $pkgVersion = '1.0.0';

    protected $pkgAutoloaderRegistries = [
        'src/CustomRequest' => '\Concrete\Package\CornercadCustomRequests\Src\CustomRequest',
    ];

    public function getPackageName()
    {
        return t('CornerCAD Custom Requests');
    }

    public function getPackageDescription()
    {
        return t('Login-gated custom-work request intake with status tracking, shared across both sites.');
    }

    public function install()
    {
        $pkg = parent::install();
        (new Installer($this->app))->run($pkg);

        return $pkg;
    }

    /**
     * Idempotent: Installer existence-checks the group, Express object, and
     * single-pages, so re-running upgrade() will not duplicate them.
     */
    public function upgrade()
    {
        parent::upgrade();
        (new Installer($this->app))->run($this->getPackageEntity());
    }
}

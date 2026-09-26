# Login-Gated Custom-Request Flow — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a Concrete CMS package that lets customers submit a custom-work request without an account (fill-first), never loses the submission, and lets them register and later log in to track its status — surfaced on both multisite front doors (cornercad.com + cornercadworks.com) feeding one shared pipeline.

**Architecture:** One installable package `cornercad_custom_requests` creates a `custom_request` Express object, a `Custom Request Customers` user group, and two single-pages (Submit, My Requests). A guest submit writes the Express entry immediately (owner=0), emails Brad, then the entry is *claimed* to the user after register/login via a session-held entry id or an emailed one-time token. Pure logic (status ladder, source resolution, claim tokens, file validation) lives in framework-free `src/` classes with plain-PHP tests runnable on the host; CMS-integration lives in controllers verified by deploy-and-check.

**Tech Stack:** PHP 8.3, Concrete CMS 9.5.2 (Express objects, single-page controllers, Groups, Mail service), Cloudflare Turnstile captcha add-on (installed), Community Store (shared accounts — no code here).

## Global Constraints

- **Target platform:** Concrete CMS **9.5.2**, PHP **8.3** (host). `$appVersionRequired = '9.0.0'`.
- **No REST/API path:** Express objects, groups, single-pages, and attributes are created **in package PHP `install()`** — the OAuth token cannot create these (see `docs/concrete-cms-api-protocol.md` §3). Only the final CTA wiring (Task 11) uses the `concretecms` MCP.
- **No local PHP runtime.** Author locally; **verification runs on the host** (Dashboard/CLI/browser). Mark any line whose exact live API you could not confirm with `// VERIFY ON DEPLOY`, matching the existing `cornercad_catalog_import` convention.
- **Package handle:** `cornercad_custom_requests`. Namespace: `Concrete\Package\CornercadCustomRequests`.
- **Notification recipient:** `brad@cornercad.com`.
- **Owner tracking:** store the owning user id in our own `cr_owner_uid` **number attribute** (0 = unclaimed) — do NOT rely on Express entry-author semantics.
- **File attachments:** handled in the controller (import to File Manager, store comma-separated file IDs in `cr_attachment_fids`), NOT via Express multi-file attributes. Allowed types: jpg, jpeg, png, pdf, step, stp, stl. Max 10 MB/file, max 5 files.
- **Source tagging:** the submit controller reads `?source=individual|business` (default `individual`) — this avoids multisite page-tree resolution entirely.
- **Status ladder (verbatim, customer-visible):** `New`, `Reviewing`, `Quoted (awaiting approval)`, `Approved`, `In design`, `In production`, `Shipped`, `Completed`, `Declined / Not a fit`, `On hold`.
- **Idempotent install:** re-running install must not duplicate the Express object, group, or single-pages (existence-check each), matching `cornercad_setup`'s idempotency stance.
- **Spec:** `docs/superpowers/specs/2026-07-20-login-gated-custom-request-design.md`.

---

## File Structure

```
packages/cornercad_custom_requests/
  controller.php                                   # install/upgrade orchestration
  README.md                                        # deploy + verify runbook
  src/CustomRequest/StatusLadder.php               # pure: canonical status option list
  src/CustomRequest/SourceResolver.php             # pure: map ?source param -> label
  src/CustomRequest/ClaimToken.php                 # pure: generate/verify one-time tokens
  src/CustomRequest/FileValidator.php              # pure: allowed types/sizes
  src/CustomRequest/Installer.php                  # install steps (Express object, group, pages)
  src/CustomRequest/RequestRepository.php          # create/find/claim Express entries
  src/CustomRequest/Notifier.php                   # Brad email + customer claim email
  controllers/single_page/custom_request.php       # Submit controller (form + POST + claim)
  controllers/single_page/my_requests.php          # My Requests controller (list + detail + claim action)
  single_pages/custom_request.php                  # Submit view
  single_pages/custom_request/thanks.php           # post-submit view
  single_pages/my_requests.php                     # My Requests list/detail view
  tests/run.php                                     # plain-PHP test runner (host)
  tests/StatusLadderTest.php
  tests/SourceResolverTest.php
  tests/ClaimTokenTest.php
  tests/FileValidatorTest.php
```

**Testing approach (read once):** `tests/*.php` are plain PHP assertion files with no framework. `tests/run.php` requires each, runs its `run()` function, prints `PASS`/`FAIL`, exits non-zero on any failure. Run on the host (PHP 8.3) with `php packages/cornercad_custom_requests/tests/run.php`. Pure-logic tasks (2, 4, 6, 7) are true test-first. Integration tasks (1, 3, 5, 8, 9, 10, 11) end with a **deploy-and-verify procedure** (Dashboard/browser/`concretecms` MCP), which is their test cycle.

---

## Task 1: Package skeleton + host test runner

**Files:**
- Create: `packages/cornercad_custom_requests/controller.php`
- Create: `packages/cornercad_custom_requests/tests/run.php`
- Create: `packages/cornercad_custom_requests/README.md`

**Interfaces:**
- Produces: installable package handle `cornercad_custom_requests`; `Installer::run(Package $pkg)` (stub, filled in Task 3/5); a host test runner discovering `tests/*Test.php`.

- [ ] **Step 1: Write the package controller (install stub)**

```php
<?php
namespace Concrete\Package\CornercadCustomRequests;

use Concrete\Core\Package\Package;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\Installer;

defined('C5_EXECUTE') or die('Access Denied.');

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
        (new Installer($this->app))->run($pkg); // filled in later tasks
        return $pkg;
    }

    public function upgrade()
    {
        parent::upgrade();
        (new Installer($this->app))->run($this->getPackageEntity()); // idempotent
    }
}
```

- [ ] **Step 2: Create a temporary no-op Installer so install() runs**

```php
<?php
namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

use Concrete\Core\Package\Package;
use Concrete\Core\Application\Application;

defined('C5_EXECUTE') or die('Access Denied.');

class Installer
{
    public function __construct(protected Application $app) {}

    public function run(Package $pkg): void
    {
        // Steps added in Tasks 3 and 5.
    }
}
```

- [ ] **Step 3: Write the host test runner**

```php
<?php
// Run on the host: php packages/cornercad_custom_requests/tests/run.php
// Framework-free. Each *Test.php returns a callable via run(); prints PASS/FAIL.
$dir = __DIR__;
$failures = 0; $total = 0;
foreach (glob($dir . '/*Test.php') as $file) {
    require $file;
    $fn = 'test_' . strtolower(pathinfo($file, PATHINFO_FILENAME)); // e.g. test_statusladdertest
    if (!function_exists($fn)) { echo "NO ENTRYPOINT: $file\n"; $failures++; continue; }
    foreach ($fn() as $name => $ok) {
        $total++;
        echo ($ok ? "PASS" : "FAIL") . "  $name\n";
        if (!$ok) { $failures++; }
    }
}
echo "\n$total checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 4: Write the README runbook**

```markdown
# CornerCAD Custom Requests

Login-gated custom-work intake with status tracking. Guest submits (never lost) →
register/login → claim → track status. One shared pipeline for both sites.

## Deploy
1. Upload this folder to `<site>/packages/cornercad_custom_requests`.
2. Install: Dashboard → Extend Concrete → "CornerCAD Custom Requests"
   (or `./concrete/bin/concrete c5:package:install cornercad_custom_requests`).
3. Configure (Dashboard, one-time): Members → Registration = enabled + auto-approve +
   email verification ON; ensure Turnstile is the active captcha; From-email set.
4. Wire CTAs (Task 11) and confirm the two front doors.

## Verify pure logic (host has PHP 8.3)
    php packages/cornercad_custom_requests/tests/run.php

## Lines marked `// VERIFY ON DEPLOY` were authored without server access —
## sanity-check them against the live 9.5.2 instance on first install.
```

- [ ] **Step 5: Deploy-and-verify**

Upload the folder; install via Dashboard. Expected: package installs with no error and appears under Extend Concrete. Run `php packages/cornercad_custom_requests/tests/run.php` → `0 checks, 0 failed`. Uninstall → clean (no orphan errors).

- [ ] **Step 6: Commit**

```bash
git add packages/cornercad_custom_requests
git commit -m "feat(custom-requests): package skeleton + host test runner"
```

---

## Task 2: StatusLadder (pure, test-first)

**Files:**
- Create: `packages/cornercad_custom_requests/src/CustomRequest/StatusLadder.php`
- Test: `packages/cornercad_custom_requests/tests/StatusLadderTest.php`

**Interfaces:**
- Produces: `StatusLadder::options(): array` (ordered list of status label strings), `StatusLadder::default(): string` (`'New'`), `StatusLadder::isValid(string $s): bool`.
- Consumes: nothing.

- [ ] **Step 1: Write the failing test**

```php
<?php
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\StatusLadder;
require __DIR__ . '/../src/CustomRequest/StatusLadder.php';

function test_statusladdertest(): array {
    $opts = StatusLadder::options();
    return [
        'default is New' => StatusLadder::default() === 'New',
        'first option is New' => ($opts[0] ?? null) === 'New',
        'has 10 statuses' => count($opts) === 10,
        'includes Quoted (awaiting approval)' => in_array('Quoted (awaiting approval)', $opts, true),
        'includes Declined / Not a fit' => in_array('Declined / Not a fit', $opts, true),
        'Completed present' => in_array('Completed', $opts, true),
        'isValid true for In design' => StatusLadder::isValid('In design'),
        'isValid false for bogus' => !StatusLadder::isValid('Nope'),
    ];
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php packages/cornercad_custom_requests/tests/run.php`
Expected: FAIL (class not found / options empty).

- [ ] **Step 3: Implement StatusLadder**

```php
<?php
namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

class StatusLadder
{
    public static function options(): array
    {
        return [
            'New', 'Reviewing', 'Quoted (awaiting approval)', 'Approved',
            'In design', 'In production', 'Shipped', 'Completed',
            'Declined / Not a fit', 'On hold',
        ];
    }
    public static function default(): string { return 'New'; }
    public static function isValid(string $s): bool { return in_array($s, self::options(), true); }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php packages/cornercad_custom_requests/tests/run.php`
Expected: all StatusLadder checks PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/cornercad_custom_requests/src/CustomRequest/StatusLadder.php packages/cornercad_custom_requests/tests/StatusLadderTest.php
git commit -m "feat(custom-requests): canonical status ladder"
```

---

## Task 3: Express object + user group install (integration)

**Files:**
- Modify: `packages/cornercad_custom_requests/src/CustomRequest/Installer.php`

**Interfaces:**
- Consumes: `StatusLadder::options()`, `StatusLadder::default()`.
- Produces: Express object handle `custom_request` with attributes `cr_name, cr_email, cr_description, cr_dimensions, cr_material, cr_timeline, cr_budget, cr_attachment_fids, cr_status (select), cr_source (select), cr_owner_uid (number), cr_claim_token, cr_internal_notes`; group `Custom Request Customers`. Idempotent.

- [ ] **Step 1: Implement Express object + group creation**

```php
<?php
namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

use Concrete\Core\Package\Package;
use Concrete\Core\Application\Application;
use Concrete\Core\Support\Facade\Express;
use Concrete\Core\User\Group\Group;

defined('C5_EXECUTE') or die('Access Denied.');

class Installer
{
    public function __construct(protected Application $app) {}

    public function run(Package $pkg): void
    {
        $this->installGroup($pkg);
        $this->installExpressObject($pkg);
        // Single-pages added in Task 5.
    }

    protected function installGroup(Package $pkg): void
    {
        $existing = Group::getByName('Custom Request Customers');
        if (is_object($existing) && $existing->getGroupID() > 0) { return; }
        Group::add('Custom Request Customers', t('Customers who submitted a custom request'), false, false, $pkg); // VERIFY ON DEPLOY (signature)
    }

    protected function installExpressObject(Package $pkg): void
    {
        $manager = $this->app->make(\Concrete\Core\Express\ObjectManager::class);
        if (is_object($manager->getObjectByHandle('custom_request'))) { return; } // idempotent

        // VERIFY ON DEPLOY: ObjectBuilder API on 9.5.2
        $object = Express::buildObject('custom_request', 'custom_requests', 'Custom Request', $pkg);
        $object->addAttribute('text',     'Name',            'cr_name');
        $object->addAttribute('email',    'Email',           'cr_email');
        $object->addAttribute('textarea', 'Description',     'cr_description');
        $object->addAttribute('text',     'Dimensions',      'cr_dimensions');
        $object->addAttribute('text',     'Material/Process','cr_material');
        $object->addAttribute('text',     'Timeline',        'cr_timeline');
        $object->addAttribute('text',     'Budget',          'cr_budget');
        $object->addAttribute('textarea', 'Attachment File IDs', 'cr_attachment_fids');
        $object->addAttribute('number',   'Owner User ID',   'cr_owner_uid');
        $object->addAttribute('text',     'Claim Token',     'cr_claim_token');
        $object->addAttribute('textarea', 'Internal Notes',  'cr_internal_notes');
        $status = $object->addAttribute('select', 'Status', 'cr_status'); // VERIFY ON DEPLOY: how options attach
        $source = $object->addAttribute('select', 'Source', 'cr_source');
        $object->save();

        // Populate select options post-save via the attribute key controllers.
        $this->setSelectOptions('cr_status', StatusLadder::options());   // VERIFY ON DEPLOY
        $this->setSelectOptions('cr_source', ['Individual', 'Business']);
    }

    protected function setSelectOptions(string $handle, array $labels): void
    {
        // VERIFY ON DEPLOY: sets SelectAttributeType option list for an Express attribute key.
        $ak = $this->app->make(\Concrete\Core\Entity\Express\ObjectManager::class); // placeholder resolve
        $key = \Concrete\Core\Attribute\Key\Key::getByHandle($handle); // express attribute key
        if (!is_object($key)) { return; }
        $ctrl = $key->getController();
        $type = $ctrl->getAttributeKeySettings();
        $list = $type->getOptionList();
        if (count($list) > 0) { return; } // already set
        foreach ($labels as $i => $label) {
            $opt = new \Concrete\Core\Entity\Attribute\Value\Value\SelectValueOption();
            $opt->setSelectAttributeOption(null);
            $opt->setDisplayOrder($i);
            $opt->setValue($label);
            $list->add($opt);
        }
        // persist $type via its repository — VERIFY ON DEPLOY
    }
}
```

> Note to implementer: the select-option wiring above is the least-certain live API. If `setSelectOptions()` proves unreliable on 9.5.2, fall back to: create `cr_status`/`cr_source` as `text` attributes, and constrain values in the Submit controller / Dashboard instead. The customer-visible behaviour is unchanged because status is set by Brad in the Dashboard. Keep `StatusLadder` as the single source either way.

- [ ] **Step 2: Deploy-and-verify**

Reinstall the package. Expected in Dashboard → System & Settings → Express → Data Objects: **Custom Request** with all 13 attributes. Add a test entry by hand; confirm `Status` shows the 10 ladder values (or, if the fallback was used, a free-text field). Confirm Dashboard → Members → Groups shows **Custom Request Customers**. Re-run install → no duplicate object/group.

- [ ] **Step 3: Commit**

```bash
git add packages/cornercad_custom_requests/src/CustomRequest/Installer.php
git commit -m "feat(custom-requests): install Express object + customer group"
```

---

## Task 4: SourceResolver + ClaimToken + FileValidator (pure, test-first)

**Files:**
- Create: `src/CustomRequest/SourceResolver.php`, `src/CustomRequest/ClaimToken.php`, `src/CustomRequest/FileValidator.php`
- Test: `tests/SourceResolverTest.php`, `tests/ClaimTokenTest.php`, `tests/FileValidatorTest.php`

**Interfaces:**
- Produces:
  - `SourceResolver::label(?string $param): string` → `'Individual'` (default) or `'Business'`.
  - `ClaimToken::generate(): string` (URL-safe, ≥32 chars); `ClaimToken::matches(string $stored, string $candidate): bool` (constant-time, non-empty).
  - `FileValidator::allowedExtensions(): array`; `FileValidator::check(string $filename, int $bytes): ?string` → error string or `null` if OK. Max 10 MB.

- [ ] **Step 1: Write failing tests**

```php
<?php
// tests/SourceResolverTest.php
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\SourceResolver;
require __DIR__ . '/../src/CustomRequest/SourceResolver.php';
function test_sourceresolvertest(): array {
    return [
        'business maps' => SourceResolver::label('business') === 'Business',
        'individual maps' => SourceResolver::label('individual') === 'Individual',
        'null defaults individual' => SourceResolver::label(null) === 'Individual',
        'garbage defaults individual' => SourceResolver::label('xyz') === 'Individual',
        'case-insensitive' => SourceResolver::label('BUSINESS') === 'Business',
    ];
}
```

```php
<?php
// tests/ClaimTokenTest.php
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\ClaimToken;
require __DIR__ . '/../src/CustomRequest/ClaimToken.php';
function test_claimtokentest(): array {
    $a = ClaimToken::generate(); $b = ClaimToken::generate();
    return [
        'length >= 32' => strlen($a) >= 32,
        'unique' => $a !== $b,
        'url-safe' => preg_match('/^[A-Za-z0-9_-]+$/', $a) === 1,
        'matches self' => ClaimToken::matches($a, $a),
        'rejects other' => !ClaimToken::matches($a, $b),
        'rejects empty stored' => !ClaimToken::matches('', ''),
    ];
}
```

```php
<?php
// tests/FileValidatorTest.php
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\FileValidator;
require __DIR__ . '/../src/CustomRequest/FileValidator.php';
function test_filevalidatortest(): array {
    $tenMB = 10 * 1024 * 1024;
    return [
        'jpg ok' => FileValidator::check('sketch.jpg', 1000) === null,
        'stl ok' => FileValidator::check('part.STL', 1000) === null,
        'step ok' => FileValidator::check('model.step', 1000) === null,
        'exe rejected' => FileValidator::check('evil.exe', 1000) !== null,
        'no ext rejected' => FileValidator::check('noext', 1000) !== null,
        'oversize rejected' => FileValidator::check('big.pdf', $tenMB + 1) !== null,
        'at limit ok' => FileValidator::check('ok.pdf', $tenMB) === null,
    ];
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php packages/cornercad_custom_requests/tests/run.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement the three classes**

```php
<?php
// src/CustomRequest/SourceResolver.php
namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;
class SourceResolver
{
    public static function label(?string $param): string
    {
        return strtolower((string) $param) === 'business' ? 'Business' : 'Individual';
    }
}
```

```php
<?php
// src/CustomRequest/ClaimToken.php
namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;
class ClaimToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
    public static function matches(string $stored, string $candidate): bool
    {
        if ($stored === '' || $candidate === '') { return false; }
        return hash_equals($stored, $candidate);
    }
}
```

```php
<?php
// src/CustomRequest/FileValidator.php
namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;
class FileValidator
{
    public const MAX_BYTES = 10485760; // 10 MB
    public static function allowedExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'pdf', 'step', 'stp', 'stl'];
    }
    public static function check(string $filename, int $bytes): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, self::allowedExtensions(), true)) {
            return t('File type not allowed: %s', $filename);
        }
        if ($bytes > self::MAX_BYTES) {
            return t('File too large (max 10 MB): %s', $filename);
        }
        return null;
    }
}
```

> `t()` is a Concrete global. For host test runs outside Concrete, prepend a guard in `tests/run.php`: `if (!function_exists('t')) { function t($s, ...$a){ return $a ? vsprintf($s, $a) : $s; } }`. Add this now.

- [ ] **Step 4: Add the `t()` shim to run.php, run tests, verify PASS**

Run: `php packages/cornercad_custom_requests/tests/run.php`
Expected: all SourceResolver/ClaimToken/FileValidator checks PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/cornercad_custom_requests/src packages/cornercad_custom_requests/tests
git commit -m "feat(custom-requests): source resolver, claim token, file validator (tested)"
```

---

## Task 5: Submit single-page — render form (integration)

**Files:**
- Modify: `src/CustomRequest/Installer.php` (register single-pages)
- Create: `controllers/single_page/custom_request.php`
- Create: `single_pages/custom_request.php`, `single_pages/custom_request/thanks.php`

**Interfaces:**
- Consumes: `SourceResolver::label()`, `FileValidator::allowedExtensions()`.
- Produces: single-page at path `/custom-request` (view method renders the form); `/custom-request/thanks`; captcha displayed via active library.

- [ ] **Step 1: Register single-pages in Installer::run()**

```php
// add to Installer::run(), after installExpressObject():
$this->installSinglePages($pkg);
```
```php
protected function installSinglePages(Package $pkg): void
{
    foreach (['/custom-request', '/my-requests'] as $path) {
        $existing = \Concrete\Core\Page\Page::getByPath($path);
        if (is_object($existing) && !$existing->isError()) { continue; }
        \Concrete\Core\Page\Single::add($path, $pkg); // VERIFY ON DEPLOY
    }
}
```

- [ ] **Step 2: Write the Submit controller (GET/view only for now)**

```php
<?php
namespace Concrete\Package\CornercadCustomRequests\Controller\SinglePage;

use Concrete\Core\Page\Controller\PageController;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\SourceResolver;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\FileValidator;

defined('C5_EXECUTE') or die('Access Denied.');

class CustomRequest extends PageController
{
    public function view()
    {
        $this->set('sourceLabel', SourceResolver::label($this->request->query->get('source')));
        $this->set('sourceParam', strtolower((string) $this->request->query->get('source')) === 'business' ? 'business' : 'individual');
        $this->set('allowedExt', FileValidator::allowedExtensions());
        $captcha = $this->app->make('captcha'); // active library = Turnstile // VERIFY ON DEPLOY
        $this->set('captcha', $captcha);
        $u = $this->app->make(\Concrete\Core\User\User::class);
        $this->set('isLoggedIn', $u->isRegistered());
    }
}
```

- [ ] **Step 3: Write the Submit view**

```php
<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>
<div class="ccm-custom-request">
  <h1><?= t('Request a custom project') ?></h1>
  <p><?= t('Send the details and I\'ll tell you if it\'s a fit, what it\'ll cost, and how long it\'ll take.') ?></p>
  <form method="post" enctype="multipart/form-data" action="<?= $view->action('submit') ?>">
    <?php $token = $app->make('token'); ?>
    <input type="hidden" name="source" value="<?= h($sourceParam) ?>">
    <input type="hidden" name="<?= $token::DEFAULT_TOKEN_NAME ?? 'ccm_token' ?>" value="<?= $token->generate('cr_submit') ?>">
    <label><?= t('Your name') ?><input type="text" name="cr_name" required></label>
    <label><?= t('Email') ?><input type="email" name="cr_email" required></label>
    <label><?= t('What do you need?') ?><textarea name="cr_description" required></textarea></label>
    <label><?= t('Rough dimensions / size') ?><input type="text" name="cr_dimensions"></label>
    <label><?= t('Material or process') ?>
      <select name="cr_material">
        <option value="Unsure / other"><?= t('Unsure / other') ?></option>
        <option value="FDM print">FDM print</option>
        <option value="Resin print">Resin print</option>
        <option value="Laser engraving">Laser engraving</option>
      </select>
    </label>
    <label><?= t('Timeline / deadline') ?><input type="text" name="cr_timeline"></label>
    <label><?= t('Budget range') ?>
      <select name="cr_budget">
        <option value="Prefer not to say / other"><?= t('Prefer not to say / other') ?></option>
        <option value="Under $50">Under $50</option>
        <option value="$50–$150">$50–$150</option>
        <option value="$150–$500">$150–$500</option>
        <option value="$500+">$500+</option>
      </select>
    </label>
    <label><?= t('Attach sketches / photos / model files') ?>
      <input type="file" name="cr_files[]" multiple accept="<?= '.' . implode(',.', $allowedExt) ?>">
      <small><?= t('Up to 5 files, 10 MB each: %s', implode(', ', $allowedExt)) ?></small>
    </label>
    <?php if (isset($captcha) && is_object($captcha)) { $captcha->display(); } // VERIFY ON DEPLOY ?>
    <button type="submit"><?= t('Send request') ?></button>
  </form>
</div>
```

- [ ] **Step 4: Write the thanks view**

```php
<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>
<div class="ccm-custom-request-thanks">
  <h1><?= t('Thanks — your request is in.') ?></h1>
  <?php if (!empty($needsAccount)) { ?>
    <p><?= t('Check your email to finish setting up your account and track this request.') ?></p>
  <?php } else { ?>
    <p><?= t('You can track its status any time under %sMy Requests%s.', '<a href="' . $myRequestsUrl . '">', '</a>') ?></p>
  <?php } ?>
</div>
```

- [ ] **Step 5: Deploy-and-verify**

Reinstall. Visit `/custom-request?source=business` → form renders, Turnstile widget shows, "Send request" present. Visit `/custom-request` (no param) → renders (source defaults individual). `/custom-request/thanks` renders. (POST not wired yet — submitting is Task 7.)

- [ ] **Step 6: Commit**

```bash
git add packages/cornercad_custom_requests/controllers packages/cornercad_custom_requests/single_pages packages/cornercad_custom_requests/src/CustomRequest/Installer.php
git commit -m "feat(custom-requests): submit single-page renders form on both front doors"
```

---

## Task 6: RequestRepository — create entry (test-first where pure)

**Files:**
- Create: `src/CustomRequest/RequestRepository.php`
- Test: (logic-level) extend `tests/` only for the pure mapping helper below.

**Interfaces:**
- Consumes: `ObjectManager` (Express), `StatusLadder::default()`, `ClaimToken::generate()`.
- Produces:
  - `RequestRepository::sanitizeMaterial(string $v): string` / `sanitizeBudget(string $v): string` (pure — clamp to known options or pass through) — **tested**.
  - `create(array $fields, int $ownerUid, string $sourceLabel, array $fileIds): array` → `['entryId' => int, 'claimToken' => string]` (integration — verified on deploy).
  - `findById(int $id)`, `findByClaimToken(string $token)`, `claim(int $entryId, int $uid): bool`, `listForUser(int $uid): array` (integration).

- [ ] **Step 1: Write failing test for the pure mapping helpers**

```php
<?php
// tests/RequestRepositoryTest.php  (pure helpers only)
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\RequestRepository;
require __DIR__ . '/../src/CustomRequest/RequestRepository.php';
function test_requestrepositorytest(): array {
    return [
        'known material passes' => RequestRepository::sanitizeMaterial('FDM print') === 'FDM print',
        'unknown material -> Unsure' => RequestRepository::sanitizeMaterial('haxx') === 'Unsure / other',
        'known budget passes' => RequestRepository::sanitizeBudget('$500+') === '$500+',
        'unknown budget -> Prefer not' => RequestRepository::sanitizeBudget('haxx') === 'Prefer not to say / other',
    ];
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php packages/cornercad_custom_requests/tests/run.php` → FAIL.

- [ ] **Step 3: Implement RequestRepository (pure helpers + integration methods)**

```php
<?php
namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

use Concrete\Core\Application\Application;
use Concrete\Core\Express\ObjectManager;

defined('C5_EXECUTE') or die('Access Denied.');

class RequestRepository
{
    public function __construct(protected ?Application $app = null) {}

    public static function sanitizeMaterial(string $v): string
    {
        $ok = ['FDM print', 'Resin print', 'Laser engraving', 'Unsure / other'];
        return in_array($v, $ok, true) ? $v : 'Unsure / other';
    }
    public static function sanitizeBudget(string $v): string
    {
        $ok = ['Under $50', '$50–$150', '$150–$500', '$500+', 'Prefer not to say / other'];
        return in_array($v, $ok, true) ? $v : 'Prefer not to say / other';
    }

    protected function object() // VERIFY ON DEPLOY: ObjectManager API
    {
        return $this->app->make(ObjectManager::class)->getObjectByHandle('custom_request');
    }

    public function create(array $f, int $ownerUid, string $sourceLabel, array $fileIds): array
    {
        $token = ClaimToken::generate();
        $manager = $this->app->make(ObjectManager::class);
        $entry = $manager->getEntryBuilder()->createEntry($this->object()); // VERIFY ON DEPLOY
        $entry->setCrName($f['cr_name'] ?? '');
        $entry->setCrEmail($f['cr_email'] ?? '');
        $entry->setCrDescription($f['cr_description'] ?? '');
        $entry->setCrDimensions($f['cr_dimensions'] ?? '');
        $entry->setCrMaterial(self::sanitizeMaterial($f['cr_material'] ?? ''));
        $entry->setCrTimeline($f['cr_timeline'] ?? '');
        $entry->setCrBudget(self::sanitizeBudget($f['cr_budget'] ?? ''));
        $entry->setCrAttachmentFids(implode(',', array_map('intval', $fileIds)));
        $entry->setCrStatus(StatusLadder::default());
        $entry->setCrSource($sourceLabel);
        $entry->setCrOwnerUid($ownerUid);
        $entry->setCrClaimToken($token);
        $manager->saveEntry($entry); // VERIFY ON DEPLOY: exact setter/save API
        return ['entryId' => (int) $entry->getID(), 'claimToken' => $token];
    }

    public function findById(int $id) { return $this->app->make(ObjectManager::class)->getEntry($id); } // VERIFY ON DEPLOY
    public function findByClaimToken(string $token)
    {
        foreach ($this->listAll() as $e) { if (ClaimToken::matches((string) $e->getCrClaimToken(), $token)) { return $e; } }
        return null;
    }
    public function claim(int $entryId, int $uid): bool
    {
        $e = $this->findById($entryId);
        if (!is_object($e) || (int) $e->getCrOwnerUid() > 0) { return false; }
        $e->setCrOwnerUid($uid);
        $this->app->make(ObjectManager::class)->saveEntry($e); // VERIFY ON DEPLOY
        return true;
    }
    public function listForUser(int $uid): array
    {
        return array_values(array_filter($this->listAll(), fn($e) => (int) $e->getCrOwnerUid() === $uid));
    }
    protected function listAll(): array
    {
        return $this->app->make(ObjectManager::class)->getEntryList($this->object()); // VERIFY ON DEPLOY
    }
}
```

> The `getCr*/setCr*` magic accessors are Express's generated entry methods (attribute handle → camelCase). If they differ on 9.5.2, use `$entry->getAttribute('cr_name')` / `$entry->setAttribute('cr_name', $v)`. Mark and adjust on deploy.

- [ ] **Step 4: Run to verify pure helpers PASS**

Run: `php packages/cornercad_custom_requests/tests/run.php` → RequestRepository helper checks PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/cornercad_custom_requests/src/CustomRequest/RequestRepository.php packages/cornercad_custom_requests/tests/RequestRepositoryTest.php
git commit -m "feat(custom-requests): request repository (create/find/claim/list) + tested input sanitizers"
```

---

## Task 7: Notifier + Submit POST handling (integration)

**Files:**
- Create: `src/CustomRequest/Notifier.php`
- Modify: `controllers/single_page/custom_request.php` (add `submit()` action)

**Interfaces:**
- Consumes: `RequestRepository::create()`, `FileValidator::check()`, `Notifier::notifyBrad()`, `Notifier::sendClaimEmail()`, Concrete `token`, file importer, mail, session.
- Produces: `submit()` action that validates, imports files, creates the entry, notifies, and redirects to `/custom-request/thanks`; stores `crEntryId` in session when guest.

- [ ] **Step 1: Implement Notifier**

```php
<?php
namespace Concrete\Package\CornercadCustomRequests\Src\CustomRequest;

use Concrete\Core\Application\Application;

defined('C5_EXECUTE') or die('Access Denied.');

class Notifier
{
    public const BRAD = 'brad@cornercad.com';
    public function __construct(protected Application $app) {}

    public function notifyBrad(int $entryId, array $f, string $sourceLabel): void
    {
        $mail = $this->app->make('mail'); // VERIFY ON DEPLOY
        $mail->to(self::BRAD);
        $mail->setSubject(t('New custom request (#%s, %s)', $entryId, $sourceLabel));
        $mail->setBody(t("From: %s <%s>\nSource: %s\n\n%s\n\nDashboard → Express → Custom Request → entry %s",
            $f['cr_name'] ?? '', $f['cr_email'] ?? '', $sourceLabel, $f['cr_description'] ?? '', $entryId));
        $mail->sendMail();
    }

    public function sendClaimEmail(string $toEmail, string $toName, int $entryId, string $token): void
    {
        $url = $this->app->make('url/manager')->resolve(['/my-requests', 'claim'])
             . '?e=' . $entryId . '&token=' . rawurlencode($token); // VERIFY ON DEPLOY
        $mail = $this->app->make('mail');
        $mail->to($toEmail, $toName);
        $mail->setSubject(t('Finish your account to track your custom request'));
        $mail->setBody(t("Thanks for your request.\n\nCreate an account (or log in) here to track it:\n%s", (string) $url));
        $mail->sendMail();
    }
}
```

- [ ] **Step 2: Add the `submit()` action to the controller**

```php
// add `use` lines:
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\RequestRepository;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\Notifier;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\SourceResolver;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\FileValidator;

public function submit()
{
    $token = $this->app->make('token');
    if (!$token->validate('cr_submit')) { $this->flash('error', t('Session expired — try again.')); return $this->redirect('/custom-request'); }

    $captcha = $this->app->make('captcha');
    if (is_object($captcha) && !$captcha->check()) { $this->flash('error', t('Captcha failed.')); return $this->redirect('/custom-request'); } // VERIFY ON DEPLOY

    $post = $this->request->request;
    foreach (['cr_name', 'cr_email', 'cr_description'] as $req) {
        if (trim((string) $post->get($req)) === '') { $this->flash('error', t('Please complete the required fields.')); return $this->redirect('/custom-request'); }
    }

    // Files: validate + import (max 5).
    $fileIds = [];
    $files = $this->request->files->get('cr_files') ?: [];
    $files = is_array($files) ? array_slice($files, 0, 5) : [];
    $importer = $this->app->make(\Concrete\Core\File\Import\FileImporter::class); // VERIFY ON DEPLOY
    foreach ($files as $file) {
        if (!$file) { continue; }
        $err = FileValidator::check($file->getClientOriginalName(), (int) $file->getSize());
        if ($err) { $this->flash('error', $err); return $this->redirect('/custom-request'); }
        $imported = $importer->importUploadedFile($file); // VERIFY ON DEPLOY
        if (is_object($imported) && method_exists($imported, 'getFileID')) { $fileIds[] = (int) $imported->getFile()->getFileID(); }
    }

    $fields = [];
    foreach (['cr_name','cr_email','cr_description','cr_dimensions','cr_material','cr_timeline','cr_budget'] as $k) {
        $fields[$k] = trim((string) $post->get($k));
    }
    $sourceLabel = SourceResolver::label($post->get('source'));

    $u = $this->app->make(\Concrete\Core\User\User::class);
    $ownerUid = $u->isRegistered() ? (int) $u->getUserID() : 0;

    $repo = $this->app->make(RequestRepository::class);
    $result = $repo->create($fields, $ownerUid, $sourceLabel, $fileIds);

    $this->app->make(Notifier::class)->notifyBrad($result['entryId'], $fields, $sourceLabel);

    if ($ownerUid === 0) {
        $this->request->getSession()->set('crEntryId', $result['entryId']); // claim after register
        $this->app->make(Notifier::class)->sendClaimEmail($fields['cr_email'], $fields['cr_name'], $result['entryId'], $result['claimToken']);
        $this->set('needsAccount', true);
    }
    return $this->redirect('/custom-request/thanks');
}
```

> `flash()`/`redirect()` are `PageController` conveniences; if unavailable, use `$this->app->make('helper/concrete/ui')->getBlockError` pattern or `Redirect::to()`. Mark on deploy.

- [ ] **Step 3: Deploy-and-verify (guest path)**

Reinstall. Log OUT. Submit `/custom-request?source=business` with a name/email/description + one JPG and one STL. Expected:
1. Redirect to `/custom-request/thanks` showing the "check your email" message.
2. Dashboard → Express → Custom Request: a new entry, `Status=New`, `Source=Business`, `Owner User ID=0`, `cr_attachment_fids` populated, files in File Manager.
3. `brad@cornercad.com` receives the "New custom request" email.
4. The customer email receives a claim link.
Try an `.exe` upload → rejected with an error; try 12 MB file → rejected.

- [ ] **Step 4: Commit**

```bash
git add packages/cornercad_custom_requests/src/CustomRequest/Notifier.php packages/cornercad_custom_requests/controllers/single_page/custom_request.php
git commit -m "feat(custom-requests): submit action — validate, import files, create entry, notify"
```

---

## Task 8: Claim handshake after register/login (integration)

**Files:**
- Create: `controllers/single_page/my_requests.php` (with `claim()` action)
- Modify: `controllers/single_page/custom_request.php` (claim from session on next authenticated view — see Step 2)

**Interfaces:**
- Consumes: `RequestRepository::claim()`, `RequestRepository::findByClaimToken()`, session `crEntryId`, current user, `Custom Request Customers` group.
- Produces: `/my-requests/claim?e=&token=` action; automatic session-claim on first authenticated hit; adds the user to the customer group.

- [ ] **Step 1: Implement the claim action + auto-group in my_requests controller**

```php
<?php
namespace Concrete\Package\CornercadCustomRequests\Controller\SinglePage;

use Concrete\Core\Page\Controller\PageController;
use Concrete\Package\CornercadCustomRequests\Src\CustomRequest\RequestRepository;

defined('C5_EXECUTE') or die('Access Denied.');

class MyRequests extends PageController
{
    public function on_start()
    {
        $u = $this->app->make(\Concrete\Core\User\User::class);
        if (!$u->isRegistered()) {
            $this->redirect('/login', 'forward', $this->request->getUri()); // return here post-login // VERIFY ON DEPLOY signature
        }
    }

    public function claim()
    {
        $u = $this->app->make(\Concrete\Core\User\User::class);
        $uid = (int) $u->getUserID();
        $repo = $this->app->make(RequestRepository::class);

        $entryId = (int) $this->request->query->get('e');
        $token = (string) $this->request->query->get('token');
        $entry = $repo->findByClaimToken($token);
        if (is_object($entry) && (int) $entry->getID() === $entryId) {
            $repo->claim($entryId, $uid);
            $this->addToCustomerGroup($u);
        }
        return $this->redirect('/my-requests');
    }

    protected function addToCustomerGroup(\Concrete\Core\User\User $u): void
    {
        $g = \Concrete\Core\User\Group\Group::getByName('Custom Request Customers');
        if (is_object($g) && $g->getGroupID() > 0 && !$u->inGroup($g)) { $u->enterGroup($g); } // VERIFY ON DEPLOY
    }

    public function view()
    {
        // Filled in Task 9.
    }
}
```

- [ ] **Step 2: Add session-claim to the Submit controller's `view()`**

```php
// append to CustomRequest::view(), so a guest who registers then returns to /custom-request auto-claims:
$u = $this->app->make(\Concrete\Core\User\User::class);
if ($u->isRegistered()) {
    $sessionEntryId = (int) $this->request->getSession()->get('crEntryId');
    if ($sessionEntryId > 0) {
        $this->app->make(RequestRepository::class)->claim($sessionEntryId, (int) $u->getUserID());
        $this->request->getSession()->remove('crEntryId');
    }
}
```

- [ ] **Step 3: Deploy-and-verify (register → claim)**

Reinstall. As a logged-out user, submit a request (creates unclaimed entry, session holds its id). Register a NEW account (Dashboard Members settings must be: registration enabled, auto-approve, Turnstile). After email verification + login, open the claim link from the email. Expected: entry's `Owner User ID` now equals the new user's id; the user is in `Custom Request Customers`. Repeat WITHOUT the email link — instead revisit `/custom-request` while logged in → session-claim sets the owner. Confirm an already-claimed entry is not re-claimable (owner unchanged).

- [ ] **Step 4: Commit**

```bash
git add packages/cornercad_custom_requests/controllers
git commit -m "feat(custom-requests): claim handshake (session + token) and customer-group assignment"
```

---

## Task 9: My Requests list + detail view (integration)

**Files:**
- Modify: `controllers/single_page/my_requests.php` (`view()`)
- Create: `single_pages/my_requests.php`

**Interfaces:**
- Consumes: `RequestRepository::listForUser()`, `StatusLadder`.
- Produces: login-required list of the current user's requests with status; per-entry detail via `?e=`.

- [ ] **Step 1: Implement `view()`**

```php
public function view()
{
    $u = $this->app->make(\Concrete\Core\User\User::class);
    $repo = $this->app->make(RequestRepository::class);
    $entries = $repo->listForUser((int) $u->getUserID());
    $rows = [];
    foreach ($entries as $e) {
        $rows[] = [
            'id' => (int) $e->getID(),
            'submitted' => method_exists($e, 'getDateCreated') ? $e->getDateCreated() : null, // VERIFY ON DEPLOY
            'description' => (string) $e->getCrDescription(),
            'status' => (string) $e->getCrStatus(),
            'source' => (string) $e->getCrSource(),
        ];
    }
    usort($rows, fn($a, $b) => $b['id'] <=> $a['id']);
    $this->set('rows', $rows);
}
```

- [ ] **Step 2: Write the view**

```php
<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>
<div class="ccm-my-requests">
  <h1><?= t('My Requests') ?></h1>
  <?php if (empty($rows)) { ?>
    <p><?= t('You have no custom requests yet.') ?> <a href="/custom-request"><?= t('Start one') ?></a>.</p>
  <?php } else { ?>
    <table class="table">
      <thead><tr><th><?= t('Request') ?></th><th><?= t('Status') ?></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r) { ?>
        <tr>
          <td>#<?= (int) $r['id'] ?> — <?= h(mb_strimwidth($r['description'], 0, 80, '…')) ?></td>
          <td><span class="badge"><?= h($r['status']) ?></span></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  <?php } ?>
</div>
```

- [ ] **Step 3: Deploy-and-verify**

Reinstall. Log in as the customer from Task 8. Visit `/my-requests` → their request(s) listed with `Status=New`. In Dashboard, change that entry's `Status` to `In design`; reload `/my-requests` → status now reads `In design`. Log in as a DIFFERENT user → they do NOT see the first user's requests. Log out → `/my-requests` redirects to login.

- [ ] **Step 4: Commit**

```bash
git add packages/cornercad_custom_requests/controllers/single_page/my_requests.php packages/cornercad_custom_requests/single_pages/my_requests.php
git commit -m "feat(custom-requests): My Requests list with per-user status"
```

---

## Task 10: Logged-in submitter fast path (integration)

**Files:**
- Modify: `controllers/single_page/custom_request.php` (verify no claim email for logged-in users; already handled in Task 7 `submit()` via `$ownerUid`).

**Interfaces:**
- Consumes: existing `submit()`.
- Produces: confirmation that a logged-in submit sets owner immediately, sends NO claim email, and shows the "track under My Requests" thanks variant.

- [ ] **Step 1: Ensure the thanks view gets the right flag + link**

```php
// in submit(), the $ownerUid !== 0 branch: set myRequestsUrl for the thanks view.
if ($ownerUid !== 0) {
    $this->request->getSession()->set('crThanksMyReq', (string) $this->app->make('url/manager')->resolve(['/my-requests']));
}
```
```php
// in CustomRequest::view() for /custom-request/thanks rendering path — OR set directly before redirect.
// Simplest: pass via flash/session and read in a thanks() method:
public function thanks()
{
    $this->set('needsAccount', (bool) $this->request->getSession()->get('crNeedsAccount'));
    $this->set('myRequestsUrl', (string) $this->request->getSession()->get('crThanksMyReq') ?: '/my-requests');
    $this->request->getSession()->remove('crNeedsAccount');
    $this->request->getSession()->remove('crThanksMyReq');
}
```
```php
// and in submit(), replace `$this->set('needsAccount', true)` with a session flag since it's a redirect:
$this->request->getSession()->set('crNeedsAccount', $ownerUid === 0);
```

> Note: single-page sub-views (`/custom-request/thanks`) map to a `thanks()` method on the same controller. Confirm routing on deploy; if the thanks page is a separate single-page, move `thanks()` logic into its own controller.

- [ ] **Step 2: Deploy-and-verify**

Log IN. Submit `/custom-request?source=individual`. Expected: entry created with `Owner User ID` = your uid immediately; **no** claim email sent; thanks page shows the "track under My Requests" variant (not "check your email"); `/my-requests` shows it right away.

- [ ] **Step 3: Commit**

```bash
git add packages/cornercad_custom_requests/controllers/single_page/custom_request.php
git commit -m "feat(custom-requests): logged-in submitter fast path (immediate ownership, no claim email)"
```

---

## Task 11: Wire the two front-door CTAs + Dashboard config (integration, uses `concretecms` MCP)

**Files:**
- Modify: `content/custom-work.md` (record the wired CTA target)
- (Live) CornerCAD Custom Work page CTA + a Works Custom Work page CTA.

**Interfaces:**
- Consumes: the deployed `/custom-request` single-page.
- Produces: CornerCAD CTA → `/custom-request?source=individual`; Works CTA → `/custom-request?source=business`.

- [ ] **Step 1: Confirm Dashboard config (manual, one-time)**

Dashboard → Members → Registration: **enabled**, **auto-approve**, **email verification ON**. Dashboard → confirm **Turnstile** is the active captcha library and keys are set. Dashboard → System & Settings → Email: **From** address/name set (so Brad + claim emails send).

- [ ] **Step 2: Wire the CornerCAD Custom Work CTA (via `concretecms` MCP)**

On the CornerCAD Custom Work page, point the `inquire` CTA button/link at `/custom-request?source=individual` (replace the current `mailto:` from `content/custom-work.md` §CTA). Use `get-page-by-id` (includes `areas.content`) to find the CTA block, then `update-block-in-page-area`; approve the new version via `update-page-version-by-page-id-and-version-id` `is_approved:true`. (These scopes were confirmed grantable this session.)

- [ ] **Step 3: Create/point the Works Custom Work front door**

On cornercadworks.com (site id 2), ensure a business-voiced Custom Work page exists with a CTA to `/custom-request?source=business`. If the page is missing, `add-page` under the Works home (page 422) with `type=page`, add a `content` block with the CTA, and approve. (Per §4 of the spec, the copy differs but the target single-page is shared.)

- [ ] **Step 4: Deploy-and-verify (end-to-end, both sites)**

From cornercad.com Custom Work → click CTA → lands on `/custom-request` with the form; submit as guest → entry `Source=Individual`. From cornercadworks.com Custom Work → CTA → same form; submit → entry `Source=Business`. Both appear in the one Dashboard Express queue, filterable by Source.

- [ ] **Step 5: Record the wiring in content + commit**

Update `content/custom-work.md` §CTA to note the button now targets `/custom-request?source=individual` (not mailto).

```bash
git add content/custom-work.md
git commit -m "docs(custom-work): CTA now targets the custom-request single-page"
```

---

## Task 12: Docs reconciliation + go-live entries

**Files:**
- Modify: `go-live.md`
- Modify: `content/_sales-model.md` (reconcile the stale "No Community Store" note)
- Modify: `packages/cornercad_custom_requests/README.md` (final state)

**Interfaces:**
- Consumes: nothing.
- Produces: updated project docs reflecting the shipped feature and the settled commerce decision.

- [ ] **Step 1: Add go-live items**

Append to `go-live.md`: verify registration auto-approve + Turnstile live keys; confirm Brad-notification email deliverability; confirm claim/verify emails send; (cosmetic) fix the OAuth/login `{h(())}` heading in the Genesis theme; test both front-door CTAs; confirm `blocks:update` scope is granted.

- [ ] **Step 2: Reconcile `_sales-model.md`**

Update the "Checkout & payments (DECIDED)" section: the site now uses **Community Store + Square Payment Method add-on** (not Square Payment Links only). Note the custom-request flow is `inquire`-lane and independent of checkout.

- [ ] **Step 3: Note Phase-2 deferral**

In the README, record that **status-change emails to the customer** (spec §9) are deferred to Phase 2 (Express has no native on-update email; needs a custom event handler).

- [ ] **Step 4: Commit**

```bash
git add go-live.md content/_sales-model.md packages/cornercad_custom_requests/README.md
git commit -m "docs: reconcile commerce decision, add custom-request go-live items"
```

---

## Self-Review — spec coverage

- Spec §1 purpose (gate + status, no lost submission) → Tasks 7 (save-first), 8 (claim), 9 (status view). ✓
- §2 multisite facts / API constraint → Global Constraints + Task 3/5 (install-only) + Task 11 (source param avoids page-tree). ✓
- §3 save-first/claim → Tasks 7, 8. ✓
- §4 components → Tasks 1,3,5,8,9. ✓
- §5 data model (all fields, `cr_owner_uid`, status ladder) → Tasks 2, 3, 6. ✓
- §6 data flow / invariant → Task 7 Step 3 verify. ✓
- §7 registration/approval/Turnstile → Task 11 Step 1; Turnstile display/check Tasks 5/7. ✓
- §8 back-office (Dashboard Express, no custom UI) → Tasks 3/9 verify use Dashboard. ✓
- §9 notifications (Brad + claim; status-email deferred) → Task 7 (Notifier), Task 12 Step 3 (deferral). ✓
- §10 edge cases (abandoned register, logged-in, duplicates, file abuse, cross-site, token reuse) → Tasks 4 (validator/token), 7, 8 (`claim()` guards owner>0), 10. ✓
- §11 testing → pure tests Tasks 2/4/6; deploy-verify each integration task. ✓
- §12 Square futureproofing (email key, no API) → data model stores `cr_email`; no Square calls anywhere. ✓

**Gaps/risks flagged for the implementer:** the select-option install (Task 3) and Express entry accessor API (Task 6) are the least-certain live calls — both carry documented fallbacks. Multisite single-page placement is sidestepped by the `?source` param (Task 11) rather than per-site trees.

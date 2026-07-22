<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>
<div class="ccm-custom-request">
    <h1><?= t('Request a custom project') ?></h1>
    <p><?= t('Send the details and I\'ll tell you if it\'s a fit, what it\'ll cost, and how long it\'ll take.') ?></p>

    <form method="post" enctype="multipart/form-data" action="<?= $view->action('submit') ?>">
        <?php $token = $app->make('token'); ?>
        <?= $token->output('cr_submit') ?>
        <input type="hidden" name="source" value="<?= h($sourceParam) ?>">

        <div class="form-group">
            <label for="cr_name"><?= t('Your name') ?> *</label>
            <input class="form-control" type="text" id="cr_name" name="cr_name" required>
        </div>

        <div class="form-group">
            <label for="cr_email"><?= t('Email') ?> *</label>
            <input class="form-control" type="email" id="cr_email" name="cr_email" required>
        </div>

        <div class="form-group">
            <label for="cr_description"><?= t('What do you need?') ?> *</label>
            <textarea class="form-control" id="cr_description" name="cr_description" rows="5" required></textarea>
        </div>

        <div class="form-group">
            <label for="cr_dimensions"><?= t('Rough dimensions / size') ?></label>
            <input class="form-control" type="text" id="cr_dimensions" name="cr_dimensions">
        </div>

        <div class="form-group">
            <label for="cr_material"><?= t('Material or process') ?></label>
            <select class="form-control" id="cr_material" name="cr_material">
                <option value="Unsure / other"><?= t('Unsure / other') ?></option>
                <option value="FDM print"><?= t('FDM print') ?></option>
                <option value="Resin print"><?= t('Resin print') ?></option>
                <option value="Laser engraving"><?= t('Laser engraving') ?></option>
            </select>
        </div>

        <div class="form-group">
            <label for="cr_timeline"><?= t('Timeline / deadline') ?></label>
            <input class="form-control" type="text" id="cr_timeline" name="cr_timeline">
        </div>

        <div class="form-group">
            <label for="cr_budget"><?= t('Budget range') ?></label>
            <select class="form-control" id="cr_budget" name="cr_budget">
                <option value="Prefer not to say / other"><?= t('Prefer not to say / other') ?></option>
                <option value="Under $50"><?= t('Under $50') ?></option>
                <option value="$50–$150">$50–$150</option>
                <option value="$150–$500">$150–$500</option>
                <option value="$500+">$500+</option>
            </select>
        </div>

        <div class="form-group">
            <label for="cr_files"><?= t('Attach sketches / photos / model files') ?></label>
            <input type="file" id="cr_files" name="cr_files[]" multiple accept="<?= '.' . implode(',.', $allowedExt) ?>">
            <small class="text-muted"><?= t('Up to 5 files, 10 MB each: %s', implode(', ', $allowedExt)) ?></small>
        </div>

        <?php if (isset($captcha) && is_object($captcha) && method_exists($captcha, 'display')) {
            $captcha->display(); // VERIFY ON DEPLOY
        } ?>

        <button class="btn btn-primary" type="submit"><?= t('Send request') ?></button>
    </form>
</div>

<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>
<div class="ccm-custom-request-thanks">
    <h1><?= t('Thanks — your request is in.') ?></h1>
    <?php if (!empty($needsAccount)) { ?>
        <p><?= t('Check your email to finish setting up your account and track this request.') ?></p>
    <?php } else { ?>
        <p><?= t('You can track its status any time under %sMy Requests%s.', '<a href="' . h($myRequestsUrl) . '">', '</a>') ?></p>
    <?php } ?>
</div>

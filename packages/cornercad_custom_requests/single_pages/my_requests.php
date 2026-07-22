<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>
<div class="ccm-my-requests">
    <h1><?= t('My Requests') ?></h1>
    <?php if (empty($rows)) { ?>
        <p>
            <?= t('You have no custom requests yet.') ?>
            <a href="/custom-request"><?= t('Start one') ?></a>.
        </p>
    <?php } else { ?>
        <table class="table">
            <thead>
                <tr>
                    <th><?= t('Request') ?></th>
                    <th><?= t('Status') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) { ?>
                <tr>
                    <td>#<?= (int) $r['id'] ?> — <?= h(mb_strimwidth($r['description'], 0, 80, '…')) ?></td>
                    <td><span class="badge badge-info"><?= h($r['status']) ?></span></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } ?>
</div>

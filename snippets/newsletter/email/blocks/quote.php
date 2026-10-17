<?php

/** @var \Kirby\Cms\Block $block */
/** @var \E9li\Newsletter\Theme $theme */
/** @var string $html */

if ($html === '' && $block->citation()->isEmpty() === true) {
    return;
}

?>
<blockquote style="margin:0 0 12px 0;padding:0 0 0 12px;border-left:3px solid <?= esc($theme->colors['brand'], 'attr') ?>;font-family:<?= esc($theme->font, 'attr') ?>;font-size:16px;line-height:26px;">
  <?= $html ?>
  <?php if ($block->citation()->isNotEmpty() === true): ?>
  <p style="margin:8px 0 0 0;font-size:14px;line-height:20px;color:<?= esc($theme->colors['muted'], 'attr') ?>;">
    <?= esc($block->citation()->value()) ?>
  </p>
  <?php endif ?>
</blockquote>

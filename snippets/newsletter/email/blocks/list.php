<?php

/** @var \Kirby\Cms\Block $block */
/** @var \E9li\Newsletter\Theme $theme */

use E9li\Newsletter\Render\Blocks;

$items = Blocks::listItems($block);

if ($items === []) {
    return;
}

?>
<ul style="margin:0 0 12px 20px;padding:0;font-family:<?= esc($theme->font, 'attr') ?>;font-size:16px;line-height:26px;">
  <?php foreach ($items as $item): ?>
  <li style="margin:0 0 4px 0;"><?= esc($item) ?></li>
  <?php endforeach ?>
</ul>

<?php

/** @var \Kirby\Cms\Block $block */
/** @var \E9li\Newsletter\Theme $theme */
/** @var string $html */

use E9li\Newsletter\Render\Blocks;

$level = Blocks::headingLevel($block);
$size  = ['h1' => 28, 'h2' => 22, 'h3' => 18, 'h4' => 16, 'h5' => 15, 'h6' => 14][$level];

?>
<<?= $level ?> style="margin:0 0 12px 0;font-family:<?= esc($theme->font, 'attr') ?>;font-size:<?= $size ?>px;line-height:1.3;font-weight:700;">
  <?= $html ?>
</<?= $level ?>>

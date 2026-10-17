<?php

/** @var string $html */
/** @var \E9li\Newsletter\Theme $theme */

if ($html === '') {
    return;
}

?>
<div style="margin:0 0 12px 0;font-family:<?= esc($theme->font, 'attr') ?>;font-size:16px;line-height:26px;">
  <?= $html ?>
</div>

<?php

/** @var \Kirby\Cms\Block $block */

use E9li\Newsletter\Render\Blocks;

$height = Blocks::spacerHeight($block);

if ($height <= 0) {
    return;
}

?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
  <tr>
    <td height="<?= $height ?>" style="height:<?= $height ?>px;font-size:0;line-height:0;">&nbsp;</td>
  </tr>
</table>

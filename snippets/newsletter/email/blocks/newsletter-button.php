<?php

/** @var \Kirby\Cms\Block $block */
/** @var \E9li\Newsletter\Theme $theme */

use E9li\Newsletter\Render\Blocks;

$url   = Blocks::buttonUrl($block);
$label = trim($block->label()->value());

if ($url === null || $label === '') {
    return;
}

$font = $theme->font;
$bg   = $theme->colors['button'];
$fg   = $theme->colors['buttonText'];

?>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;margin:0 0 12px 0;">
  <tr>
    <td align="center" bgcolor="<?= esc($bg, 'attr') ?>" style="border-radius:6px;background-color:<?= esc($bg, 'attr') ?>;mso-padding-alt:14px 28px;">
      <a href="<?= esc($url, 'attr') ?>"
         style="display:block;padding:14px 28px;font-family:<?= esc($font, 'attr') ?>;font-size:16px;line-height:20px;font-weight:700;color:<?= esc($fg, 'attr') ?>;text-decoration:none;border-radius:6px;-webkit-text-size-adjust:none;">
        <?= esc($label) ?>
      </a>
    </td>
  </tr>
</table>

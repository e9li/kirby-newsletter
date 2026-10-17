<?php

/** @var \Kirby\Cms\Block $block */

use E9li\Newsletter\Render\Blocks;

$url = Blocks::imageUrl($block);

if ($url === null) {
    return;
}

$alt = $block->alt()->value();

?>
<img src="<?= esc($url, 'attr') ?>" alt="<?= esc($alt, 'attr') ?>" width="536"
     style="display:block;width:100%;max-width:100%;height:auto;border:0;margin:0 0 12px 0;" />

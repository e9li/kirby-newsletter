<?php

/**
 * Campaign HTML shell.
 *
 * Table layout and inline styles, same constraints as the opt-in mail:
 * Outlook's Word engine ignores most CSS on non-table elements, and Gmail
 * strips a `<style>` block in several contexts. The one style block below
 * is only the hide-on-mobile rule, which cannot be expressed inline.
 *
 * Placeholders ({{name}}, {{unsubscribe_url}}, …) are left for send time.
 *
 * @var \E9li\Newsletter\Theme $theme
 * @var string $subject
 * @var string $preheader
 * @var array<int, array<string, mixed>> $rows
 * @var bool $trackOpens
 */

$font  = $theme->font;
$width = $theme->width;
$page  = $theme->colors['page'];
$card  = $theme->colors['card'];
$text  = $theme->colors['text'];
$muted = $theme->colors['muted'];
$link  = $theme->colors['link'];

$align = $theme->logoAlign === 'center' ? 'center' : 'left';

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="<?= esc(kirby()->language()?->code() ?? 'en', 'attr') ?>">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="color-scheme" content="light dark" />
<meta name="supported-color-schemes" content="light dark" />
<title><?= esc($subject) ?></title>
<!--[if mso]>
<style>table,td,div,p,a,h1,h2,h3 { font-family: Arial, Helvetica, sans-serif !important; }</style>
<![endif]-->
<style>
@media only screen and (max-width: 620px) {
  .nl-hide-mobile { display: none !important; max-height: 0 !important; overflow: hidden !important; }
  .nl-stack { display: block !important; width: 100% !important; }
}
</style>
</head>
<body style="margin:0;padding:0;width:100%!important;background-color:<?= esc($page, 'attr') ?>;-webkit-text-size-adjust:100%;">

<?php if ($preheader !== ''): ?>
<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:<?= esc($page, 'attr') ?>;">
  <?= esc($preheader) ?>
</div>
<?php endif ?>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-collapse:collapse;background-color:<?= esc($page, 'attr') ?>;">
  <tr>
    <td align="center" style="padding:24px 12px;">

      <!--[if mso]><table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="<?= $width ?>"><tr><td width="<?= $width ?>"><![endif]-->
      <table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="<?= $width ?>"
             style="width:<?= $width ?>px;max-width:<?= $width ?>px;border-collapse:collapse;background-color:<?= esc($card, 'attr') ?>;">

        <?php if ($theme->logo !== null || $theme->headerHtml !== '' || $theme->headerImage !== null): ?>
        <tr>
          <td align="<?= esc($align, 'attr') ?>" style="padding:28px 32px 8px 32px;font-family:<?= esc($font, 'attr') ?>;color:<?= esc($text, 'attr') ?>;">
            <?php if ($theme->logo !== null): ?>
            <img src="<?= esc($theme->logo, 'attr') ?>" alt="<?= esc($theme->legalName, 'attr') ?>"
                 width="160" style="display:inline-block;width:160px;max-width:60%;height:auto;border:0;" />
            <?php if ($theme->logoDark !== null): ?>
            <!-- Dark-mode clients that honour color-scheme can swap via the second image in supporting clients.
                 Both are raster; the light logo is the one every client shows. -->
            <?php endif ?>
            <?php endif ?>
            <?php if ($theme->headerImage !== null): ?>
            <img src="<?= esc($theme->headerImage, 'attr') ?>" alt="" width="<?= $width - 64 ?>"
                 style="display:block;width:100%;max-width:<?= $width - 64 ?>px;height:auto;border:0;margin-top:12px;" />
            <?php endif ?>
            <?php if ($theme->headerHtml !== ''): ?>
            <p style="margin:12px 0 0 0;font-family:<?= esc($font, 'attr') ?>;font-size:14px;line-height:20px;color:<?= esc($muted, 'attr') ?>;">
              <?= $theme->headerHtml ?>
            </p>
            <?php endif ?>
          </td>
        </tr>
        <?php endif ?>

        <?php foreach ($rows as $row): ?>
        <tr class="<?= ($row['hide'] ?? false) === true ? 'nl-hide-mobile' : '' ?>">
          <td style="padding:0;background-color:<?= esc($row['background'], 'attr') ?>;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="border-collapse:collapse;">
              <tr>
                <?php foreach ($row['columns'] as $column): ?>
                <td class="nl-stack" valign="top" width="<?= (int)$column['width'] ?>%"
                    style="width:<?= (int)$column['width'] ?>%;padding:<?= (int)$row['padding'] ?>px 32px;font-family:<?= esc($font, 'attr') ?>;font-size:16px;line-height:26px;color:<?= esc($row['color'], 'attr') ?>;mso-line-height-rule:exactly;">
                  <?= $column['html'] ?>
                </td>
                <?php endforeach ?>
              </tr>
            </table>
          </td>
        </tr>
        <?php endforeach ?>

        <tr>
          <td style="padding:8px 32px 28px 32px;font-family:<?= esc($font, 'attr') ?>;font-size:12px;line-height:20px;color:<?= esc($muted, 'attr') ?>;">
            <?php if ($theme->footerHtml !== ''): ?>
            <p style="margin:0 0 12px 0;"><?= $theme->footerHtml ?></p>
            <?php endif ?>

            <?php if ($theme->social !== []): ?>
            <p style="margin:0 0 12px 0;">
              <?php foreach ($theme->social as $index => $item): ?>
                <?php if ($index > 0): ?> · <?php endif ?>
                <a href="<?= esc($item['url'], 'attr') ?>" style="color:<?= esc($link, 'attr') ?>;"><?= esc($item['label']) ?></a>
              <?php endforeach ?>
            </p>
            <?php endif ?>

            <p style="margin:0 0 12px 0;">{{list_reason}}</p>

            <?php if ($theme->legalName !== ''): ?>
            <p style="margin:0;"><?= esc($theme->legalName) ?></p>
            <?php endif ?>
            <?php if ($theme->address !== ''): ?>
            <p style="margin:0 0 12px 0;white-space:pre-line;"><?= esc($theme->address) ?></p>
            <?php endif ?>

            <p style="margin:0 0 12px 0;">
              <?php if ($theme->privacyUrl !== null): ?>
              <a href="<?= esc($theme->privacyUrl, 'attr') ?>" style="color:<?= esc($link, 'attr') ?>;"><?= esc(t('e9li.newsletter.form.privacy', 'Privacy policy')) ?></a>
              <?php endif ?>
              <?php if ($theme->privacyUrl !== null && $theme->imprintUrl !== null): ?> · <?php endif ?>
              <?php if ($theme->imprintUrl !== null): ?>
              <a href="<?= esc($theme->imprintUrl, 'attr') ?>" style="color:<?= esc($link, 'attr') ?>;"><?= esc(t('e9li.newsletter.email.imprint', 'Imprint')) ?></a>
              <?php endif ?>
            </p>

            <p style="margin:0 0 4px 0;">
              <a href="{{unsubscribe_url}}" style="color:<?= esc($link, 'attr') ?>;"><?= esc(t('e9li.newsletter.email.unsubscribe', 'Unsubscribe')) ?></a>
              ·
              <a href="{{preferences_url}}" style="color:<?= esc($link, 'attr') ?>;"><?= esc(t('e9li.newsletter.email.preferences', 'Email preferences')) ?></a>
            </p>

            <?php if ($trackOpens === true): ?>
            <p style="margin:12px 0 0 0;">
              <?= esc(t('e9li.newsletter.email.tracking', 'This message records that it was opened.')) ?>
            </p>
            <img src="{{open_url}}" width="1" height="1" alt=""
                 style="display:block;width:1px;height:1px;border:0;" />
            <?php endif ?>
          </td>
        </tr>
      </table>
      <!--[if mso]></td></tr></table><![endif]-->

    </td>
  </tr>
</table>
</body>
</html>

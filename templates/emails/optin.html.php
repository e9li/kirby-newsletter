<?php

/**
 * Double opt-in confirmation — HTML part.
 *
 * Table-based with inline styles throughout, because this is a real email:
 * Outlook's Word engine ignores most CSS on non-table elements, and Gmail
 * strips the <style> block in several contexts. Everything structural here has
 * to survive both.
 *
 * @var \E9li\Newsletter\Model\Subscriber $subscriber
 * @var string $listTitle
 * @var string $url
 * @var int $expires
 */

$greeting = $subscriber->firstName() !== null
    ? t('e9li.newsletter.optin.greeting', 'Hello') . ' ' . $subscriber->firstName()
    : t('e9li.newsletter.optin.greeting', 'Hello');

$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

?><!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="<?= esc(kirby()->language()?->code() ?? 'en', 'attr') ?>">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="color-scheme" content="light dark" />
<meta name="supported-color-schemes" content="light dark" />
<title><?= esc(t('e9li.newsletter.optin.subject', 'Please confirm your subscription')) ?></title>
<!--[if mso]>
<style>table,td,div,p,a { font-family: Arial, Helvetica, sans-serif !important; }</style>
<![endif]-->
</head>
<body style="margin:0;padding:0;width:100%!important;background-color:#f4f4f5;-webkit-text-size-adjust:100%;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-collapse:collapse;background-color:#f4f4f5;">
  <tr>
    <td align="center" style="padding:32px 12px;">

      <!--[if mso]><table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="600"><tr><td width="600"><![endif]-->
      <table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="600"
             style="width:600px;max-width:600px;border-collapse:collapse;background-color:#ffffff;border-radius:6px;">
        <tr>
          <td style="padding:32px;font-family:<?= $font ?>;font-size:16px;line-height:26px;color:#18181b;mso-line-height-rule:exactly;">

            <p style="margin:0 0 16px 0;"><?= esc($greeting) ?></p>

            <p style="margin:0 0 24px 0;">
              <?= esc(tt('e9li.newsletter.optin.intro', 'Please confirm that you would like to receive “{list}”.', [
                  'list' => $listTitle,
              ])) ?>
            </p>

            <?php /* mso-padding-alt gives Outlook the same hit area, since Word
                     ignores padding on <a>. border-collapse:separate keeps the
                     border-radius from being eaten. */ ?>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0"
                   style="border-collapse:separate;margin:0 0 24px 0;">
              <tr>
                <td align="center" bgcolor="#18181b"
                    style="border-radius:6px;background-color:#18181b;mso-padding-alt:14px 28px;">
                  <a href="<?= esc($url, 'attr') ?>"
                     style="display:block;padding:14px 28px;font-family:<?= $font ?>;font-size:16px;line-height:20px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:6px;-webkit-text-size-adjust:none;">
                    <?= esc(t('e9li.newsletter.optin.button', 'Confirm subscription')) ?>
                  </a>
                </td>
              </tr>
            </table>

            <p style="margin:0 0 8px 0;font-size:14px;line-height:22px;color:#71717a;">
              <?= esc(t('e9li.newsletter.optin.fallback', 'If the button does not work, copy this link into your browser:')) ?>
            </p>
            <?php /* word-break so a long token cannot force a 600px-wide
                     horizontal scroll on a phone. */ ?>
            <p style="margin:0 0 24px 0;font-size:14px;line-height:22px;word-break:break-all;">
              <a href="<?= esc($url, 'attr') ?>" style="color:#18181b;"><?= esc($url) ?></a>
            </p>

            <p style="margin:0 0 16px 0;font-size:14px;line-height:22px;color:#71717a;">
              <?= esc(tt('e9li.newsletter.optin.expires', 'This link is valid until {date}.', [
                  'date' => date('d.m.Y', $expires),
              ])) ?>
            </p>

            <p style="margin:0;font-size:14px;line-height:22px;color:#71717a;">
              <?= esc(t('e9li.newsletter.optin.ignore', 'If you did not request this, simply ignore this email — nothing will be sent to you.')) ?>
            </p>

          </td>
        </tr>
      </table>

      <table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" width="600"
             style="width:600px;max-width:600px;border-collapse:collapse;">
        <tr>
          <td style="padding:16px 32px;font-family:<?= $font ?>;font-size:12px;line-height:20px;color:#71717a;text-align:center;">
            <?= esc($site->title()) ?><br />
            <a href="<?= esc($site->url(), 'attr') ?>" style="color:#71717a;"><?= esc($site->url()) ?></a>
          </td>
        </tr>
      </table>
      <!--[if mso]></td></tr></table><![endif]-->

    </td>
  </tr>
</table>

</body>
</html>

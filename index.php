<?php

use E9li\Newsletter\Lists;
use E9li\Newsletter\Newsletter;
use E9li\Newsletter\Routes;
use Kirby\Cms\App;

load([
    'E9li\\Newsletter\\Newsletter'   => 'src/Newsletter.php',
    'E9li\\Newsletter\\Database'     => 'src/Database.php',
    'E9li\\Newsletter\\Migrations'   => 'src/Migrations.php',
    'E9li\\Newsletter\\Secret'       => 'src/Secret.php',
    'E9li\\Newsletter\\Token'        => 'src/Token.php',
    'E9li\\Newsletter\\Log'          => 'src/Log.php',
    'E9li\\Newsletter\\EmailAddress' => 'src/EmailAddress.php',
    'E9li\\Newsletter\\Result'       => 'src/Result.php',
    'E9li\\Newsletter\\Lists'        => 'src/Lists.php',
    'E9li\\Newsletter\\Mailer'       => 'src/Mailer.php',
    'E9li\\Newsletter\\Signup'       => 'src/Signup.php',
    'E9li\\Newsletter\\Routes'       => 'src/Routes.php',
    'E9li\\Newsletter\\Csv'          => 'src/Csv.php',
    'E9li\\Newsletter\\Commands'     => 'src/Commands.php',

    'E9li\\Newsletter\\Panel\\Area'     => 'src/Panel/Area.php',
    'E9li\\Newsletter\\Panel\\Views'    => 'src/Panel/Views.php',
    'E9li\\Newsletter\\Panel\\Requests' => 'src/Panel/Requests.php',
    'E9li\\Newsletter\\Panel\\Dialogs'  => 'src/Panel/Dialogs.php',

    'E9li\\Newsletter\\Model\\Subscriber'   => 'src/Model/Subscriber.php',
    'E9li\\Newsletter\\Model\\Subscription' => 'src/Model/Subscription.php',

    'E9li\\Newsletter\\Repository\\Subscribers'   => 'src/Repository/Subscribers.php',
    'E9li\\Newsletter\\Repository\\Subscriptions' => 'src/Repository/Subscriptions.php',
    'E9li\\Newsletter\\Repository\\Suppressions'  => 'src/Repository/Suppressions.php',
    'E9li\\Newsletter\\Repository\\State'         => 'src/Repository/State.php',

    'E9li\\Newsletter\\Consent' => 'src/Consent.php',
    'E9li\\Newsletter\\Theme'   => 'src/Theme.php',

    'E9li\\Newsletter\\Render\\Blocks'   => 'src/Render/Blocks.php',
    'E9li\\Newsletter\\Render\\Document' => 'src/Render/Document.php',

    'E9li\\Newsletter\\Send\\Message'              => 'src/Send/Message.php',
    'E9li\\Newsletter\\Send\\SendResult'           => 'src/Send/SendResult.php',
    'E9li\\Newsletter\\Send\\Transport'            => 'src/Send/Transport.php',
    'E9li\\Newsletter\\Send\\UnavailableTransport' => 'src/Send/UnavailableTransport.php',
    'E9li\\Newsletter\\Send\\LogTransport'         => 'src/Send/LogTransport.php',
    'E9li\\Newsletter\\Send\\SmtpTransport'        => 'src/Send/SmtpTransport.php',
    'E9li\\Newsletter\\Send\\Preflight'            => 'src/Send/Preflight.php',
    'E9li\\Newsletter\\Send\\Seeder'               => 'src/Send/Seeder.php',
    'E9li\\Newsletter\\Send\\Runner'               => 'src/Send/Runner.php',
], __DIR__);

App::plugin('e9li/kirby-newsletter', [
    'options' => require __DIR__ . '/src/options.php',

    'blueprints' => [
        'pages/newsletter'           => __DIR__ . '/blueprints/pages/newsletter.yml',
        'pages/newsletter-lists'     => __DIR__ . '/blueprints/pages/newsletter-lists.yml',
        'pages/newsletter-list'      => __DIR__ . '/blueprints/pages/newsletter-list.yml',
        'pages/newsletter-campaigns' => __DIR__ . '/blueprints/pages/newsletter-campaigns.yml',
        'pages/newsletter-campaign'  => __DIR__ . '/blueprints/pages/newsletter-campaign.yml',
        'pages/newsletter-templates' => __DIR__ . '/blueprints/pages/newsletter-templates.yml',
        'pages/newsletter-template'  => __DIR__ . '/blueprints/pages/newsletter-template.yml',
        'blocks/newsletter-button'   => __DIR__ . '/blueprints/blocks/newsletter-button.yml',
        'blocks/newsletter-spacer'   => __DIR__ . '/blueprints/blocks/newsletter-spacer.yml',
    ],

    // One entry in the Panel sidebar. Registering it also creates the
    // `access.newsletter` permission automatically, so a role blueprint can
    // gate the whole area.
    'areas' => [
        E9li\Newsletter\Panel\Area::ID => fn () => E9li\Newsletter\Panel\Area::definition(),
    ],

    'snippets' => [
        'newsletter/form'        => __DIR__ . '/snippets/newsletter/form.php',
        'newsletter/layout'      => __DIR__ . '/snippets/newsletter/layout.php',
        'newsletter/subscribe'   => __DIR__ . '/snippets/newsletter/subscribe.php',
        'newsletter/confirm'     => __DIR__ . '/snippets/newsletter/confirm.php',
        'newsletter/unsubscribe' => __DIR__ . '/snippets/newsletter/unsubscribe.php',
        'newsletter/preferences' => __DIR__ . '/snippets/newsletter/preferences.php',

        'newsletter/email/document'      => __DIR__ . '/snippets/newsletter/email/document.php',
        'newsletter/email/document.text' => __DIR__ . '/snippets/newsletter/email/document.text.php',
        'newsletter/email/blocks/heading' => __DIR__ . '/snippets/newsletter/email/blocks/heading.php',
        'newsletter/email/blocks/text'    => __DIR__ . '/snippets/newsletter/email/blocks/text.php',
        'newsletter/email/blocks/image'   => __DIR__ . '/snippets/newsletter/email/blocks/image.php',
        'newsletter/email/blocks/list'    => __DIR__ . '/snippets/newsletter/email/blocks/list.php',
        'newsletter/email/blocks/quote'   => __DIR__ . '/snippets/newsletter/email/blocks/quote.php',
        'newsletter/email/blocks/line'    => __DIR__ . '/snippets/newsletter/email/blocks/line.php',
        'newsletter/email/blocks/newsletter-button' => __DIR__ . '/snippets/newsletter/email/blocks/newsletter-button.php',
        'newsletter/email/blocks/newsletter-spacer' => __DIR__ . '/snippets/newsletter/email/blocks/newsletter-spacer.php',
    ],

    // Email templates resolve as `emails/<name>` for the text part and
    // `emails/<name>.html` for the HTML part (see Kirby\Cms\Email::getTemplate).
    'templates' => [
        'emails/newsletter/optin'      => __DIR__ . '/templates/emails/optin.php',
        'emails/newsletter/optin.html' => __DIR__ . '/templates/emails/optin.html.php',
    ],

    'translations' => Newsletter::translations(),

    'routes' => Routes::all(),

    'hooks' => [
        // Deleting a list page would silently orphan its subscription rows:
        // the people stay in the database with a consent record pointing at a
        // list nobody can name any more.
        'page.delete:before' => function ($page) {
            Lists::guardDeletion($page);
        },
    ],

    'commands' => E9li\Newsletter\Commands::all(),
]);

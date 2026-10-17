<?php

/**
 * Swiss German.
 *
 * Inherits the German strings and overrides the few lines that differ in
 * Switzerland. The German file already spells those words with ss, so this
 * file stays free of the sharp s. Swiss usage prefers a neutral greeting.
 */
$de = require __DIR__ . '/de.php';

return array_merge($de, [
    'e9li.newsletter.form.consent' => 'Ich möchte diesen Newsletter erhalten und kann mich jederzeit wieder abmelden.',
    'e9li.newsletter.optin.greeting' => 'Guten Tag',
]);

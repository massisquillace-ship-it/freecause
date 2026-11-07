<?php

$strings = require __DIR__.'/../en/organizations.php';
$strings['mail'] = [
    'applied_subject' => 'Aanvraag ontvangen: :name',
    'applied_body' => 'We hebben de aanvraag voor :name ontvangen. Een beheerder zal de identiteit van de organisatie en uw bevoegdheid om deze te vertegenwoordigen controleren. U kunt de voortgang volgen in uw beheerpaneel.',
    'admin_subject' => 'Organisatiebeoordeling nodig: :name',
    'admin_body' => 'De aanvraag voor :name ligt ter beoordeling. Open de besloten beheerpagina om het bewijsmateriaal en de verklaring van vertegenwoordiging te bekijken.',
    'verify_subject' => ':name is gecontroleerd',
    'verify_body' => 'De identiteitscontrole voor :name is voltooid. Je openbare profiel is klaar: deel de URL ervan en koppel je campagnes vanuit je beheerpaneel. Verificatie houdt geen goedkeuring in van de beweringen in de campagne.',
    'reject_subject' => 'Update van de applicatie: :name',
    'reject_body' => 'De aanvraag voor :name is niet goedgekeurd. Bekijk hieronder de reden, werk je profiel bij en dien de aanvraag opnieuw in zodra je klaar bent.',
    'suspend_subject' => 'Profiel opgeschort: :name',
    'suspend_body' => 'Het openbare profiel van :name is opgeschort. In je beheerderspaneel wordt de status toegelicht; neem contact op met de helpdesk voor hulp. Dit leidt niet automatisch tot het verwijderen van je verzoekschriften.',
    'restore_subject' => 'Profiel hersteld: :name',
    'restore_body' => 'De opschorting voor :name is na beoordeling opgeheven. Open je profiel of het beheerpaneel om de huidige verificatiestatus te controleren.',
    'donation_subject' => 'Donatielink goedgekeurd: :name',
    'donation_body' => 'De externe donatielink voor :name is goedgekeurd. Supporters kunnen deze link vinden via het geverifieerde profiel. Als de bestemming wordt gewijzigd, is een nieuwe beoordeling vereist.',
    'action' => 'Open FreeCause',
];

return $strings;

<?php

$strings = require __DIR__.'/../en/organizations.php';
$strings['mail'] = [
    'applied_subject' => 'Ansøgning modtaget: :name',
    'applied_body' => 'Vi har modtaget ansøgningen vedrørende :name. En administrator vil gennemgå organisationens identitet og din beføjelse til at repræsentere den. Du kan følge med i forløbet via dit administrationspanel.',
    'admin_subject' => 'Der er behov for en gennemgang af organisationen: :name',
    'admin_body' => 'Ansøgningen vedrørende :name afventer behandling. Åbn den private administrationsside for at gennemgå bevismaterialet og erklæringen om repræsentation.',
    'verify_subject' => ':name er verificeret',
    'verify_body' => 'Identitetskontrollen for :name er afsluttet. Din offentlige profil er klar: Del URL’en, og link dine kampagner fra dit administrationspanel. Verifikationen indebærer ikke, at vi støtter påstandene i kampagnerne.',
    'reject_subject' => 'Programopdatering: :name',
    'reject_body' => 'Ansøgningen vedrørende :name blev ikke godkendt. Se begrundelsen nedenfor, opdater din profil, og indsend ansøgningen igen, når du er klar.',
    'suspend_subject' => 'Profil suspenderet: :name',
    'suspend_body' => 'Den offentlige profil for :name er blevet suspenderet. Statusen forklares i dit administrationspanel; kontakt support for at få hjælp. Dette medfører ikke automatisk, at dine underskriftsindsamlinger slettes.',
    'restore_subject' => 'Profil gendannet: :name',
    'restore_body' => 'Suspensionen af :name er blevet ophævet efter en gennemgang. Åbn din profil eller dit administrationspanel for at se den aktuelle verifikationsstatus.',
    'donation_subject' => 'Link til donation godkendt: :name',
    'donation_body' => 'Det eksterne donationslink til :name er blevet godkendt. Tilhængere kan få adgang til det via den verificerede profil. Hvis destinationen ændres, skal der foretages en ny gennemgang.',
    'action' => 'Åbn FreeCause',
];

return $strings;

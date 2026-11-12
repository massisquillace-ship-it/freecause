<?php

$strings = require __DIR__.'/../en/organizations.php';
$strings['mail'] = [
    'applied_subject' => 'Ansökan mottagen: :name',
    'applied_body' => 'Vi har mottagit ansökan för :name. En administratör kommer att granska organisationens identitet och din behörighet att företräda den. Du kan följa hur ärendet fortskrider i din kontrollpanel.',
    'admin_subject' => 'Översyn av organisationen behövs: :name',
    'admin_body' => 'Ansökan för :name väntar på granskning. Öppna den privata administrationssidan för att granska underlagen och försäkran om företrädarskap.',
    'verify_subject' => ':name har verifierats',
    'verify_body' => 'Identitetsgranskningen för :name är klar. Din offentliga profil är klar: dela dess URL och länka dina kampanjer från din kontrollpanel. Verifieringen innebär inte att kampanjens påståenden godkänns.',
    'reject_subject' => 'Uppdatering av applikationen: :name',
    'reject_body' => 'Ansökan för :name har inte godkänts. Läs igenom skälet nedan, uppdatera din profil och skicka in ansökan på nytt när du är redo.',
    'suspend_subject' => 'Profilen har stängts av: :name',
    'suspend_body' => 'Den offentliga profilen för :name har stängts av. I din administrationspanel förklaras statusen; kontakta supporten om du behöver hjälp. Detta innebär inte att dina petitioner automatiskt tas bort.',
    'restore_subject' => 'Profilen har återställts: :name',
    'restore_body' => 'Avstängningen för :name har hävts efter granskning. Öppna din profil eller kontrollpanelen för att kontrollera den aktuella verifieringsstatusen.',
    'donation_subject' => 'Länk till donation godkänd: :name',
    'donation_body' => 'Den externa donationslänken för :name har godkänts. Supportrar kan nå den via den verifierade profilen. Om mottagaren ändras krävs en ny granskning.',
    'founding_subject' => 'Uppdatering av erkännande av grundandet: :name',
    'founding_body' => 'Grundläggande erkännandet för :name har uppdaterats. Öppna din kontrollpanel för att kontrollera den aktuella märkningen och organisationens status.',
    'owner_reassigned_subject' => 'Tilldelat organisationsägande: :name',
    'owner_reassigned_body' => 'Du är nu ägare till :name på FreeCause. Öppna din kontrollpanel för att se organisationen, dess medlemmar och kampanjer.',
    'admin_deletion_subject' => 'Begäran om borttagning av organisation: :name',
    'admin_deletion_body' => 'Ägaren till :name har begärt att organisationen ska tas bort. Granska begäran och dess granskningshistorik från den privata administrationssidan.',
    'action' => 'Öppna FreeCause',
];

return $strings;

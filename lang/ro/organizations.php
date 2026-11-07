<?php

$strings = require __DIR__.'/../en/organizations.php';
$strings['mail'] = [
    'applied_subject' => 'Cerere primită: :name',
    'applied_body' => 'Am primit cererea pentru :name. Un administrator va verifica identitatea organizației și împuternicirea dumneavoastră de a o reprezenta. Puteți urmări evoluția procesului în panoul de administrare.',
    'admin_subject' => 'Este necesară o revizuire a organizării: :name',
    'admin_body' => 'Cererea pentru :name este în așteptare pentru examinare. Deschideți pagina administrativă privată pentru a examina probele și declarația de reprezentare.',
    'verify_subject' => 'Se verifică :name',
    'verify_body' => 'Verificarea identității pentru :name s-a finalizat. Profilul tău public este gata: distribuie adresa URL a acestuia și conectează-ți campaniile din panoul de administrare. Verificarea nu garantează veridicitatea afirmațiilor din campanii.',
    'reject_subject' => 'Actualizare aplicație: :name',
    'reject_body' => 'Cererea pentru :name nu a fost aprobată. Verifică motivul prezentat mai jos, actualizează-ți profilul și trimite din nou cererea în mod explicit când ești pregătit.',
    'suspend_subject' => 'Profil suspendat: :name',
    'suspend_body' => 'Profilul public al utilizatorului :name a fost suspendat. Panoul de administrare oferă explicații cu privire la această situație; pentru asistență, contactați serviciul de asistență. Această măsură nu duce la ștergerea automată a petițiilor dumneavoastră.',
    'restore_subject' => 'Profilul a fost restabilit: :name',
    'restore_body' => 'Suspendarea contului :name a fost ridicată în urma unei reevaluări. Accesați profilul dvs. sau panoul de administrare pentru a verifica starea actuală a verificării.',
    'donation_subject' => 'Linkul pentru donații a fost aprobat: :name',
    'donation_body' => 'Linkul extern pentru donații destinat lui :name a fost aprobat. Susținătorii îl pot accesa din profilul verificat. Schimbarea destinației va necesita o nouă verificare.',
    'action' => 'Deschide FreeCause',
];

return $strings;

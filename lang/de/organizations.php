<?php

$strings = require __DIR__.'/../en/organizations.php';
$strings['mail'] = [
    'applied_subject' => 'Bewerbung eingegangen: :name',
    'applied_body' => 'Wir haben den Antrag für :name erhalten. Ein Administrator wird die Identität der Organisation sowie Ihre Befugnis, diese zu vertreten, prüfen. Sie können den Bearbeitungsstatus in Ihrem Verwaltungsbereich verfolgen.',
    'admin_subject' => 'Überprüfung der Organisation erforderlich: :name',
    'admin_body' => 'Der Antrag für :name wartet auf Prüfung. Öffnen Sie die private Verwaltungsseite, um die Nachweise und die Erklärung des Vertreters einzusehen.',
    'verify_subject' => ':name wurde überprüft',
    'verify_body' => 'Die Identitätsprüfung für :name ist abgeschlossen. Ihr öffentliches Profil ist fertig: Teilen Sie die URL und verknüpfen Sie Ihre Kampagnen über Ihr Verwaltungsportal. Die Verifizierung bedeutet keine Bestätigung der Aussagen in den Kampagnen.',
    'reject_subject' => 'Anwendungsupdate: :name',
    'reject_body' => 'Der Antrag für :name wurde nicht genehmigt. Bitte überprüfen Sie den unten angegebenen Grund, aktualisieren Sie Ihr Profil und reichen Sie den Antrag erneut ein, sobald Sie bereit sind.',
    'suspend_subject' => 'Profil gesperrt: :name',
    'suspend_body' => 'Das öffentliche Profil von :name wurde gesperrt. In Ihrem Verwaltungsbereich finden Sie Erläuterungen zum Status; wenden Sie sich an den Support, wenn Sie Hilfe benötigen. Ihre Petitionen werden dadurch nicht automatisch gelöscht.',
    'restore_subject' => 'Profil wiederhergestellt: :name',
    'restore_body' => 'Die Sperre für :name wurde nach einer Überprüfung aufgehoben. Öffnen Sie Ihr Profil oder Ihr Verwaltungsportal, um den aktuellen Verifizierungsstatus zu überprüfen.',
    'donation_subject' => 'Spendenlink genehmigt: :name',
    'donation_body' => 'Der externe Spendenlink für :name wurde genehmigt. Unterstützer können über das verifizierte Profil darauf zugreifen. Eine Änderung des Spendenziels erfordert eine erneute Prüfung.',
    'action' => 'Open FreeCause öffnen',
];

return $strings;

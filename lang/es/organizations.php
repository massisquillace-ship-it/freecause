<?php

$strings = require __DIR__.'/../en/organizations.php';
$strings['mail'] = [
    'applied_subject' => 'Solicitud recibida: :name',
    'applied_body' => 'Hemos recibido la solicitud correspondiente a :name. Un administrador comprobará la identidad de la organización y tu autoridad para representarla. Puedes seguir el estado de la solicitud en tu panel de administración.',
    'admin_subject' => 'Es necesario revisar la organización: :name',
    'admin_body' => 'La solicitud de :name está pendiente de revisión. Abre la página administrativa privada para revisar las pruebas y la declaración de representación.',
    'verify_subject' => 'Se comprueba :name',
    'verify_body' => 'Se ha completado la verificación de identidad de :name. Tu perfil público ya está listo: comparte su URL y vincula tus campañas desde tu panel de gestión. La verificación no avala las afirmaciones de las campañas.',
    'reject_subject' => 'Actualización de la aplicación: :name',
    'reject_body' => 'La solicitud para :name no ha sido aprobada. Consulta el motivo a continuación, actualiza tu perfil y vuelve a enviarla expresamente cuando estés listo.',
    'suspend_subject' => 'Perfil suspendido: :name',
    'suspend_body' => 'Se ha suspendido el perfil público de :name. En tu panel de administración se explica el estado; ponte en contacto con el servicio de asistencia si necesitas ayuda. Esto no elimina automáticamente tus peticiones.',
    'restore_subject' => 'Perfil restaurado: :name',
    'restore_body' => 'La suspensión de :name se ha levantado tras su revisión. Abre tu perfil o el panel de administración para comprobar su estado de verificación actual.',
    'donation_subject' => 'Enlace de donación aprobado: :name',
    'donation_body' => 'Se ha aprobado el enlace externo de donaciones para :name. Los seguidores pueden acceder a él desde el perfil verificado. Para cambiar el destino, será necesario someterlo a una nueva revisión.',
    'action' => 'Abrir FreeCause',
];

return $strings;

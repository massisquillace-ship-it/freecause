<?php

$legal = json_decode(file_get_contents(resource_path('legal/organizations/2026-11-08.json')), true, 512, JSON_THROW_ON_ERROR);

return [
    // Applications, optional consent capture and notification delivery are independent switches.
    'public_launch' => env('ORGANIZATIONS_PUBLIC_LAUNCH', false),
    'min_directory_count' => (int) env('ORGANIZATIONS_MIN_DIRECTORY_COUNT', 20),
    'consent_enabled' => env('ORGANIZATIONS_CONSENT_ENABLED', false),
    'applications_open' => env('ORGANIZATIONS_APPLICATIONS_OPEN', false),
    // Keep the accepted version coupled to its immutable, tracked document.
    'terms_version' => $legal['version'],
    'terms' => $legal['documents'],
    'email_delivery_paused' => env('ORGANIZATIONS_EMAIL_DELIVERY_PAUSED', false),
    'delivery_notices' => $legal['delivery_notices'],
    'notifications_enabled' => env('ORGANIZATIONS_NOTIFICATIONS_ENABLED', false),
    'admin_email' => env('ORGANIZATIONS_ADMIN_EMAIL'),
    'admin_locale' => env('ORGANIZATIONS_ADMIN_LOCALE', 'en'),
    'support_email' => env('ORGANIZATIONS_SUPPORT_EMAIL'),
    'locales' => ['en', 'fr', 'it'],
];

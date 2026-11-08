<?php

return [
    // Public applications stay closed until approved terms and phase-three delivery exist.
    // Enable only after the owner approves the retained consent/privacy texts.
    'consent_enabled' => env('ORGANIZATIONS_CONSENT_ENABLED', false),
    'applications_open' => env('ORGANIZATIONS_APPLICATIONS_OPEN', false),
    'terms_version' => env('ORGANIZATIONS_TERMS_VERSION', ''),
    'terms' => [],
    'notifications_enabled' => env('ORGANIZATIONS_NOTIFICATIONS_ENABLED', false),
    'admin_email' => env('ORGANIZATIONS_ADMIN_EMAIL'),
    'admin_locale' => env('ORGANIZATIONS_ADMIN_LOCALE', 'en'),
    'support_email' => env('ORGANIZATIONS_SUPPORT_EMAIL'),
    'locales' => ['en', 'fr', 'it'],
];

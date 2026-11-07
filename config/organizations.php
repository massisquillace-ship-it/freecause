<?php

return [
    // Public applications stay closed until approved terms and phase-three delivery exist.
    // Enable only after the owner approves the retained consent/privacy texts.
    'consent_enabled' => env('ORGANIZATIONS_CONSENT_ENABLED', false),
    'applications_open' => env('ORGANIZATIONS_APPLICATIONS_OPEN', false),
    'terms_version' => env('ORGANIZATIONS_TERMS_VERSION', ''),
    'terms' => [],
    'support_email' => env('ORGANIZATIONS_SUPPORT_EMAIL'),
];

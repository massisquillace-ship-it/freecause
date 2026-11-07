<?php

return [
    // Public applications stay closed until approved terms and phase-three delivery exist.
    'applications_open' => env('ORGANIZATIONS_APPLICATIONS_OPEN', false),
    'terms_version' => env('ORGANIZATIONS_TERMS_VERSION', ''),
    'terms' => [],
    'support_email' => env('ORGANIZATIONS_SUPPORT_EMAIL'),
];

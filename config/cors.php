<?php

return [

    // The embed script and chat widget call /api from the client websites' own domains
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Browsers cache the preflight (Chrome up to 2h), so widget POSTs don't cost two requests each
    'max_age' => 7200,

    'supports_credentials' => false,

];

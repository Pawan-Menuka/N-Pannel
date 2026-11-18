<?php

return [
    'admin_email' => env('SEED_ADMIN_EMAIL', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true) ? 'admin@npannel.test' : null),
    'admin_password' => env('SEED_ADMIN_PASSWORD'),
    'sample_catalog' => (bool) env('SEED_SAMPLE_CATALOG', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
];

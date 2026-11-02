<?php

return [
    // Allow plain-HTTP calls from Core\Http\Client to exactly localhost / 127.0.0.1 / ::1 outside
    // APP_ENV=local|testing (e.g. a local mock server in staging). Never enable for public hosts.
    'allow_insecure_local' => (bool) env('HTTP_ALLOW_INSECURE_LOCAL', false),
];

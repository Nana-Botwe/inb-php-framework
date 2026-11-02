<?php

return [
    // Days to keep storage/logs/*.log ("php console log:prune", scheduled daily).
    'retention_days' => (int) env('LOG_RETENTION_DAYS', 30),

    // mail.log / sms.log contain reset and verification links: keep them only briefly.
    'sensitive_retention_days' => (int) env('LOG_SENSITIVE_RETENTION_DAYS', 3),

    // Email an alert when a server error (500) happens. At most one alert per error per 15 minutes.
    'alert_email' => env('LOG_ALERT_EMAIL', ''),
    'alert_throttle_minutes' => 15,
];

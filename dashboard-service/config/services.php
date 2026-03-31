<?php

return [
    'experience' => [
        'url' => env('EXPERIENCE_SERVICE_URL', 'http://localhost:8002'),
    ],
    'enrolment' => [
        'url' => env('ENROLMENT_SERVICE_URL', 'http://localhost:8003'),
    ],
    'user' => [
        'url' => env('USER_SERVICE_URL', 'http://localhost:8080'),
    ],
    'course' => [
        'url' => env('COURSE_SERVICE_URL', 'http://localhost:8004'),
    ],
    'credential' => [
        'url' => env('CREDENTIAL_SERVICE_URL', 'http://localhost:8005'),
    ],
];

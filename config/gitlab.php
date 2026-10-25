<?php

return [
    'client_id' => env('GITLAB_CLIENT_ID'),
    'client_secret' => env('GITLAB_CLIENT_SECRET'),
    'redirect' => env('GITLAB_REDIRECT_URI', '/auth/gitlab/callback'),

    // GitLab.com, or the address of a self-hosted GitLab.
    'host' => rtrim(env('GITLAB_URL', 'https://gitlab.com'), '/'),
];

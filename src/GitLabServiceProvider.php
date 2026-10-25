<?php

namespace Swage\GitLab;

use App\SourceControl\SourceControlProviders;
use Illuminate\Support\ServiceProvider;

class GitLabServiceProvider extends ServiceProvider
{
    /**
     * Read the GitLab settings into services.gitlab, where Socialite looks for them.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gitlab.php', 'services.gitlab');
    }

    public function boot(): void
    {
        $this->app->make(SourceControlProviders::class)->register(GitLabProvider::class);
    }
}

<?php

use App\Jobs\DeploySite;
use App\Models\Site;
use App\Models\SourceControlConnection;
use App\Models\User;
use App\SourceControl\HasSettings;
use App\SourceControl\InvalidWebhookException;
use App\SourceControl\ProviderSettings;
use App\SourceControl\SourceControlException;
use App\SourceControl\SourceControlProviders;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Swage\GitLab\GitLabClient;
use Swage\GitLab\GitLabProvider;
use Swage\GitLab\GitLabServiceProvider;

beforeEach(function () {
    $this->app->register(GitLabServiceProvider::class, force: true);

    config([
        'services.gitlab.client_id' => 'client',
        'services.gitlab.client_secret' => 'secret',
        'services.gitlab.host' => 'https://git.example.com',
    ]);
});

function gitlabProjects(): void
{
    Http::fake([
        'git.example.com/api/v4/user' => Http::response(['username' => 'jane']),
        'git.example.com/api/v4/projects?*' => fn (ClientRequest $request) => (int) $request['page'] === 1
            ? Http::response([['path_with_namespace' => 'platform/backend/api'], ['path_with_namespace' => 'jane/blog']], 200, ['X-Next-Page' => '2'])
            : Http::response([['path_with_namespace' => 'acme/site']], 200, ['X-Next-Page' => '']),
        'git.example.com/api/v4/projects/platform%2Fbackend%2Fapi' => Http::response([
            'default_branch' => 'develop',
            'ssh_url_to_repo' => 'ssh://git@git.example.com:2222/platform/backend/api.git',
        ]),
        'git.example.com/api/v4/projects/platform%2Fbackend%2Fapi/deploy_keys' => Http::response(['id' => 31], 201),
        'git.example.com/api/v4/projects/platform%2Fbackend%2Fapi/hooks' => Http::response(['id' => 77], 201),
        'git.example.com/api/v4/*' => Http::response([], 204),
    ]);
}

test('the plugin registers gitlab as a provider, configured from its own settings', function () {
    $provider = app(SourceControlProviders::class)->get('gitlab');

    expect($provider)->toBeInstanceOf(GitLabProvider::class)
        ->and($provider->isConfigured())->toBeTrue()
        ->and(config('services.gitlab.redirect'))->toBe('/auth/gitlab/callback');

    config(['services.gitlab.client_id' => null]);

    expect($provider->isConfigured())->toBeFalse()
        ->and($provider->setupInstructions())->toContain(route('source-control.callback', 'gitlab'));
});

test('owners are the user then every group holding a project they maintain, subgroups included', function () {
    gitlabProjects();
    $client = new GitLabClient('https://git.example.com', 'token');

    expect($client->owners())->toBe(['jane', 'acme', 'platform/backend'])
        ->and($client->repositories('platform/backend'))->toBe(['platform/backend/api'])
        ->and($client->repositories('jane'))->toBe(['jane/blog']);

    Http::assertSent(fn (ClientRequest $request) => str_contains($request->url(), '/projects?') && $request['min_access_level'] === 40);
    Http::assertSent(fn (ClientRequest $request) => $request->hasHeader('Authorization', 'Bearer token'));
});

test('projects are addressed by their url-encoded path, and the clone url comes from gitlab', function () {
    gitlabProjects();
    $client = new GitLabClient('https://git.example.com', 'token');

    expect($client->defaultBranch('platform/backend/api'))->toBe('develop')
        ->and($client->cloneUrl('platform/backend/api'))->toBe('ssh://git@git.example.com:2222/platform/backend/api.git')
        ->and($client->addDeployKey('platform/backend/api', 'Swage', 'ssh-ed25519 AAAA'))->toBe('31')
        ->and($client->createWebhook('platform/backend/api', 'https://swage.test/webhooks/gitlab/1', 'hook-secret'))->toBe('77');

    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/deploy_keys') && $request['can_push'] === false && $request['key'] === 'ssh-ed25519 AAAA');
    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/hooks') && $request['token'] === 'hook-secret' && $request['push_events'] === true);
});

test('gitlab errors are explained to the user, including validation errors', function () {
    Http::fake([
        'git.example.com/api/v4/projects/acme%2Fapp/deploy_keys' => Http::response(['message' => ['key' => ['has already been taken']]], 400),
    ]);

    expect(fn () => (new GitLabClient('https://git.example.com', 'token'))->addDeployKey('acme/app', 'Swage', 'ssh-ed25519 AAAA'))
        ->toThrow(SourceControlException::class, 'GitLab: key has already been taken');

    Http::assertSentCount(1);
});

test('pushes are verified with the webhook secret token', function () {
    $provider = app(SourceControlProviders::class)->get('gitlab');
    $push = fn (array $headers, array $body) => $provider->push(Request::create('/', 'POST', $body, server: collect($headers)->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all()), 'hook-secret');

    expect(fn () => $push(['X-Gitlab-Token' => 'wrong', 'X-Gitlab-Event' => 'Push Hook'], ['ref' => 'refs/heads/main']))->toThrow(InvalidWebhookException::class)
        ->and($push(['X-Gitlab-Token' => 'hook-secret', 'X-Gitlab-Event' => 'Tag Push Hook'], ['ref' => 'refs/tags/v1']))->toBeNull()
        ->and($push(['X-Gitlab-Token' => 'hook-secret', 'X-Gitlab-Event' => 'Push Hook'], ['ref' => 'refs/heads/main', 'user_name' => 'Jane']))
        ->branch->toBe('main')
        ->pusher->toBe('Jane');
});

test('an expired token is refreshed and the new pair saved before gitlab is called', function () {
    Http::fake([
        'git.example.com/oauth/token' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 7200]),
        'git.example.com/api/v4/user' => Http::response(['username' => 'jane']),
    ]);
    $connection = SourceControlConnection::factory()->create(['provider' => 'gitlab', 'token' => 'old', 'refresh_token' => 'old-refresh', 'expires_at' => now()->subMinute()]);

    $client = app(SourceControlProviders::class)->get('gitlab')->client($connection);
    $client->username();

    expect($connection->fresh())
        ->token->toBe('new-token')
        ->refresh_token->toBe('new-refresh')
        ->expires_at->isFuture()->toBeTrue();

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://git.example.com/oauth/token' && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'old-refresh');
    Http::assertSent(fn (ClientRequest $request) => str_ends_with($request->url(), '/user') && $request->hasHeader('Authorization', 'Bearer new-token'));
});

test('a token that has not expired is used as it is', function () {
    Http::fake(['git.example.com/api/v4/user' => Http::response(['username' => 'jane'])]);
    $connection = SourceControlConnection::factory()->create(['provider' => 'gitlab', 'token' => 'current', 'refresh_token' => 'refresh', 'expires_at' => now()->addHour()]);

    app(SourceControlProviders::class)->get('gitlab')->client($connection)->username();

    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), '/oauth/token'));
});

test('a refused refresh asks the user to reconnect', function () {
    Http::fake(['git.example.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $connection = SourceControlConnection::factory()->create(['provider' => 'gitlab', 'refresh_token' => 'revoked', 'expires_at' => now()->subMinute()]);

    expect(fn () => app(SourceControlProviders::class)->get('gitlab')->client($connection))
        ->toThrow(SourceControlException::class, 'Reconnect GitLab');
});

test('connecting gitlab asks for the api scope on the configured host and stores the refresh token', function () {
    $user = User::factory()->create();

    $location = $this->actingAs($user)->get(route('source-control.redirect', 'gitlab'))->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://git.example.com/oauth/authorize');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query['scope'])->toBe('api');

    $socialiteUser = (new SocialiteUser)->map(['id' => 9, 'nickname' => 'jane'])->setToken('access')->setRefreshToken('refresh')->setExpiresIn(7200);
    Socialite::shouldReceive('buildProvider->setHost->user')->andReturn($socialiteUser);

    $this->actingAs($user)->get(route('source-control.callback', 'gitlab'))->assertRedirect(route('source-control.edit'));

    expect($user->sourceControlConnection('gitlab'))
        ->username->toBe('jane')
        ->token->toBe('access')
        ->refresh_token->toBe('refresh')
        ->expires_at->isFuture()->toBeTrue();
});

test('a site installs from gitlab and deploys on push end to end', function () {
    gitlabProjects();
    Queue::fake([DeploySite::class]);
    fakeShell();
    $site = Site::factory()->create();
    SourceControlConnection::factory()->for($site->server->user)->create(['provider' => 'gitlab', 'expires_at' => now()->addHour()]);

    $page = Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->assertSet('source', 'gitlab')
        ->set('owner', 'platform/backend')
        ->set('repository', 'platform/backend/api')
        ->assertSet('branch', 'develop')
        ->call('installRepository')
        ->assertHasNoErrors();

    $site->refresh();

    expect($site)
        ->source_control->toBe('gitlab')
        ->clone_url->toBe('ssh://git@git.example.com:2222/platform/backend/api.git')
        ->deploy_key_id->toBe('31');

    $page->set('quick_deploy', true);
    $site->refresh();

    expect($site->webhook_id)->toBe('77');

    $this->postJson(route('webhooks.source-control', ['gitlab', $site]), ['ref' => 'refs/heads/develop', 'user_name' => 'Jane'], [
        'X-Gitlab-Token' => $site->webhook_secret,
        'X-Gitlab-Event' => 'Push Hook',
    ])->assertStatus(202);

    Queue::assertPushed(DeploySite::class);
});

test('settings entered on swage\'s config page take over from .env', function () {
    config(['services.gitlab.client_id' => null, 'services.gitlab.client_secret' => null, 'services.gitlab.host' => 'https://gitlab.com']);
    $provider = app(SourceControlProviders::class)->get('gitlab');

    expect($provider)->toBeInstanceOf(HasSettings::class)
        ->and($provider->isConfigured())->toBeFalse()
        ->and(collect($provider->settings())->pluck('key')->all())->toBe(['client_id', 'client_secret', 'url']);

    app(ProviderSettings::class)->put('gitlab', ['client_id' => 'page-app', 'client_secret' => 'page-secret', 'url' => 'https://git.example.org/']);

    $location = $this->actingAs(User::factory()->create())->get(route('source-control.redirect', 'gitlab'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($provider->isConfigured())->toBeTrue()
        ->and($location)->toStartWith('https://git.example.org/oauth/authorize')
        ->and($query['client_id'])->toBe('page-app')
        ->and($query['redirect_uri'])->toBe(route('source-control.callback', 'gitlab'));

    Http::fake(['git.example.org/api/v4/user' => Http::response(['username' => 'jane'])]);
    $connection = SourceControlConnection::factory()->create(['provider' => 'gitlab', 'expires_at' => now()->addHour()]);

    expect($provider->client($connection)->username())->toBe('jane');
});

test('deployments link their commit to it on the configured gitlab', function () {
    expect(app(SourceControlProviders::class)->get('gitlab')->commitUrl('acme/group/app', 'abc1234'))
        ->toBe('https://git.example.com/acme/group/app/-/commit/abc1234');
});

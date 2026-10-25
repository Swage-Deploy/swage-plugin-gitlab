<?php

namespace Swage\GitLab;

use App\Models\SourceControlConnection;
use App\SourceControl\ConnectedAccount;
use App\SourceControl\HasSettings;
use App\SourceControl\InvalidWebhookException;
use App\SourceControl\LinksToCommits;
use App\SourceControl\ProviderSettings;
use App\SourceControl\Push;
use App\SourceControl\Setting;
use App\SourceControl\SourceControlClient;
use App\SourceControl\SourceControlException;
use App\SourceControl\SourceControlProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GitlabProvider as SocialiteGitLab;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class GitLabProvider implements HasSettings, LinksToCommits, SourceControlProvider
{
    public function id(): string
    {
        return 'gitlab';
    }

    public function name(): string
    {
        return 'GitLab';
    }

    public function settings(): array
    {
        return [
            new Setting('client_id', __('Application ID'), __('From the GitLab application.')),
            new Setting('client_secret', __('Secret'), __('From the GitLab application.'), Setting::SECRET),
            new Setting('url', __('GitLab URL'), __('Only for self-hosted GitLab. Leave blank for GitLab.com.'), Setting::URL, required: false, placeholder: 'https://gitlab.com'),
        ];
    }

    public function isConfigured(): bool
    {
        return filled($this->setting('client_id')) && filled($this->setting('client_secret'));
    }

    public function setupInstructions(): string
    {
        return __('Create an application in GitLab under User settings → Applications, with :url as its redirect URI, Confidential checked and the api scope, and enter its ID and secret. For self-hosted GitLab, also enter its URL.', [
            'url' => route('source-control.callback', $this->id()),
        ]);
    }

    /**
     * Ask for the api scope, which adding deploy keys and webhooks needs.
     */
    public function redirect(): RedirectResponse
    {
        return $this->socialite()->setScopes(['api'])->redirect();
    }

    public function connectedAccount(Request $request): ConnectedAccount
    {
        try {
            $user = $this->socialite()->user();
        } catch (Throwable $exception) {
            throw new SourceControlException('GitLab did not authorize the connection.', previous: $exception);
        }

        return new ConnectedAccount(
            id: (string) $user->getId(),
            username: (string) $user->getNickname(),
            token: $user->token,
            refreshToken: $user->refreshToken,
            expiresAt: $user->expiresIn ? now()->addSeconds((int) $user->expiresIn) : null,
        );
    }

    /**
     * GitLab's OAuth tokens expire after two hours, so an expired one is refreshed first. GitLab issues a new refresh
     * token with every refresh, which is saved for next time.
     */
    public function client(SourceControlConnection $connection): SourceControlClient
    {
        if ($connection->tokenHasExpired() && filled($connection->refresh_token)) {
            $this->refresh($connection);
        }

        return new GitLabClient($this->host(), $connection->token);
    }

    /**
     * Deliveries carry the webhook's secret token in a header.
     */
    public function push(Request $request, string $secret): ?Push
    {
        if (! hash_equals($secret, (string) $request->header('X-Gitlab-Token'))) {
            throw new InvalidWebhookException('The delivery does not carry the webhook secret.');
        }

        $ref = (string) $request->input('ref');

        if ($request->header('X-Gitlab-Event') !== 'Push Hook' || ! str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        return new Push(
            branch: substr($ref, strlen('refs/heads/')),
            pusher: $request->input('user_name') ?? $request->input('user_username'),
        );
    }

    /**
     * Exchange the refresh token for a new pair. A connection that no longer exists, such as one captured by a queued
     * job before its owner was deleted, is refreshed in memory only.
     *
     * @throws SourceControlException
     */
    protected function refresh(SourceControlConnection $connection): void
    {
        $response = Http::asForm()->timeout(15)->post($this->host().'/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'redirect_uri' => route('source-control.callback', $this->id()),
        ]);

        if ($response->failed()) {
            throw new SourceControlException('GitLab refused to refresh the connection. Reconnect GitLab from your source control settings.');
        }

        $attributes = [
            'token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 7200)),
        ];

        $connection->exists ? $connection->update($attributes) : $connection->forceFill($attributes);
    }

    /**
     * A setting entered on Swage's config page for GitLab, or else its GITLAB_ variable in .env.
     */
    protected function setting(string $key): ?string
    {
        return app(ProviderSettings::class)->get($this->id(), $key)
            ?? config('services.gitlab.'.($key === 'url' ? 'host' : $key));
    }

    /**
     * The commit's page on GitLab.com or the self-hosted GitLab.
     */
    public function commitUrl(string $repository, string $commit): string
    {
        return "{$this->host()}/{$repository}/-/commit/{$commit}";
    }

    /**
     * GitLab.com, or the address of a self-hosted GitLab.
     */
    protected function host(): string
    {
        return rtrim($this->setting('url') ?: 'https://gitlab.com', '/');
    }

    /**
     * @return SocialiteGitLab
     */
    protected function socialite()
    {
        return Socialite::buildProvider(SocialiteGitLab::class, [
            'client_id' => $this->setting('client_id'),
            'client_secret' => $this->setting('client_secret'),
            'redirect' => route('source-control.callback', $this->id()),
        ])->setHost($this->host());
    }
}

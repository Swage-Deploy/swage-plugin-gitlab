<?php

namespace Swage\GitLab;

use App\SourceControl\SourceControlClient;
use App\SourceControl\SourceControlException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class GitLabClient implements SourceControlClient
{
    /**
     * The most pages of 100 fetched from a listing.
     */
    public const int MAX_PAGES = 10;

    /**
     * The projects the user maintains, once fetched.
     *
     * @var Collection<int, array<string, mixed>>|null
     */
    protected ?Collection $projects = null;

    protected ?string $username = null;

    public function __construct(protected string $host, protected string $token) {}

    public function username(): string
    {
        return $this->username ??= $this->succeeded($this->request()->get('/user'))->json('username');
    }

    /**
     * The user's own namespace, then every group and subgroup holding a project they maintain.
     */
    public function owners(): array
    {
        $groups = $this->projects()
            ->map(fn (array $project): string => $this->ownerOf($project['path_with_namespace']))
            ->unique()
            ->reject(fn (string $owner): bool => $owner === $this->username())
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [$this->username(), ...$groups];
    }

    /**
     * Projects directly in the owner's namespace, most recently active first.
     */
    public function repositories(string $owner): array
    {
        return $this->projects()
            ->filter(fn (array $project): bool => $this->ownerOf($project['path_with_namespace']) === $owner)
            ->pluck('path_with_namespace')
            ->values()
            ->all();
    }

    public function defaultBranch(string $repository): string
    {
        return $this->project($repository)['default_branch'] ?? 'main';
    }

    /**
     * Self-hosted GitLab may serve SSH on another host or port, so the URL comes from GitLab itself.
     */
    public function cloneUrl(string $repository): string
    {
        return $this->project($repository)['ssh_url_to_repo'];
    }

    public function addDeployKey(string $repository, string $title, string $publicKey): string
    {
        return (string) $this->succeeded($this->request(retry: false)->post($this->projectPath($repository).'/deploy_keys', [
            'title' => $title,
            'key' => $publicKey,
            'can_push' => false,
        ]))->json('id');
    }

    public function deleteDeployKey(string $repository, string $keyId): void
    {
        $this->request()->delete($this->projectPath($repository).'/deploy_keys/'.rawurlencode($keyId));
    }

    public function createWebhook(string $repository, string $url, string $secret): string
    {
        return (string) $this->succeeded($this->request(retry: false)->post($this->projectPath($repository).'/hooks', [
            'url' => $url,
            'token' => $secret,
            'push_events' => true,
            'enable_ssl_verification' => true,
        ]))->json('id');
    }

    public function deleteWebhook(string $repository, string $webhookId): void
    {
        $this->request()->delete($this->projectPath($repository).'/hooks/'.rawurlencode($webhookId));
    }

    /**
     * Projects the user maintains, which is the role adding deploy keys and webhooks needs, most recently active first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function projects(): Collection
    {
        if ($this->projects !== null) {
            return $this->projects;
        }

        $projects = collect();

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->succeeded($this->request()->get('/projects', [
                'min_access_level' => 40,
                'simple' => 'true',
                'order_by' => 'last_activity_at',
                'per_page' => 100,
                'page' => $page,
            ]));

            $projects->push(...$response->json());

            if (blank($response->header('X-Next-Page'))) {
                break;
            }
        }

        return $this->projects = $projects;
    }

    /**
     * @return array<string, mixed>
     */
    protected function project(string $repository): array
    {
        return $this->succeeded($this->request()->get($this->projectPath($repository)))->json();
    }

    /**
     * GitLab addresses a project by its full path, URL-encoded as a single segment.
     */
    protected function projectPath(string $repository): string
    {
        return '/projects/'.rawurlencode($repository);
    }

    protected function ownerOf(string $path): string
    {
        return substr($path, 0, (int) strrpos($path, '/'));
    }

    /**
     * Return a successful response, or throw GitLab's explanation of why the request failed.
     *
     * @throws SourceControlException
     */
    protected function succeeded(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $message = $response->json('message') ?? $response->json('error') ?? "HTTP {$response->status()}";

        if (is_array($message)) {
            $message = collect($message)->map(fn ($errors, $field) => is_array($errors) ? "{$field} ".implode(', ', $errors) : $errors)->implode('; ');
        }

        throw new SourceControlException('GitLab: '.$message);
    }

    /**
     * Requests that create something are never retried: one that timed out may still have succeeded.
     */
    protected function request(bool $retry = true): PendingRequest
    {
        return Http::baseUrl($this->host.'/api/v4')
            ->withToken($this->token)
            ->acceptJson()
            ->timeout(15)
            ->retry($retry ? 2 : 1, 200, throw: false);
    }
}

<p align="center">
    <img src="https://raw.githubusercontent.com/Swage-Deploy/swage-core/main/public/favicon.svg" width="72" height="72" alt="Swage">
</p>

<h1 align="center">GitLab for Swage</h1>

<p align="center">
    Install repositories from GitLab.com or a self-hosted GitLab, with deploy keys and push to deploy.
    <br>
    <a href="https://swagedeploy.app"><strong>swagedeploy.app</strong></a> · <a href="https://swagedeploy.app/docs/source-control">Documentation</a>
</p>

---

Adds GitLab as a source control provider: GitLab.com or a self-hosted GitLab. Sites can install repositories from any group or subgroup where you are a Maintainer or Owner, which is the role adding deploy keys and webhooks needs. Swage adds a read-only deploy key to the project and, with quick deploy on, a push webhook.

## Requirements

- Swage `v1.0.0` or later
- A GitLab application, created in the first step below

## Installation

1. **Create an application** in GitLab under **User settings → Applications**, or as a group or instance application:
   - Redirect URI: `https://your-swage-domain/auth/gitlab/callback`
   - Confidential: yes
   - Scopes: `api`

2. **Install the plugin** from the **Available** list on Swage's **Plugins** page, where it is listed as GitLab. Swage installs the newest version, rebuilds itself with it and shows its progress.

   From the Swage host instead:

   ```bash
   sudo -u swage php /home/swage/app/current/artisan swage:plugin:install swage/gitlab
   ```

3. **Configure it** under **Plugins → System → Configure GitLab**: enter the application's ID and secret, and for self-hosted GitLab its URL. The page also shows the redirect URI to give the application.

4. **Connect your account** under **Settings → Source control** in Swage.

### What Swage can reach

GitLab has no narrower scope than `api` that can add deploy keys and webhooks, so a connection's token can do anything the account it belongs to can, in every project it can reach. Tokens expire after two hours and Swage refreshes them, but a token taken from Swage in that time could be used against all of those projects.

To limit that, connect a dedicated GitLab account, such as `swage-deploy`, that is a **Maintainer** of only the projects Swage deploys, rather than your own account. With a self-hosted GitLab, an administrator can also restrict which groups that account belongs to.

GitLab's access tokens expire after two hours. Swage refreshes them with the refresh token GitLab issues, so a connection keeps working until you disconnect it or revoke the application in GitLab.

## Webhooks

GitLab sends Swage's webhook secret in the `X-Gitlab-Token` header, which Swage checks before deploying. If the panel is behind Cloudflare Access, the `webhooks/*` bypass covers GitLab's deliveries too.

For self-hosted GitLab, the instance must be able to reach the Swage domain. GitLab refuses webhooks to private network addresses unless an administrator allows them under **Admin → Settings → Network → Outbound requests**.

## Deployments

A site's deployments link each commit to its page on GitLab.

## Updating

Swage's **Plugins** page shows when a newer version is available. Update from there, or on the Swage host:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:plugin:update swage/gitlab
```

## Development

The plugin implements Swage's source control contract (`App\SourceControl\SourceControlProvider` and `SourceControlClient`), version 1, so it runs inside a Swage install rather than on its own.

`tests/GitLabPluginTest.php` is a Pest test that runs inside a Swage checkout: it uses Swage's models, factories and test helpers. To run it:

1. Add this plugin's `src` directory to the Swage checkout's `autoload-dev` as `Swage\\GitLab\\`.
2. Run `composer dump-autoload`.
3. Copy the test into Swage's `tests/Feature`.
4. Run `php artisan test --filter=GitLabPluginTest`.

## License

Copyright (c) 2026 Node Dog Consulting. Released under the [MIT License](LICENSE).

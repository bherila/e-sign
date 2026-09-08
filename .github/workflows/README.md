# GitHub Actions Deployment

This directory contains the GitHub Actions workflow for deploying the application.

## Setup

This workflow uses a GitHub Environment named `prod`. You will need to create this environment in your repository settings (`Settings > Environments > New environment`).

Once the `prod` environment is created, you need to configure the following secrets as **Environment secrets** within that environment:

1.  `SSH_HOST`: The hostname or IP address of the deployment server.
    -   Example: `your.server.com`

2.  `SSH_USERNAME`: The username for SSH login.
    -   Example: `your_user`

3.  `SSH_PRIVATE_KEY`: The private half of a dedicated Ed25519 deploy key.
    -   Generate: `ssh-keygen -t ed25519 -C "deploy@your-repo" -f deploy_key`
    -   Add the public key (`deploy_key.pub`) to `~/.ssh/authorized_keys` on the server.
    -   Paste the private key (`deploy_key`) as this secret.

4.  `SSH_KNOWN_HOSTS`: The pinned host key entry for the deployment host, in `~/.ssh/known_hosts` format. This enables `StrictHostKeyChecking=yes` so the deploy fails closed instead of trusting whatever host answers (MITM protection).
    -   Generate from a trusted machine: `ssh-keyscan -H your.server.com`
    -   The hostname in this output must exactly match `SSH_HOST`.

## Deployment Target

The workflow rsyncs to `~/<DEPLOY_DIR>/` on the remote server, where `DEPLOY_DIR` is a repository variable naming this site's Laravel root (never a webroot). The webroot symlink to `<DEPLOY_DIR>/public` is one-time provisioning. The deploy job is skipped unless the `DEPLOY_ENABLED` variable is `true`, and `SITE_URL` must be set for the post-deploy health check. This is the cPanel/shared-hosting profile; the Docker profile is documented separately.

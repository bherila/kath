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

The workflow deploys the application to the `~/bwh-php/` directory on the remote server. Make sure this directory exists and the specified SSH user has write permissions to it.

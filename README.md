[![CI](https://github.com/amitaibu/Microsites-Drupal-Starter/actions/workflows/ci.yml/badge.svg)](https://github.com/amitaibu/Microsites-Drupal-Starter/actions/workflows/ci.yml)

# Microsites Demo

Based on top of the [Drupal-starter](https://github.com/gizra/drupal-starter/)

## Local Installation

The only requirement is having [DDEV](https://ddev.readthedocs.io/en/stable/) installed.

    ddev composer install
    cp .ddev/config.local.yaml.example .ddev/config.local.yaml
    ddev restart

Once the Drupal installation is complete, you can use `ddev login` to
log in to the site as admin user using your default browser.

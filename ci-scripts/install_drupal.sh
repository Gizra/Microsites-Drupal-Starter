#!/usr/bin/env bash
set -e

# -------------------------------------------------- #
# Installing Profile.
# -------------------------------------------------- #
echo "Install Drupal."

cp .ddev/config.ci.yaml.example .ddev/config.ci.yaml
ddev restart || ddev logs

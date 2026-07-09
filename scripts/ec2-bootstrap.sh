#!/bin/sh
# Run on the EC2 host (Amazon Linux 2023). Installs Docker, logs in to ECR,
# pulls images, and brings the stack up. Secrets load from Parameter Store via
# each container's prod entrypoint.
# Usage: AWS_DEFAULT_REGION=... ECR_REGISTRY=... sh ec2-bootstrap.sh
set -e

: "${ECR_REGISTRY:?set ECR_REGISTRY}"
: "${AWS_DEFAULT_REGION:?set AWS_DEFAULT_REGION}"

echo "==> Install Docker + compose plugin"
sudo dnf install -y docker
sudo systemctl enable --now docker
sudo usermod -aG docker "$(whoami)" || true
DOCKER_CONFIG=${DOCKER_CONFIG:-/usr/local/lib/docker}
sudo mkdir -p "$DOCKER_CONFIG/cli-plugins"
sudo curl -sSL "https://github.com/docker/compose/releases/latest/download/docker-compose-linux-x86_64" \
    -o "$DOCKER_CONFIG/cli-plugins/docker-compose"
sudo chmod +x "$DOCKER_CONFIG/cli-plugins/docker-compose"

echo "==> ECR login"
aws ecr get-login-password --region "$AWS_DEFAULT_REGION" \
    | sudo docker login --username AWS --password-stdin "$ECR_REGISTRY"

echo "==> Pull + up"
sudo -E docker compose -f docker-compose.prod.yml pull
sudo -E docker compose -f docker-compose.prod.yml up -d

echo "==> Status"
sudo docker compose -f docker-compose.prod.yml ps
sudo docker stats --no-stream

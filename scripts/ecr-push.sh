#!/bin/sh
# Build production images and push them to ECR.
# Usage: AWS_DEFAULT_REGION=ap-southeast-1 ECR_REGISTRY=<acct>.dkr.ecr.<region>.amazonaws.com scripts/ecr-push.sh
set -e

: "${ECR_REGISTRY:?set ECR_REGISTRY to <acct>.dkr.ecr.<region>.amazonaws.com}"
: "${AWS_DEFAULT_REGION:?set AWS_DEFAULT_REGION}"

echo "==> ECR login"
aws ecr get-login-password --region "$AWS_DEFAULT_REGION" \
    | docker login --username AWS --password-stdin "$ECR_REGISTRY"

echo "==> Build app image"
docker build -f docker/app/Dockerfile.prod -t "$ECR_REGISTRY/cortex-lite-app:latest" .

echo "==> Build nginx image"
docker build -f docker/nginx/Dockerfile -t "$ECR_REGISTRY/cortex-lite-nginx:latest" .

echo "==> Image sizes (must be < 500 MB each)"
docker image inspect "$ECR_REGISTRY/cortex-lite-app:latest" "$ECR_REGISTRY/cortex-lite-nginx:latest" \
    --format '{{.RepoTags}} {{.Size}}'

echo "==> Push"
docker push "$ECR_REGISTRY/cortex-lite-app:latest"
docker push "$ECR_REGISTRY/cortex-lite-nginx:latest"
echo "==> Done"

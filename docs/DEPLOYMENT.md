# Cortex Lite AWS Deployment Runbook

Manual AWS Console plus helper scripts. Target: a 48-hour live window, then tear
down. All secrets live in Parameter Store; nothing sensitive is committed.

## 0. Preconditions & Cost Guardrails

1. Check the AWS account creation date. Accounts created on or after
   2025-07-15 have $200 credits / 6 months, shared across all services.
2. Create an AWS Budgets alert: $20, hard threshold, email, before any resource.
3. Never create a NAT Gateway. EC2 goes in a public subnet.
4. Set a 48-hour calendar reminder to tear down.

## 1. Networking & Security Groups

Use the default VPC. Create:

- `cortex-ec2-sg`: inbound 80/443 from `0.0.0.0/0`; SSH 22 from your IP only.
- `cortex-rds-sg`: inbound 3306 from `cortex-ec2-sg` only.

## 2. RDS

Create MySQL on `db.t4g.micro`, in the same VPC, attached to `cortex-rds-sg`,
not publicly accessible. Note the endpoint; it becomes `DB_HOST`.

## 3. IAM Instance Role

Create an IAM role for EC2 with:

- `ssm:GetParametersByPath` and `ssm:GetParameters` on
  `arn:aws:ssm:<region>:<acct>:parameter/cortex-lite/*`
- `kms:Decrypt` on the SSM key
- `AmazonEC2ContainerRegistryReadOnly`

## 4. EC2

Launch `t3.small`, Amazon Linux 2023, public subnet, public IP, `cortex-ec2-sg`,
and the IAM instance role from section 3.

## 5. ECR + Push Images

From your laptop:

1. Create repos `cortex-lite-app` and `cortex-lite-nginx`.
2. Run:

```bash
AWS_DEFAULT_REGION=<r> ECR_REGISTRY=<acct>.dkr.ecr.<r>.amazonaws.com scripts/ecr-push.sh
```

Confirm both images are under 500 MB in the script output.

## 6. Parameter Store

Create SecureString parameters under `/cortex-lite/`:

`APP_KEY`, `APP_URL`, `APP_ENV`, `APP_DEBUG`, `DB_CONNECTION`, `DB_HOST`,
`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `CACHE_STORE`, `SESSION_DRIVER`,
`QUEUE_CONNECTION`, `REDIS_HOST`, `STRIPE_KEY`, `STRIPE_SECRET`,
`STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_PREMIUM`, `STEAM_API_KEY`,
`GEMINI_API_KEY`, `GEMINI_MODEL`, `SANCTUM_STATEFUL_DOMAINS`,
`SESSION_DOMAIN`, `SESSION_SECURE_COOKIE`, `CASHIER_CURRENCY`.

Recommended production values:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=mysql
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis
GEMINI_MODEL=gemini-3.5-flash
CASHIER_CURRENCY=myr
SESSION_SECURE_COOKIE=true
```

## 7. Bring The Stack Up

On EC2, copy `docker-compose.prod.yml` and `scripts/ec2-bootstrap.sh` to the
host, then run:

```bash
AWS_DEFAULT_REGION=<r> ECR_REGISTRY=<acct>.dkr.ecr.<r>.amazonaws.com sh scripts/ec2-bootstrap.sh
```

Verify `docker stats` shows RAM headroom before proceeding.

## 8. Migrate + Seed Live RDS

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --class=DemoAccountSeeder --force
```

## 9. CloudFront

Create a distribution:

- Origin: EC2 public DNS
- Viewer protocol: HTTPS only
- Domain: free `*.cloudfront.net`

Add the Stripe webhook cache behavior:

- Path pattern: `/api/stripe/webhook`
- Cache policy: `CachingDisabled` (TTL 0)
- Origin request policy: forward all headers, cookies, and query strings
- Allowed methods: include POST

This preserves `Stripe-Signature` and the raw body.

Set `APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, and `SESSION_DOMAIN` to the
CloudFront domain in Parameter Store, then restart app, scheduler, and queue.

## 10. Verify Before Declaring Demoable

1. Register in the HTTPS app.
2. Connect Steam via OpenID and import games.
3. Start and end a play session.
4. Run forward recommendation.
5. Run reverse mode.
6. Upgrade via Stripe test card `4242 4242 4242 4242`.
7. Confirm `is_premium` flipped.
8. Trigger or replay a Stripe webhook against `/api/stripe/webhook`; expect 200.

## 11. Screenshots

Capture EC2, RDS, ECR, CloudWatch logs, security-group rules, CloudFront
behaviors, Parameter Store with masked values, running app at CloudFront, a
recommendation result, and a reverse-mode result.

## 12. Teardown

Within 48 hours:

1. Delete CloudFront distribution.
2. Terminate EC2.
3. Delete RDS and skip final snapshot.
4. Delete ECR repos.
5. Delete Parameter Store params.
6. Delete IAM role and security groups.
7. Cancel active Stripe test subscriptions.
8. Verify $0/day:

```bash
aws ce get-cost-and-usage --time-period Start=<yyyy-mm-dd>,End=<yyyy-mm-dd> --granularity DAILY --metrics UnblendedCost
```

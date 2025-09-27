# OneBookNav Documentation Index

## Quick Start
- [README.md](../README.md) - Main project overview and setup
- [PROJECT_SUMMARY.md](../PROJECT_SUMMARY.md) - Project summary and features

## Deployment Guides

### Cloudflare Workers (Recommended)
- [CLOUDFLARE_WORKERS_DEPLOYMENT.md](CLOUDFLARE_WORKERS_DEPLOYMENT.md) - Complete Workers deployment guide
- [workers-console-setup.md](workers-console-setup.md) - Manual console setup
- [setup-pages.md](setup-pages.md) - Cloudflare Pages deployment

### Alternative Deployments
- [DOCKER_DEPLOYMENT.md](DOCKER_DEPLOYMENT.md) - Docker deployment guide
- [PHP_DEPLOYMENT.md](PHP_DEPLOYMENT.md) - Traditional PHP hosting

## Configuration & Troubleshooting
- [ENVIRONMENT_VARIABLES.md](ENVIRONMENT_VARIABLES.md) - Environment configuration
- [TROUBLESHOOTING.md](TROUBLESHOOTING.md) - Common issues and solutions

## Quick Deploy Commands

### Cloudflare Workers
```bash
cd workers/
./deploy-with-setup.sh  # Linux/Mac
# or
deploy-with-setup.bat   # Windows
```

### Docker
```bash
docker-compose up -d
```

For more detailed instructions, see the specific deployment guide for your chosen platform.
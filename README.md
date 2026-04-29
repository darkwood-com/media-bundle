# Darkwood video generator

Symfony CLI tool that turns a YAML video definition into per-scene assets (voice, video), persists run state, and produces a render manifest.

**Operational guide** (environment variables, Replicate setup, benchmark mode, where files land): see [docs/mvp-video.md](docs/mvp-video.md).

```bash
composer install
php bin/console app:video:generate examples/video.yaml
```

Run tests (no live Replicate calls; HTTP is mocked in provider tests):

```bash
./bin/phpunit
```

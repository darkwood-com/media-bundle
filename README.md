# Darkwood trailer generator

Symfony CLI tool that turns a YAML trailer definition into per-scene assets (voice, video), persists run state, and produces a render manifest.

**Operational guide** (environment variables, Replicate setup, benchmark mode, where files land): see [docs/mvp-trailer.md](docs/mvp-trailer.md).

```bash
composer install
php bin/console app:trailer:generate examples/trailer.yaml
```

Run tests (no live Replicate calls; HTTP is mocked in provider tests):

```bash
./bin/phpunit
```

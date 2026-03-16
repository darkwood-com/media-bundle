# MVP Trailer — Quick usage

## Run the example

From the project root:

```bash
php bin/console app:trailer:generate examples/trailer.yaml
```

Output is written under **`var/trailers/<project-id>/`** (the command prints the exact path). Typical layout:

- `var/trailers/<project-id>/input/` — copied definition
- `var/trailers/<project-id>/scenes/<scene-number>-<scene-id>/` — per-scene artifacts
- `var/trailers/<project-id>/render/` — final render (e.g. `trailer-manifest.json`)

Use the **Project ID** from the command output for reruns.

## Rerun a single scene

After a run, rerun one scene by project and scene IDs:

```bash
php bin/console app:trailer:rerun-scene <project-id> <scene-id>
```

Example (with IDs from a previous run):

```bash
php bin/console app:trailer:rerun-scene abc123-def456 tension
```

## Manual verification

1. Run `app:trailer:generate examples/trailer.yaml`.
2. Note the **Project ID** and **Output directory** from the table.
3. Check that `var/trailers/<project-id>/` exists and contains `input/`, `scenes/`, `render/`.
4. Optionally run `app:trailer:rerun-scene <project-id> intro` and confirm the scene is reprocessed.

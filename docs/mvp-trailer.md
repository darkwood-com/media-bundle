## MVP Trailer — Quick usage

### Run the example

From the project root:

```bash
php bin/console app:trailer:generate examples/trailer.yaml
```

Output is written under **`var/trailers/<project-id>/`** (the command prints the exact path). Typical layout:

- `var/trailers/<project-id>/input/` — copied definition
- `var/trailers/<project-id>/scenes/<scene-number>-<scene-id>/` — per-scene artifacts (`voice.mp3`, `video.mp4`, etc.)
- `var/trailers/<project-id>/render/` — final render (e.g. `trailer-manifest.json` or `final.mp4`)
- `var/trailers/<project-id>/project.json` — full project state including per-asset provider metadata

Use the **Project ID** from the command output for reruns.

### Enable the real video provider (Replicate)

- **Required env vars** (set in `.env.local` or your shell, never commit real tokens):
  - `TRAILER_VIDEO_REPLICATE_ENABLED=1`
  - `TRAILER_VIDEO_REPLICATE_API_TOKEN=<your-replicate-api-token>`
  - `TRAILER_VIDEO_REPLICATE_MODEL=<replicate-model-or-version-id>` (optional if you always pass a scene-1 preset or `replicate_model` via code/CLI)
  - Optional tuning:
    - `TRAILER_VIDEO_REPLICATE_POLL_INTERVAL_SECONDS` (default `5`)
    - `TRAILER_VIDEO_REPLICATE_MAX_ATTEMPTS` (default `60`)
- When enabled and correctly configured:
  - **Scene 1** uses the Replicate-backed real video provider.
  - All later scenes keep using the fake provider.
  - If Replicate fails, the system automatically falls back to the fake provider for that scene and records `fallback_from: real` in metadata.

To verify that Replicate was used, run the generate command and look for the **"Scene 1 video provider"** section in the CLI output. It will print the provider name (for example `replicate-video` or `fake-video`), the resolved model and preset when applicable, and tell you where detailed metadata lives.

### Scene 1 benchmark presets (multiple Replicate video models)

For quick A/B runs without changing `.env` each time, pass **scene 1 only** options on the CLI:

```bash
php bin/console app:trailer:generate examples/trailer.yaml --video-preset=hailuo
php bin/console app:trailer:generate examples/trailer.yaml --video-preset=seedance
php bin/console app:trailer:generate examples/trailer.yaml --video-preset=p_video_draft
```

Presets map to:

| Preset            | Replicate model              | Notes                          |
|-------------------|------------------------------|--------------------------------|
| `hailuo`          | `minimax/hailuo-02-fast`     |                                |
| `seedance`        | `bytedance/seedance-1-lite`  |                                |
| `p_video_draft`   | `prunaai/p-video`            | Sets `draft: true` in API input |

Override the model slug while keeping other options:

```bash
php bin/console app:trailer:generate examples/trailer.yaml --video-preset=hailuo --replicate-model=minimax/hailuo-02-fast
```

Programmatically, call `TrailerGenerationOrchestrator::generateFromYaml($path, ['replicate_preset' => 'seedance'])` or pass `replicate_model` / `replicate_input` in that same array (see `VideoGenerationProviderInterface` PHPDoc).

### Inspect outputs and metadata

- **Scene artifacts**: under `var/trailers/<project-id>/scenes/<scene-number>-<scene-id>/`.
  - `video.mp4` is the generated clip for that scene.
  - Provider metadata (including `provider`, `remote_job_id`, `remote_output_url`, `fallback_from`, etc.) is stored on the corresponding video asset inside `project.json`.
- **Project metadata**: open `var/trailers/<project-id>/project.json` to see:
  - All scenes, assets, statuses, and provider metadata.
  - Which provider was used for each asset and whether a fallback occurred.
- **Render outputs**: under `var/trailers/<project-id>/render/` (the CLI prints the exact render path when available).

### Rerun a single scene

After a run, rerun one scene by project and scene IDs:

```bash
php bin/console app:trailer:rerun-scene <project-id> <scene-id>
```

Example (with IDs from a previous run):

```bash
php bin/console app:trailer:rerun-scene abc123-def456 tension
```

### Manual verification

1. Run `app:trailer:generate examples/trailer.yaml`.
2. Note the **Project ID**, **Output directory**, and the **Scene 1 video provider** section from the CLI output.
3. Check that `var/trailers/<project-id>/` exists and contains `input/`, `scenes/`, `render/`, and `project.json`.
4. Open `project.json` and confirm that the first scene's video asset has the expected `provider` (`replicate-video` when Replicate is enabled, `fake-video` otherwise).
5. Optionally run `app:trailer:rerun-scene <project-id> intro` and confirm the scene is reprocessed.

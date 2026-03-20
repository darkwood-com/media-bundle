## MVP Trailer — Quick usage

### Run the example

From the project root:

```bash
php bin/console app:trailer:generate examples/trailer.yaml
```

Output is written under **`var/trailers/<project-id>/`** (the command prints the exact path). Typical layout:

- `var/trailers/<project-id>/input/` — copied definition
- `var/trailers/<project-id>/scenes/<scene-number>-<scene-id>/` — per-scene artifacts (`voice.mp3`, `video.mp4`, and benchmark clips as `video--{model-key}.mp4`, etc.)
- `var/trailers/<project-id>/render/` — final render (e.g. `trailer-manifest.json` or `final.mp4`), plus benchmark reports when applicable
- `var/trailers/<project-id>/project.json` — full project state including per-asset provider metadata (this is the main inspectable audit trail)

Use the **Project ID** from the command output for reruns.

### Scene routing: real vs fake providers

Configured in `config/services.yaml`:

- **`trailer.video.real_for_first_scene_only`** (default `true`): when the Replicate video provider is enabled and wired, **only scene 1** uses real video generation. Scenes 2+ use the fake video provider (fast, local placeholder MP4s).
- **`trailer.voice.real_for_first_scene_only`**: same idea for TTS — **scene 1** can use Replicate voice when enabled; other scenes use fake audio.

**Scene-aware routing** (`SceneAwareVideoGenerationProvider`, `SceneAwareVoiceGenerationProvider`): if the real provider throws, the router **falls back to fake** for that call and records `fallback_from: real` plus `real_attempt_*` fields (prediction id, model, remote status, error message) on the asset metadata so you can see what failed without opening logs.

**Exception — benchmark mode:** `SceneVideoBenchmarkService` calls **`ReplicateVideoGenerationProvider` directly** (no fake fallback). Failures surface as failed assets/scenes so benchmark runs stay honest.

### Environment variables

Set in `.env.local` or the shell; never commit secrets.

**Shared**

- Replicate API token is read as **`TRAILER_VIDEO_REPLICATE_API_TOKEN`** (voice and video share `ReplicateClient`).

**Video (Replicate)**

| Variable | Purpose |
|----------|---------|
| `TRAILER_VIDEO_REPLICATE_ENABLED=1` | Turn on real video provider |
| `TRAILER_VIDEO_REPLICATE_API_TOKEN` | Bearer token |
| `TRAILER_VIDEO_REPLICATE_MODEL` | Default model slug or version id (optional if you always use a scene-1 preset / CLI override) |
| `TRAILER_VIDEO_REPLICATE_DEFAULT_PRESET` | Optional default preset key |
| `TRAILER_VIDEO_REPLICATE_POLL_INTERVAL_SECONDS` | Poll sleep (default `5`) |
| `TRAILER_VIDEO_REPLICATE_MAX_ATTEMPTS` | Max polls (default `60`) |
| `TRAILER_VIDEO_REPLICATE_POLL_TIMEOUT_SECONDS` | Wall-clock cap for polling |

**Voice (Replicate TTS)**

| Variable | Purpose |
|----------|---------|
| `TRAILER_VOICE_REPLICATE_ENABLED=1` | Turn on real TTS for routed scenes |
| `TRAILER_VOICE_REPLICATE_MODEL` | Model slug / version id |
| `TRAILER_VOICE_REPLICATE_VOICE_ID` | Voice id required by the model |
| `TRAILER_VOICE_REPLICATE_AUDIO_FORMAT` | e.g. `mp3` |

Voice polling uses the same interval / attempts / timeout parameters as video (`TRAILER_VIDEO_REPLICATE_*`).

### Asset metadata in `project.json`

Each asset has top-level `provider`, `path`, `status`, `lastError`, and a `metadata` object. Typical keys after a **successful** Replicate run:

| Key | Meaning |
|-----|---------|
| `provider` | e.g. `replicate-video`, `replicate-voice`, `fake-video` |
| `prediction_id` | Replicate prediction id |
| `remote_job_id` | Alias of `prediction_id` (stable search key) |
| `model` / `provider_model` | Model slug or version id used for the job |
| `replicate_preset` | Preset key when a preset was used |
| `remote_output_url` | CDN URL before download |
| `local_path` / `local_artifact_path` | Final file on disk (duplicate keys for clarity) |
| `provider_status` / `provider_state` | Remote terminal status when successful (`succeeded`) |
| `fallback_from` | If present, `real` means fake output after a failed real attempt |
| `real_attempt_*` | On fallback: prediction id, model, status, error message from the failed real call |

On **failure** (asset `failed`), metadata is updated before `lastError` is set:

| Key | Meaning |
|-----|---------|
| `failure_at` | ISO-8601 timestamp |
| `provider_error_message` | Exception message |
| `prediction_id` / `remote_job_id` | Present when Replicate failed after creating a prediction |
| `provider_model` | Model attempted |
| `remote_status` | e.g. `failed`, `canceled`, `poll_timeout`, `poll_exhausted` |
| `remote_error_detail` | Replicate `error` field or timeout hint |

The CLI prints a short summary for scene 1 videos (provider, model, preset, prediction id, remote URL when present) and reminds you that **`project.json` is canonical**.

### Scene 1 benchmark presets (multiple Replicate video models)

For quick A/B runs without changing `.env` each time, pass **scene 1 only** options on the CLI. **Benchmark runs skip voice (TTS) for scene 1** so you only pay for video API calls; other scenes still get voice + video as usual.

**Shorthand (MVP-friendly):**

```bash
# One model for scene 1 (--video-model: hailuo | seedance | pvideo)
php bin/console app:trailer:generate examples/trailer.yaml --video-model=hailuo

# Benchmark scene 1: hailuo + seedance (add prunaai/p-video with --include-pvideo)
php bin/console app:trailer:generate examples/trailer.yaml --benchmark-video
php bin/console app:trailer:generate examples/trailer.yaml --benchmark-video --include-pvideo
```

**Preset keys** (same behavior, explicit internal names):

```bash
php bin/console app:trailer:generate examples/trailer.yaml --video-preset=hailuo
php bin/console app:trailer:generate examples/trailer.yaml --video-preset=seedance
php bin/console app:trailer:generate examples/trailer.yaml --video-preset=p_video_draft
```

In **one project**, run multiple presets (several video files under scene 1, same prompt):

```bash
php bin/console app:trailer:generate examples/trailer.yaml --video-preset=hailuo,seedance,p_video_draft
```

Legacy shortcut — **all** presets (`hailuo`, `seedance`, `p_video_draft`):

```bash
php bin/console app:trailer:generate examples/trailer.yaml --video-benchmark
```

Each clip is stored as `video--hailuo-02-fast.mp4`, `video--seedance-1-lite.mp4`, `video--p-video-draft.mp4` (see `video_artifact_file` / `model` / `replicate_preset` on each video asset in `project.json`).

When scene 1 has **multiple** video outputs (benchmark or comma-separated `--video-preset`), the run also writes comparison artifacts next to the manifest:

- `var/trailers/<project-id>/render/video-benchmark-report.json` — structured rows (preset, model name, local path, prompt, wall-clock generation time, Replicate `metrics.predict_time` when present, optional cost fields from the API if exposed).
- `var/trailers/<project-id>/render/video-benchmark-report.md` — the same content as a Markdown table for quick diffing in an editor.

The generate command prints those paths and a **CLI summary table** when the report exists.

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

Programmatically, call `TrailerGenerationOrchestrator::generateFromYaml($path, ['replicate_preset' => 'seedance'])` (or the `TrailerGenerationOrchestratorInterface` port) or pass `replicate_model` / `replicate_input` in that same array (see `VideoGenerationProviderInterface` PHPDoc).

### Winning model rollout

After a benchmark (`--benchmark-video` or multi `--video-preset`):

1. Compare `render/video-benchmark-report.{json,md}` and the per-asset rows in `project.json`.
2. Pick the preset (or raw `replicate_model`) you want as default.
3. Roll out by setting **`TRAILER_VIDEO_REPLICATE_DEFAULT_PRESET`** or **`TRAILER_VIDEO_REPLICATE_MODEL`** in `.env.local`, or by standardizing on a CLI flag in scripts (`--video-model=…`).
4. No code change is required as long as the chosen slug matches a defined preset or a valid Replicate version.

### Inspect outputs and metadata

- **Scene artifacts**: under `var/trailers/<project-id>/scenes/<scene-number>-<scene-id>/`.
  - `video.mp4` is the default clip when no explicit benchmark options were passed for that generation.
  - With `--video-preset` / `replicate_model` (or programmatic equivalents), scene 1 uses `video--{model-key}.mp4` so multiple models do not overwrite each other.
  - Provider metadata is stored on the corresponding asset inside **`project.json`** (see table above).
- **Project metadata**: open `var/trailers/<project-id>/project.json` for the full audit trail.
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
2. Note the **Project ID**, **Output directory**, and the **Scene 1 video** section from the CLI output.
3. Check that `var/trailers/<project-id>/` exists and contains `input/`, `scenes/`, `render/`, and `project.json`.
4. Open `project.json` and confirm that the first scene's assets have the expected `provider` and metadata (`replicate-video` / `replicate-voice` when Replicate is enabled and successful; `fake-*` otherwise or after fallback).
5. Optionally run `app:trailer:rerun-scene <project-id> intro` and confirm the scene is reprocessed.

### Automated tests

`./bin/phpunit` exercises the Replicate providers with **mocked HTTP** (no API token needed) and covers scene generation / benchmark metadata shaping. See `tests/` for examples.

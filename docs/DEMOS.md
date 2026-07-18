# Demo Projects

The bundle includes a FrankenPHP demo for Symfony 8.

## Available demo

- **Symfony 8 Demo**: `demo/symfony8/` (default port **8010**)

## Quick start

```bash
make -C demo/symfony8 up
# Open http://localhost:8010
```

See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) for Docker details, environment variables, and smoke tests.

## What the demo shows

- Processing a sample invoice into a Veri*Factu billing record
- Hash chain persistence (Doctrine)
- QR code and mandatory legend rendering
- Event subscriber hooks for audit-style logging
- Optional AEAT sandbox submission when certificates are configured

## Notes

- Only `demo/symfony8` is maintained. Older Symfony 6/7 demo folders are not shipped with this bundle.
- Production AEAT credentials must never be committed; use `.env.local` (see `demo/symfony8/.env.example`).

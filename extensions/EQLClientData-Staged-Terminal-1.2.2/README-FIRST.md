# EQLClientData staged terminal rollout 1.2.2

This package is designed for the EQL Wiki cPanel/LiteSpeed host and can be applied one stage at a time without taking the wiki offline.

Use `INSTALL_TERMINAL_ONE_STAGE_AT_A_TIME.md` for the full commands.

Important: if Stage 01 is already installed and an obviously out-of-era page such as `Necklace of Superiority` is being treated as in-era, run:

```bash
bash ~/public_html/extensions/EQLClientData-Staged-Terminal-1.2.2/terminal/repair-stage-01-era-classification.sh
```

The repair keeps the batched endpoint, restores the original category-matching semantics, invalidates bad Stage 01 cache namespaces, and automatically rolls itself back if known-page classification tests fail.

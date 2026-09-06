# Provenance — ui-ux-pro-max

Vendored copy of the `ui-ux-pro-max` skill shipped by the
[`nextlevelbuilder/ui-ux-pro-max-skill`](https://github.com/nextlevelbuilder/ui-ux-pro-max-skill)
plugin marketplace.

| | |
|---|---|
| Upstream | `nextlevelbuilder/ui-ux-pro-max-skill` |
| Plugin | `ui-ux-pro-max` v2.13.0 |
| Commit | `314307f156aeab0c6b567bbaa1ce4e7aabd5a636` (2026-09-06) |
| Source path | `.claude/skills/ui-ux-pro-max/` |
| License | MIT — see `LICENSE` |

## Why it is vendored instead of installed

`/plugin marketplace add` and `/plugin install` are unavailable in the Claude Code
web/remote environment, so the skill is committed into the repository. Claude Code
discovers any skill under `.claude/skills/<name>/SKILL.md`, so a project-level copy
loads the same way a plugin-installed one would — and it also travels with the repo
for anyone else who clones it.

## Local changes to the upstream copy

1. **Script paths in `SKILL.md` rewritten.** Upstream documents invocations as
   `${CLAUDE_PLUGIN_ROOT}/.claude/skills/ui-ux-pro-max/scripts/search.py`.
   `CLAUDE_PLUGIN_ROOT` is only set for plugin installs, so it expands to nothing
   here and the path breaks. All 11 occurrences now read
   `.claude/skills/ui-ux-pro-max/scripts/search.py`, relative to the project root.
2. **`scripts/tests/` removed.** Those are the upstream repository's own CI tests;
   they anchor themselves by looking for `scripts/generate-catalog-summary.py` at a
   repository root that does not exist here, so they cannot run and would only add
   noise to this project's test suite.

Nothing under `data/` or `references/` was modified, and the `scripts/*.py` sources
are byte-identical to upstream.

## Refreshing

```bash
git clone --depth 1 https://github.com/nextlevelbuilder/ui-ux-pro-max-skill.git /tmp/uiux
rm -rf .claude/skills/ui-ux-pro-max
cp -r /tmp/uiux/.claude/skills/ui-ux-pro-max .claude/skills/
rm -rf .claude/skills/ui-ux-pro-max/scripts/tests
sed -i 's|${CLAUDE_PLUGIN_ROOT}/.claude/skills/ui-ux-pro-max/scripts/|.claude/skills/ui-ux-pro-max/scripts/|g' \
  .claude/skills/ui-ux-pro-max/SKILL.md
cp /tmp/uiux/LICENSE .claude/skills/ui-ux-pro-max/LICENSE
python3 .claude/skills/ui-ux-pro-max/scripts/validate_data.py   # must print OK
```

Then update the commit and version in the table above.

## Sanity check

```bash
python3 .claude/skills/ui-ux-pro-max/scripts/validate_data.py
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "keyboard focus modal" --domain ux
```

## Other skills in the same plugin

The upstream plugin also ships `banner-design`, `brand`, `design`, `design-system`,
`slides` and `ui-styling`. Only `ui-ux-pro-max` is vendored here; the others can be
copied from the same path upstream if they are ever needed.

# AGENTS

## Pull requests
- Follow `.github/PULL_REQUEST_TEMPLATE.md` (Summary, Changes, Verification, Not verified / notes, Checklist) when writing PR descriptions.
- Base branch is `main`. Work on a feature branch; don't commit directly to `main`.
- If `gh pr create` reports the branch isn't pushed after `git push -u`, pass `--head <branch>` explicitly.
- Be explicit about what was not verified (untested layouts, undeployed changes, required secrets).

## Commits
- End commit messages with the trailer `Co-authored-by: copilot-swe-agent[bot] <198982749+Copilot@users.noreply.github.com>`.

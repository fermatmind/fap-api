#!/usr/bin/env bash
set -euo pipefail

BRANCH="${1:?branch required}"
BASE="${2:-main}"

git fetch origin --prune
git checkout "$BASE"
git pull --ff-only origin "$BASE"

if git show-ref --verify --quiet "refs/heads/$BRANCH"; then
  git branch -D "$BRANCH"
fi

git fetch origin --prune

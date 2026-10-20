#!/usr/bin/env bash
# Keeps generated and untracked files out of the repository.
#
#   scripts/ci/clean-tree.sh tracked-ignored   fail when a committed file matches .gitignore
#   scripts/ci/clean-tree.sh clean             fail when the working tree has changes or untracked files
#
# Run "tracked-ignored" anywhere. Run "clean" last in a job, after the tests and the build have written their output:
# anything they leave behind is either missing from .gitignore or a tracked file that a build rewrites.
set -uo pipefail

cd "$(dirname "$0")/../.."

case "${1:-}" in
  tracked-ignored)
    found="$(git ls-files --cached --ignored --exclude-standard)"
    if [ -n "$found" ]; then
      echo "These files are committed but match .gitignore. Generated output does not belong in the repository:" >&2
      echo "$found" | head -20 >&2
      [ "$(echo "$found" | wc -l)" -gt 20 ] && echo "…and $(( $(echo "$found" | wc -l) - 20 )) more" >&2
      echo "Run \`git rm -r --cached <path>\` and commit." >&2
      exit 1
    fi
    echo "No committed file matches .gitignore."
    ;;
  clean)
    found="$(git status --porcelain)"
    if [ -n "$found" ]; then
      echo "The build left files that are not committed or ignored:" >&2
      echo "$found" | head -20 >&2
      echo "Add generated output to .gitignore, or commit the file if it should be tracked." >&2
      exit 1
    fi
    echo "The working tree is clean after the build."
    ;;
  *)
    echo "usage: $0 tracked-ignored|clean" >&2
    exit 2
    ;;
esac

#!/bin/sh

git svn log --oneline --show-commit | sed -r "s/^r([0-9]+) +\| +([a-f0-9]+) +\|.*$/\1 \2/g" > revision_map

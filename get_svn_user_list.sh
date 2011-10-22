#!/bin/sh

git log --format='%aN' | sort -u > svn_users


#!/bin/sh

svn log | grep -E "^r[0-9]+ \| " | awk '{print $3}' | sort | uniq


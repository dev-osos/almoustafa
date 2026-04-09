#!/bin/bash
# Script for Git Push (zsh/bash)
# Usage: ./p.sh [commit message]
# Pushes to origin (default: https://github.com/dev-osos/almoustafa.git)

YELLOW='\033[33m'
GREEN='\033[32m'
RED='\033[31m'
CYAN='\033[36m'
RESET='\033[0m'

# Go to almostafa/ directory (its own git repo)
cd "$(dirname "$0")"

printf "${YELLOW}Adding files...${RESET}\n"
git add -A

if [ -z "$(git status --porcelain)" ]; then
    printf "${CYAN}No changes to commit${RESET}\n"
    exit 0
fi

if [ -n "$1" ]; then
    msg="$1"
else
    msg="Update - $(date '+%Y-%m-%d %H:%M')"
fi

printf "${YELLOW}Creating commit: $msg${RESET}\n"
git commit -m "$msg"

if [ $? -ne 0 ]; then
    printf "${RED}Error creating commit${RESET}\n"
    exit 1
fi

printf "${YELLOW}Fetching latest changes from remote...${RESET}\n"
git fetch dev-osos main

local_commit=$(git rev-parse HEAD)
remote_commit=$(git rev-parse dev-osos/main 2>/dev/null)
if [ $? -eq 0 ] && [ "$local_commit" != "$remote_commit" ]; then
    printf "${YELLOW}Remote has new changes. Pulling...${RESET}\n"
    git pull dev-osos main --no-rebase
    if [ $? -ne 0 ]; then
        printf "${RED}Error during pull. Resolve conflicts manually.${RESET}\n"
        exit 1
    fi
    printf "${GREEN}Pull completed successfully!${RESET}\n"
fi

printf "${YELLOW}Pushing to GitHub...${RESET}\n"
git push dev-osos main

if [ $? -eq 0 ]; then
    printf "${GREEN}Push completed successfully!${RESET}\n"
else
    printf "${RED}Error during push. Check internet, credentials, and permissions.${RESET}\n"
    exit 1
fi

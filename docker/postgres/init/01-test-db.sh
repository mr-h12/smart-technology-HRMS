#!/bin/sh
# Runs once on first boot of an empty data volume: the database phpunit.xml points at.
set -e
psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "CREATE DATABASE hrms_test"

#!/bin/sh
# Atajo para Git Bash. MSYS_NO_PATHCONV evita que argumentos como /%postname%/ se conviertan en rutas de Windows.
MSYS_NO_PATHCONV=1 exec pwsh -NoLogo -NoProfile -ExecutionPolicy Bypass -File "$(dirname "$0")/scripts/rt.ps1" "$@"

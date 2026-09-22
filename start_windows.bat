@echo off
title BTC News Sentiment - One Click Host

cd /d "%~dp0"

where python >nul 2>nul

if %errorlevel% neq 0 (
    echo Python was not found.
    echo Please install Python 3.x.
    pause
    exit /b 1
)

python run.py

pause

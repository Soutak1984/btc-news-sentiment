#!/usr/bin/env python3

import os
import shutil
import subprocess
import sys
import time
import webbrowser
import socket

HOST="127.0.0.1"
START_PORT=8080
ROOT=os.path.dirname(os.path.abspath(__file__))

def wait_before_exit():

    if not sys.stdin or not sys.stdin.isatty():
        return

    try:
        input("\nPress Enter to exit...")
    except EOFError:
        pass

def find_php():

    php=shutil.which("php")

    if php:
        return php

    candidates=[
        r"C:\xampp\php\php.exe",
        r"C:\wamp64\bin\php\php.exe",
        r"C:\wamp\bin\php\php.exe",
        r"C:\php\php.exe",
        "/usr/bin/php",
        "/usr/local/bin/php",
        "/opt/homebrew/bin/php"
    ]

    for p in candidates:
        if os.path.isfile(p):
            return p

    return None

def available(port):

    s=socket.socket(
        socket.AF_INET,
        socket.SOCK_STREAM
    )

    try:
        return s.connect_ex((HOST,port)) != 0
    finally:
        s.close()

def main():

    print("="*60)
    print("       BTC NEWS SENTIMENT - ONE CLICK HOST")
    print("              SQLite edition")
    print("="*60)

    php=find_php()

    if not php:

        print("\nPHP was not found.")
        print("\nInstall PHP 8+ or XAMPP.")
        print("Then run this launcher again.")

        wait_before_exit()
        sys.exit(1)

    print("\nPHP:",php)

    try:

        version=subprocess.run(
            [php,"-v"],
            capture_output=True,
            text=True
        )

        print(version.stdout.splitlines()[0])

    except Exception as e:

        print("PHP execution error:",e)
        wait_before_exit()
        sys.exit(1)

    port=START_PORT

    while port < START_PORT+50:

        if available(port):
            break

        port+=1

    url=f"http://{HOST}:{port}/index.php"

    print("\nStarting PHP server...")
    print("URL:",url)
    print("\nDatabase: SQLite")
    print("No MySQL configuration required.")
    print("\nPress Ctrl+C to stop.\n")

    process=subprocess.Popen(
        [
            php,
            "-S",
            f"{HOST}:{port}",
            "-t",
            ROOT
        ],
        cwd=ROOT
    )

    time.sleep(1.5)

    webbrowser.open(url)

    try:

        process.wait()

    except KeyboardInterrupt:

        print("\nStopping server...")

        process.terminate()

        try:
            process.wait(timeout=3)
        except subprocess.TimeoutExpired:
            process.kill()

if __name__=="__main__":
    main()

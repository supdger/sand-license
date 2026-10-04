#!/usr/bin/env python3
"""Validate the independent plugin's actual index, never dirty source files."""

import configparser
import re
import subprocess


def main():
    source = subprocess.check_output(["git", "show", ":info.ini"], text=True)
    parser = configparser.ConfigParser(interpolation=None, strict=True)
    parser.read_string("[metadata]\n" + source)
    section = "app" if parser.has_section("app") else "metadata"
    support = parser.get(section, "support", fallback="").strip("\"'")
    if re.fullmatch(r">=(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", support) is None:
        raise ValueError("宿主兼容范围冲突：support 必须为 >=host_min 开放上限")
    if parser.get(section, "app", fallback="") != "sand-license":
        raise ValueError("实际 index 的插件身份不符")
    print("[PASS] staged SandLicense metadata: " + support)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, configparser.Error, subprocess.CalledProcessError) as error:
        raise SystemExit("[FAIL] " + str(error))

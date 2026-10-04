#!/bin/bash
set -euo pipefail

root="$(git rev-parse --show-toplevel)"
if [[ -n "$(git config --get core.hooksPath || true)" ]]; then
  echo "失败：已有自定义 core.hooksPath，须保留并明确接入。" >&2
  exit 1
fi
if [[ -z "${QUALITY_CHECKER:-}" || ! -f "$QUALITY_CHECKER" ]]; then
  echo "失败：请用 QUALITY_CHECKER 指定已有 scan_change_quality.py。" >&2
  exit 1
fi
echo "步骤 1/2：核对并保留已有 pre-commit"
python3 - "$root" <<'PY'
import os
import pathlib
import subprocess
import sys

root = pathlib.Path(sys.argv[1])
hook = pathlib.Path(subprocess.check_output(["git", "rev-parse", "--git-path", "hooks/pre-commit"], text=True).strip())
if not hook.is_absolute():
    hook = pathlib.Path.cwd() / hook
saved = hook.with_name("pre-commit.before-sand-checks")
if hook.is_symlink() or saved.is_symlink():
    raise SystemExit("失败：不能安全替换符号链接 hook")
marker = "# sand-license-pre-commit-v1"
if hook.is_file() and marker in hook.read_text():
    print("已有 SandLicense hook，保留")
    raise SystemExit(0)
if saved.exists():
    raise SystemExit("失败：存在已保留 hook，须明确接入")
if hook.exists():
    hook.rename(saved)
hook.write_text("""#!/bin/bash
# sand-license-pre-commit-v1
set -euo pipefail
root="$(git rev-parse --show-toplevel)"
hook="$(git rev-parse --git-path hooks/pre-commit)"
if [[ -f "$hook.before-sand-checks" ]]; then
  "$hook.before-sand-checks" "$@"
fi
if [[ -z "${QUALITY_CHECKER:-}" || ! -f "$QUALITY_CHECKER" ]]; then
  echo "失败：缺少 QUALITY_CHECKER，未执行 index 审查。" >&2
  exit 1
fi
python3 "$root/scripts/check-staged-plugin-support.py"
python3 "$QUALITY_CHECKER" "$root" --repository-review check
""")
hook.chmod(0o755)
PY
echo "步骤 2/2：hook 已接入；提交仍须实际 index 审查记录"
echo "成功：未修改全局 Git 配置。"

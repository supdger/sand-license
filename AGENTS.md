# SandLicense

先读 README.md、SOURCE_OF_TRUTH.md 和 CHANGELOG.md。本仓是完整 SandPackage 插件源码，不是宿主；产品 source、tests、构包工具及既有客户端接入文档长期维护于本仓。

PostgreSQL only；业务表仅 `sand_license_*`。身份、应用、凭证、服务授权与访问审计消费 SandIAM。业务异常复用 SandAdmin ApiException。不引入重复 JWT/Composer/vendor；宿主 Tinywan/Firebase 版本和来源必须符合运行契约。

更改 app/config 后刷新并审查 host-payload.json。正式发布从 clean committed revision 构包，显式提供固定 SandIAM 工件；安装、数据库、秘密和服务操作须有对应授权。原始日志、截图、临时夹具、发布审查 JSON 和构建工件存放仓库外。

提交前审查实际 index；运行 scripts/install-pre-commit-checks.sh 接入并保留已有 hook，不覆盖自定义 hooksPath。通过 QUALITY_CHECKER 指定现有 scan_change_quality.py，以 --repository-review prepare/record/check 绑定实际 index；审查记录不授予提交权限。公开源码不含作者机器默认路径或秘密。

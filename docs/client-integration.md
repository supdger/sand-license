# 客户端接入

适用对象：负责把 SandLicense 接入桌面或命令行产品的开发者。目标是在每次新启动时在线确认权益，并让已经运行的进程在网络中断后最多运行到最后一张已验签租约的截止时间。此处提供协议与可运行 PHP 参考实现，不代表工作区外的客户桌面应用已经完成改造。

## 先准备什么

从发放方取得产品的 HTTPS 服务 origin、期望 issuer、audience、product_code 与领取的授权码。issuer/audience 是预先配置的信任值，不能从未验证的租约中反向推导。origin 不含尾斜线、查询参数或路径；接口统一在 `/api/sand-license/v1` 下。生产环境使用正常受信任的 TLS 证书，不得关闭证书或主机名验证。

参考实现位于 [`examples/php-client`](../examples/php-client/)。需要已有 PHP 8.1+ 环境、Curl、Sodium，以及宿主现有 Tinywan JWT 能力所使用的 Firebase JWT/JWK 标准 API；当前实测组合为 Tinywan 1.15.0 / Firebase 7.1.1。用 `--autoload` 明确指定该现有环境的 `vendor/autoload.php`，示例不安装另一套 JWT、Composer 或 vendor，也不使用服务器的签名私钥。

本示例仅实现 POSIX 私钥文件保护。为每个产品安装建立当前用户独占的绝对目录（0700），安装私钥与记录均为普通文件（0600），禁止符号链接及共享目录。首次运行由 Sodium 生成本设备自己的 Ed25519 密钥。生产桌面客户端应使用操作系统密钥库与适当的进程隔离；可修改本机程序的管理员仍可能绕过客户端功能门禁，服务端业务操作必须继续独立鉴权。

授权码只能放在当前用户的 0600 普通文件中，不要作为命令参数、环境变量或日志字段。不要记录私钥、授权码、租约、proof、挑战 nonce、完整请求/响应体。安装记录只保存服务/产品/设备公钥指纹与权益/激活 ID，不保存可离线启动的租约。

## 最小运行

先用 `php examples/php-client/run.php --help` 检查入口。下面的路径和产品值是占位示例，替换成已取得的配置；私有目录必须预先建立，`realpath` 后的规范绝对路径不能含符号链接。

```sh
mkdir -m 700 /absolute/private/client-install
php examples/php-client/run.php \
  --autoload=/absolute/existing-host/vendor/autoload.php \
  --origin=https://license.example.com \
  --issuer=https://license.example.com \
  --audience=sand-license:my-product \
  --product=my-product \
  --directory=/absolute/private/client-install \
  --code-file=/absolute/private/received-code.txt \
  watch
```

授权码文件须由安全领取/交付渠道提供并设为 0600；不要通过会进入 shell 历史的 `echo` 命令写入真实码。测试自签名证书只可显式加 `--ca-file=/absolute/test-ca.pem`；仍执行完整 TLS 验证。

`watch` 显示启动、运行心跳、续租、重试及截止结果，在同一进程中约每 300 秒续租。首次安装兑换一次，后续启动凭相同设备密钥与安装记录在线续租，不需要再次传入授权码。`start` 或 `current` 只做一次在线检查后退出，不能把退出码当成另一个新进程的持久运行许可。

应用接入时，在实际功能执行前调用 `requireFeature($feature)`，持续检查 `canRun()`，在 `renewalDue()` 时续租。功能值来自签名的 `features`；套餐限额的服务端扣减不能只靠客户端检查。不要只在欢迎界面检查一次，也不要把参考 CLI 的状态输出当作签名证据。

## 协议顺序与签名边界

1. `POST /challenges`：JSON 为 `product_code`、`purpose`、`installation_public_key`。purpose 为目标操作 `redeem/current/renew/release`；挑战 TTL 120 秒。服务端保存挑战摘要、产品、用途及安装公钥指纹，并在目标业务事务内一次消费。
2. 构造目标 POST 的原始 JSON 字节，包含 `product_code`、`challenge`、稳定的 `request_id` 和业务参数。对最终发送的字节做 SHA-256，不先解析、排序或再次编码后校验。
3. 用设备 Ed25519 私钥及 Firebase JWT 标准 API 签 proof JWS，独立放在 `Sand-License-Proof` 头。头固定 `typ=sand-license-proof+jwt`、`alg=EdDSA`。claims 恰为 `htm`、`htu`、`iat`、`jti`、`nonce`、`body_sha256`、`product_code` 七项；没有第八项 purpose。iat 窗口 60 秒。htu 为预配置可信 origin 加已路由固定路径，不接受查询参数、斜线归一化或客户端任意指定 origin。
4. 发送目标请求：首次兑换 `POST /redemptions`（授权码、安装公钥、显示名称）；当前权益 `POST /entitlements/current`（activation_id）；续租 `POST /leases/renew`（activation_id）；释放 `POST /activations/release`（activation_id、issue_enrollment_ticket）。全部选择器与 request_id 都由原始 body 哈希签名绑定。current 不使用 GET，也没有 `Sand-License-Challenge` 请求头。
5. 兑换/续租返回租约后，从同一可信 HTTPS 服务 `GET /.well-known/jwks.json` 获取公钥，使用标准 JWK/JWT API 校验 EdDSA 签名。只接受 OKP/Ed25519、alg EdDSA、use sig、唯一 kid 的公开 JWK，拒绝私钥字段。公钥发布或轮换不能改变预期 issuer/audience。

租约头固定 `typ=JWT`、`alg=EdDSA`、受信任 JWKS 中的 `kid`。必须校验 `iss`、`aud`、`product_code`、`sub=entitlement_id`、`entitlement_id`、`activation_id`、`cnf.jkt` 与本安装绑定；ID 为字符串，不是 license_id。还必须验证 jti、iat/nbf/exp、plan_revision 及 features 类型，拒绝未来 iat、尚未生效或已到期租约，且 `exp - iat <= 900`。服务端保证 exp 不晚于权益期限。cnf.jkt 使用 RFC 7638 的 OKP/Ed25519 公钥指纹。此协议是安装证明 JWS，不是完整 DPoP 实现。

## 启动、失联和恢复

新启动永远需要一次成功在线兑换或续租，再完成租约验签。读取安装文件不构成运行许可；网络不可达、TLS 失败、服务端拒绝或租约验证失败时不放行新启动。

已经运行的进程，遇到网络/暂时服务故障，可在最后一张已验签租约有效期内重试，不生成新离线期限。参考实现捕获请求发送前及验签时的墙钟/单调时钟：初始预算取 `min(exp - 发送前墙钟, exp - iat)`，再扣除请求和 JWKS 已耗的单调时间；剩余预算还不得超过 `exp - 验签时墙钟`，据此设置单调截止。签名区间硬上限防止请求前慢钟、响应前校准、随后回拨延长许可，网络/JWKS 延迟也不延长期限。运行时同时检查墙钟 exp 和单调截止；进程退出后该内存授权失效。收到明确业务拒绝、绑定/签名不一致或到期时立即关闭本次运行。离线客户端不可能即时得知退款或撤销；最长残余运行窗口就是尚未到期的旧租约，下一次在线请求会按服务端状态拒绝。

首次兑换会先持久化稳定 request_id。响应丢失后，保留原设备密钥、记录和同一授权码，取得新挑战/proof，用相同 request_id 重试；不要换新设备密钥或误认为可以再次领取。续租/查询每次取新挑战，不重放已消费 nonce。参考 CLI 对 transient 网络错误每 15 秒重试，不打印秘密；生产可加有上限的退避与抖动，但不得越过租约截止。非 transient 拒绝应向用户显示错误分类与联系发放方的下一步，不自动重发、重置设备或删除密钥。

释放是用户明确操作，用稳定 request_id 发请求；参考实现发送前先停止本次运行。释放响应丢失时保留安装记录，重试同一操作以确认结果。服务端席位仍冻结到旧租约可能运行的截止时间，释放成功不等于另一设备立即能用该席位。重发授权码/设备恢复属于发放方管理流程，可能需要一次性 enrollment ticket；示例不自动执行管理端重发、退款或跨设备迁移。

```sh
php examples/php-client/run.php \
  --autoload=/absolute/existing-host/vendor/autoload.php \
  --origin=https://license.example.com --issuer=https://license.example.com \
  --audience=sand-license:my-product --product=my-product \
  --directory=/absolute/private/client-install \
  --request-id=a-stable-unique-release-operation-id \
  release
```

## 验证层次

以下 `tests.php` 命令仅用于源码仓库回归；安装 ZIP 不包含该测试文件。安装用户可使用随包提供的 `run.php` 做在线接入检查，并在专用隔离环境运行 `live-test.php`。

```sh
php examples/php-client/tests.php --autoload=/absolute/existing-host/vendor/autoload.php
```

这是实际 Firebase/Sodium 签验与负向协议测试，使用注入的 transport/单调时钟验证截止逻辑；不是实际 HTTP 或自然到期证据。

`live-test.php` 是隔离环境的真实 TLS 验收入口，参数与上面相同，但必须另用专属产品/授权码与私有目录，并提供测试 CA。它会真实在线启动、POST current、续租；随后用本测试自己的 loopback TCP socket 选择临时端口，立即释放，再以无秘密的公开 JWKS GET 预检，严格要求 cURL errno 7。Darwin 上持续持有但不监听的 TCP socket 可能产生 errno 28 超时，因此不把该行为冒称为连接拒绝。

故障请求通过 `CURLOPT_CONNECT_TO` 仅改变连接目标，URI、proof、issuer 和 TLS 信任上下文保持不变；不关闭证书/主机名校验，也不允许重定向。端口释放后存在极短的被其他进程占用窗口：非任务证书的服务会在 TLS 验证阶段被拒绝，任何不是 errno 7 的结果都会明确使验收失败，不会当作连接拒绝通过。该端口不再被本测试全程占有。

它验证新的客户端启动拒绝、旧进程失联只保留最后签名许可，并自然等待默认 900 秒租约到期；每 30 秒输出状态，到剩余 60 秒和截止时显示阶段结果。不修改数据库时钟、租约期限，也不停止共享服务。

```sh
php examples/php-client/live-test.php \
  --autoload=/absolute/test-host/vendor/autoload.php \
  --origin=https://127.0.0.1:18984 --issuer=https://127.0.0.1:18984 \
  --audience=sand-license:dedicated-test-product --product=dedicated-test-product \
  --directory=/absolute/private/dedicated-live-client \
  --ca-file=/absolute/task/test-ca.pem \
  --code-file=/absolute/private/dedicated-test-code.txt
```

故障注入前已释放本测试选择端口的 socket；设备私钥/安装记录保留在指定目录，按本次临时资源清理授权由任务所有者清理。该验收需要真实隔离服务、专属可兑换码与自然等待约 15 分钟，不能以本地注入测试代替。未执行的目标客户平台和其密钥库接入仍需单独验收。

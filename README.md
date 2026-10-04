# SandLicense

SandLicense 在一个 SandAdmin 中管理多个软件产品的桌面许可和会员权益。运营创建产品、发布套餐并发码；买家首次兑换后开始计时，设备在线取得最长十五分钟的租约。可信业务系统可通过 SandIAM 授权的履约接口交付领取资格或会员周期。

客户开发者从[客户端接入](docs/client-integration.md)开始；ZIP 包含 [PHP 参考客户端](examples/php-client/run.php)、完整签验实现和真实 TLS 验收入口，不另装 JWT 依赖。提取后运行 `php examples/php-client/run.php --help` 查看所需服务、产品、已有依赖及私有安装目录；授权码只通过拥有者可读文件提供，不作命令参数或日志。

当前版本 **0.1.0**，安装包从 [Releases](https://github.com/supdger/sand-license/releases) 下载，变化与限制见[更新日志](CHANGELOG.md)。隔离临时宿主已完成标准安装、PostgreSQL 生命周期 SQL、197 项真实 HTTPS 业务／并发／审计检查、参考客户端自然租约到期，以及管理页面、领取响应丢失恢复、设备重置和无权限拒绝的真实界面检查。后续设备展示修复按受影响接口与页面复验；未变组件通过工件字节映射继承既有证据，不声称每个最终包都重跑全部业务。没有执行生产部署或客户应用改造。源码来源见[来源记录](SOURCE_OF_TRUTH.md)，第三方版本和移植范围见 [NOTICE](NOTICE)。

## 从源码验证与打包

使用 PHP 8.2+、JSON/Sodium/Zip 扩展。运行环境需 SandAdmin、已有 PostgreSQL、SandIAM 0.8.4 及宿主已有 Redis；挑战限流使用 Redis 原子计数，连接不可用时明确拒绝，不自动启动服务。安装器与插件都不会建库。宿主声明 `support=">=0.1.0"`，保持开放上限；声明范围不代表全部版本已验收，当前隔离安装和运行检查使用 PHP 8.2.29、Core 0.2.0 / Package 0.2.1，未完成的业务验收不能由版本声明代替。

签验复用宿主 **tinywan/jwt** 已有栈及其传递依赖 Firebase，不提供插件 Composer、vendor 或回退副本，也不修改登录 JWT 配置。Tinywan 高层接口绑定后台登录用户、全局配置和可选 Redis 会话，没有独立许可 `kid/claim/audience/key` 入口；许可因此直接消费同一宿主 Firebase 的公开签验 API，保留 Ed25519、安装证明和十五分钟租约契约。启动检查 Firebase `^6.8||^7.0`、所需 API/EdDSA 和实际类路径；两包元数据以宿主标准 `base_path()/vendor/composer/installed.php` 为准，包目录及签验类必须来自该宿主 vendor。IAM 可先加载其 Composer 元数据类，不据全局 registry 的先后顺序选择 JWT。不支持自定义 vendor-dir，缺失、混用或来源异常明确拒绝。当前实际验证为 Tinywan **1.15.0** / Firebase **7.1.1**，范围内其他版本尚未实测。

在本目录执行：

```sh
export SAND_LICENSE_TEST_HOST_AUTOLOAD=/实际宿主/server/vendor/autoload.php
php tests/api_adapter.php
php tests/security.php
```

测试必须显式指定已安装 SandAdmin 的依赖入口，不依赖作者本机目录；本仓不含可运行宿主。宿主须已安装匹配的 Sand Core、Sand Package、Tinywan/Firebase 和 SandIAM。完整依赖检查及包检查另需 `SAND_LICENSE_TEST_FOREIGN_HOST_AUTOLOAD=/另一个真实宿主/server/vendor/autoload.php` 和 `SAND_LICENSE_TEST_OLD_JWT_ARCHIVE=/真实旧版firebase归档.zip`。IAM 先加载测试默认取指定宿主的 `plugin/sand-iam/app/functions.php`，也可设置 `SAND_LICENSE_TEST_IAM_FUNCTIONS=/实际安装的IAM/app/functions.php`。设置这些输入后运行 `php tests/dependencies.php`；十个独立 PHP 进程验证无宿主拒绝、已有宿主真实签验、插件先加载后重验、正式 IAM 先加载、异常类来源、混合 registry、整套外来栈，以及宿主元数据缺失、缺包记录和外来安装路径，并显示实际版本与 JWT 类路径。负例使用真实库与实际 Composer 数据，不用自造 JWT stub；测试不启动服务或连接数据库。

准备一个新的输出目录，并提供固定公开 SandIAM 工件：

```sh
php tools/build-package.php --output=/已建输出目录 \
  --iam-bundle=/路径/sand-iam-0.8.4.zip
```

工件 SHA-256 必须为 `1a3352a6a5556bb8f605e783dd8f66981d5d3436d0af33c053fcb618424e9366`。成功会显示 `sand-license-0.1.0.zip` 和摘要；输出已存在或源码摘要发生变化会拒绝覆盖。ZIP 根目录直接包含安装元数据、精确 `host-payload.json`、SQL、完整 plugin/app/config、运营前端、领取页和第三方许可，不含重复 JWT 依赖。随后运行 `php tests/packaging.php /输出/sand-license-0.1.0.zip`，真实解包后检查宿主加载与缺依赖拒绝；并不执行安装或 SQL。

正式发布从 clean 已提交 revision 构包；修改 app/config 后开发者运行 `php tools/refresh-manifests.php` 更新精确载荷清单并审查差异。宿主与 IAM 安装不会由测试自动完成。

## 在授权宿主中开始使用

通过 SandPackage 上传该 ZIP，按标准安装流程完成 SQL、文件和管理端构建。此步骤需要冻结并获授权的现有 PostgreSQL 目标；源码检查不能代替安装成功。服务目录只登记 `sand_license.fulfillment.write/read` 和 `sand_license.membership.read`，不自动创建组织、凭证、全能管理员或服务 grant。管理菜单“商业许可”进入产品、套餐、卡密、权益、设备、商品映射、履约、会员及操作记录。

部署管理员在 `config/sand_license_runtime.php` 配置可信 HTTPS `origin`、外部 `pepper_file`、外部 `signing_key_file`、公开 `public_keys` 和 `channels`。当前服务器密钥检查使用 POSIX 绝对路径及文件权限，秘密文件必须在宿主根之外且仅拥有者可读；Windows 服务器 ACL 尚未实现或验证。不得将秘密放进 ZIP 或数据库。pepper 至少 32 字节；签名文件 JSON 包含 `kid/private_key_base64/issuer/public_keys_by_kid`，私钥为 Sodium Ed25519 secret key 的标准 base64，公钥映射为标准 base64。公开 `public_keys` 是按 kid 索引的 `OKP/Ed25519/x` JWK，无私钥 `d`。本仓不提供默认密钥，也不会修改真实密钥。

普通产品/套餐配置不需要签名私钥；发码需要 pepper，签租约才读取签名文件。每个渠道配置服务端固定的产品、组织、应用、环境、workload client 和允许的 `subject_codes`，不从请求 header 猜组织。管理员权限使用宿主 Permission 与 SandIAM 管理组织范围；没有可验证范围则拒绝。

运营先创建软件产品，再创建并发布套餐。桌面套餐默认一个设备；产品编码和受众、已发布套餐快照不可随意改变。手工发码带唯一 `request_id`，明码只展示一次；响应不明时用原请求查询状态，普通重试不回明码。设备重置需理由，旧设备立即不能续签，席位等最后租约到期后才能重用。

## 最小服务接入

履约请求发送 `Authorization: Bearer` 正式上下文，字段来源为渠道订单项、付款周期及后台配置的 SKU 映射。每次请求带稳定 `request_id`；同一次付款的 `order_item_id/payment_cycle_id` 不变，不同 `event_id` 也不会重复发放。

```http
POST /api/sand-license/v1/fulfillments/events
Content-Type: application/json
Authorization: Bearer <SandIAM 签发的服务上下文>

{"product_code":"desktop","channel_code":"store","event_id":"paid-001","event_type":"paid","order_id":"order-001","order_item_id":"item-001","payment_cycle_id":"one-time-001","sku_code":"annual","quantity":1,"request_id":"delivery-001"}
```

成功响应沿宿主 `code/message/data`，首次桌面履约的 `data.claim_credentials` 为 `{claim_id,claim_credential}` 数组。普通重试只返回状态和 ID。交付领取链接 `/license/claim#id=<claim_id>&credential=<claim_credential>`，凭证留在 fragment，页面将其转 POST 并清除 fragment；订单号本身不能领码。领取返回的一次明码丢失，买家明确重签会撤销旧未兑码；已兑换不能重签。若领取凭证丢失，由可信业务显式调用 `POST /api/sand-license/v1/fulfillments/claims/{id}/credential/reissue`（同正式履约写授权，含产品、渠道、`request_id`），新凭证只返回一次，旧凭证立即失效；这不会自动重签卡密或再发权益。

会员支付使用 `subject_code` 与明确的 `cycle_start_time/cycle_expire_time`（ISO UTC），首版数量只能为 1；通过 `GET /api/sand-license/v1/memberships/current` 查询，需配置渠道和主体范围及正式读授权。退款只撤销原付款来源，取消续期保留已付期限，部分退款返回 `SAND_LICENSE_PARTIAL_REFUND_UNSUPPORTED` 并要求人工处理。

安装端使用独立 `Sand-License-Proof` header；证明绑定方法、固定可信 URL、原始 body 摘要、短时 challenge、产品和安装公钥。当前权益读取为 `POST /api/sand-license/v1/entitlements/current`，`product_code/activation_id/challenge/request_id` 全部放在已签摘要的 JSON body；安装证明接口不接受 GET 或查询参数，nonce 不进入 URL。兑换/释放/登记带 `Idempotency-Key`，不要将 proof 放进 body。运行启动必须在线取得新租约，默认建议每五分钟续签；断网运行不能超过租约 `exp`。已兑卡密不作日常续签或新设备资格。

部署验收还须确认真实宿主全局日志、反向代理和 APM 不记录卡密、领取凭证、安装 proof 或含秘密响应。插件自身不挂载 SystemLog，领域日志脱敏；这不代替宿主日志配置验收。临时 PostgreSQL/HTTPS 与界面验收不代表生产部署、客户应用接入或 Wiki 发布；实际交付仍需核对最终工件、标准生命周期与资源清理记录，当前不宣称可生产使用。

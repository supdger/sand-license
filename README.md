# SandLicense

SandLicense 是 SandAdmin 的商业许可插件，用于集中管理多个软件产品的授权码、设备许可和会员权益。在一个宿主安装一次，再为每个产品创建记录和套餐。软件许可从首次兑换起算，启动须联网，运行租约最长十五分钟；会员权益按业务主体及已付周期管理，不使用卡密或设备绑定。

当前版本 **0.1.2**。升级与限制见[更新日志](CHANGELOG.md)，安装包及摘要见 [Releases](https://github.com/supdger/sand-license/releases)。客户开发者可直接阅读[客户端接入](docs/client-integration.md)。

## 安装

先准备一个已连接现有 PostgreSQL 数据库的 SandAdmin 宿主：

- Sand Core ≥ 0.1.0、支持标准 ZIP 安装的 SandPackage、SandIAM **0.8.4**；已有 PHP **8.2+**、JSON/Sodium 扩展及可用 Redis。
- 宿主已有 `tinywan/jwt` 及 Firebase JWT（`^6.8 || ^7.0`）。插件复用宿主标准 vendor，不安装另一套 JWT。
- 运营账号有商业许可的创建、发布及发码权限，并具有可验证的 SandIAM 管理组织范围；产品所属组织及应用已在 SandIAM 登记。

在 SandAdmin 的插件管理页面进入“插件仓库”，点击“刷新仓库”，搜索 **SandLicense**，选择推荐版本并点击“直接安装”，按提示完成标准安装及依赖、管理端构建。官方目录来源为 [supdger/sandadmin 的 main](https://github.com/supdger/sandadmin/blob/main/catalog.json)。安装会在现有数据库执行插件 SQL，不会创建数据库。

以插件管理显示“已安装”为安装完成结果；完成管理端构建后，有权限的账号应能进入“商业授权”下的对应业务模块。从 0.1.0 升级后，业务模块在左侧菜单独立显示；旧地址按账号已有列表权限跳转，升级影响见[更新日志](CHANGELOG.md)。0.1.2 修正混合及双列布局的目录路由，升级后从菜单重新进入；旧 `/plugin/sand-license/*` 页面书签须更新。看不到菜单或显示暂无可访问功能时，请管理员核对角色权限与组织范围；安装不会自动创建组织、应用、凭证或服务授权。

在线目录不可用时，从 [v0.1.2 Release](https://github.com/supdger/sand-license/releases/tag/v0.1.2) 下载 `sand-license-0.1.2.zip` 与 `SHA256SUMS`，核对摘要后，在同一页面点击“上传插件包”并继续标准安装。安装用户不需要克隆源码或自行构包。

兼容声明为 `>=0.1.0`，没有隐式上限；当前隔离实测组合为 PHP 8.2.29、Core 0.2.0 / Package 0.2.1、Tinywan 1.15.0 / Firebase 7.1.1，其他组合尚未全部实测。服务器秘密文件检查仅实现 POSIX 路径与权限，Windows 服务端 ACL 尚未实现或验证。

## 第一次手工发码

先由部署管理员完成下方[部署配置](#部署配置)：发码需要 pepper，客户兑换及签租约还需要可信 HTTPS 和签名密钥。运营不接触私钥。以下示例创建一个月、一个设备的软件许可：

1. 从宿主左侧菜单进入“商业授权 → 软件产品”，点击“创建产品”。填写产品名称，例如“桌面编辑器”；所属组织编号和应用编号从 **SandIAM 已登记的组织、应用**取得。产品编码（例如 `desktop_editor`）与许可受众由产品接入开发者约定，受众必须与客户端验签配置一致。点击“保存草稿”，核对后在列表点击“发布”，确认状态为“已发布”。
2. 从左侧菜单进入“套餐与功能”，点击“创建套餐版本”，选择该产品。填写套餐名称、编码，例如“个人月度版”、`personal_monthly`；类型选“软件设备许可”，有效期选 **1 月**，设备数为 **1**。功能可留空，需要时由产品开发者提供功能编码及布尔值或非负整数值。保存草稿后点击“发布”，确认套餐为“已发布”。发布后版本固定，调整期限、设备数或功能须创建新版本。
3. 从左侧菜单进入“卡密发放”，点击“手工发码”，选择产品及已发布的软件许可套餐，点击“签发授权码”。页面自动生成请求编号，无须手填。成功时立即复制并安全交付授权码；**明码只展示一次**，关闭后列表只保留前缀和状态。
4. 在“卡密发放”确认新记录为“未兑换”。此时尚未计时；客户在已接入的产品中首次兑换后，在“已发放权益”和“设备激活”核对期限、设备及状态。

网络中断或发码结果不明时，在原窗口点击“查询本次发码结果”，不要另发一张。查询可确认编号与状态，不能找回明码；未兑换的丢失码可在列表明确“重新签发”，旧码随即失效。已兑换卡密不能用于日常续签或作为新设备资格。

## 接入客户产品

发放方向产品开发者提供：部署配置中的 HTTPS `origin`、签名文件中的预期 `issuer`、产品页面的 `audience` 与 `product_code`；向客户安全交付上一步签发的授权码。这些信任值须事先约定，不能从尚未验签的租约反推。

协议、运行与恢复说明见[客户端接入](docs/client-integration.md)。随包提供 [PHP 参考客户端](examples/php-client/run.php)，在源码目录或解出的安装包根目录执行 `php examples/php-client/run.php --help` 可查看参数，不发起兑换。参考实现需要已有依赖入口与 POSIX 私有安装目录，不是通用 Windows SDK，也不代表客户现有桌面应用已完成改造。授权码只通过拥有者可读文件提供，不放在命令参数、环境变量或日志中。

## 部署配置

<details>
<summary>部署管理员：首次配置</summary>

部署管理员编辑宿主 `server/config/sand_license_runtime.php`（从服务端工作目录看是 `config/sand_license_runtime.php`），字段定义见[配置源码](config/sand_license_runtime.php)。普通产品/套餐配置无需私钥；手工发码读取 pepper，兑换和签租约读取签名文件。手工发码无需配置 `channels`，订单履约及会员读取接入才需要它。

配置可信 HTTPS `origin`（无路径、查询参数或尾斜线）、外部 `pepper_file`、外部 `signing_key_file` 和公开 `public_keys`。秘密文件必须位于宿主根之外，只允许拥有者读取；不能放进 ZIP、数据库或仓库。安装包不提供默认密钥，下面给出部署管理员在受控 POSIX 环境生成新密钥的示例；已有服务升级应沿用原密钥及轮换计划，不能重新生成替换。

先创建一个新的私有目录，例如由服务账号运行 `mkdir -m 700 /absolute/private/sand-license`（父目录需已存在），不要位于宿主源码根内。目录拥有者应是实际运行 PHP 的服务账号；以下命令也由该账号执行。把目录、HTTPS 地址、密钥编号替换为真实值：

```sh
php -r '
umask(0077);
$dir = realpath($argv[1]);
if (!$dir || $dir !== $argv[1] || is_link($argv[1]) || !is_dir($dir)
    || (fileperms($dir) & 0777) !== 0700 || fileowner($dir) !== posix_geteuid()) {
    throw new RuntimeException("需要当前账号拥有的规范绝对路径私有目录，权限0700");
}
if (!preg_match("~^https://[^/?#]+$~D", $argv[2]) || $argv[3] === "") {
    throw new RuntimeException("请提供HTTPS origin及非空kid");
}
foreach (["pepper", "signing.json", "public-jwk.json"] as $name) {
    if (file_exists("$dir/$name") || is_link("$dir/$name")) {
        throw new RuntimeException("拒绝覆盖已有文件");
    }
}
$pair = sodium_crypto_sign_keypair();
$public = sodium_crypto_sign_publickey($pair);
$secret = sodium_crypto_sign_secretkey($pair);
$kid = $argv[3];
$jwk = [$kid => ["kty" => "OKP", "crv" => "Ed25519", "kid" => $kid,
    "alg" => "EdDSA", "use" => "sig",
    "x" => rtrim(strtr(base64_encode($public), "+/", "-_"), "=")]];
$files = [
    "pepper" => bin2hex(random_bytes(32)),
    "signing.json" => json_encode(["kid" => $kid,
        "private_key_base64" => base64_encode($secret), "issuer" => $argv[2],
        "public_keys_by_kid" => [$kid => base64_encode($public)]], JSON_THROW_ON_ERROR),
    "public-jwk.json" => json_encode($jwk, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
];
foreach ($files as $name => $contents) {
    $file = fopen("$dir/$name", "x");
    if (!$file || fwrite($file, $contents) !== strlen($contents)) {
        throw new RuntimeException("文件写入失败，请核对本次新建文件后再继续");
    }
    fclose($file);
    echo "已生成 {$name}（0600）\n";
}
sodium_memzero($secret);
' /absolute/private/sand-license https://license.example.com license-2026-01
```

此示例需要 PHP Sodium 和 POSIX 扩展，只创建新文件，输出文件名而不输出秘密。`pepper` 是至少 32 字节的随机秘密；`signing.json` 含 `kid/private_key_base64/issuer/public_keys_by_kid`，私钥为 Sodium Ed25519 secret key 的标准 base64，公钥映射也是标准 base64。`public-jwk.json` 只含公开 `OKP/Ed25519/x` JWK，`x` 为 base64url，无私钥 `d`。

在运行配置中将 `pepper_file` 与 `signing_key_file` 分别设为这两份秘密文件的绝对路径，`origin` 设为真实 HTTPS 地址。将 `public-jwk.json` 的公开映射作为 `public_keys`，例如配置文件中使用 `json_decode(file_get_contents('/absolute/private/sand-license/public-jwk.json'), true, 16, JSON_THROW_ON_ERROR)`。普通产品受众以产品记录为准，填写产品时与开发者约定，不能把配置中的默认 `audience` 当成所有产品的受众。

按宿主现有方式使运行配置生效后，通过正常 TLS 请求 `https://你的服务/.well-known/jwks.json`，应看到对应 `kid` 的公开密钥且无 `d`。再按“第一次手工发码”步骤确认 pepper 可用；首次客户端兑换并成功验签租约后，才能确认私钥签发与产品受众配置一致。能读取公钥不代表兑换已验收。

</details>

<details>
<summary>源码开发、回归与构包（安装用户可跳过）</summary>

本仓不含可运行宿主。源码回归使用显式提供的现有 SandAdmin 依赖；构包另需 Zip 扩展、新输出目录和固定 SandIAM 0.8.4 工件。在源码根目录执行：

```sh
export SAND_LICENSE_TEST_HOST_AUTOLOAD=/实际宿主/server/vendor/autoload.php
php tests/api_adapter.php
php tools/build-package.php --output=/已建输出目录 --iam-bundle=/路径/sand-iam-0.8.4.zip
php tests/packaging.php /已建输出目录/sand-license-0.1.2.zip
```

SandIAM 工件 SHA-256 必须为 `1a3352a6a5556bb8f605e783dd8f66981d5d3436d0af33c053fcb618424e9366`。已有输出或载荷摘要变化会拒绝覆盖；正式发布使用 clean 已提交源码。更多测试及各自前置输入见 [tests/](tests/)，构包与清单入口见 [tools/](tools/)；客户端专用 TLS 验收见[客户端接入](docs/client-integration.md#验证层次)。测试不会代为安装宿主或 IAM，包检查不执行安装及 SQL。

</details>

## 订单履约与买家领取

服务目录只登记 `sand_license.fulfillment.write/read` 和 `sand_license.membership.read`，不自动创建组织、凭证、全能管理员或服务 grant。已有商城或业务系统先在 SandIAM 登记服务调用方并取得对应正式服务授权，再接入下面的履约接口。“商品映射”决定渠道 SKU 发放哪个套餐，不配置商城或支付。

SandLicense 不提供淘宝、京东或支付平台直接连接器。买家领取页负责领取已通过可信履约事件发放的资格，不创建订单或处理支付。

<details>
<summary>业务系统开发者：最小履约示例</summary>

部署管理员在 `channels` 固定每个渠道的产品、组织、应用、环境、workload client 和允许的 `subject_codes`；编号来自 SandIAM 已登记对象，不能从请求 header 猜组织。

履约请求发送 `Authorization: Bearer` 正式上下文，字段来源为渠道订单项、付款周期及后台配置的 SKU 映射。每次请求带稳定 `request_id`；同一次付款的 `order_item_id/payment_cycle_id` 不变，不同 `event_id` 也不会重复发放。

```http
POST /api/sand-license/v1/fulfillments/events
Content-Type: application/json
Authorization: Bearer <SandIAM 签发的服务上下文>

{"product_code":"desktop","channel_code":"store","event_id":"paid-001","event_type":"paid","order_id":"order-001","order_item_id":"item-001","payment_cycle_id":"one-time-001","sku_code":"annual","quantity":1,"request_id":"delivery-001"}
```

成功响应沿宿主 `code/message/data`，首次桌面履约的 `data.claim_credentials` 为 `{claim_id,claim_credential}` 数组。普通重试只返回状态和 ID。交付领取链接 `/license/claim#id=<claim_id>&credential=<claim_credential>`，凭证留在 fragment，页面将其转 POST 并清除 fragment；订单号本身不能领码。领取返回的一次明码丢失，买家明确重签会撤销旧未兑码；已兑换不能重签。若领取凭证丢失，由可信业务显式调用 `POST /api/sand-license/v1/fulfillments/claims/{id}/credential/reissue`（同正式履约写授权，含产品、渠道、`request_id`），新凭证只返回一次，旧凭证立即失效；这不会自动重签卡密或再发权益。

会员支付使用 `subject_code` 与明确的 `cycle_start_time/cycle_expire_time`（ISO UTC），首版数量只能为 1；通过 `GET /api/sand-license/v1/memberships/current` 查询，需配置渠道和主体范围及正式读授权。退款只撤销原付款来源，取消续期保留已付期限，部分退款返回 `SAND_LICENSE_PARTIAL_REFUND_UNSUPPORTED` 并要求人工处理。

</details>

## 安全与运行限制

新启动必须在线取得并验签租约，建议每五分钟续签；断网运行不能超过最后租约 `exp`。完整安装证明及续租协议见[客户端接入](docs/client-integration.md)。Redis 不可用时挑战限流明确拒绝。JWT 依赖必须来自该宿主标准 vendor，不支持自定义 vendor-dir；缺失或混用明确拒绝，不修改后台登录 JWT 配置。

部署还须确认真实宿主全局日志、反向代理和 APM 不记录卡密、领取凭证、安装 proof 或含秘密响应。插件自身不挂载 SystemLog，领域日志脱敏；这不代替宿主日志配置。隔离安装、业务及界面验收已完成，边界见[更新日志](CHANGELOG.md)；这不代表生产部署、客户应用改造或所有目标平台验收。

设备释放或重置须由有权限的使用者明确操作，重置需理由；旧设备立即不能续签，席位等最后租约到期才能重用。

问题反馈：[GitHub Issues](https://github.com/supdger/sand-license/issues)，请提供版本、操作及已脱敏错误，不附卡密、凭证或秘密响应。源码来源见[来源记录](SOURCE_OF_TRUTH.md)，开源许可见 [LICENSE](LICENSE)，第三方版本与许可见 [NOTICE](NOTICE)。

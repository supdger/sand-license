# 源码来源

`supdger/sand-license` 是 SandLicense 独立公共源码与 Release 仓库。宿主安装副本用于消费和验收，不作为并行开发来源。

0.1.0 初始源码来自 `sand_plugins/sand-license` 已完成隔离验收的冻结清单；该目录当时全部未跟踪，没有可拆分的 Git 提交历史，本仓初始提交是源码快照，不声称保留目录历史。冻结清单 SHA-256 为 `41f61b2fc75e08d169c86b39e3ac61398c4ba48cea922d390f485cc40c419dbc`，验收候选 ZIP SHA-256 为 `0a2f8105b5c505f10f1c5d409341c6aa5677291b8cf1c264f9e4770a4e6af575`。

独立发布适配仅涉及 README、更新日志、来源声明、构包参数、测试依赖路径和仓库维护入口；生产业务内核、接口、管理端、领取页、客户端实现、SQL、配置与载荷清单均保留冻结字节。正式 Release 从本仓已提交且 clean 的 revision 构建，工件摘要以对应 Release 为准。验收候选与正式 Release 的文档字节不同，不能混用工件摘要。

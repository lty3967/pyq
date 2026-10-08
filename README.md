# php版朋友圈开源源码

一个纯 PHP + MySQL 实现的「微信朋友圈」风格社交动态系统。支持发布图文与视频、点赞、
评论与回复、多用户注册、分类管理、违禁词与 IP 黑名单、SMTP 邮件通知与完整后台管理。

---

## 功能特性

**前台展示**

- 微信朋友圈风格时间线：封面、分类横滑筛选、九宫格图片、视频内嵌播放、定位、相对时间
- 右上角「拍照」入口（对齐微信朋友圈）：已登录直接进发布动态，未登录先去登录页
- 点赞 / 取消点赞、评论与多级回复、图片点击大图浏览与滑动切换
- 浏览量按「IP + 日期」去重统计
- 响应式布局，移动端优先

**用户中心**

- 注册 / 登录 / 退出，个人资料与头像、个性签名
- 第三方聚合登录（QQ / 微信快捷登录）
- 账号设置里可自行绑定 / 解绑快捷登录（用户名密码注册的用户也能开通）
- 发布与管理自己的动态（文字 + 图片或视频，支持草稿）
- 查看并审核别人对自己动态的评论，可回复

**后台管理**

- 控制台：数据概览、近 14 天趋势、存储占用、服务器信息
- 内容：发布 / 编辑动态、置顶、批量上下架与删除、分类管理
- 互动：评论审核 / 屏蔽 / 删除 / 回复、违禁词库、IP 黑名单、回溯扫描历史评论
- 用户：前台用户的启用 / 禁用 / 编辑 / 级联删除，列表可看快捷登录绑定状态
- 系统：站点设置、上传参数、安全策略、SMTP 发信、聚合登录、存储类型、操作日志、孤立文件清理

**存储与登录扩展（v1.3.3 新增）**

- **聚合登录**：后台可自由开启 / 关闭，填写聚合登录接口地址、应用 APPID、应用 APPKEY，并勾选开放的登录方式（QQ、微信）；
  - 接口地址**只填站点根地址即可**
- **多存储**：本地存储、阿里云 OSS、腾讯云 COS、华为云 OBS、又拍云、七牛云、通用 S3 兼容、WebDAV、OneDrive、OpenList(Alist) 共 10 种，按类型显示各自参数，支持「保存 / 连接测试 / 开通地址」
- 上传链路仍是「本地重编码 + 生成缩略图 → 推送远程 → 删除本地临时文件」，远端不可用时自动回退本地，不会让站点发不出图

**安全**

- 全站 PDO 参数化查询，无 SQL 拼接注入点
- 全站 CSRF Token 校验；会话 HttpOnly + SameSite + 严格模式 + UA 指纹绑定
- 登录失败锁定、多维度频率限制、密码 `password_hash` 存储与强度校验
- 上传文件 MIME/扩展名白名单、图片重编码剥离元数据、视频容器头校验、随机文件名
- 敏感配置（SMTP 密码、存储密钥、聚合登录 APPKEY）AES 加密存储
- 聚合登录用挂在 `redirect_uri` 上的一次性随机串（该协议无 `state`）做 CSRF 校验，用完即焚
- 操作日志覆盖登录、内容、审核、配置等关键动作

---

## 环境要求与依赖

### 运行环境

| 项目 | 要求 |
| --- | --- |
| PHP | ≥ 7.4（推荐 8.0+） |
| MySQL / MariaDB | ≥ 5.6（**使用点赞账号去重需 5.7+**，低版本自动降级） |
| Web 服务器 | Apache或Nginx |

### 必需的 PHP 扩展

| 扩展 | 用途 |
| --- | --- |
| `pdo_mysql` | 数据库访问 |
| `gd` | 图片重编码、缩略图 |
| `mbstring` | 多字节字符串（中文长度校验） |
| `json` | 配置与接口数据 |
| `fileinfo` | 视频 MIME 检测 |
| `openssl` | 敏感配置加密、SMTP SSL |

安装向导第 1 步会自动检测以上扩展，未通过会给出明确提示。

### 第三方依赖

**无。** 项目不使用 Composer 包，也不使用 npm 包，没有 `package.json` / `composer.json`，
也没有任何构建、编译、打包步骤 —— 上传源码到站点根目录即可运行。

唯一的外部资源是后台图标库 Lucide，通过 CDN 引入：

```html
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>
```

> 说明：CDN 不可达时**功能完全正常**，只是后台菜单图标不显示（菜单文字仍在）。
> 如需完全离线，把 `lucide.min.js` 下载到 `assets/js/` 并修改 4 处
> （`admin/inc/layout.php`、`admin/login.php`、`user/login.php`、`user/register.php`）的引用路径即可。

---
## 目录结构

```
.
├── index.php              前台首页（朋友圈时间线）
├── api.php                前台 AJAX 接口：点赞 / 评论 / 浏览上报
│
├── admin/                 后台管理（需登录）
│   ├── inc/layout.php     后台公共引导 + 页面骨架
│   ├── index.php          控制台
│   ├── post_edit.php      发布 / 编辑朋友圈
│   ├── posts.php          内容管理（批量操作）
│   ├── categories.php     分类管理
│   ├── comments.php       评论管理（审核 / 屏蔽 / 回复）
│   ├── badwords.php       违禁词、IP 黑名单、评论策略
│   ├── users.php          前台用户管理
│   ├── user_edit.php      编辑用户
│   ├── profile.php        管理员信息与改密
│   ├── mail.php           SMTP 配置与发信
│   ├── oauth.php          聚合登录（QQ / 微信快捷登录）设置
│   ├── storage.php        存储类型设置（本地 / 各类云存储）
│   ├── settings.php       站点 / 上传 / 安全设置与维护
│   ├── logs.php           操作日志
│   ├── upload.php         上传接口（JSON）
│   └── login.php logout.php
│
├── user/                  用户中心（需登录）
│   ├── layout.php         公共引导 + 页面骨架
│   ├── index.php          我的动态
│   ├── post.php           发布 / 编辑动态
│   ├── comments.php       收到的评论
│   ├── profile.php        账号设置与改密
│   ├── upload.php avatar_upload.php   上传接口（JSON）
│   ├── oauth.php oauth_callback.php   聚合登录跳转与回调
│   └── login.php register.php logout.php
│
├── includes/              核心代码（禁止 Web 直接访问）
│   ├── init.php           引导：常量、配置加载、会话、安全响应头
│   ├── db.php             PDO 单例、参数化查询封装、设置读写
│   ├── functions.php      通用函数（转义、输入、时间、分页、加解密、媒体地址）
│   ├── security.php       会话、CSRF、限流、登录锁定、密码、违禁词
│   ├── model.php          业务模型（动态、评论、点赞、统计）
│   ├── upload.php         上传处理（白名单、重编码、缩略图）
│   ├── storage.php        存储抽象与 10 种存储驱动（零依赖实现）
│   ├── oauth.php          聚合登录核心逻辑
│   ├── mailer.php         轻量 SMTP 发信实现
│   └── config.php         （安装程序生成，不在仓库中）
│
├── install/               安装向导（安装完成后请删除）
├── assets/                静态资源
│   ├── css/               style.css / admin.css / user.css / install.css
│   └── js/                app.js / admin.js / user.js / user-profile.js
├── data/                  运行时数据（会话、安装锁、错误日志）
├── uploads/               上传媒体（image / video / thumb）
│
├── upgrade.sql            表结构升级脚本
└── LICENSE                MIT
```

---

## 安装部署

### 一、准备

1. 在宝塔面板中创建一个网站和数据库（记录库名、用户名、密码）。
2. 把本仓库源码上传到站点根目录（或任意子目录，程序支持子目录部署）。

### 二、执行安装向导

浏览器访问站点，会自动跳转到安装向导，共 4 步：

| 步骤 | 内容 |
| --- | --- |
| 1. 环境检测 | 检查 PHP 版本、6 个必需扩展、目录可写权限 |
| 2. 数据库配置 | 填写地址、端口、库名、用户、密码、表前缀 |
| 3. 创建管理员 | 站点名称、管理员账号、密码、邮箱 |
| 4. 安装完成 | 提示删除 `install/` 目录 |

> 密码要求：至少 8 位，且包含大写字母、小写字母、数字、符号中的至少三类。

安装向导会自动完成：建表、写入默认配置、创建管理员账号、创建 4 个默认分类、
生成 `includes/config.php`（含随机 `AUTH_SALT`）、写入 `data/install.lock`。

### 三、安装后必做

1. **删除 `install/` 目录**（否则后台会持续显示安全风险提示）。
2. 访问 `你的域名/admin/login.php` 进入后台。

---

### 配置示例

**评论策略**（后台 → 违禁词设置）

| 项 | 说明 | 建议 |
| --- | --- | --- |
| 违禁词列表 | 每行一个，最多 2000 个 | 匹配时忽略大小写与空格，可拦截「违 禁 词」拆分绕过 |
| 命中处理方式 | 拒绝 / 打码 / 转待审 | 小站建议「拒绝」 |
| 评论需审核 | 开启后新评论默认待审 | 建议开启 |
| 发言间隔 | 秒 | 默认 30 |
| 每小时上限 | 条 | 默认 10 |

**SMTP 发信**（后台 → 发信功能）

| 项 | 示例 |
| --- | --- |
| 服务器 | `smtp.qq.com` |
| 端口 / 加密 | `465` + SSL，或 `587` + STARTTLS |
| 账号 | `123456@qq.com` |
| 密码 | QQ / 163 邮箱请填**授权码**，不是登录密码 |
| 发件地址 | 留空则同账号 |

**反向代理**（后台 → 站点设置 → 安全设置）

| 值 | 适用场景 |
| --- | --- |
| `1` 智能（默认） | 本机 / 内网 Nginx 反代。仅当直连来源是内网时才采信 `X-Forwarded-For`，防 IP 伪造 |
| `2` 始终信任 | Cloudflare、独立反代服务器（此时 `REMOTE_ADDR` 是对方公网 IP） |
| `0` 不信任 | 直连暴露，始终使用 `REMOTE_ADDR` |

---

## 常见问题

**Q：安装后访问首页提示「系统未安装或配置文件缺失」？**

A：`includes/config.php` 不存在，或 `install/` 已删除但安装未完成。重新上传
`install/` 目录并跑一遍向导。

**Q：想重新安装（重置）怎么办？**

A：删除 `includes/config.php` 与 `data/install.lock`，保留 `install/` 目录，重新访问即可。

**Q：忘记管理员密码？**

A：暂时没有找回功能。可在数据库中直接更新 `{前缀}admin` 表的 `password` 字段，
值为 `password_hash('新密码', PASSWORD_DEFAULT)` 的结果。

**Q：同一网络下多个人无法同时点赞？**

A：匿名访客按 IP 去重，同一出口 IP 只能点一次。让用户登录后点赞即可按账号去重
（需要 MySQL 5.7+ 以启用 `owner_key` 生成列；全新安装由安装向导自动建好，
旧站点请执行根目录 `upgrade.sql`，低版本数据库会自动降级为 IP 去重）。

**Q：上传图片失败 / 提示像素过大？**

A：像素上限按服务器 `memory_limit` 反推（约取其一半 / 4 字节）。提高 PHP
`memory_limit` 或在后台调小「图片最长边」。

**Q：后台图标不显示？**

A：图标库走 unpkg CDN，离线或网络受限时不加载。功能不受影响，也可本地化该文件。

**Q：如何迁移到新服务器？**

A：打包 `uploads/` 全部文件 + 导出数据库；新服务器上传源码、导入数据库、
把旧的 `includes/config.php` 复制过去（或重跑安装向导指向已导入的库）。

---

## 声明与许可

1. 此项目只能以自用学习为目的，不得用于商用及侵犯其他第三方的知识产权和其他合法权利。
2. 本项目为开源项目，自用或二开请保留原作者版权，非常感谢。

代码许可：**MIT**（见 `LICENSE`）。

觉得项目不错的话欢迎点个 Star ⭐


# 前台页面
<img width="1582" height="1250" alt="image" src="https://github.com/user-attachments/assets/3e162f69-77bc-4c16-83e6-5fcf5141b738" />

# 用户页面
<img width="1598" height="830" alt="image" src="https://github.com/user-attachments/assets/eee9aff7-bf91-46e2-acd0-ec317b4e49eb" />
<img width="1584" height="830" alt="image" src="https://github.com/user-attachments/assets/a0ba0f84-2810-42e2-8fb2-33f1031dfe54" />
<img width="1598" height="830" alt="image" src="https://github.com/user-attachments/assets/ff2aa287-c16e-47a8-9549-18e3e1fe61b3" />
<img width="1598" height="986" alt="image" src="https://github.com/user-attachments/assets/37c86083-3422-49ff-aa45-9fd8a0498637" />

# 后台页面
<img width="1582" height="1273" alt="image" src="https://github.com/user-attachments/assets/5edaf912-85a7-444b-ab95-340a086197c4" />
<img width="1598" height="984" alt="image" src="https://github.com/user-attachments/assets/8d2fdd44-26a3-4073-a15e-bc9675dc8db4" />
<img width="1598" height="984" alt="image" src="https://github.com/user-attachments/assets/ad6d9410-6e08-48dd-8862-4d753d422b81" />
<img width="1598" height="984" alt="image" src="https://github.com/user-attachments/assets/b1f00c9a-ecb3-41a5-bd4a-07534b7d1bf1" />
<img width="1598" height="984" alt="image" src="https://github.com/user-attachments/assets/e381b7ad-0862-4995-af20-bdcb5fd51674" />
<img width="1598" height="984" alt="image" src="https://github.com/user-attachments/assets/afe3cafb-6f99-4a3b-b4e1-741283c44ac1" />
<img width="1598" height="984" alt="image" src="https://github.com/user-attachments/assets/42de3657-efc3-4c9b-8339-03f6ded37976" />
<img width="1598" height="984" alt="image" src="https://github.com/user-attachments/assets/7a9818c4-ef51-4fbc-be5e-1bbc81456bf8" />
<img width="1577" height="936" alt="image" src="https://github.com/user-attachments/assets/205bec63-4974-4bc1-8f8f-75c40a8b6421" />
<img width="1575" height="984" alt="image" src="https://github.com/user-attachments/assets/2997fdbe-3862-41e5-8285-9b6f9cf1ff8b" />
<img width="1579" height="1101" alt="image" src="https://github.com/user-attachments/assets/3fd8d367-a467-4138-879f-5219e4245312" />
<img width="1576" height="1273" alt="image" src="https://github.com/user-attachments/assets/a503a9d3-f946-420f-aa5b-7822cba01b94" />
<img width="1593" height="1035" alt="image" src="https://github.com/user-attachments/assets/d0ff41a7-0fb2-4abc-af58-134d84b7c87f" />
<img width="1577" height="1035" alt="image" src="https://github.com/user-attachments/assets/ea1e5b48-221c-4d6b-b846-db3f11a7ede8" />

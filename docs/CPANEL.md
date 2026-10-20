# VeloraMeet — cPanel 上线清单

本地 smoke test 与 health API 已通过。上 cPanel 后**必须改配置**，否则无法运行。

## 上线前必改（最重要）

编辑服务器上的 `config/config.php`：

| 配置项 | 本地现在 | cPanel 必须改成 |
|--------|----------|-----------------|
| `base_url` | `http://localhost/project/Google_Meet` | 你的真实地址，例如 `https://你的域名.com` 或 `https://你的域名.com/velorameet`（**不要**末尾 `/`） |
| `db.host` | `127.0.0.1` | 通常仍是 `localhost`（以 cPanel MySQL 主机名为准） |
| `db.name` | `connectmeet` | cPanel 创建的完整库名（常带前缀，如 `user_velorameet`） |
| `db.user` / `db.pass` | 本地 root | cPanel 数据库用户名和密码 |
| `session.secure` | `false` | **`true`**（HTTPS 必须） |
| `security.allowed_origins` | localhost | 加上 `https://你的域名.com` |

## 部署步骤

1. **SSL**：在 cPanel 启用 HTTPS（Let's Encrypt），并强制跳转 HTTPS。  
   - 摄像头/麦克风在非 localhost 的 **HTTP 下会被浏览器禁止**。
2. **PHP**：MultiPHP Manager 选 **8.2+**（不要用 7.x）。
3. **数据库**：
   - MySQL® Databases 新建数据库 + 用户，并授权。
   - phpMyAdmin 选中该库 → 导入 `database/install_cpanel.sql`（**不要**用带 `CREATE DATABASE` 的本地版）。
4. **上传文件**：整包上传到 `public_html` 或子目录（如 `public_html/velorameet`）。
5. **配置**：上传后立刻改 `config/config.php`（见上表）。可用 `config.example.php` 对照。
6. **权限**：确保 `storage/` 与 `storage/logs/` 可写（通常 755/775）。
7. **健康检查**：浏览器打开  
   `https://你的域名/.../api/health.php`  
   应看到 `"database":"ok"`。
8. **可选 cron**（每 10 分钟）：  
   `php /home/你的用户/path/to/scripts/cleanup.php`

## 上线后自测（5 分钟）

1. 打开首页，无 PHP 报错  
2. New meeting → 进入 lobby  
3. 手机或另一个浏览器（无痕）用会议链接加入  
4. 两边开摄像头/麦克风，确认能看到对方  
5. 发一条聊天  

## 已知限制（不是配置错误）

- 只有 **STUN** 时，部分公司网络/对称 NAT 可能连不上对方视频 → 需要外接 **TURN**（见 `config.example.php` 注释）。
- 共享主机 **不能** 自建 WebSocket / Coturn / SFU；本项目用 HTTP 轮询 + mesh，小会议室可用。
- 字幕/录制是浏览器本地能力，不是服务器合成。

## 出问题先看

| 现象 | 原因 |
|------|------|
| health.php database error | `db.*` 写错或未导入表 |
| 页面 CSS/JS 404 | `base_url` 路径与实际上传目录不一致 |
| 进不去会 / Join 失败 | `base_url` 或 HTTPS / CSRF session（`session.secure`） |
| 摄像头黑/被拒 | 没用 HTTPS |
| 能进房但看不到对方 | 网络需 TURN；或防火墙 |
| 整站 500 | 旧 `.htaccess` 含 `php_value`（当前版本已去掉） |

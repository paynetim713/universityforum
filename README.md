# UKM Forum

给马来西亚国立大学（UKM）学生用的内部论坛，PHP + MySQL。学校课程作业，写完之后觉得挺好玩，留在这里。

只允许 `@siswa.ukm.edu.my` 邮箱注册（在 register.php 里用正则限的），相当于"准 UKM 内部圈"。

## 功能

- 注册 / 登录（密码 bcrypt 哈希存的，不是明文）
- 邮箱验证码（发送由 SMTP + 一个 Python 脚本完成）
- 发问题、回答、按学院（Faculty）标签
- 通知系统（有人回你帖子会提醒）
- 个人资料、上传头像
- 找回密码（重置码邮件）
- 敏感词审核（`CensorWords.txt`）
- 管理员后台（在 `admin/` 里）

## 跑起来

需要 LAMP / XAMPP 类环境（PHP 7.4+、MySQL 5.7+、Apache 或 Nginx）。

```bash
# 1. 配数据库
mysql -u root -p < schema.sql      # 如果你有 schema，没有的话第一次跑会建表

# 2. 改 includes/config.php 里的数据库连接参数

# 3. SMTP 配置改用环境变量(forgot_password.php / register.php 顶部)
export SMTP_HOST=smtp.gmail.com
export SMTP_PORT=465
export SMTP_USER=you@gmail.com
export SMTP_PASS=你的Gmail应用专用密码

# 4. 把整个目录扔到 web root
```

如果是用 PHP 内置 server 测试：

```bash
php -S localhost:8000
```

## 已知的烂部分

- 原本 SMTP 凭据是写死在代码里的（**别学**），现在改成环境变量了。
- 上传文件夹 `uploads/` 没做后缀白名单严格校验，部署到公网前要再加一层。
- `nginx.htaccess` 和 `.htaccess` 都在仓库里，不同部署环境记得只用一个。
- 大部分页面是直出 HTML，没做模板分层（PHP 课作业的典型结构）。
- 学校服务器密码 `123456` 留在 `includes/config.php` 里——这是当时学校实验室分配的演示账号，已废弃。换部署环境前必须改。

## 协议

MIT。

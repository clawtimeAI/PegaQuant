# Git 与远程推送

本地已初始化仓库并已有一次提交；**尚未绑定远程**，需按下面任一路径完成一次配置，之后即可随时推送。

## 1. 在托管平台新建空仓库

在 **GitHub / Gitee / GitLab** 等平台新建仓库（**不要**勾选「用 README 初始化」，避免首次推送冲突）。

记下克隆地址，例如：

- HTTPS：`https://github.com/你的用户名/Quant.git` 或 `https://gitee.com/你的用户名/quant.git`
- SSH：`git@github.com:你的用户名/Quant.git`

## 2. 绑定远程（只需做一次）

在项目根目录 `d:\TraeProjects\Quant` 执行（把地址换成你的）：

```powershell
cd d:\TraeProjects\Quant
git remote add origin https://你的托管平台/你的用户名/你的仓库名.git
```

若已误加错误地址，可删掉重来：

```powershell
git remote remove origin
git remote add origin <正确地址>
```

查看：

```powershell
git remote -v
```

## 3. 首次推送

当前默认分支为 `master`。若平台默认分支是 `main`，任选其一：

**A. 保持 master，推送 master：**

```powershell
git push -u origin master
```

**B. 改名为 main 再推送（与 GitHub 默认一致）：**

```powershell
git branch -M main
git push -u origin main
```

之后在平台将「默认分支」设为 `main` 即可。

## 4. 身份校验

- **HTTPS**：首次 `git push` 会提示登录；GitHub 需用 **Personal Access Token** 代替密码。
- **SSH**：本机生成密钥并把公钥添加到平台后，远程地址改用 `git@...` 形式。

## 5. 日常：提交并推送

```powershell
cd d:\TraeProjects\Quant
git status
git add -A
git commit -m "说明本次改动"
git push
```

或使用根目录脚本（见下）。

## 6. 脚本 `scripts/sync-git.ps1`

在仓库根目录执行：

```powershell
.\scripts\sync-git.ps1 "本次提交说明"
```

无参数时使用带时间戳的默认说明。若没有任何变更，脚本会提示并退出。

---

**说明**：根目录 `.gitignore` 已排除 `node_modules`、`.next`、`.env` 等；勿将密钥提交进仓库。

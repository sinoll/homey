<?php

declare(strict_types=1);

use App\Storage\StoragePath;

session_name('yii_cloud_installer');
session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
]);
session_start();

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$root = dirname(__DIR__, 2);
require_once $root . '/src/Storage/StoragePath.php';
$runtime = $root . '/runtime';
$configFile = $runtime . '/installation.php';
$publicRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$errors = [];
$notice = '';
$installed = is_file($configFile);
$csrfToken = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$defaultStoragePath = $root . '/data/storage';
$form = [
    'host' => '127.0.0.1',
    'port' => '3306',
    'database' => '',
    'username' => '',
    'storage' => $defaultStoragePath,
];

$requiredExtensions = ['pdo_mysql'];
$missingExtensions = array_values(array_filter(
    $requiredExtensions,
    static fn(string $extension): bool => !extension_loaded($extension),
));
if ($missingExtensions !== []) {
    $errors[] = 'PHP 缺少数据库扩展：' . implode(', ', $missingExtensions) . '。请联系服务器管理员启用。';
}
if (!is_file($root . '/vendor/autoload.php')) {
    $errors[] = '没有找到 vendor/autoload.php。请先将项目 PHP 依赖文件一同上传到服务器。';
}
foreach (['001_create_cloud_tables.sql', '002_add_folders_and_shares.sql', '003_add_file_trash.sql'] as $migration) {
    if (!is_file($root . '/database/migrations/' . $migration)) {
        $errors[] = '缺少数据库迁移文件：' . $migration;
    }
}
if ($installed) {
    $notice = '此程序已经安装。为安全起见，请从网站中删除 public/install/ 安装目录。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        $errors[] = '页面已过期或验证失败，请刷新后重试。';
    }

    foreach (['host', 'port', 'database', 'username', 'storage'] as $field) {
        $value = $_POST[$field] ?? '';
        $form[$field] = is_string($value) ? trim($value) : '';
    }
    $password = $_POST['password'] ?? '';
    $password = is_string($password) ? $password : '';
    $action = $_POST['action'] ?? '';
    $action = is_string($action) ? $action : '';

    if (preg_match('/\A[A-Za-z0-9_.:-]{1,255}\z/', $form['host']) !== 1) {
        $errors[] = '请填写有效的数据库主机地址。';
    }
    if (preg_match('/\A[0-9]{1,5}\z/', $form['port']) !== 1 || (int) $form['port'] < 1 || (int) $form['port'] > 65535) {
        $errors[] = '数据库端口必须是 1 到 65535 之间的数字。';
    }
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $form['database']) !== 1) {
        $errors[] = '数据库名只能包含字母、数字和下划线，长度不超过 64 个字符。';
    }
    if (preg_match('/\A[A-Za-z0-9_.@-]{1,128}\z/', $form['username']) !== 1) {
        $errors[] = '请填写有效的数据库用户名。';
    }
    if (!StoragePath::isAbsolute($form['storage'])) {
        $errors[] = '文件存储目录必须填写绝对路径。';
    }
    if (!in_array($action, ['test', 'install'], true)) {
        $errors[] = '无效的安装操作，请刷新页面重试。';
    }

    if ($errors === []) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $form['host'],
                (int) $form['port'],
                $form['database'],
            );
            $database = new PDO(
                $dsn,
                $form['username'],
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
            $database->query('SELECT 1')->fetchColumn();
        } catch (PDOException $exception) {
            $errors[] = '数据库连接失败。请检查主机、端口、数据库名、用户名、密码和数据库授权。';
            error_log('Yii Cloud installer database connection failed: ' . $exception->getMessage());
        }

        if ($errors === [] && $action === 'test') {
            $notice = '数据库连接成功。请确认该数据库账号有创建表、修改表和创建索引的权限。';
        }

        if ($errors === [] && $action === 'install') {
            try {
                $storagePath = rtrim($form['storage'], DIRECTORY_SEPARATOR);
                if ($storagePath === '') {
                    $storagePath = DIRECTORY_SEPARATOR;
                }
                if (!is_dir($storagePath) && !mkdir($storagePath, 0770, true) && !is_dir($storagePath)) {
                    throw new RuntimeException('无法创建文件存储目录。');
                }
                $resolvedStoragePath = realpath($storagePath);
                if ($resolvedStoragePath === false || !is_writable($resolvedStoragePath)) {
                    throw new RuntimeException('文件存储目录不可写，请调整目录权限或填写其他路径。');
                }
                $resolvedStoragePath = rtrim($resolvedStoragePath, DIRECTORY_SEPARATOR);
                if (
                    $resolvedStoragePath === ''
                    || $resolvedStoragePath === DIRECTORY_SEPARATOR
                    || StoragePath::isWithin($resolvedStoragePath, $publicRoot)
                ) {
                    throw new RuntimeException('文件存储目录不能位于网站 public 目录中。');
                }

                if (!is_dir($runtime) && !mkdir($runtime, 0770, true) && !is_dir($runtime)) {
                    throw new RuntimeException('无法创建 runtime 配置目录。请为 PHP 运行用户授予应用目录或 runtime 目录写权限。');
                }
                if (!is_writable($runtime)) {
                    throw new RuntimeException('runtime 目录不可写。请将 runtime 目录所有者或写权限设置为 PHP/Apache 运行用户后重试。');
                }

                $tableCount = (int) $database->query(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()',
                )->fetchColumn();
                if ($tableCount === 0) {
                    foreach (['001_create_cloud_tables.sql', '002_add_folders_and_shares.sql', '003_add_file_trash.sql'] as $migration) {
                        $sql = file_get_contents($root . '/database/migrations/' . $migration);
                        if (!is_string($sql)) {
                            throw new RuntimeException('无法读取数据库迁移文件：' . $migration);
                        }
                        $statements = preg_split('/;\s*/', $sql, -1, PREG_SPLIT_NO_EMPTY);
                        foreach ($statements ?: [] as $statement) {
                            if (trim($statement) !== '') {
                                $database->exec($statement);
                            }
                        }
                    }
                } else {
                    $schemaCheck = $database->query(
                        "SELECT
                            (SELECT COUNT(*) FROM information_schema.tables
                             WHERE table_schema = DATABASE()
                               AND table_name IN ('users', 'access_tokens', 'files', 'folders', 'shares')) AS table_count,
                            (SELECT COUNT(*) FROM information_schema.columns
                             WHERE table_schema = DATABASE() AND table_name = 'files'
                               AND column_name IN ('folder_id', 'updated_at', 'deleted_at')) AS file_columns,
                            (SELECT COUNT(*) FROM information_schema.statistics
                             WHERE table_schema = DATABASE() AND table_name = 'files'
                               AND index_name = 'files_user_deleted_index') AS trash_index",
                    )->fetch(PDO::FETCH_ASSOC);
                    if (
                        !is_array($schemaCheck)
                        || (int) $schemaCheck['table_count'] !== 5
                        || (int) $schemaCheck['file_columns'] !== 3
                        || (int) $schemaCheck['trash_index'] < 1
                    ) {
                        throw new RuntimeException(
                            '数据库不是空库，并且未检测到完整的 Yii Cloud 表结构。请备份数据库后，先手动按顺序执行迁移 001、002、003。',
                        );
                    }
                }

                $settings = [
                    'APP_ENV' => 'prod',
                    'APP_DEBUG' => 'false',
                    'DB_HOST' => $form['host'],
                    'DB_PORT' => (string) (int) $form['port'],
                    'DB_NAME' => $form['database'],
                    'DB_USER' => $form['username'],
                    'DB_PASSWORD' => $password,
                    'APP_STORAGE_PATH' => $resolvedStoragePath,
                ];
                $temporaryConfig = tempnam($runtime, '.installation-');
                if ($temporaryConfig === false) {
                    throw new RuntimeException('无法创建安装配置文件。');
                }
                $configContents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($settings, true) . ";\n";
                if (file_put_contents($temporaryConfig, $configContents, LOCK_EX) === false) {
                    @unlink($temporaryConfig);
                    throw new RuntimeException('无法写入安装配置文件。');
                }
                if (!chmod($temporaryConfig, 0600)) {
                    @unlink($temporaryConfig);
                    throw new RuntimeException('无法保护安装配置文件权限，请检查 PHP chmod() 权限设置。');
                }
                if (!rename($temporaryConfig, $configFile)) {
                    @unlink($temporaryConfig);
                    throw new RuntimeException('无法保存安装配置文件。请检查 runtime 目录权限。');
                }
                if (!chmod($configFile, 0600)) {
                    @unlink($configFile);
                    throw new RuntimeException('无法将数据库配置设为仅文件所有者可读；安装未完成。');
                }

                $installed = true;
                $notice = '安装完成。现在可以打开云盒首页并注册第一个账户。请立即删除 public/install/ 安装目录。';
            } catch (Throwable $exception) {
                $errors[] = $exception instanceof PDOException
                    ? '执行数据库迁移失败。请检查数据库账号是否有建表和修改表权限，并查看服务器 PHP 错误日志。'
                    : $exception->getMessage();
                error_log('Yii Cloud installer failed: ' . $exception->getMessage());
            }
        }
    }
}

$canInstall = $missingExtensions === []
    && is_file($root . '/vendor/autoload.php')
    && count(array_filter(
        ['001_create_cloud_tables.sql', '002_add_folders_and_shares.sql', '003_add_file_trash.sql'],
        static fn(string $migration): bool => is_file($root . '/database/migrations/' . $migration),
    )) === 3;
$homeUrl = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/install/index.php')), '/\\') . '/';
if ($homeUrl === '//') {
    $homeUrl = '/';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>云盒安装向导</title>
    <style>
        :root{font-family:system-ui,-apple-system,"Microsoft YaHei",sans-serif;color:#20263a;background:#f4f6fb;font-synthesis:none;text-rendering:optimizeLegibility}
        *{box-sizing:border-box}body{margin:0;min-height:100vh;padding:32px 16px}.card{width:min(100%,680px);margin:0 auto;padding:32px;border:1px solid #e5e9f2;border-radius:18px;background:#fff;box-shadow:0 18px 60px #28325a12}
        h1{margin:0;font-size:26px}.intro{margin:10px 0 24px;color:#71798c;font-size:14px;line-height:1.7}.step{display:flex;gap:14px;margin:18px 0;padding:15px;border:1px solid #edf0f6;border-radius:11px;background:#fbfcff}.step b{display:grid;width:27px;height:27px;flex:0 0 auto;place-items:center;border-radius:50%;color:#fff;background:#4d61e7}.step div{color:#656d80;font-size:13px;line-height:1.7}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:15px}.field{display:grid;gap:7px;margin-bottom:15px}.field.full{grid-column:1/-1}.field label{font-size:12px;font-weight:650;color:#50596e}.field input{width:100%;height:43px;padding:0 12px;border:1px solid #dfe4ef;border-radius:8px;color:#333c52;background:#fff;font:inherit;font-size:13px}.field input:focus{outline:3px solid #4d61e722;border-color:#7180eb}.help{margin:0;color:#8991a2;font-size:11px;line-height:1.6}
        .buttons{display:flex;justify-content:flex-end;gap:10px;margin-top:8px}.button{min-height:42px;padding:0 17px;border:1px solid #dfe4ef;border-radius:8px;color:#555e72;background:#fff;font:inherit;font-size:13px;font-weight:650;cursor:pointer}.button.primary{border-color:#4d61e7;color:#fff;background:#4d61e7}.button:disabled{opacity:.55;cursor:not-allowed}.notice,.error{margin:15px 0;padding:12px 14px;border-radius:9px;font-size:13px;line-height:1.6}.notice{border:1px solid #ccebdc;color:#25794f;background:#f1fbf5}.error{border:1px solid #f3d3d1;color:#a63e38;background:#fff5f4}.error ul{margin:6px 0 0;padding-left:20px}.warning{margin:18px 0 0;padding:12px;border-radius:8px;color:#895f14;background:#fff8e8;font-size:12px;line-height:1.6}.footer{margin-top:22px;color:#9aa1b0;text-align:center;font-size:11px}.link{color:#4d61e7;text-decoration:none}.locked{text-align:center;padding:24px 0}
        @media(max-width:560px){body{padding:14px 10px}.card{padding:23px 18px}.grid{grid-template-columns:1fr;gap:0}.field.full{grid-column:auto}.buttons{flex-direction:column-reverse}.button{width:100%}h1{font-size:22px}}
    </style>
</head>
<body>
<main class="card">
    <h1>云盒安装向导</h1>
    <p class="intro">连接已有的 MySQL 数据库，创建数据表并配置安全的文件存储位置。此向导不安装或修改服务器软件。</p>

    <?php if ($installed): ?>
        <div class="locked">
            <p class="notice"><?= $escape($notice) ?></p>
            <p><a class="link" href="<?= $escape($homeUrl) ?>">打开云盒首页</a></p>
        </div>
    <?php else: ?>
        <div class="step"><b>1</b><div><strong>准备数据库</strong><br>请先在主机面板创建一个空的 MySQL 数据库和专用用户，并授予该用户创建、修改表和索引的权限。</div></div>
        <div class="step"><b>2</b><div><strong>填写连接信息</strong><br>可先测试连接；正式安装时会自动按顺序执行项目的数据库迁移。</div></div>
        <div class="step"><b>3</b><div><strong>开始使用</strong><br>安装完成后配置 HTTPS，再打开首页注册账户。数据库密码会保存在网站目录之外的 runtime 配置文件中。</div></div>

        <?php if ($notice !== ''): ?><p class="notice"><?= $escape($notice) ?></p><?php endif; ?>
        <?php if ($errors !== []): ?>
            <div class="error"><strong>需要处理以下问题：</strong><ul><?php foreach ($errors as $error): ?><li><?= $escape($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <div class="grid">
                <div class="field"><label for="host">数据库主机</label><input id="host" name="host" value="<?= $escape($form['host']) ?>" required maxlength="255" placeholder="127.0.0.1"></div>
                <div class="field"><label for="port">数据库端口</label><input id="port" name="port" type="number" min="1" max="65535" value="<?= $escape($form['port']) ?>" required></div>
                <div class="field"><label for="database">数据库名</label><input id="database" name="database" value="<?= $escape($form['database']) ?>" required maxlength="64" pattern="[A-Za-z0-9_]+" autocomplete="off"></div>
                <div class="field"><label for="username">数据库用户名</label><input id="username" name="username" value="<?= $escape($form['username']) ?>" required maxlength="128" autocomplete="username"></div>
                <div class="field full"><label for="password">数据库密码</label><input id="password" name="password" type="password" required autocomplete="new-password"></div>
                <div class="field full"><label for="storage">文件存储绝对路径</label><input id="storage" name="storage" value="<?= $escape($form['storage']) ?>" required maxlength="1024" autocomplete="off"><p class="help">默认 <?= $escape($defaultStoragePath) ?>。目录必须位于 public 网站目录之外，并且 PHP/Apache 运行用户有写权限。</p></div>
            </div>
            <p class="warning">请在 HTTPS 下运行此向导，不要把数据库密码通过未加密的 HTTP 网络提交。安装成功后务必删除 public/install/ 目录。</p>
            <div class="buttons">
                <button class="button" type="submit" name="action" value="test" <?= !$canInstall ? 'disabled' : '' ?>>测试数据库连接</button>
                <button class="button primary" type="submit" name="action" value="install" <?= !$canInstall ? 'disabled' : '' ?>>开始安装</button>
            </div>
        </form>
    <?php endif; ?>
    <p class="footer">云盒 · 私人文件云</p>
</main>
</body>
</html>

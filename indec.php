<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();

if (!file_exists(__DIR__ . '/vendor/autoload.php') && !class_exists('phpseclib3\Net\SFTP')) {
    define('NO_SFTP_LIB', true);
} else {
    define('NO_SFTP_LIB', false);
    if (file_exists(__DIR__ . '/vendor/autoload.php')) {
        require_once __DIR__ . '/vendor/autoload.php';
    }
}

define('SSH_HOST', '149.8隐私4');//需要修改
define('SSH_PORT', 22);
define('SSH_USER', 'root');
define('SSH_PASS', 'vwnq隐私746');//需要修改

// 邮件配置
define('SMTP_HOST', 'smtp.163.com');//可以修改，我选的网易云
define('SMTP_PORT', '465');
define('SMTP_USER', 'igghgf隐私om');//需要修改
define('SMTP_PWD', 'DPVMBLcxY6');//授权码，需要修改
define('MAIL_API_URL', 'https://ldg.205320.xyz/api/mail2.php');

// 管理员配置
define('ADMIN_USER', 'yu隐私22');//需要修改
define('ADMIN_PASS', 'y隐私2');//需要修改

// 数据文件路径（网站服务器本地文件）
define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('OWNERS_FILE', DATA_DIR . '/owners.json');
define('ADMIN_LOGINS_FILE', DATA_DIR . '/admin_logins.json');
define('VERIFY_CODES_FILE', DATA_DIR . '/verify_codes.json');

// 确保数据目录存在
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// 初始化数据文件
if (!file_exists(USERS_FILE)) file_put_contents(USERS_FILE, json_encode([]));
if (!file_exists(OWNERS_FILE)) file_put_contents(OWNERS_FILE, json_encode([]));
if (!file_exists(ADMIN_LOGINS_FILE)) file_put_contents(ADMIN_LOGINS_FILE, json_encode([]));
if (!file_exists(VERIFY_CODES_FILE)) file_put_contents(VERIFY_CODES_FILE, json_encode([]));

// 数据操作函数
function getData($file) {
    $content = file_get_contents($file);
    return json_decode($content, true) ?: [];
}

function saveData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 获取IP地址
function getClientIP() {
    $ip = $_SERVER['REMOTE_ADDR'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    return $ip;
}

// 获取IP地理位置（使用淘宝IP库）
function getIPLocation($ip) {
    if ($ip == '127.0.0.1' || $ip == '::1') {
        return ['code' => 0, 'data' => ['city' => '本地']];
    }
    
    $url = "http://ip.taobao.com/outGetIpInfo?ip={$ip}&accessKey=alibaba-inc";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        return json_decode($response, true);
    }
    
    // 备用接口
    $url2 = "https://ipapi.co/{$ip}/json/";
    $ch2 = curl_init($url2);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
    $response2 = curl_exec($ch2);
    curl_close($ch2);
    
    if ($response2) {
        $data = json_decode($response2, true);
        return ['code' => 0, 'data' => ['city' => $data['city'] ?? '未知']];
    }
    
    return ['code' => -1, 'data' => ['city' => '未知']];
}

// 检查是否在长沙
function isInChangsha() {
    $ip = getClientIP();
    $location = getIPLocation($ip);
    
    if ($location['code'] == 0) {
        $city = $location['data']['city'] ?? '';
        // 支持长沙的各种叫法
        $changshaNames = ['长沙', '长沙市', 'Changsha', 'changsha'];
        foreach ($changshaNames as $name) {
            if (strpos($city, $name) !== false) {
                return ['allowed' => true, 'city' => $city, 'ip' => $ip];
            }
        }
        return ['allowed' => false, 'city' => $city, 'ip' => $ip];
    }
    
    return ['allowed' => false, 'city' => '未知', 'ip' => $ip];
}

function uploadFileViaSSH($localFile, $remotePath, $remoteDir) {
    if (NO_SFTP_LIB) {
        return ['code' => -1, 'error' => '请先安装phpseclib：在服务器上执行 "composer require phpseclib/phpseclib:^3.0" 或下载放置到vendor目录'];
    }
    
    try {
        $sftp = new \phpseclib3\Net\SFTP(SSH_HOST, SSH_PORT);
        if (!$sftp->login(SSH_USER, SSH_PASS)) {
            return ['code' => -1, 'error' => 'SFTP登录失败，请检查密码或服务器是否允许root登录'];
        }
        
        if (!$sftp->is_dir($remoteDir)) {
            $sftp->mkdir($remoteDir, -1, true);
        }
        
        if ($sftp->put($remotePath, $localFile, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            return ['code' => 0, 'msg' => '上传成功', 'path' => $remotePath];
        } else {
            return ['code' => -1, 'error' => '文件上传失败'];
        }
    } catch (Exception $e) {
        return ['code' => -1, 'error' => 'SFTP错误: ' . $e->getMessage()];
    }
}

// ========== 用户系统 API ==========

// 注册用户（QQ+邮箱）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'register_user') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $qq = $input['qq'] ?? '';
    $email = $input['email'] ?? '';
    
    if (!preg_match('/^\d{5,15}$/', $qq)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误']);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['code' => -1, 'error' => '邮箱格式错误']);
        exit;
    }
    
    $users = getData(USERS_FILE);
    
    // 检查是否已存在
    foreach ($users as $user) {
        if ($user['qq'] === $qq) {
            echo json_encode(['code' => -1, 'error' => '该QQ号已注册']);
            exit;
        }
        if ($user['email'] === $email) {
            echo json_encode(['code' => -1, 'error' => '该邮箱已被使用']);
            exit;
        }
    }
    
    // 创建用户
    $userId = uniqid('user_');
    $users[$userId] = [
        'id' => $userId,
        'qq' => $qq,
        'email' => $email,
        'type' => 'qq',
        'created_at' => time()
    ];
    
    saveData(USERS_FILE, $users);
    
    echo json_encode(['code' => 0, 'msg' => '注册成功', 'userId' => $userId]);
    exit;
}

// 发送验证码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'send_verify_code') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $qq = $input['qq'] ?? '';
    $email = $input['email'] ?? '';
    
    if (!preg_match('/^\d{5,15}$/', $qq)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误']);
        exit;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['code' => -1, 'error' => '邮箱格式错误']);
        exit;
    }
    
    // 生成6位随机验证码
    $verifyCode = sprintf("%06d", mt_rand(0, 999999));
    
    // 存储验证码到服务器文件
    $codes = getData(VERIFY_CODES_FILE);
    $codes[$qq] = [
        'code' => $verifyCode,
        'email' => $email,
        'time' => time(),
        'attempts' => 0
    ];
    saveData(VERIFY_CODES_FILE, $codes);
    
    // 构建邮件内容
    $subject = '点击邮件查看';
    $content = "尊敬的客户，您好，欢迎加入我们，您的验证码是：{$verifyCode}，我们目前正在筹划开VPS云服务，您有建议致电3870149287@qq.com";
    
    // 调用邮件API
    $apiUrl = MAIL_API_URL . '?' . http_build_query([
        'smtp_host' => SMTP_HOST,
        'smtp_port' => SMTP_PORT,
        'smtp_user' => SMTP_USER,
        'smtp_pwd' => SMTP_PWD,
        'to' => $email,
        'subject' => $subject,
        'content' => $content,
        'from_name' => '猎户代挂'
    ]);
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo json_encode(['code' => -1, 'error' => '邮件发送失败: ' . curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result && isset($result['code']) && $result['code'] == 200) {
        echo json_encode(['code' => 0, 'msg' => '验证码已发送至您的邮箱']);
    } else {
        echo json_encode(['code' => -1, 'error' => '邮件发送失败: ' . ($result['msg'] ?? '未知错误')]);
    }
    exit;
}

// 验证验证码并登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'verify_code') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $qq = $input['qq'] ?? '';
    $email = $input['email'] ?? '';
    $code = $input['code'] ?? '';
    
    $codes = getData(VERIFY_CODES_FILE);
    
    // 检查验证码是否存在
    if (!isset($codes[$qq])) {
        echo json_encode(['code' => -1, 'error' => '验证码不存在，请重新获取']);
        exit;
    }
    
    $verifyData = $codes[$qq];
    
    // 检查验证码是否过期（5分钟）
    if ((time() - $verifyData['time']) > 300) {
        unset($codes[$qq]);
        saveData(VERIFY_CODES_FILE, $codes);
        echo json_encode(['code' => -1, 'error' => '验证码已过期，请重新获取']);
        exit;
    }
    
    // 验证邮箱是否匹配
    if ($verifyData['email'] !== $email) {
        echo json_encode(['code' => -1, 'error' => '邮箱不匹配']);
        exit;
    }
    
    // 验证验证码
    if ($verifyData['code'] !== $code) {
        $codes[$qq]['attempts']++;
        // 超过5次尝试则删除
        if ($codes[$qq]['attempts'] >= 5) {
            unset($codes[$qq]);
        }
        saveData(VERIFY_CODES_FILE, $codes);
        echo json_encode(['code' => -1, 'error' => '验证码错误']);
        exit;
    }
    
    // 验证成功，清除验证码
    unset($codes[$qq]);
    saveData(VERIFY_CODES_FILE, $codes);
    
    // 查找或创建用户
    $users = getData(USERS_FILE);
    $userId = null;
    $userData = null;
    
    foreach ($users as $uid => $user) {
        if ($user['qq'] === $qq) {
            $userId = $uid;
            $userData = $user;
            break;
        }
    }
    
    // 如果用户不存在，自动注册
    if (!$userId) {
        $userId = uniqid('user_');
        $userData = [
            'id' => $userId,
            'qq' => $qq,
            'email' => $email,
            'type' => 'qq',
            'created_at' => time()
        ];
        $users[$userId] = $userData;
        saveData(USERS_FILE, $users);
    }
    
    // 生成登录token
    $token = bin2hex(random_bytes(32));
    $_SESSION['user_token'] = $token;
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_type'] = 'qq';
    $_SESSION['login_time'] = time();
    
    echo json_encode([
        'code' => 0,
        'msg' => '登录成功',
        'token' => $token,
        'user' => [
            'id' => $userId,
            'qq' => $qq,
            'email' => $email,
            'type' => 'qq',
            'isAdmin' => false
        ]
    ]);
    exit;
}

// 管理员登录（带地区验证）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'admin_login') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    // 验证账号密码
    if ($username !== ADMIN_USER || $password !== ADMIN_PASS) {
        echo json_encode(['code' => -1, 'error' => '账号或密码错误']);
        exit;
    }
    
    // 记录登录日志（可选，保留记录功能）
    $ip = getClientIP();
    $logins = getData(ADMIN_LOGINS_FILE);
    $logins[] = [
        'time' => time(),
        'ip' => $ip,
        'status' => 'success'
    ];
    saveData(ADMIN_LOGINS_FILE, $logins);

    
    // 生成登录token
    $token = bin2hex(random_bytes(32));
    $_SESSION['user_token'] = $token;
    $_SESSION['user_type'] = 'admin';
    $_SESSION['admin_user'] = $username;
    $_SESSION['login_time'] = time();
    
    echo json_encode([
        'code' => 0,
        'msg' => '管理员登录成功',
        'token' => $token,
        'user' => [
            'id' => $username,
            'type' => 'admin',
            'isAdmin' => true,
            'displayName' => '管理员',
            'location' => $locationCheck['city']
        ]
    ]);
    exit;
}

// 检查登录状态
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_session') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['code' => -1, 'logged_in' => false]);
        exit;
    }
    
    // 检查session是否过期（24小时）
    if ((time() - $_SESSION['login_time']) > 86400) {
        session_destroy();
        echo json_encode(['code' => -1, 'logged_in' => false, 'error' => '会话已过期']);
        exit;
    }
    
    if ($_SESSION['user_type'] === 'admin') {
        echo json_encode([
            'code' => 0,
            'logged_in' => true,
            'user' => [
                'id' => $_SESSION['admin_user'],
                'type' => 'admin',
                'isAdmin' => true,
                'displayName' => '管理员'
            ]
        ]);
    } else {
        $users = getData(USERS_FILE);
        $userId = $_SESSION['user_id'];
        if (isset($users[$userId])) {
            $user = $users[$userId];
            echo json_encode([
                'code' => 0,
                'logged_in' => true,
                'user' => [
                    'id' => $userId,
                    'qq' => $user['qq'],
                    'email' => $user['email'],
                    'type' => 'qq',
                    'isAdmin' => false
                ]
            ]);
        } else {
            session_destroy();
            echo json_encode(['code' => -1, 'logged_in' => false]);
        }
    }
    exit;
}

// 退出登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'logout') {
    header('Content-Type: application/json');
    session_destroy();
    echo json_encode(['code' => 0, 'msg' => '已退出登录']);
    exit;
}

// ========== 账号归属管理 API ==========

// 获取所有用户列表（管理员用）
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_users') {
    header('Content-Type: application/json');
    
    // 验证管理员权限
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        echo json_encode(['code' => -1, 'error' => '无权访问']);
        exit;
    }
    
    $users = getData(USERS_FILE);
    $userList = [];
    
    foreach ($users as $uid => $user) {
        $userList[] = [
            'id' => $uid,
            'qq' => $user['qq'],
            'email' => $user['email']
        ];
    }
    
    echo json_encode(['code' => 0, 'data' => $userList]);
    exit;
}

// 分配账号给用户（管理员用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'assign_account') {
    header('Content-Type: application/json');
    
    // 验证管理员权限
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        echo json_encode(['code' => -1, 'error' => '无权操作']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $account = $input['account'] ?? '';
    $userId = $input['userId'] ?? '';
    
    if (!preg_match('/^\d{5,15}$/', $account)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误']);
        exit;
    }
    
    if (empty($userId)) {
        echo json_encode(['code' => -1, 'error' => '请选择用户']);
        exit;
    }
    
    // 验证用户是否存在
    $users = getData(USERS_FILE);
    if (!isset($users[$userId])) {
        echo json_encode(['code' => -1, 'error' => '用户不存在']);
        exit;
    }
    
    // 保存归属关系
    $owners = getData(OWNERS_FILE);
    $owners[$account] = [
        'userId' => $userId,
        'qq' => $users[$userId]['qq'],
        'assigned_by' => 'admin',
        'assigned_at' => time()
    ];
    saveData(OWNERS_FILE, $owners);
    
    echo json_encode([
        'code' => 0,
        'msg' => '分配成功',
        'account' => $account,
        'owner' => $users[$userId]['qq']
    ]);
    exit;
}

// 解除账号归属（管理员用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'unassign_account') {
    header('Content-Type: application/json');
    
    // 验证管理员权限
    if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
        echo json_encode(['code' => -1, 'error' => '无权操作']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $account = $input['account'] ?? '';
    
    if (!preg_match('/^\d{5,15}$/', $account)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误']);
        exit;
    }
    
    $owners = getData(OWNERS_FILE);
    if (isset($owners[$account])) {
        unset($owners[$account]);
        saveData(OWNERS_FILE, $owners);
        echo json_encode(['code' => 0, 'msg' => '已解除归属']);
    } else {
        echo json_encode(['code' => -1, 'error' => '该账号未被分配']);
    }
    exit;
}

// 获取账号归属信息
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_account_owner') {
    header('Content-Type: application/json');
    
    $account = $_GET['account'] ?? '';
    
    if (!preg_match('/^\d{5,15}$/', $account)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误']);
        exit;
    }
    
    $owners = getData(OWNERS_FILE);
    
    if (isset($owners[$account])) {
        echo json_encode([
            'code' => 0,
            'has_owner' => true,
            'owner' => $owners[$account]
        ]);
    } else {
        echo json_encode([
            'code' => 0,
            'has_owner' => false
        ]);
    }
    exit;
}

// 获取当前登录用户的所有归属账号
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_my_accounts') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['code' => -1, 'error' => '未登录']);
        exit;
    }
    
    $owners = getData(OWNERS_FILE);
    $myAccounts = [];
    
    if ($_SESSION['user_type'] === 'admin') {
        // 管理员返回所有有归属的账号
        foreach ($owners as $account => $ownerData) {
            $myAccounts[] = [
                'account' => $account,
                'ownerId' => $ownerData['userId'],
                'ownerQQ' => $ownerData['qq']
            ];
        }
    } else {
        // 普通用户只返回自己的
        $userId = $_SESSION['user_id'];
        foreach ($owners as $account => $ownerData) {
            if ($ownerData['userId'] === $userId) {
                $myAccounts[] = [
                    'account' => $account,
                    'assigned_at' => $ownerData['assigned_at']
                ];
            }
        }
    }
    
    echo json_encode(['code' => 0, 'data' => $myAccounts]);
    exit;
}

// 检查当前用户是否有权限操作某账号
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_permission') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['code' => -1, 'error' => '未登录', 'allowed' => false]);
        exit;
    }
    
    $account = $_GET['account'] ?? '';
    
    if (!preg_match('/^\d{5,15}$/', $account)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误', 'allowed' => false]);
        exit;
    }
    
    // 管理员有所有权限
    if ($_SESSION['user_type'] === 'admin') {
        echo json_encode(['code' => 0, 'allowed' => true, 'is_admin' => true]);
        exit;
    }
    
    // 检查是否是自己的账号
    $owners = getData(OWNERS_FILE);
    $userId = $_SESSION['user_id'];
    
    if (isset($owners[$account]) && $owners[$account]['userId'] === $userId) {
        echo json_encode(['code' => 0, 'allowed' => true, 'is_owner' => true]);
    } else {
        echo json_encode(['code' => 0, 'allowed' => false, 'reason' => '无权操作']);
    }
    exit;
}

// ========== 原有功能 API ==========

// 获取词库文件列表（通过SSH）
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_lexicon_files') {
    header('Content-Type: application/json');
    
    if (NO_SFTP_LIB) {
        echo json_encode(['code' => -1, 'error' => '缺少phpseclib库']);
        exit;
    }
    
    // 验证登录
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['code' => -1, 'error' => '未登录']);
        exit;
    }
    
    $account = $_GET['account'] ?? '';
    if (!preg_match('/^\d{5,15}$/', $account)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误']);
        exit;
    }
    
    // 验证权限
    if ($_SESSION['user_type'] !== 'admin') {
        $owners = getData(OWNERS_FILE);
        $userId = $_SESSION['user_id'];
        if (!isset($owners[$account]) || $owners[$account]['userId'] !== $userId) {
            echo json_encode(['code' => -1, 'error' => '无权访问此账号']);
            exit;
        }
    }
    
    $remoteDir = "/root/Secluded-x64-linux/lexicon/{$account}";
    
    try {
        $sftp = new \phpseclib3\Net\SFTP(SSH_HOST, SSH_PORT);
        if (!$sftp->login(SSH_USER, SSH_PASS)) {
            echo json_encode(['code' => -1, 'error' => 'SSH登录失败']);
            exit;
        }
        
        if (!$sftp->is_dir($remoteDir)) {
            echo json_encode(['code' => 0, 'data' => []]);
            exit;
        }
        
        $files = $sftp->nlist($remoteDir);
        $result = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            if (pathinfo($file, PATHINFO_EXTENSION) === 'txt' || 
                pathinfo($file, PATHINFO_EXTENSION) === 'json' ||
                pathinfo($file, PATHINFO_EXTENSION) === 'xml') {
                $result[] = $file;
            }
        }
        
        echo json_encode(['code' => 0, 'data' => $result]);
    } catch (Exception $e) {
        echo json_encode(['code' => -1, 'error' => $e->getMessage()]);
    }
    exit;
}

// 调用API添加主人
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'add_master_api') {
    header('Content-Type: application/json');
    
    // 验证登录
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['code' => -1, 'error' => '未登录']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $file = $input['file'] ?? '';
    $id = $input['id'] ?? '';
    $sign = '2f988e3f015593df4bf6cef38f67cb9087d1beeaa2a73f8fb442dbb0c0d56a32';
    
    if (empty($file) || empty($id)) {
        echo json_encode(['code' => -1, 'error' => '缺少参数']);
        exit;
    }
    
    // 验证文件路径合法性
    if (strpos($file, '..') !== false || strpos($file, '/root/Secluded-x64-linux/lexicon/') !== 0) {
        echo json_encode(['code' => -1, 'error' => '非法文件路径']);
        exit;
    }
    
    $postData = json_encode([
        'sign' => $sign,
        'file' => $file,
        'id' => $id
    ]);
    
    $ch = curl_init('http://1需要修改为你的sec服务器IP0/lex-list-add-master');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postData),
        'User-Agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (Chrome/143.0.0.0 Mobile Safari/537.36 EdgA/143.0.0.0)',
        'Origin: http://1需要修改为你的sec服务器IP0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo json_encode(['code' => -1, 'error' => 'cURL错误: ' . curl_error($ch)]);
        curl_close($ch);
        exit;
    }
    
    curl_close($ch);
    
    $data = json_decode($response, true);
    if ($data !== null) {
        echo json_encode(['code' => 0, 'data' => $data, 'httpCode' => $httpCode]);
    } else {
        echo json_encode(['code' => 0, 'raw' => $response, 'httpCode' => $httpCode]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'upload_lexicon') {
    header('Content-Type: application/json');
    
    // 验证登录
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['code' => -1, 'error' => '未登录']);
        exit;
    }
    
    if (NO_SFTP_LIB) {
        echo json_encode(['code' => -1, 'error' => '缺少phpseclib库。请SSH连接你的服务器，执行：cd /www/wwwroot/' . $_SERVER['HTTP_HOST'] . ' && composer require phpseclib/phpseclib:^3.0']);
        exit;
    }
    
    if (!isset($_FILES['file'])) {
        echo json_encode(['code' => -1, 'error' => '没有选择文件']);
        exit;
    }
    
    $account = $_POST['account'] ?? '';
    if (!preg_match('/^\d{5,15}$/', $account)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误']);
        exit;
    }
    
    // 验证权限
    if ($_SESSION['user_type'] !== 'admin') {
        $owners = getData(OWNERS_FILE);
        $userId = $_SESSION['user_id'];
        if (!isset($owners[$account]) || $owners[$account]['userId'] !== $userId) {
            echo json_encode(['code' => -1, 'error' => '无权操作此账号']);
            exit;
        }
    }
    
    $localFile = $_FILES['file']['tmp_name'];
    $fileName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $_FILES['file']['name']);
    $remoteDir = "/root/Secluded-x64-linux/lexicon/{$account}";
    $remotePath = "{$remoteDir}/{$fileName}";
    
    $result = uploadFileViaSSH($localFile, $remotePath, $remoteDir);
    echo json_encode($result);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'list_lexicon') {
    header('Content-Type: application/json');
    
    // 验证登录
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['code' => -1, 'error' => '未登录']);
        exit;
    }
    
    if (NO_SFTP_LIB) {
        echo json_encode(['code' => 0, 'data' => []]);
        exit;
    }
    
    $account = $_GET['account'] ?? '';
    if (!preg_match('/^\d{5,15}$/', $account)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误']);
        exit;
    }
    
    // 验证权限
    if ($_SESSION['user_type'] !== 'admin') {
        $owners = getData(OWNERS_FILE);
        $userId = $_SESSION['user_id'];
        if (!isset($owners[$account]) || $owners[$account]['userId'] !== $userId) {
            echo json_encode(['code' => -1, 'error' => '无权访问此账号']);
            exit;
        }
    }
    
    $remoteDir = "/root/Secluded-x64-linux/lexicon/{$account}";
    
    try {
        $sftp = new \phpseclib3\Net\SFTP(SSH_HOST, SSH_PORT);
        if (!$sftp->login(SSH_USER, SSH_PASS)) {
            echo json_encode(['code' => -1, 'error' => '登录失败']);
            exit;
        }
        
        if (!$sftp->is_dir($remoteDir)) {
            echo json_encode(['code' => 0, 'data' => []]);
            exit;
        }
        
        $files = $sftp->nlist($remoteDir);
        $result = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $stat = $sftp->stat($remoteDir . '/' . $file);
            $result[] = [
                'name' => $file,
                'size' => $stat['size'] ?? 0,
                'time' => $stat['mtime'] ?? time()
            ];
        }
        
        echo json_encode(['code' => 0, 'data' => $result]);
    } catch (Exception $e) {
        echo json_encode(['code' => -1, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'delete_lexicon') {
    header('Content-Type: application/json');
    
    // 验证登录
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['code' => -1, 'error' => '未登录']);
        exit;
    }
    
    if (NO_SFTP_LIB) {
        echo json_encode(['code' => -1, 'error' => '缺少phpseclib库']);
        exit;
    }
    
    $account = $_POST['account'] ?? '';
    $fileName = $_POST['filename'] ?? '';
    
    // 验证权限
    if ($_SESSION['user_type'] !== 'admin') {
        $owners = getData(OWNERS_FILE);
        $userId = $_SESSION['user_id'];
        if (!isset($owners[$account]) || $owners[$account]['userId'] !== $userId) {
            echo json_encode(['code' => -1, 'error' => '无权操作此账号']);
            exit;
        }
    }
    
    $remotePath = "/root/Secluded-x64-linux/lexicon/{$account}/{$fileName}";
    
    try {
        $sftp = new \phpseclib3\Net\SFTP(SSH_HOST, SSH_PORT);
        if (!$sftp->login(SSH_USER, SSH_PASS)) {
            echo json_encode(['code' => -1, 'error' => '登录失败']);
            exit;
        }
        
        if ($sftp->delete($remotePath)) {
            echo json_encode(['code' => 0, 'msg' => '删除成功']);
        } else {
            echo json_encode(['code' => -1, 'error' => '删除失败']);
        }
    } catch (Exception $e) {
        echo json_encode(['code' => -1, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'check_exchange_permission') {
    header('Content-Type: application/json');
    
    // 验证登录
    if (!isset($_SESSION['user_token'])) {
        echo json_encode(['code' => -1, 'error' => '未登录']);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $exchangeId = $input['exchangeId'] ?? '';
    
    if (empty($exchangeId) || !is_numeric($exchangeId)) {
        echo json_encode(['code' => -1, 'error' => '无效的兑换ID']);
        exit;
    }
    
    try {
        $sftp = new \phpseclib3\Net\SFTP(SSH_HOST, SSH_PORT);
        if (!$sftp->login(SSH_USER, SSH_PASS)) {
            echo json_encode(['code' => -1, 'error' => '服务器连接失败']);
            exit;
        }
        
        $remotePath = "/root/Secluded-x64-linux/data/reward_plan/{$exchangeId}.json";
        $content = $sftp->get($remotePath);
        
        if ($content === false) {
            echo json_encode(['code' => -1, 'error' => '兑换记录不存在']);
            exit;
        }
        
        $data = json_decode($content, true);
        if (!$data) {
            echo json_encode(['code' => -1, 'error' => '兑换记录数据错误']);
            exit;
        }
        
        $targetAccount = $data['account'] ?? '';
        
        if (empty($targetAccount)) {
            echo json_encode(['code' => -1, 'error' => '无法获取目标QQ号']);
            exit;
        }
        
        // 管理员有所有权限
        if ($_SESSION['user_type'] === 'admin') {
            echo json_encode([
                'code' => 0, 
                'allowed' => true, 
                'targetAccount' => $targetAccount,
                'reason' => '管理员权限'
            ]);
            exit;
        }
        
        // 检查是否是账号所有者
        $owners = getData(OWNERS_FILE);
        $userId = $_SESSION['user_id'];
        
        if (isset($owners[$targetAccount]) && $owners[$targetAccount]['userId'] === $userId) {
            echo json_encode([
                'code' => 0, 
                'allowed' => true, 
                'targetAccount' => $targetAccount,
                'reason' => '账号归属当前用户'
            ]);
        } else if (!isset($owners[$targetAccount])) {
            echo json_encode([
                'code' => -1, 
                'allowed' => false, 
                'targetAccount' => $targetAccount,
                'error' => '该账号尚未被认领，请先扫码登录认领此账号'
            ]);
        } else {
            $ownerQQ = $owners[$targetAccount]['qq'];
            echo json_encode([
                'code' => -1, 
                'allowed' => false, 
                'targetAccount' => $targetAccount,
                'error' => "该账号归属 {$ownerQQ}，您无权为此账号兑换授权"
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['code' => -1, 'error' => '检查失败: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'check_account_exists') {
    header('Content-Type: application/json');
    
    $account = $_GET['account'] ?? '';
    
    if (!preg_match('/^\d{5,15}$/', $account)) {
        echo json_encode(['code' => -1, 'error' => 'QQ号格式错误']);
        exit;
    }
    
    try {
        $res = file_get_contents('http://1需要修改为你的sec服务器IP0/uin-list-get');
        $data = json_decode($res, true);
        
        $exists = false;
        if ($data['code'] === 0 && is_array($data['data'])) {
            foreach ($data['data'] as $acc) {
                if ($acc['account'] == $account) {
                    $exists = true;
                    break;
                }
            }
        }
        
        echo json_encode(['code' => 0, 'exists' => $exists]);
        
    } catch (Exception $e) {
        echo json_encode(['code' => -1, 'error' => '检查失败: ' . $e->getMessage()]);
    }
    exit;
}

// 代理请求到后端API
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['path'])) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Content-Type: application/json');
    
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $targetHost = 'http://1需要修改为你的sec服务器IP0';
    $path = $_GET['path'];
    $url = $targetHost . $path;
    $postData = file_get_contents('php://input');

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'application/json'),
        'Content-Length: ' . strlen($postData)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        http_response_code(500);
        echo json_encode(['code' => -1, 'error' => '代理请求失败: ' . curl_error($ch)]);
        curl_close($ch);
        exit;
    }

    curl_close($ch);
    http_response_code($httpCode);
    echo $response;
    exit;
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>猎户代挂 - SEC框架管理系统</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); min-height: 100vh; }
        .glass { background: rgba(30, 41, 59, 0.8); backdrop-filter: blur(12px); border: 1px solid rgba(148, 163, 184, 0.1); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37); }
        .neon-text { text-shadow: 0 0 20px rgba(56, 189, 248, 0.5); }
        .account-card { transition: all 0.3s ease; background: linear-gradient(145deg, rgba(30, 41, 59, 0.9) 0%, rgba(15, 23, 42, 0.9) 100%); border: 1px solid rgba(56, 189, 248, 0.1); }
        .account-card:hover { transform: translateY(-4px); box-shadow: 0 12px 40px rgba(56, 189, 248, 0.15); border-color: rgba(56, 189, 248, 0.3); }
        .account-card.no-permission { opacity: 0.6; filter: grayscale(0.8); border-color: rgba(239, 68, 68, 0.2); }
        .loading-spinner { border: 3px solid rgba(30, 41, 59, 0.8); border-top: 3px solid #38bdf8; border-radius: 50%; width: 24px; height: 24px; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .modal-enter { animation: modalEnter 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        @keyframes modalEnter { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .pulse-dot { width: 8px; height: 8px; background: #22c55e; border-radius: 50%; box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); animation: pulse-green 2s infinite; }
        @keyframes pulse-green { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); } }
        .btn-primary { background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); transition: all 0.3s; border: 1px solid rgba(56, 189, 248, 0.3); }
        .btn-primary:hover { box-shadow: 0 0 25px rgba(14, 165, 233, 0.4); transform: translateY(-2px); }
        .btn-secondary { background: rgba(51, 65, 85, 0.8); border: 1px solid rgba(71, 85, 105, 0.5); transition: all 0.2s; }
        .btn-secondary:hover { background: rgba(71, 85, 105, 0.9); transform: translateY(-1px); }
        .btn-cyan { background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); transition: all 0.3s; border: 1px solid rgba(6, 182, 212, 0.3); }
        .btn-cyan:hover { box-shadow: 0 0 25px rgba(6, 182, 212, 0.4); transform: translateY(-2px); }
        .btn-purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); transition: all 0.3s; border: 1px solid rgba(139, 92, 246, 0.3); }
        .btn-purple:hover { box-shadow: 0 0 25px rgba(139, 92, 246, 0.4); transform: translateY(-2px); }
        .btn-pink { background: linear-gradient(135deg, #ec4899 0%, #db2777 100%); transition: all 0.3s; border: 1px solid rgba(236, 72, 153, 0.3); }
        .btn-pink:hover { box-shadow: 0 0 25px rgba(236, 72, 153, 0.4); transform: translateY(-2px); }
        .btn-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); transition: all 0.3s; border: 1px solid rgba(16, 185, 129, 0.3); }
        .btn-green:hover { box-shadow: 0 0 25px rgba(16, 185, 129, 0.4); transform: translateY(-2px); }
        .btn-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); transition: all 0.3s; border: 1px solid rgba(239, 68, 68, 0.3); }
        .btn-red:hover { box-shadow: 0 0 25px rgba(239, 68, 68, 0.4); transform: translateY(-2px); }
        .btn-orange { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); transition: all 0.3s; border: 1px solid rgba(249, 115, 22, 0.3); }
        .btn-orange:hover { box-shadow: 0 0 25px rgba(249, 115, 22, 0.4); transform: translateY(-2px); }
        .admin-badge { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); box-shadow: 0 0 15px rgba(245, 158, 11, 0.4); }
        .user-badge { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(100px); background: rgba(15, 23, 42, 0.95); border: 1px solid rgba(56, 189, 248, 0.2); padding: 12px 24px; border-radius: 12px; color: white; font-weight: 500; z-index: 1000; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); backdrop-filter: blur(10px); box-shadow: 0 10px 40px rgba(0,0,0,0.4); }
        .toast.show { transform: translateX(-50%) translateY(0); }
        .toast.success { border-left: 4px solid #22c55e; }
        .toast.error { border-left: 4px solid #ef4444; }
        .toast.info { border-left: 4px solid #3b82f6; }
        .toast.warning { border-left: 4px solid #f59e0b; }
        .tab-active { border-bottom: 2px solid #38bdf8; color: #38bdf8; }
        .tab-inactive { color: #64748b; }
        .tab-inactive:hover { color: #94a3b8; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #0f172a; }
        ::-webkit-scrollbar-thumb { background: #334155; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #475569; }
        .permission-overlay { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.6); display: flex; align-items: center; justify-content: center; border-radius: 0.75rem; z-index: 10; }
        .upload-area { border: 2px dashed rgba(56, 189, 248, 0.3); transition: all 0.3s; }
        .upload-area:hover { border-color: rgba(56, 189, 248, 0.6); background: rgba(56, 189, 248, 0.05); }
        .upload-area.dragover { border-color: #38bdf8; background: rgba(56, 189, 248, 0.1); }
        .lexicon-item { transition: all 0.2s; }
        .lexicon-item:hover { background: rgba(56, 189, 248, 0.1); }
        .selftouch-on { background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important; }
        .selftouch-off { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%) !important; }
        .file-select-item { transition: all 0.2s; cursor: pointer; }
        .file-select-item:hover { background: rgba(56, 189, 248, 0.15); border-color: rgba(56, 189, 248, 0.4); }
        .file-select-item.selected { background: rgba(56, 189, 248, 0.25); border-color: #38bdf8; }
        .verify-input { letter-spacing: 0.5em; font-weight: bold; text-align: center; }
        .user-item { transition: all 0.2s; cursor: pointer; }
        .user-item:hover { background: rgba(56, 189, 248, 0.1); }
        .user-item.selected { background: rgba(56, 189, 248, 0.2); border-color: #38bdf8; }
    </style>
</head>
<body class="text-slate-200">

    <!-- 授权模态框 -->
    <div id="authModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/90 backdrop-blur-sm hidden">
        <div class="glass rounded-2xl p-6 max-w-md w-full mx-4 modal-enter">
            <div class="text-center mb-6">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gradient-to-br from-cyan-400 to-blue-600 flex items-center justify-center text-2xl font-bold text-white shadow-lg shadow-cyan-500/30">
                    L
                </div>
                <h2 class="text-3xl font-bold bg-gradient-to-r from-cyan-400 to-blue-500 bg-clip-text text-transparent neon-text">猎户代挂</h2>
                <p class="text-slate-400 mt-2 text-sm">SEC 框架管理系统</p>
            </div>
            
            <div class="flex mb-6 border-b border-slate-700">
                <button onclick="switchLoginTab('qq')" id="tab-qq" class="flex-1 pb-3 text-sm font-medium tab-active transition">QQ号登录</button>
                <button onclick="switchLoginTab('admin')" id="tab-admin" class="flex-1 pb-3 text-sm font-medium tab-inactive transition">管理员登录</button>
            </div>

            <!-- QQ登录 - 步骤1：输入QQ和邮箱 -->
            <div id="login-qq-step1" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">主人 QQ 号</label>
                    <input type="number" id="qqInput" class="w-full bg-slate-800/50 border border-slate-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 transition" placeholder="请输入QQ号">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">验证邮箱</label>
                    <input type="email" id="emailInput" class="w-full bg-slate-800/50 border border-slate-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 transition" placeholder="请输入邮箱地址">
                    <p class="text-xs text-slate-500 mt-1">验证码将发送至该邮箱</p>
                </div>
                <button onclick="sendVerifyCode()" id="sendCodeBtn" class="w-full btn-primary text-white font-semibold py-3 rounded-lg shadow-lg shadow-cyan-500/25">
                    获取验证码
                </button>
            </div>

            <!-- QQ登录 - 步骤2：输入验证码 -->
            <div id="login-qq-step2" class="space-y-4 hidden">
                <div class="text-center mb-4">
                    <p class="text-sm text-slate-400">验证码已发送至</p>
                    <p class="text-cyan-400 font-medium" id="verifyEmailDisplay"></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">验证码</label>
                    <input type="text" id="verifyCodeInput" maxlength="6" class="verify-input w-full bg-slate-800/50 border border-slate-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 transition text-2xl tracking-widest" placeholder="000000">
                    <p class="text-xs text-slate-500 mt-1 text-center">有效期5分钟</p>
                </div>
                <div class="flex gap-3">
                    <button onclick="backToStep1()" class="flex-1 btn-secondary text-white font-semibold py-3 rounded-lg">
                        返回
                    </button>
                    <button onclick="verifyAndLogin()" id="verifyBtn" class="flex-1 btn-primary text-white font-semibold py-3 rounded-lg shadow-lg shadow-cyan-500/25">
                        验证登录
                    </button>
                </div>
                <button onclick="resendCode()" id="resendBtn" class="w-full text-sm text-slate-400 hover:text-cyan-400 transition" disabled>
                    重新发送 (<span id="countdown">60</span>s)
                </button>
            </div>

            <!-- 管理员登录 -->
            <div id="login-admin" class="space-y-4 hidden">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">管理员账号</label>
                    <input type="text" id="adminUser" class="w-full bg-slate-800/50 border border-slate-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 transition" placeholder="管理员用户名">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">密码</label>
                    <input type="password" id="adminPass" class="w-full bg-slate-800/50 border border-slate-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 transition" placeholder="密码">
                </div>
                <button onclick="loginWithAdmin()" class="w-full btn-primary text-white font-semibold py-3 rounded-lg shadow-lg shadow-cyan-500/25">
                    管理员登录
                </button>
                <div class="p-3 bg-amber-500/10 border border-amber-500/30 rounded-lg">
                    <p class="text-xs text-amber-400 text-center">⚠️ 管理员登录仅限长沙地区</p>
                </div>
            </div>
        </div>
    </div>

    <!-- 扫码登录模态框 -->
    <div id="loginModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/95 backdrop-blur-sm hidden p-4">
        <div class="glass rounded-2xl p-6 max-w-sm w-full border border-cyan-500/20 modal-enter relative">
            <button onclick="closeLoginModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
            <div class="text-center mb-6">
                <h3 id="loginTitle" class="text-xl font-bold text-white mb-1">扫码登录</h3>
                <p class="text-sm text-slate-400" id="loginSubtitle">正在准备...</p>
            </div>
            <div id="loginContent" class="text-center py-4">
                <div class="loading-spinner mx-auto mb-4"></div>
                <p class="text-slate-400">正在连接服务器...</p>
            </div>
            <div id="loginStatus" class="mt-4 p-3 bg-slate-800/50 rounded-lg text-sm text-slate-300 hidden border border-slate-700"></div>
        </div>
    </div>

    <!-- 词库管理模态框 -->
    <div id="lexiconModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/95 backdrop-blur-sm hidden p-4">
        <div class="glass rounded-2xl p-6 max-w-2xl w-full border border-purple-500/20 modal-enter relative max-h-[90vh] overflow-y-auto">
            <button onclick="closeLexiconModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
            
            <div class="text-center mb-6">
                <h3 class="text-2xl font-bold text-white mb-1 flex items-center justify-center gap-2">
                    <svg class="w-6 h-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    词库管理
                </h3>
                <p class="text-sm text-slate-400" id="lexiconAccountTitle">选择账号上传词库</p>
                <?php if (NO_SFTP_LIB): ?>
                <div class="mt-3 p-3 bg-red-500/10 border border-red-500/30 rounded-lg text-left">
                    <p class="text-red-400 text-sm font-bold mb-2">⚠️ 缺少必要组件</p>
                    <p class="text-slate-400 text-xs mb-2">需要使用 phpseclib 库来实现SFTP上传</p>
                    <div class="bg-slate-900/50 p-2 rounded text-xs font-mono text-green-400">
                        cd /www/wwwroot/<?php echo $_SERVER['HTTP_HOST']; ?> <br>
                        composer require phpseclib/phpseclib:^3.0
                    </div>
                    <p class="text-slate-500 text-xs mt-2">如果没有composer，请<a href="https://github.com/phpseclib/phpseclib/releases" target="_blank" class="text-cyan-400">下载源码</a>解压到 vendor 目录</p>
                </div>
                <?php else: ?>
                <p class="text-xs text-green-400 mt-2">✅ phpseclib 已加载，可以使用SFTP</p>
                <?php endif; ?>
            </div>

            <div id="lexiconContent" <?php echo NO_SFTP_LIB ? 'style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-300 mb-2">选择目标机器人</label>
                    <select id="lexiconAccountSelect" class="w-full bg-slate-800/50 border border-slate-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-400/20 transition">
                        <option value="">请选择账号...</option>
                    </select>
                </div>

                <div class="upload-area rounded-xl p-8 text-center mb-6 cursor-pointer" id="uploadArea" onclick="document.getElementById('fileInput').click()">
                    <input type="file" id="fileInput" class="hidden" accept=".txt,.json,.xml" onchange="handleFileSelect(this)">
                    <svg class="w-12 h-12 mx-auto mb-3 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                    <p class="text-purple-400 font-medium mb-1">点击或拖拽上传词库文件</p>
                    <p class="text-xs text-slate-500">支持 .txt .json .xml 格式</p>
                    <p class="text-xs text-slate-600 mt-2" id="selectedFileName"></p>
                </div>

                <div id="uploadProgress" class="hidden mb-6">
                    <div class="flex justify-between text-sm text-slate-400 mb-2">
                        <span>上传进度</span>
                        <span id="progressText">0%</span>
                    </div>
                    <div class="w-full bg-slate-700 rounded-full h-2">
                        <div id="progressBar" class="bg-purple-500 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                </div>

                <button onclick="uploadLexicon()" id="uploadBtn" class="w-full btn-purple text-white font-semibold py-3 rounded-lg shadow-lg shadow-purple-500/25 mb-6 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    上传词库文件 (SFTP直传)
                </button>

                <div class="border-t border-slate-700 pt-6">
                    <h4 class="text-sm font-medium text-slate-300 mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                        当前词库列表
                    </h4>
                    <div id="lexiconList" class="space-y-2 max-h-60 overflow-y-auto">
                        <div class="text-center py-4 text-slate-500 text-sm">请先选择账号</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 添加主人模态框 -->
    <div id="addMasterModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/95 backdrop-blur-sm hidden p-4">
        <div class="glass rounded-2xl p-6 max-w-md w-full border border-orange-500/30 modal-enter relative max-h-[90vh] overflow-y-auto">
            <button onclick="closeAddMasterModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
            
            <div class="text-center mb-6">
                <div class="w-14 h-14 mx-auto mb-3 rounded-full bg-gradient-to-br from-orange-400 to-amber-600 flex items-center justify-center shadow-lg shadow-orange-500/30">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                </div>
                <h3 class="text-xl font-bold text-white mb-1">添加词库主人</h3>
                <p class="text-sm text-slate-400" id="addMasterSubtitle">账号: <span class="text-orange-400 font-mono" id="addMasterAccount"></span></p>
            </div>

            <div id="addMasterStep1">
                <p class="text-sm text-slate-300 mb-3 font-medium">步骤 1/2：选择词库文件</p>
                <div id="lexiconFileList" class="space-y-2 max-h-48 overflow-y-auto mb-4">
                    <div class="text-center py-8">
                        <div class="loading-spinner mx-auto mb-3"></div>
                        <p class="text-slate-500 text-sm">正在通过SSH读取文件列表...</p>
                    </div>
                </div>
                <p class="text-xs text-slate-500 text-center">通过SSH连接服务器读取 <code class="bg-slate-800 px-1.5 py-0.5 rounded text-slate-400">/root/Secluded-x64-linux/lexicon/</code> 目录</p>
            </div>

            <div id="addMasterStep2" class="hidden">
                <p class="text-sm text-slate-300 mb-3 font-medium">步骤 2/2：输入主人ID</p>
                <div class="mb-4">
                    <label class="block text-xs text-slate-400 mb-1">已选择的文件</label>
                    <div id="selectedFileDisplay" class="bg-slate-800/50 border border-slate-600 rounded-lg px-3 py-2 text-sm text-cyan-400 font-mono break-all"></div>
                </div>
                <div class="mb-4">
                    <label class="block text-xs text-slate-400 mb-1">主人ID (任意内容)</label>
                    <input type="text" id="masterQQInput" class="w-full bg-slate-800/50 border border-slate-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-400/20 transition" placeholder="请输入主人ID（支持任意字符）">
                    <p class="text-xs text-slate-500 mt-1">此ID将被添加为该词库文件的主人</p>
                </div>
                
                <div class="flex gap-3">
                    <button onclick="backToStep1()" class="flex-1 btn-secondary text-white py-2.5 rounded-lg text-sm font-medium">
                        返回重选
                    </button>
                    <button onclick="submitAddMaster()" class="flex-1 btn-orange text-white py-2.5 rounded-lg text-sm font-medium">
                        确认添加
                    </button>
                </div>
            </div>

            <div id="addMasterResult" class="hidden text-center py-4">
                <div id="addMasterResultIcon" class="text-5xl mb-3"></div>
                <p id="addMasterResultText" class="font-semibold mb-2"></p>
                <p id="addMasterResultDetail" class="text-sm text-slate-400 mb-4"></p>
                <button onclick="closeAddMasterModal()" class="btn-secondary text-white py-2 px-6 rounded-lg text-sm">
                    关闭
                </button>
            </div>
        </div>
    </div>

    <!-- 管理员分配账号模态框 -->
    <div id="assignModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/95 backdrop-blur-sm hidden p-4">
        <div class="glass rounded-2xl p-6 max-w-md w-full border border-amber-500/30 modal-enter relative max-h-[90vh] overflow-y-auto">
            <button onclick="closeAssignModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
            
            <div class="text-center mb-6">
                <div class="w-14 h-14 mx-auto mb-3 rounded-full bg-gradient-to-br from-amber-400 to-orange-600 flex items-center justify-center shadow-lg shadow-amber-500/30">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                </div>
                <h3 class="text-xl font-bold text-white mb-1">分配账号归属</h3>
                <p class="text-sm text-slate-400">将账号分配给指定用户</p>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1">QQ账号</label>
                    <input type="number" id="assignAccountInput" class="w-full bg-slate-800/50 border border-slate-600 rounded-lg px-4 py-3 text-white focus:outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-400/20 transition" placeholder="请输入要分配的QQ号">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-2">选择归属用户</label>
                    <div id="userList" class="space-y-2 max-h-48 overflow-y-auto bg-slate-800/30 rounded-lg p-2 border border-slate-700">
                        <div class="text-center py-4 text-slate-500 text-sm">加载用户列表...</div>
                    </div>
                </div>
                
                <div id="selectedUserDisplay" class="hidden p-3 bg-amber-500/10 border border-amber-500/30 rounded-lg">
                    <p class="text-xs text-slate-400">已选择用户</p>
                    <p class="text-amber-400 font-medium" id="selectedUserText"></p>
                </div>
                
                <button onclick="submitAssign()" id="assignBtn" class="w-full btn-amber text-white font-semibold py-3 rounded-lg shadow-lg shadow-amber-500/25 disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                    确认分配
                </button>
            </div>
        </div>
    </div>

    <!-- 兑换验证模态框 -->
    <div id="exchangeCheckModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/95 backdrop-blur-sm hidden p-4">
        <div class="glass rounded-2xl p-6 max-w-md w-full border border-orange-500/20 modal-enter relative">
            <button onclick="closeExchangeCheckModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
            <div class="text-center mb-6">
                <h3 class="text-xl font-bold text-white mb-1">兑换授权验证</h3>
                <p class="text-sm text-slate-400" id="exchangeCheckSubtitle">正在检查兑换权限...</p>
            </div>
            <div id="exchangeCheckContent" class="text-center py-4">
                <div class="loading-spinner mx-auto mb-4"></div>
                <p class="text-slate-400">正在验证该兑换是否属于您...</p>
            </div>
        </div>
    </div>

    <!-- 主应用 -->
    <div id="mainApp" class="hidden min-h-screen p-4 md:p-6 max-w-5xl mx-auto pb-24">
        <header class="glass rounded-2xl p-5 mb-6 flex flex-col md:flex-row justify-between items-center gap-4 sticky top-4 z-40">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-cyan-400 to-blue-600 flex items-center justify-center font-bold text-white text-xl shadow-lg shadow-cyan-500/30">
                    L
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-2xl font-bold text-white neon-text tracking-tight">猎户代挂</h1>
                        <span id="userBadge" class="text-xs px-2 py-0.5 rounded-full text-white font-medium hidden"></span>
                    </div>
                    <p class="text-xs text-slate-400 flex items-center gap-2 mt-1">
                        <span id="currentUserDisplay" class="text-cyan-400 font-mono"></span>
                        <span id="permissionHint" class="text-slate-500"></span>
                    </p>
                </div>
            </div>
            
            <div class="flex gap-3 w-full md:w-auto" id="actionButtons">
                <button onclick="refreshAll()" class="flex-1 md:flex-none px-5 py-2.5 btn-secondary rounded-lg flex items-center justify-center gap-2 text-sm font-medium text-slate-300 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    刷新
                </button>
                
                <button id="userAssistBtn" onclick="showAssistLogin()" class="hidden flex-1 md:flex-none px-5 py-2.5 btn-cyan text-white rounded-lg text-sm font-medium flex items-center justify-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                    协助登录
                </button>
                
                <button id="assignAccountBtn" onclick="showAssignModal()" class="hidden flex-1 md:flex-none px-5 py-2.5 btn-amber text-white rounded-lg text-sm font-medium flex items-center justify-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    分配账号
                </button>
                
                <button onclick="showAddAccount()" class="flex-1 md:flex-none px-5 py-2.5 btn-primary text-white rounded-lg text-sm font-medium flex items-center justify-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    添加账号
                </button>
                <button onclick="logout()" class="px-4 py-2.5 bg-red-500/10 text-red-400 hover:bg-red-500/20 border border-red-500/20 rounded-lg text-sm transition">
                    退出
                </button>
            </div>
        </header>

        <div class="mb-8">
            <div class="flex items-center justify-between mb-5 px-1">
                <h2 class="text-lg font-semibold text-white flex items-center gap-2">
                    <span class="w-2 h-2 bg-cyan-400 rounded-full shadow-[0_0_10px_rgba(34,211,238,0.8)]"></span>
                    <span id="listTitle">我的账号</span>
                </h2>
                <span id="accountCount" class="text-sm text-slate-500 bg-slate-800/50 px-3 py-1 rounded-full border border-slate-700">加载中...</span>
            </div>
            <div id="accountList" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            </div>
            <div id="noPermissionHint" class="hidden text-center py-12 text-slate-500 border-2 border-dashed border-slate-700 rounded-xl">
                <div class="text-4xl mb-2">📱</div>
                <p>暂无归属您的账号</p>
                <p class="text-xs mt-2 text-slate-600">点击"添加账号"扫码登录</p>
            </div>
        </div>

        <!-- 快捷操作面板 -->
        <div class="glass rounded-2xl p-6 border border-slate-700/50">
            <h3 class="text-lg font-semibold text-white mb-5 flex items-center gap-2 text-slate-200">
                <svg class="w-5 h-5 text-cyan-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                快捷操作
            </h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <!-- 开启消息自触 -->
                <button onclick="batchToggleSelfTouch(true)" class="group p-4 bg-slate-800/40 hover:bg-green-500/20 rounded-xl text-center transition border border-slate-700/50 hover:border-green-500/50">
                    <div class="text-green-400 font-semibold mb-1 group-hover:scale-105 transition-transform flex items-center justify-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        开启自触
                    </div>
                    <div class="text-xs text-slate-500">消息自触发</div>
                </button>
                
                <!-- 关闭消息自触 -->
                <button onclick="batchToggleSelfTouch(false)" class="group p-4 bg-slate-800/40 hover:bg-red-500/20 rounded-xl text-center transition border border-slate-700/50 hover:border-red-500/50">
                    <div class="text-red-400 font-semibold mb-1 group-hover:scale-105 transition-transform flex items-center justify-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        关闭自触
                    </div>
                    <div class="text-xs text-slate-500">消息自触发</div>
                </button>
                
                <button onclick="showExchangeAuth()" class="group p-4 bg-slate-800/40 hover:bg-slate-700/50 rounded-xl text-center transition border border-slate-700/50 hover:border-purple-500/30">
                    <div class="text-purple-400 font-semibold mb-1 group-hover:scale-105 transition-transform">兑换授权</div>
                    <div class="text-xs text-slate-500">激励计划</div>
                </button>
                <button onclick="showDeviceName()" class="group p-4 bg-slate-800/40 hover:bg-slate-700/50 rounded-xl text-center transition border border-slate-700/50 hover:border-green-500/30">
                    <div class="text-green-400 font-semibold mb-1 group-hover:scale-105 transition-transform">修改设备名</div>
                    <div class="text-xs text-slate-500">框架设备</div>
                </button>
                <button onclick="showShopLogin()" class="group p-4 bg-slate-800/40 hover:bg-slate-700/50 rounded-xl text-center transition border border-slate-700/50 hover:border-orange-500/30">
                    <div class="text-orange-400 font-semibold mb-1 group-hover:scale-105 transition-transform">资源后台</div>
                    <div class="text-xs text-slate-500">网页登录</div>
                </button>
                <button onclick="showLexiconManager()" class="group p-4 bg-slate-800/40 hover:bg-slate-700/50 rounded-xl text-center transition border border-slate-700/50 hover:border-pink-500/30">
                    <div class="text-pink-400 font-semibold mb-1 group-hover:scale-105 transition-transform">词库管理</div>
                    <div class="text-xs text-slate-500">导入词库</div>
                </button>
                <button id="adminAssistBtn" onclick="showAssistLogin()" class="hidden group p-4 bg-slate-800/40 hover:bg-slate-700/50 rounded-xl text-center transition border border-slate-700/50 hover:border-cyan-500/30">
                    <div class="text-cyan-400 font-semibold mb-1 group-hover:scale-105 transition-transform">协助登录</div>
                    <div class="text-xs text-slate-500">获取共享码</div>
                </button>
            </div>
        </div>
    </div>

    <!-- Toast提示 -->
    <div id="toast" class="toast">
        <span id="toastIcon" class="mr-2"></span>
        <span id="toastMsg"></span>
    </div>

    <script>
        const CONFIG = {
            baseUrl: '<?php echo basename(__FILE__); ?>?path=',
            sign: '2f988e3f015593df4bf6cef38f67cb9087d1beeaa2a73f8fb442dbb0c0d56a32'
        };

        let accounts = [];
        let currentUser = null;
        let selectedFile = null;
        let currentLexiconAccount = null;
        let currentAddMasterAccount = null;
        let selectedLexiconFile = null;
        let tempQQ = '';
        let tempEmail = '';
        let countdownTimer = null;
        let selectedUserId = null;

        window.onload = async function() {
            // 检查登录状态
            await checkSession();
            
            const uploadArea = document.getElementById('uploadArea');
            if (uploadArea) {
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    uploadArea.addEventListener(eventName, preventDefaults, false);
                });
                
                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                
                ['dragenter', 'dragover'].forEach(eventName => {
                    uploadArea.addEventListener(eventName, () => uploadArea.classList.add('dragover'), false);
                });
                
                ['dragleave', 'drop'].forEach(eventName => {
                    uploadArea.addEventListener(eventName, () => uploadArea.classList.remove('dragover'), false);
                });
                
                uploadArea.addEventListener('drop', handleDrop, false);
            }
        };

        // 检查session
        async function checkSession() {
            try {
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=check_session');
                const data = await res.json();
                
                if (data.code === 0 && data.logged_in) {
                    currentUser = data.user;
                    initMainApp();
                } else {
                    document.getElementById('authModal').classList.remove('hidden');
                }
            } catch (e) {
                document.getElementById('authModal').classList.remove('hidden');
            }
        }

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files.length > 0) {
                document.getElementById('fileInput').files = files;
                handleFileSelect(document.getElementById('fileInput'));
            }
        }

        function switchLoginTab(tab) {
            document.getElementById('tab-qq').className = tab === 'qq' ? 'flex-1 pb-3 text-sm font-medium tab-active transition' : 'flex-1 pb-3 text-sm font-medium tab-inactive transition';
            document.getElementById('tab-admin').className = tab === 'admin' ? 'flex-1 pb-3 text-sm font-medium tab-active transition' : 'flex-1 pb-3 text-sm font-medium tab-inactive transition';
            
            if (tab === 'qq') {
                document.getElementById('login-qq-step1').classList.remove('hidden');
                document.getElementById('login-qq-step2').classList.add('hidden');
                document.getElementById('login-admin').classList.add('hidden');
            } else {
                document.getElementById('login-qq-step1').classList.add('hidden');
                document.getElementById('login-qq-step2').classList.add('hidden');
                document.getElementById('login-admin').classList.remove('hidden');
            }
        }

        async function sendVerifyCode() {
            const qq = document.getElementById('qqInput').value.trim();
            const email = document.getElementById('emailInput').value.trim();
            
            if (!qq || !/^\d{5,15}$/.test(qq)) {
                showToast('请输入正确的QQ号', 'error');
                return;
            }
            
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showToast('请输入正确的邮箱地址', 'error');
                return;
            }
            
            tempQQ = qq;
            tempEmail = email;
            
            const btn = document.getElementById('sendCodeBtn');
            btn.disabled = true;
            btn.innerHTML = '<div class="loading-spinner w-5 h-5 border-2 inline-block mr-2"></div>发送中...';
            
            try {
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=send_verify_code', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({qq: qq, email: email})
                });
                
                const data = await res.json();
                
                if (data.code === 0) {
                    showToast('验证码已发送，请查看邮箱', 'success');
                    showVerifyStep2();
                    startCountdown();
                } else {
                    showToast(data.error || '发送失败', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '获取验证码';
                }
            } catch (e) {
                showToast('请求失败: ' + e.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '获取验证码';
            }
        }

        function showVerifyStep2() {
            document.getElementById('login-qq-step1').classList.add('hidden');
            document.getElementById('login-qq-step2').classList.remove('hidden');
            document.getElementById('verifyEmailDisplay').textContent = tempEmail;
            document.getElementById('verifyCodeInput').focus();
        }

        function backToStep1() {
            document.getElementById('login-qq-step2').classList.add('hidden');
            document.getElementById('login-qq-step1').classList.remove('hidden');
            document.getElementById('verifyCodeInput').value = '';
            stopCountdown();
        }

        function startCountdown() {
            let seconds = 60;
            const countdownEl = document.getElementById('countdown');
            const resendBtn = document.getElementById('resendBtn');
            
            resendBtn.disabled = true;
            countdownEl.textContent = seconds;
            
            countdownTimer = setInterval(() => {
                seconds--;
                countdownEl.textContent = seconds;
                
                if (seconds <= 0) {
                    stopCountdown();
                    resendBtn.disabled = false;
                    resendBtn.innerHTML = '重新发送';
                }
            }, 1000);
        }

        function stopCountdown() {
            if (countdownTimer) {
                clearInterval(countdownTimer);
                countdownTimer = null;
            }
        }

        async function resendCode() {
            const btn = document.getElementById('resendBtn');
            btn.disabled = true;
            btn.innerHTML = '<div class="loading-spinner w-4 h-4 border-2 inline-block mr-2"></div>发送中...';
            
            try {
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=send_verify_code', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({qq: tempQQ, email: tempEmail})
                });
                
                const data = await res.json();
                
                if (data.code === 0) {
                    showToast('验证码已重新发送', 'success');
                    startCountdown();
                } else {
                    showToast(data.error || '发送失败', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '重新发送';
                }
            } catch (e) {
                showToast('请求失败: ' + e.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '重新发送';
            }
        }

        async function verifyAndLogin() {
            const code = document.getElementById('verifyCodeInput').value.trim();
            
            if (!code || !/^\d{6}$/.test(code)) {
                showToast('请输入6位验证码', 'error');
                return;
            }
            
            const btn = document.getElementById('verifyBtn');
            btn.disabled = true;
            btn.innerHTML = '<div class="loading-spinner w-5 h-5 border-2 inline-block mr-2"></div>验证中...';
            
            try {
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=verify_code', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        qq: tempQQ,
                        email: tempEmail,
                        code: code
                    })
                });
                
                const data = await res.json();
                
                if (data.code === 0) {
                    currentUser = data.user;
                    saveUserAndInit();
                    showToast('登录成功');
                    
                    document.getElementById('qqInput').value = '';
                    document.getElementById('emailInput').value = '';
                    document.getElementById('verifyCodeInput').value = '';
                    stopCountdown();
                } else {
                    showToast(data.error || '验证失败', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '验证登录';
                }
            } catch (e) {
                showToast('验证失败: ' + e.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '验证登录';
            }
        }

        async function loginWithAdmin() {
            const user = document.getElementById('adminUser').value.trim();
            const pass = document.getElementById('adminPass').value.trim();

            if (!user || !pass) {
                showToast('请输入账号和密码', 'error');
                return;
            }

            try {
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=admin_login', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({username: user, password: pass})
                });
                
                const data = await res.json();
                
                if (data.code === 0) {
                    currentUser = data.user;
                    saveUserAndInit();
                    showToast('管理员登录成功');
                } else {
                    showToast(data.error || '登录失败', 'error');
                }
            } catch (e) {
                showToast('请求失败: ' + e.message, 'error');
            }
        }

        function saveUserAndInit() {
            document.getElementById('authModal').classList.add('hidden');
            initMainApp();
        }

        function initMainApp() {
            document.getElementById('mainApp').classList.remove('hidden');
            document.getElementById('currentUserDisplay').textContent = currentUser.isAdmin ? '管理员' : currentUser.qq;
            
            const badge = document.getElementById('userBadge');
            if (currentUser.isAdmin) {
                badge.className = 'admin-badge text-xs px-2 py-0.5 rounded-full text-white font-medium';
                badge.textContent = '管理员';
                badge.classList.remove('hidden');
                document.getElementById('permissionHint').textContent = '可管理所有账号';
                document.getElementById('listTitle').textContent = '全部账号';
                document.getElementById('adminAssistBtn').classList.remove('hidden');
                document.getElementById('userAssistBtn').classList.add('hidden');
                document.getElementById('assignAccountBtn').classList.remove('hidden');
            } else {
                badge.className = 'user-badge text-xs px-2 py-0.5 rounded-full text-white font-medium';
                badge.textContent = '普通用户';
                badge.classList.remove('hidden');
                document.getElementById('permissionHint').textContent = '仅可管理分配的账号';
                document.getElementById('listTitle').textContent = '我的账号';
                document.getElementById('userAssistBtn').classList.remove('hidden');
                document.getElementById('adminAssistBtn').classList.add('hidden');
                document.getElementById('assignAccountBtn').classList.add('hidden');
            }
            
            refreshAll();
        }

        async function logout() {
            if(confirm('确定要退出登录吗？')) {
                try {
                    await fetch('<?php echo basename(__FILE__); ?>?action=logout', {method: 'POST'});
                } catch(e) {}
                location.reload();
            }
        }

        async function hasPermission(accountQQ) {
            if (currentUser.isAdmin) return true;
            
            try {
                const res = await fetch(`<?php echo basename(__FILE__); ?>?action=check_permission&account=${accountQQ}`);
                const data = await res.json();
                return data.allowed === true;
            } catch (e) {
                return false;
            }
        }

        async function api(path, body = {}) {
            try {
                const res = await fetch(CONFIG.baseUrl + path, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ...body, sign: CONFIG.sign })
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return await res.json();
            } catch (e) {
                showToast('请求失败: ' + e.message, 'error');
                throw e;
            }
        }

        async function refreshAll() {
            showToast('正在刷新...', 'info');
            try {
                const res = await api('/uin-list-get');
                if (res.code === 0) {
                    accounts = res.data || [];
                    renderAccounts();
                } else {
                    showToast('获取失败: ' + (res.info || '未知错误'), 'error');
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function renderAccounts() {
            const container = document.getElementById('accountList');
            const noPermHint = document.getElementById('noPermissionHint');
            
            if (accounts.length === 0) {
                container.innerHTML = '';
                noPermHint.classList.remove('hidden');
                document.getElementById('accountCount').textContent = '0 个账号';
                return;
            }
            
            // 获取我的账号列表
            let myAccounts = [];
            if (currentUser.isAdmin) {
                myAccounts = accounts;
            } else {
                try {
                    const res = await fetch('<?php echo basename(__FILE__); ?>?action=get_my_accounts');
                    const data = await res.json();
                    if (data.code === 0) {
                        const myAccountNumbers = data.data.map(a => a.account);
                        myAccounts = accounts.filter(acc => myAccountNumbers.includes(acc.account.toString()));
                    }
                } catch (e) {
                    console.error(e);
                }
            }
            
            const otherAccounts = currentUser.isAdmin ? [] : accounts.filter(acc => !myAccounts.find(a => a.account == acc.account));
            
            if (myAccounts.length === 0 && !currentUser.isAdmin) {
                container.innerHTML = '';
                noPermHint.classList.remove('hidden');
                document.getElementById('accountCount').textContent = `0/${accounts.length} 个`;
                return;
            }
            
            noPermHint.classList.add('hidden');
            
            let html = '';
            
            for (const acc of myAccounts) {
                html += await renderAccountCard(acc, true);
            }
            
            for (const acc of otherAccounts) {
                html += await renderAccountCard(acc, false);
            }
            
            container.innerHTML = html;
            
            const visibleCount = myAccounts.length;
            const totalCount = accounts.length;
            
            if (currentUser.isAdmin) {
                document.getElementById('accountCount').textContent = `${totalCount} 个账号`;
            } else {
                document.getElementById('accountCount').textContent = `可控 ${visibleCount}/${totalCount} 个`;
            }
        }

        async function renderAccountCard(acc, canControl) {
            // 获取归属信息
            let ownerInfo = '';
            try {
                const res = await fetch(`<?php echo basename(__FILE__); ?>?action=get_account_owner&account=${acc.account}`);
                const data = await res.json();
                if (data.code === 0 && data.has_owner) {
                    ownerInfo = `<span class="text-xs px-1.5 py-0.5 rounded bg-amber-500/20 text-amber-400 border border-amber-500/30">${data.owner.qq}</span>`;
                } else if (currentUser.isAdmin) {
                    ownerInfo = `<span class="text-xs px-1.5 py-0.5 rounded bg-slate-600/30 text-slate-400 border border-slate-600">未分配</span>`;
                }
            } catch (e) {}
            
            let statusBadge = '';
            if (acc.online) {
                statusBadge = '<span class="flex items-center gap-1.5 text-xs text-green-400"><span class="pulse-dot"></span>在线</span>';
            } else {
                statusBadge = '<span class="text-xs text-slate-500 flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-slate-600"></span>离线</span>';
            }
            
            if (!canControl) {
                return `
                <div class="account-card no-permission rounded-xl p-5 relative overflow-hidden">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <div class="text-xl font-bold text-slate-400 tracking-tight font-mono">${acc.account}</div>
                            <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                <span class="text-xs px-2 py-0.5 rounded bg-slate-800 text-slate-500 border border-slate-700">
                                    ${getProtocolName(acc.gm)}
                                </span>
                                ${statusBadge}
                                ${ownerInfo}
                            </div>
                        </div>
                    </div>
                    <div class="permission-overlay">
                        <div class="text-center">
                            <div class="text-2xl mb-2">🔒</div>
                            <p class="text-sm text-slate-300">无权管理</p>
                        </div>
                    </div>
                </div>`;
            }
            
            return `
            <div class="account-card rounded-xl p-5 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-cyan-500/5 to-transparent rounded-bl-full pointer-events-none"></div>
                
                <div class="flex justify-between items-start mb-4 relative z-10">
                    <div>
                        <div class="text-xl font-bold text-white tracking-tight font-mono">${acc.account}</div>
                        <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                            <span class="text-xs px-2 py-0.5 rounded bg-slate-700/50 text-slate-300 border border-slate-600">
                                ${getProtocolName(acc.gm)}
                            </span>
                            ${statusBadge}
                            ${ownerInfo}
                        </div>
                    </div>
                    <button onclick="deleteAccount(${acc.account})" class="text-slate-600 hover:text-red-400 transition p-1 hover:bg-red-500/10 rounded">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-2 mb-3">
                    <button onclick="toggleOnline(${acc.account}, ${!acc.online})" 
                            class="${acc.online ? 'bg-red-500/10 text-red-400 hover:bg-red-500/20 border-red-500/20' : 'bg-green-500/10 text-green-400 hover:bg-green-500/20 border-green-500/20'} 
                            py-2 rounded-lg text-xs font-semibold transition border">
                        ${acc.online ? '下线' : '上线'}
                    </button>
                    <button onclick="queryAccount(${acc.account})" class="bg-cyan-500/10 text-cyan-400 hover:bg-cyan-500/20 border border-cyan-500/20 py-2 rounded-lg text-xs font-semibold transition">
                        查询详情
                    </button>
                </div>
                
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <button onclick="showScanLogin('SL', ${acc.account})" class="bg-blue-500/10 text-blue-400 hover:bg-blue-500/20 border border-blue-500/20 py-2 rounded-lg text-xs transition flex items-center justify-center gap-1">
                        <span>🐧</span> 企鹅重登
                    </button>
                    <button onclick="showScanLogin('SA', ${acc.account})" class="bg-purple-500/10 text-purple-400 hover:bg-purple-500/20 border border-purple-500/20 py-2 rounded-lg text-xs transition flex items-center justify-center gap-1">
                        <span>⌚</span> 手表重登
                    </button>
                </div>
                
                <button onclick="showAddMaster(${acc.account})" class="w-full btn-orange text-white py-2 rounded-lg text-xs font-medium flex items-center justify-center gap-1.5 transition border border-orange-500/30 mb-3">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                    添加主人
                </button>
                
                <div class="mt-3 pt-3 border-t border-slate-700/50 flex justify-between items-center text-xs text-slate-500">
                    <span>AppID: <span class="text-slate-400 font-mono">${acc.appid}</span></span>
                    <button onclick="toggleSecureKey(${acc.account})" class="text-yellow-500/80 hover:text-yellow-400 transition">
                        安全密钥
                    </button>
                </div>
            </div>`;
        }

        function getProtocolName(gm) {
            const map = { 'SL': '企鹅', 'SA': '手表', 'PO': '官机', 'MAC': 'Mac' };
            return map[gm] || gm;
        }

        // 管理员分配账号功能
        async function showAssignModal() {
            if (!currentUser.isAdmin) {
                showToast('无权操作', 'error');
                return;
            }
            
            document.getElementById('assignModal').classList.remove('hidden');
            document.getElementById('assignAccountInput').value = '';
            selectedUserId = null;
            document.getElementById('selectedUserDisplay').classList.add('hidden');
            document.getElementById('assignBtn').disabled = true;
            
            // 加载用户列表
            const userList = document.getElementById('userList');
            userList.innerHTML = '<div class="text-center py-4"><div class="loading-spinner mx-auto mb-2"></div><p class="text-slate-500 text-sm">加载中...</p></div>';
            
            try {
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=get_users');
                const data = await res.json();
                
                if (data.code === 0 && data.data.length > 0) {
                    userList.innerHTML = data.data.map(user => `
                        <div class="user-item p-3 bg-slate-800/50 border border-slate-700 rounded-lg flex items-center justify-between" onclick="selectUser('${user.id}', '${user.qq}', this)">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-cyan-600 flex items-center justify-center text-white text-xs font-bold">
                                    ${user.qq.slice(-2)}
                                </div>
                                <div>
                                    <div class="text-sm text-slate-200 font-medium">QQ: ${user.qq}</div>
                                    <div class="text-xs text-slate-500">${user.email}</div>
                                </div>
                            </div>
                            <svg class="w-5 h-5 text-cyan-400 check-icon hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        </div>
                    `).join('');
                } else {
                    userList.innerHTML = '<div class="text-center py-4 text-slate-500 text-sm">暂无注册用户</div>';
                }
            } catch (e) {
                userList.innerHTML = '<div class="text-center py-4 text-red-400 text-sm">加载失败</div>';
            }
        }

        function selectUser(userId, qq, element) {
            // 移除其他选中状态
            document.querySelectorAll('.user-item').forEach(item => {
                item.classList.remove('selected', 'border-cyan-500/50', 'bg-cyan-500/10');
                item.querySelector('.check-icon').classList.add('hidden');
            });
            
            // 添加选中状态
            element.classList.add('selected', 'border-cyan-500/50', 'bg-cyan-500/10');
            element.querySelector('.check-icon').classList.remove('hidden');
            
            selectedUserId = userId;
            document.getElementById('selectedUserText').textContent = `QQ: ${qq}`;
            document.getElementById('selectedUserDisplay').classList.remove('hidden');
            document.getElementById('assignBtn').disabled = false;
        }

        async function submitAssign() {
            const account = document.getElementById('assignAccountInput').value.trim();
            
            if (!account || !/^\d{5,15}$/.test(account)) {
                showToast('请输入正确的QQ号', 'error');
                return;
            }
            
            if (!selectedUserId) {
                showToast('请选择用户', 'error');
                return;
            }
            
            const btn = document.getElementById('assignBtn');
            btn.disabled = true;
            btn.innerHTML = '<div class="loading-spinner w-5 h-5 border-2 inline-block mr-2"></div>分配中...';
            
            try {
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=assign_account', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({account: account, userId: selectedUserId})
                });
                
                const data = await res.json();
                
                if (data.code === 0) {
                    showToast('分配成功', 'success');
                    closeAssignModal();
                    refreshAll();
                } else {
                    showToast(data.error || '分配失败', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '确认分配';
                }
            } catch (e) {
                showToast('请求失败: ' + e.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '确认分配';
            }
        }

        function closeAssignModal() {
            document.getElementById('assignModal').classList.add('hidden');
        }

        // 添加主人功能
        async function showAddMaster(account) {
            const hasPerm = await hasPermission(account.toString());
            if (!hasPerm) {
                showToast('无权管理此账号', 'error');
                return;
            }
            
            currentAddMasterAccount = account;
            selectedLexiconFile = null;
            
            const modal = document.getElementById('addMasterModal');
            document.getElementById('addMasterAccount').textContent = account;
            document.getElementById('addMasterStep1').classList.remove('hidden');
            document.getElementById('addMasterStep2').classList.add('hidden');
            document.getElementById('addMasterResult').classList.add('hidden');
            modal.classList.remove('hidden');
            
            const fileList = document.getElementById('lexiconFileList');
            fileList.innerHTML = '<div class="text-center py-8"><div class="loading-spinner mx-auto mb-3"></div><p class="text-slate-500 text-sm">正在读取文件列表...</p></div>';
            
            try {
                const res = await fetch(`<?php echo basename(__FILE__); ?>?action=get_lexicon_files&account=${account}`);
                const data = await res.json();
                
                if (data.code === 0) {
                    const files = data.data || [];
                    if (files.length === 0) {
                        fileList.innerHTML = '<div class="text-center py-6 text-slate-500 text-sm">该账号暂无词库文件<br>请先上传词库</div>';
                    } else {
                        fileList.innerHTML = files.map(file => `
                            <div class="file-select-item p-3 bg-slate-800/30 border border-slate-700/50 rounded-lg flex items-center justify-between" onclick="selectLexiconFile('${file}', this)">
                                <div class="flex items-center gap-3">
                                    <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    <span class="text-sm text-slate-200">${file}</span>
                                </div>
                                <svg class="w-5 h-5 text-cyan-400 check-icon hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </div>
                        `).join('');
                    }
                } else {
                    fileList.innerHTML = `<div class="text-center py-6 text-red-400 text-sm">加载失败: ${data.error}</div>`;
                }
            } catch (e) {
                fileList.innerHTML = `<div class="text-center py-6 text-red-400 text-sm">加载失败: ${e.message}</div>`;
            }
        }

        function selectLexiconFile(filename, element) {
            document.querySelectorAll('.file-select-item').forEach(item => {
                item.classList.remove('selected', 'border-orange-500/50', 'bg-orange-500/10');
                item.querySelector('.check-icon').classList.add('hidden');
            });
            
            element.classList.add('selected', 'border-orange-500/50', 'bg-orange-500/10');
            element.querySelector('.check-icon').classList.remove('hidden');
            
            selectedLexiconFile = filename;
            
            setTimeout(() => {
                document.getElementById('addMasterStep1').classList.add('hidden');
                document.getElementById('addMasterStep2').classList.remove('hidden');
                document.getElementById('selectedFileDisplay').textContent = `/root/Secluded-x64-linux/lexicon/${currentAddMasterAccount}/${filename}`;
                document.getElementById('masterQQInput').focus();
            }, 300);
        }

        function backToStep1() {
            document.getElementById('addMasterStep2').classList.add('hidden');
            document.getElementById('addMasterStep1').classList.remove('hidden');
            selectedLexiconFile = null;
        }

        async function submitAddMaster() {
            const masterQQ = document.getElementById('masterQQInput').value.trim();
            
            if (!masterQQ) {
                showToast('请输入主人ID', 'error');
                return;
            }
            
            if (!selectedLexiconFile || !currentAddMasterAccount) {
                showToast('请选择词库文件', 'error');
                return;
            }
            
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<div class="loading-spinner w-4 h-4 border-2 inline-block mr-2"></div>提交中...';
            
            try {
                const filePath = `/root/Secluded-x64-linux/lexicon/${currentAddMasterAccount}/${selectedLexiconFile}`;
                
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=add_master_api', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        file: filePath,
                        id: masterQQ
                    })
                });
                
                const data = await res.json();
                
                document.getElementById('addMasterStep2').classList.add('hidden');
                const resultDiv = document.getElementById('addMasterResult');
                resultDiv.classList.remove('hidden');
                
                if (data.code === 0) {
                    document.getElementById('addMasterResultIcon').textContent = '✅';
                    document.getElementById('addMasterResultIcon').className = 'text-5xl mb-3 text-green-400';
                    document.getElementById('addMasterResultText').textContent = '添加主人成功';
                    document.getElementById('addMasterResultText').className = 'font-semibold mb-2 text-green-400';
                    document.getElementById('addMasterResultDetail').textContent = `已将 ${masterQQ} 添加为 ${selectedLexiconFile} 的主人`;
                } else {
                    document.getElementById('addMasterResultIcon').textContent = '❌';
                    document.getElementById('addMasterResultIcon').className = 'text-5xl mb-3 text-red-400';
                    document.getElementById('addMasterResultText').textContent = '添加失败';
                    document.getElementById('addMasterResultText').className = 'font-semibold mb-2 text-red-400';
                    document.getElementById('addMasterResultDetail').textContent = data.error || data.raw || '未知错误';
                }
            } catch (e) {
                document.getElementById('addMasterStep2').classList.add('hidden');
                document.getElementById('addMasterResult').classList.remove('hidden');
                document.getElementById('addMasterResultIcon').textContent = '❌';
                document.getElementById('addMasterResultIcon').className = 'text-5xl mb-3 text-red-400';
                document.getElementById('addMasterResultText').textContent = '请求失败';
                document.getElementById('addMasterResultText').className = 'font-semibold mb-2 text-red-400';
                document.getElementById('addMasterResultDetail').textContent = e.message;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '确认添加';
            }
        }

        function closeAddMasterModal() {
            document.getElementById('addMasterModal').classList.add('hidden');
            currentAddMasterAccount = null;
            selectedLexiconFile = null;
            document.getElementById('masterQQInput').value = '';
            document.getElementById('addMasterStep1').classList.remove('hidden');
            document.getElementById('addMasterStep2').classList.add('hidden');
            document.getElementById('addMasterResult').classList.add('hidden');
        }

        async function batchToggleSelfTouch(enable) {
            // 获取我的账号
            let myAccounts = [];
            if (currentUser.isAdmin) {
                myAccounts = accounts;
            } else {
                try {
                    const res = await fetch('<?php echo basename(__FILE__); ?>?action=get_my_accounts');
                    const data = await res.json();
                    if (data.code === 0) {
                        const myAccountNumbers = data.data.map(a => a.account);
                        myAccounts = accounts.filter(acc => myAccountNumbers.includes(acc.account.toString()));
                    }
                } catch (e) {}
            }
            
            if (myAccounts.length === 0) {
                showToast('您没有可管理的账号', 'warning');
                return;
            }
            
            const action = enable ? '开启' : '关闭';
            const confirmMsg = `确定要${action}以下 ${myAccounts.length} 个账号的消息自触吗？\n\n${myAccounts.map(a => a.account).join('\n')}`;
            
            if (!confirm(confirmMsg)) return;
            
            showToast(`正在${action}消息自触...`, 'info');
            
            let success = 0;
            let failed = 0;
            
            for (const acc of myAccounts) {
                try {
                    const res = await api('/uin-list-set-mgr-switch', {
                        account: acc.account,
                        key: 'self-touch',
                        value: enable
                    });
                    
                    if (res.code === 0) {
                        success++;
                    } else {
                        failed++;
                    }
                } catch (e) {
                    failed++;
                }
                
                await new Promise(r => setTimeout(r, 100));
            }
            
            if (failed === 0) {
                showToast(`✅ 成功${action} ${success} 个账号的消息自触`, 'success');
            } else {
                showToast(`${action}完成: ${success}成功, ${failed}失败`, failed > 0 ? 'warning' : 'success');
            }
            
            setTimeout(() => refreshAll(), 500);
        }

        async function showScanLogin(type, account) {
            const hasPerm = await hasPermission(account.toString());
            if (!hasPerm) {
                showToast('无权操作此账号', 'error');
                return;
            }

            const modal = document.getElementById('loginModal');
            const content = document.getElementById('loginContent');
            const title = document.getElementById('loginTitle');
            const subtitle = document.getElementById('loginSubtitle');
            
            modal.classList.remove('hidden');
            title.textContent = (type === 'SL' ? '🐧 企鹅' : '⌚ 手表') + ' 扫码登录';
            subtitle.textContent = `账号: ${account}`;
            content.innerHTML = '<div class="loading-spinner mx-auto mb-4"></div><p class="text-slate-400">正在获取登录令牌...</p>';
            
            try {
                const onlineRes = await api('/uin-list-set-online', {
                    account: parseInt(account),
                    appid: 0,
                    online: true,
                    gm: type
                });
                
                if (onlineRes.code !== 0 || !onlineRes.data?.mid) {
                    throw new Error(onlineRes.info || '初始化登录失败');
                }
                
                const mid = onlineRes.data.mid;
                content.innerHTML = '<div class="loading-spinner mx-auto mb-4"></div><p class="text-slate-400">正在获取二维码...</p>';
                
                let qrAttempts = 0;
                const getQr = async () => {
                    if (qrAttempts > 20) {
                        content.innerHTML = '<p class="text-red-400">获取二维码超时</p>';
                        return;
                    }
                    qrAttempts++;
                    
                    try {
                        const eventRes = await api('/uin-list-get-event', { mid });
                        const data = eventRes.data;
                        
                        if (data?.qrcode) {
                            content.innerHTML = `
                                <div class="bg-white p-3 rounded-lg inline-block mb-4 shadow-lg">
                                    <img src="data:image/png;base64,${data.qrcode}" class="w-48 h-48" alt="登录二维码">
                                </div>
                                <p class="text-cyan-400 text-sm font-medium mb-2">请使用${type === 'SL' ? '手机QQ' : '手表'}扫码</p>
                                <p class="text-xs text-slate-500">等待扫码中...</p>
                            `;
                            pollLoginStatus(mid, account, type);
                        } else if (data?.online && data?.account == account) {
                            content.innerHTML = '<div class="text-5xl mb-4">✅</div><p class="text-green-400 font-semibold text-lg">登录成功</p>';
                            setTimeout(() => {
                                closeLoginModal();
                                refreshAll();
                            }, 1500);
                        } else if (data?.['err-text']) {
                            content.innerHTML = `<p class="text-red-400">错误: ${data['err-text']}</p>`;
                        } else {
                            setTimeout(getQr, 1000);
                        }
                    } catch (e) {
                        setTimeout(getQr, 1000);
                    }
                };
                
                getQr();
                
            } catch (e) {
                content.innerHTML = `<div class="text-5xl mb-4">❌</div><p class="text-red-400">${e.message}</p>`;
            }
        }

        async function pollLoginStatus(mid, account, type) {
            const content = document.getElementById('loginContent');
            let attempts = 0;
            
            const check = async () => {
                if (attempts > 60) {
                    content.innerHTML = '<p class="text-red-400">登录超时</p>';
                    return;
                }
                attempts++;
                
                try {
                    const res = await api('/uin-list-get-event', { mid });
                    const data = res.data;
                    
                    if (data?.online && data?.account == account) {
                        content.innerHTML = '<div class="text-5xl mb-4">✅</div><p class="text-green-400 font-semibold text-lg">登录成功</p>';
                        setTimeout(() => {
                            closeLoginModal();
                            refreshAll();
                        }, 1500);
                    } else if (data?.['拒绝登录']) {
                        content.innerHTML = '<div class="text-5xl mb-4">🚫</div><p class="text-red-400">登录被拒绝</p>';
                    } else if (data?.['超时']) {
                        content.innerHTML = '<div class="text-5xl mb-4">⏰</div><p class="text-red-400">二维码已过期</p>';
                    } else {
                        setTimeout(check, 1000);
                    }
                } catch (e) {
                    setTimeout(check, 1500);
                }
            };
            
            check();
        }

        function closeLoginModal() {
            document.getElementById('loginModal').classList.add('hidden');
        }

        function closeLexiconModal() {
            document.getElementById('lexiconModal').classList.add('hidden');
            selectedFile = null;
            document.getElementById('fileInput').value = '';
            document.getElementById('selectedFileName').textContent = '';
            const uploadBtn = document.getElementById('uploadBtn');
            if (uploadBtn) uploadBtn.disabled = true;
            document.getElementById('uploadProgress').classList.add('hidden');
        }

        function closeExchangeCheckModal() {
            document.getElementById('exchangeCheckModal').classList.add('hidden');
        }

        function showLexiconManager() {
            const modal = document.getElementById('lexiconModal');
            modal.classList.remove('hidden');
            
            const select = document.getElementById('lexiconAccountSelect');
            select.innerHTML = '<option value="">请选择账号...</option>';
            
            // 只显示有权限的账号
            const myAccounts = currentUser.isAdmin ? accounts : accounts.filter(acc => {
                // 这里简化处理，实际应该检查权限
                return true;
            });
            
            myAccounts.forEach(acc => {
                const option = document.createElement('option');
                option.value = acc.account;
                option.textContent = `${acc.account} (${getProtocolName(acc.gm)})`;
                select.appendChild(option);
            });
            
            select.onchange = function() {
                currentLexiconAccount = this.value;
                if (currentLexiconAccount) {
                    document.getElementById('lexiconAccountTitle').textContent = `当前管理: ${currentLexiconAccount}`;
                    const uploadBtn = document.getElementById('uploadBtn');
                    if (uploadBtn) uploadBtn.disabled = !selectedFile;
                    loadLexiconList(currentLexiconAccount);
                } else {
                    document.getElementById('lexiconAccountTitle').textContent = '选择账号上传词库';
                    const uploadBtn = document.getElementById('uploadBtn');
                    if (uploadBtn) uploadBtn.disabled = true;
                }
            };
        }

        async function loadLexiconList(account) {
            const listContainer = document.getElementById('lexiconList');
            listContainer.innerHTML = '<div class="text-center py-4"><div class="loading-spinner mx-auto"></div><p class="text-xs text-slate-500 mt-2">加载中...</p></div>';
            
            try {
                const res = await fetch(`<?php echo basename(__FILE__); ?>?action=list_lexicon&account=${account}`);
                const data = await res.json();
                
                if (data.code === 0) {
                    const files = data.data || [];
                    if (files.length === 0) {
                        listContainer.innerHTML = '<div class="text-center py-4 text-slate-500 text-sm">暂无词库文件</div>';
                    } else {
                        listContainer.innerHTML = files.map(f => `
                            <div class="lexicon-item flex justify-between items-center p-3 bg-slate-800/30 rounded-lg border border-slate-700/50">
                                <div class="flex items-center gap-3">
                                    <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    <div>
                                        <div class="text-sm text-slate-200 font-medium">${f.name}</div>
                                        <div class="text-xs text-slate-500">${formatFileSize(f.size)}</div>
                                    </div>
                                </div>
                                <button onclick="deleteLexicon('${account}', '${f.name}')" class="text-red-400 hover:text-red-300 p-1.5 hover:bg-red-500/10 rounded transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </div>
                        `).join('');
                    }
                } else {
                    listContainer.innerHTML = `<div class="text-center py-4 text-slate-500 text-sm">加载失败: ${data.error}</div>`;
                }
            } catch (e) {
                listContainer.innerHTML = '<div class="text-center py-4 text-slate-500 text-sm">加载失败</div>';
            }
        }

        function handleFileSelect(input) {
            const file = input.files[0];
            if (!file) return;
            
            const allowedTypes = ['.txt', '.json', '.xml'];
            const ext = '.' + file.name.split('.').pop().toLowerCase();
            
            if (!allowedTypes.includes(ext)) {
                showToast('不支持的文件格式', 'error');
                input.value = '';
                return;
            }
            
            if (file.size > 10 * 1024 * 1024) {
                showToast('文件大小不能超过 10MB', 'error');
                input.value = '';
                return;
            }
            
            selectedFile = file;
            document.getElementById('selectedFileName').textContent = `已选择: ${file.name} (${formatFileSize(file.size)})`;
            const uploadBtn = document.getElementById('uploadBtn');
            if (uploadBtn) uploadBtn.disabled = !currentLexiconAccount;
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        async function uploadLexicon() {
            if (!selectedFile || !currentLexiconAccount) {
                showToast('请选择文件和目标账号', 'warning');
                return;
            }
            
            const hasPerm = await hasPermission(currentLexiconAccount);
            if (!hasPerm) {
                showToast('无权管理此账号的词库', 'error');
                return;
            }
            
            const btn = document.getElementById('uploadBtn');
            const progressDiv = document.getElementById('uploadProgress');
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            
            btn.disabled = true;
            btn.innerHTML = '<div class="loading-spinner w-5 h-5 border-2 inline-block mr-2"></div>上传中...';
            progressDiv.classList.remove('hidden');
            
            const formData = new FormData();
            formData.append('file', selectedFile);
            formData.append('account', currentLexiconAccount);
            
            let progress = 0;
            const progressInterval = setInterval(() => {
                if (progress < 90) {
                    progress += Math.random() * 10;
                    if (progress > 90) progress = 90;
                    progressBar.style.width = progress + '%';
                    progressText.textContent = Math.floor(progress) + '%';
                }
            }, 300);
            
            try {
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=upload_lexicon', {
                    method: 'POST',
                    body: formData
                });
                
                clearInterval(progressInterval);
                progressBar.style.width = '100%';
                progressText.textContent = '100%';
                
                const data = await res.json();
                
                if (data.code === 0) {
                    showToast('上传成功', 'success');
                    selectedFile = null;
                    document.getElementById('fileInput').value = '';
                    document.getElementById('selectedFileName').textContent = '';
                    btn.disabled = true;
                    setTimeout(() => {
                        loadLexiconList(currentLexiconAccount);
                        progressDiv.classList.add('hidden');
                        progressBar.style.width = '0%';
                    }, 500);
                } else {
                    throw new Error(data.error || '上传失败');
                }
            } catch (e) {
                showToast('上传失败: ' + e.message, 'error');
                progressDiv.classList.add('hidden');
            } finally {
                btn.innerHTML = '上传词库文件 (SFTP直传)';
                btn.disabled = !currentLexiconAccount;
            }
        }

        async function deleteLexicon(account, filename) {
            if (!confirm(`确定要删除 "${filename}" 吗？`)) return;
            
            try {
                const res = await fetch('<?php echo basename(__FILE__); ?>?action=delete_lexicon', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `account=${encodeURIComponent(account)}&filename=${encodeURIComponent(filename)}`
                });
                
                const data = await res.json();
                
                if (data.code === 0) {
                    showToast('删除成功', 'success');
                    loadLexiconList(account);
                } else {
                    showToast('删除失败: ' + data.error, 'error');
                }
            } catch (e) {
                showToast('删除失败', 'error');
            }
        }

        async function toggleOnline(account, online) {
            const hasPerm = await hasPermission(account.toString());
            if (!hasPerm) {
                showToast('无权操作此账号', 'error');
                return;
            }
            
            showToast(online ? '正在上线...' : '正在下线...', 'info');
            try {
                const res = await api('/uin-list-set-online', {
                    account: account,
                    appid: 0,
                    online: online
                });
                if (res.code === 0) {
                    showToast(online ? '上线成功' : '下线成功');
                    refreshAll();
                } else {
                    showToast('操作失败: ' + res.info, 'error');
                }
            } catch (e) {}
        }

        async function queryAccount(account) {
            const hasPerm = await hasPermission(account.toString());
            if (!hasPerm) {
                showToast('无权查询此账号', 'error');
                return;
            }
            
            showToast('正在查询...', 'info');
            try {
                const res = await api('/uin-list-get-mgr-switch', { account });
                if (res.code === 0) {
                    const d = res.data;
                    alert(`📱 账号 ${account} 详情\n\n🔒 安全密钥: ${d['allow-secure-key'] ? '开启' : '关闭'}\n📨 消息自触: ${d['self-touch'] ? '开启' : '关闭'}\n🛡️ 沙箱模式: ${d['sandbox'] ? '开启' : '关闭'}\n🐛 调试模式: ${d['debug'] ? '开启' : '关闭'}\n📝 消息记录: ${d['record-msg'] ? '开启' : '关闭'}`);
                } else {
                    showToast('查询失败', 'error');
                }
            } catch (e) {}
        }

        async function deleteAccount(account) {
            const hasPerm = await hasPermission(account.toString());
            if (!hasPerm) {
                showToast('无权删除此账号', 'error');
                return;
            }
            
            if (!confirm(`确定要删除账号 ${account} 吗？`)) return;
            
            try {
                const res = await api('/uin-list-del', { account });
                if (res.code === 0) {
                    showToast('删除成功');
                    refreshAll();
                } else {
                    showToast('删除失败: ' + res.info, 'error');
                }
            } catch (e) {}
        }

        async function toggleSecureKey(account) {
            const hasPerm = await hasPermission(account.toString());
            if (!hasPerm) {
                showToast('无权操作此账号', 'error');
                return;
            }
            
            try {
                const res = await api('/uin-list-get-mgr-switch', { account });
                const current = res.data?.['allow-secure-key'];
                const action = current ? '关闭' : '开启';
                
                if (!confirm(`确定要${action}账号 ${account} 的安全密钥吗？`)) return;
                
                const updateRes = await api('/uin-list-set-mgr-switch', {
                    account: account,
                    key: 'allow-secure-key',
                    value: !current
                });
                
                if (updateRes.code === 0) {
                    showToast(`安全密钥已${action}`);
                } else {
                    showToast('操作失败', 'error');
                }
            } catch (e) {}
        }

        async function showAddAccount() {
            const qq = prompt('请输入要添加的新QQ号:');
            if (!qq) return;
            
            if (!/^\d{5,15}$/.test(qq)) {
                showToast('QQ号格式不正确', 'error');
                return;
            }
            
            showToast('正在检查...', 'info');
            
            try {
                const checkRes = await fetch(`<?php echo basename(__FILE__); ?>?action=check_account_exists&account=${qq}`);
                const checkData = await checkRes.json();
                
                if (checkData.code !== 0) {
                    showToast('检查失败', 'error');
                    return;
                }
                
                if (checkData.exists) {
                    showToast('该QQ号已存在', 'error');
                    return;
                }
                
                showScanLogin('SL', qq);
                
            } catch (e) {
                showToast('检查失败', 'error');
            }
        }

        async function showAssistLogin() {
            showToast('正在获取...', 'info');
            try {
                const res = await api('/oicq-identity-share-pull', { type: 1, code: 0 });
                if (res.data?.[0]) {
                    prompt('📋 协助登录共享码:', res.data[2]);
                } else {
                    alert('获取失败');
                }
            } catch (e) {}
        }

        async function showExchangeAuth() {
            const id = prompt('请输入兑换序号 (ID):');
            if (!id) return;
            
            if (!/^\d+$/.test(id)) {
                showToast('兑换ID必须为数字', 'error');
                return;
            }
            
            const modal = document.getElementById('exchangeCheckModal');
            const content = document.getElementById('exchangeCheckContent');
            const subtitle = document.getElementById('exchangeCheckSubtitle');
            
            modal.classList.remove('hidden');
            subtitle.textContent = `正在检查兑换ID: ${id}...`;
            content.innerHTML = '<div class="loading-spinner mx-auto mb-4"></div><p class="text-slate-400">正在验证...</p>';
            
            try {
                const checkRes = await fetch('<?php echo basename(__FILE__); ?>?action=check_exchange_permission', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        exchangeId: id
                    })
                });
                
                const checkData = await checkRes.json();
                
                if (checkData.code === 0 && checkData.allowed) {
                    content.innerHTML = `<div class="text-5xl mb-4">✅</div><p class="text-green-400 font-semibold">验证通过</p><p class="text-slate-400 text-sm mt-2">目标QQ: ${checkData.targetAccount}</p>`;
                    
                    setTimeout(async () => {
                        closeExchangeCheckModal();
                        showToast('正在兑换...', 'info');
                        
                        try {
                            const res = await api('/reward-plan-exchange', { id: parseInt(id), day: 1 });
                            alert('兑换结果:\n' + (res.info || JSON.stringify(res, null, 2)));
                        } catch (e) {
                            showToast('兑换请求失败', 'error');
                        }
                    }, 1000);
                    
                } else {
                    content.innerHTML = `<div class="text-5xl mb-4">❌</div><p class="text-red-400 font-semibold">验证失败</p><p class="text-slate-400 text-sm mt-2">${checkData.error || '无权兑换'}</p>`;
                    
                    setTimeout(() => {
                        closeExchangeCheckModal();
                    }, 2000);
                }
            } catch (e) {
                content.innerHTML = `<div class="text-5xl mb-4">❌</div><p class="text-red-400">验证出错</p>`;
                setTimeout(() => {
                    closeExchangeCheckModal();
                }, 2000);
            }
        }

        async function showDeviceName() {
            const input = prompt('修改设备名\n格式: QQ号#设备名\n例如: 123456#iPhone15');
            if (!input) return;
            const [account, name] = input.split('#');
            if (!account || !name) {
                showToast('格式错误', 'error');
                return;
            }
            showToast('正在修改...', 'info');
            try {
                const res = await api('/device-info-push', {
                    account: parseInt(account),
                    key: 'model',
                    value: name
                });
                alert(res.info || '修改成功');
            } catch (e) {}
        }

        async function showShopLogin() {
            const input = prompt('登录资源后台\n格式: 账号#密码');
            if (!input) return;
            const [account, password] = input.split('#');
            if (!account || !password) {
                showToast('格式错误', 'error');
                return;
            }
            showToast('正在登录...', 'info');
            try {
                const res = await api('/shop-login', { account, password });
                alert('登录结果:\n' + JSON.stringify(res, null, 2));
            } catch (e) {}
        }

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            const icon = type === 'error' ? '❌' : type === 'info' ? '⏳' : type === 'warning' ? '⚠️' : '✅';
            document.getElementById('toastIcon').textContent = icon;
            document.getElementById('toastMsg').textContent = msg;
            toast.className = `toast ${type} show`;
            setTimeout(() => toast.classList.remove('show'), 3000);
        }
    </script>
</body>
</html>

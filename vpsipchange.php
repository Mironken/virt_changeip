<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function vpsipchange_config() {
    return [
        'name' => 'Virtualizor批量换IP',
        'description' => '为VPS提供批量更换IPv4地址功能。支持IP段选择、批量操作、自动stop/start。',
        'version' => '2.2 - Fixed API',
        'author' => 'DEBEEIDC',
        'fields' => [
            'admin_api_key' => [
                'FriendlyName' => 'Admin API Key',
                'Type' => 'text',
                'Size' => '50',
                'Description' => 'Virtualizor API Key',
                'Default' => ''
            ],
            'admin_api_pass' => [
                'FriendlyName' => 'Admin API Pass',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'Virtualizor API Pass',
                'Default' => ''
            ],
            'server_ip' => [
                'FriendlyName' => 'Virtualizor IP',
                'Type' => 'text',
                'Size' => '30',
                'Description' => '纯IP就行 (无需http或端口)',
                'Default' => ''
            ],
            'change_fee' => [
                'FriendlyName' => '换IP费用',
                'Type' => 'text',
                'Size' => '10',
                'Description' => '每次更换IP的费用（CNY）',
                'Default' => '10.00'
            ],
            'operation_delay' => [
                'FriendlyName' => '操作间隔（秒）',
                'Type' => 'text',
                'Size' => '5',
                'Description' => '批量操作时每个VPS之间的延迟时间，建议5-10秒',
                'Default' => '8'
            ],
            'stop_wait_time' => [
                'FriendlyName' => 'Stop等待时间（秒）',
                'Type' => 'text',
                'Size' => '5',
                'Description' => 'VPS停止后等待时间，确保完全停止',
                'Default' => '10'
            ],
            'start_wait_time' => [
                'FriendlyName' => 'Start等待时间（秒）',
                'Type' => 'text',
                'Size' => '5',
                'Description' => 'VPS启动后等待时间，确保完全启动',
                'Default' => '15'
            ],
            'enable_batch' => [
                'FriendlyName' => '启用批量操作',
                'Type' => 'yesno',
                'Description' => '允许管理员进行批量IP更换',
                'Default' => 'yes'
            ],
        ]
    ];
}

function vpsipchange_activate() {
    try {
        if (!Capsule::schema()->hasTable('mod_vpsipchange_logs')) {
            Capsule::schema()->create('mod_vpsipchange_logs', function ($table) {
                $table->increments('id');
                $table->integer('userid');
                $table->string('productname', 255);
                $table->integer('vps_id');
                $table->string('old_ip', 50);
                $table->string('new_ip', 50);
                $table->datetime('change_time');
                $table->decimal('fee', 10, 2);
                $table->integer('invoiceid')->default(0);
                $table->text('notes')->nullable();
                $table->index('userid');
                $table->index('vps_id');
                $table->index('change_time');
            });
        }
        
        if (!Capsule::schema()->hasTable('mod_vpsipchange_queue')) {
            Capsule::schema()->create('mod_vpsipchange_queue', function ($table) {
                $table->increments('id');
                $table->integer('vps_id');
                $table->integer('userid');
                $table->integer('invoiceid');
                $table->string('old_ip', 50)->nullable();
                $table->string('new_ip', 50)->nullable();
                $table->string('target_pool', 50)->nullable();
                $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
                $table->text('error_msg')->nullable();
                $table->datetime('created_at');
                $table->datetime('updated_at')->nullable();
                $table->datetime('processed_at')->nullable();
                $table->integer('retry_count')->default(0);
                $table->index('status');
                $table->index('created_at');
            });
        }
        
        if (!Capsule::schema()->hasTable('mod_vpsipchange_pools')) {
            Capsule::schema()->create('mod_vpsipchange_pools', function ($table) {
                $table->increments('id');
                $table->integer('ippid');
                $table->string('ippool_name', 100);
                $table->integer('total_ips')->default(0);
                $table->integer('available_ips')->default(0);
                $table->datetime('last_update');
                $table->unique('ippid');
            });
        }
        
        return ['status' => 'success', 'description' => '模块已激活，数据表已创建'];
    } catch (Exception $e) {
        return ['status' => 'error', 'description' => '激活失败：' . $e->getMessage()];
    }
}

function vpsipchange_deactivate() {
    return ['status' => 'success', 'description' => '模块已停用'];
}

function vpsipchange_output($params) {
    $action = isset($_GET['action']) ? $_GET['action'] : 'logs';
    
    // 刷新IP池信息
    if (isset($_GET['refresh_pools'])) {
        $result = refreshIPPools($params);
        if ($result['success']) {
            echo "<div class='alert alert-success'>";
            echo "<strong>✓ 刷新成功!</strong> {$result['message']}";
            if (!empty($result['pools'])) {
                echo "<br><small style='margin-top:8px;display:block;'>找到 " . count($result['pools']) . " 个IP池：";
                foreach ($result['pools'] as $poolId => $poolData) {
                    echo " <span class='label label-primary'>[{$poolId}] {$poolData['name']} ({$poolData['total']}个IP, {$poolData['available']}可用)</span>";
                }
                echo "</small>";
            }
            echo "</div>";
        } else {
            echo "<div class='alert alert-danger'>";
            echo "<strong>✗ 刷新失败!</strong> {$result['message']}";
            if (!empty($result['debug'])) {
                echo "<hr><strong>调试信息：</strong><pre style='background:#f5f5f5;padding:10px;border-radius:3px;margin-top:10px;'>";
                echo htmlspecialchars($result['debug']);
                echo "</pre>";
            }
            echo "</div>";
        }
    }
    
    // 处理批量操作
    if (isset($_POST['batch_change'])) {
        processBatchChange($params);
    }
    
    // 处理测试
    if (isset($_POST['test_migration'])) {
        processTestMigration($params);
    }
    
    // 处理队列
    if (isset($_GET['process_queue'])) {
        processQueue($params);
        header("Location: addonmodules.php?module=vpsipchange&action=queue");
        exit;
    }
    
    echo '<ul class="nav nav-tabs" role="tablist">';
    echo '<li class="' . ($action == 'logs' ? 'active' : '') . '"><a href="?module=vpsipchange&action=logs">操作日志</a></li>';
    echo '<li class="' . ($action == 'pools' ? 'active' : '') . '"><a href="?module=vpsipchange&action=pools">IP池管理</a></li>';
    echo '<li class="' . ($action == 'test' ? 'active' : '') . '"><a href="?module=vpsipchange&action=test">测试功能</a></li>';
    echo '<li class="' . ($action == 'batch' ? 'active' : '') . '"><a href="?module=vpsipchange&action=batch">批量操作</a></li>';
    echo '<li class="' . ($action == 'queue' ? 'active' : '') . '"><a href="?module=vpsipchange&action=queue">操作队列</a></li>';
    echo '</ul>';
    echo '<div style="padding-top: 20px;"></div>';
    
    switch ($action) {
        case 'pools':
            displayIPPools($params);
            break;
        case 'test':
            displayTestMigration($params);
            break;
        case 'batch':
            displayBatchOperation($params);
            break;
        case 'queue':
            displayQueue($params);
            break;
        default:
            displayLogs($params);
            break;
    }
}

function displayLogs($params) {
    $logs = Capsule::table('mod_vpsipchange_logs')->orderBy('id','desc')->limit(100)->get();
    
    $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
    $systemUrl = rtrim($systemUrl, '/');
    $adminDirName = basename(dirname($_SERVER['SCRIPT_NAME']));
    
    echo "<h2>IP更换记录</h2>";
    echo "<table class='table table-striped'>";
    echo "<thead><tr><th>ID</th><th>用户</th><th>产品</th><th>VPS ID</th><th>旧IP</th><th>新IP</th><th>时间</th><th>费用</th><th>账单</th><th>备注</th></tr></thead>";
    echo "<tbody>";
    foreach($logs as $log){
        $userLink = "clientssummary.php?userid={$log->userid}";
        $invoiceLink = "{$systemUrl}/{$adminDirName}/invoices.php?action=edit&id={$log->invoiceid}";
        
        echo "<tr>";
        echo "<td>{$log->id}</td>";
        echo "<td><a href=\"{$userLink}\" target=\"_blank\">{$log->userid}</a></td>";
        echo "<td>{$log->productname}</td>";
        echo "<td>{$log->vps_id}</td>";
        echo "<td><code>{$log->old_ip}</code></td>";
        echo "<td><code>{$log->new_ip}</code></td>";
        echo "<td>{$log->change_time}</td>";
        echo "<td>¥{$log->fee}</td>";
        echo "<td>" . ($log->invoiceid > 0 ? "<a href=\"{$invoiceLink}\" target=\"_blank\" class=\"btn btn-xs btn-info\">#{$log->invoiceid}</a>" : "-") . "</td>";
        echo "<td>{$log->notes}</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
}

function displayIPPools($params) {
    echo "<h2>IP池管理</h2>";
    echo "<a href='?module=vpsipchange&action=pools&refresh_pools=1' class='btn btn-primary' style='margin-bottom: 15px;'>";
    echo "<i class='fa fa-refresh'></i> 刷新IP池信息</a>";
    
    $pools = Capsule::table('mod_vpsipchange_pools')->orderBy('ippid')->get();
    
    if (empty($pools)) {
        echo "<div class='alert alert-info'><strong>提示：</strong> 请先点击上方\"刷新IP池信息\"按钮获取数据</div>";
        return;
    }
    
    echo "<table class='table table-striped'>";
    echo "<thead><tr><th>池ID</th><th>池名称</th><th>总IP数</th><th>可用IP数</th><th>使用率</th><th>最后更新</th></tr></thead>";
    echo "<tbody>";
    foreach($pools as $pool){
        $usageRate = $pool->total_ips > 0 ? round(($pool->total_ips - $pool->available_ips) / $pool->total_ips * 100, 2) : 0;
        $alertClass = $pool->available_ips < 10 ? 'danger' : ($pool->available_ips < 50 ? 'warning' : 'success');
        
        echo "<tr class='{$alertClass}'>";
        echo "<td><strong>{$pool->ippid}</strong></td>";
        echo "<td>{$pool->ippool_name}</td>";
        echo "<td>{$pool->total_ips}</td>";
        echo "<td><strong>{$pool->available_ips}</strong></td>";
        echo "<td>{$usageRate}%</td>";
        echo "<td>{$pool->last_update}</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    
    echo "<div class='alert alert-info'>";
    echo "<strong>颜色说明：</strong> ";
    echo "<span class='label label-danger'>红色</span> 可用IP < 10 | ";
    echo "<span class='label label-warning'>黄色</span> 可用IP < 50 | ";
    echo "<span class='label label-success'>绿色</span> 可用IP充足";
    echo "</div>";
}

function displayTestMigration($params) {
    echo "<h2>IP迁移测试</h2>";
    
    $pools = Capsule::table('mod_vpsipchange_pools')->orderBy('ippid')->get();
    
    if (empty($pools)) {
        echo "<div class='alert alert-danger'>请先在\"IP池管理\"中刷新IP池信息</div>";
        return;
    }
    
    echo "<form method='post' onsubmit='return confirmTest();'>";
    echo "<input type='hidden' name='test_migration' value='1'>";
    
    echo "<div class='row'>";
    echo "<div class='col-md-6'>";
    echo "<div class='panel panel-info'>";
    echo "<div class='panel-heading'><strong>测试参数</strong></div>";
    echo "<div class='panel-body'>";
    
    echo "<div class='form-group'>";
    echo "<label>测试模式</label>";
    echo "<select name='test_mode' id='test_mode' class='form-control' onchange='toggleTestMode()' required>";
    echo "<option value='auto'>自动选择VPS</option>";
    echo "<option value='manual'>手动指定VPS ID</option>";
    echo "</select>";
    echo "</div>";
    
    echo "<div id='auto_mode_options'>";
    echo "<div class='form-group'>";
    echo "<label>源IP池</label>";
    echo "<select name='test_source_pool' id='test_source_pool' class='form-control'>";
    echo "<option value=''>选择源IP池...</option>";
    foreach ($pools as $pool) {
        echo "<option value='{$pool->ippid}'>[{$pool->ippid}] {$pool->ippool_name} (总:{$pool->total_ips})</option>";
    }
    echo "</select>";
    echo "</div>";
    
    echo "<div class='form-group'>";
    echo "<label>测试VPS数量</label>";
    echo "<select name='test_count' class='form-control'>";
    echo "<option value='1'>1台 (快速测试)</option>";
    echo "<option value='3'>3台 (完整测试)</option>";
    echo "</select>";
    echo "</div>";
    echo "</div>";
    
    echo "<div id='manual_mode_options' style='display:none;'>";
    echo "<div class='form-group'>";
    echo "<label>VPS ID列表</label>";
    echo "<textarea name='manual_vps_ids' id='manual_vps_ids' class='form-control' rows='4' placeholder='输入VPS ID，每行一个或用逗号分隔&#10;例如：&#10;384&#10;385&#10;386&#10;&#10;或：384,385,386'></textarea>";
    echo "<p class='help-block'>输入要测试的VPS ID，每行一个或用逗号分隔，最多10台</p>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='form-group'>";
    echo "<label>目标IP池</label>";
    echo "<select name='test_target_pool' class='form-control' required>";
    echo "<option value=''>选择目标IP池...</option>";
    foreach ($pools as $pool) {
        echo "<option value='{$pool->ippid}'>[{$pool->ippid}] {$pool->ippool_name} (可用:{$pool->available_ips})</option>";
    }
    echo "</select>";
    echo "</div>";
    
    echo "<button type='submit' class='btn btn-success btn-lg btn-block'>";
    echo "<i class='fa fa-flask'></i> 开始测试</button>";
    
    echo "</div></div></div>";
    
    echo "<div class='col-md-6'>";
    echo "<div class='panel panel-warning'>";
    echo "<div class='panel-heading'><strong>测试说明</strong></div>";
    echo "<div class='panel-body'>";
    echo "<ol>";
    echo "<li><strong>自动模式：</strong>从源IP池中查找已使用的IP对应的VPS进行测试</li>";
    echo "<li><strong>手动模式：</strong>指定具体的VPS ID进行测试</li>";
    echo "<li>每台VPS将执行：停止→换IP→启动</li>";
    echo "<li>测试过程会详细记录每个步骤</li>";
    echo "<li><strong>注意：测试会实际停止和重启VPS</strong></li>";
    echo "<li>建议先选择测试环境的VPS</li>";
    echo "<li>确保目标IP池有足够的可用IP</li>";
    echo "</ol>";
    echo "</div></div></div>";
    
    echo "</div></form>";
    
    echo "<script>";
    echo "function toggleTestMode() {";
    echo "  var mode = document.getElementById('test_mode').value;";
    echo "  var autoOptions = document.getElementById('auto_mode_options');";
    echo "  var manualOptions = document.getElementById('manual_mode_options');";
    echo "  var sourcePool = document.getElementById('test_source_pool');";
    echo "  ";
    echo "  if (mode === 'manual') {";
    echo "    autoOptions.style.display = 'none';";
    echo "    manualOptions.style.display = 'block';";
    echo "    sourcePool.removeAttribute('required');";
    echo "  } else {";
    echo "    autoOptions.style.display = 'block';";
    echo "    manualOptions.style.display = 'none';";
    echo "    sourcePool.setAttribute('required', 'required');";
    echo "  }";
    echo "}";
    echo "function confirmTest() {";
    echo "  var mode = document.getElementById('test_mode').value;";
    echo "  var message = '确认开始测试？\\n\\n';";
    echo "  ";
    echo "  if (mode === 'manual') {";
    echo "    var vpsIds = document.getElementById('manual_vps_ids').value.trim();";
    echo "    if (!vpsIds) {";
    echo "      alert('请输入要测试的VPS ID！');";
    echo "      return false;";
    echo "    }";
    echo "    var ids = vpsIds.split(/[,\\n\\s]+/).filter(function(id) { return id.trim(); });";
    echo "    if (ids.length > 10) {";
    echo "      alert('最多只能测试10台VPS！');";
    echo "      return false;";
    echo "    }";
    echo "    message += '将测试 ' + ids.length + ' 台指定的VPS\\n';";
    echo "    message += 'VPS ID: ' + ids.join(', ') + '\\n\\n';";
    echo "  } else {";
    echo "    var count = document.querySelector('[name=test_count]').value;";
    echo "    message += '将从源IP池自动选择 ' + count + ' 台VPS进行测试\\n\\n';";
    echo "  }";
    echo "  ";
    echo "  message += '测试期间VPS将被停止并重启\\n\\n';";
    echo "  message += '请确保已选择正确的VPS和目标IP池';";
    echo "  return confirm(message);";
    echo "}";
    echo "</script>";
}

function displayBatchOperation($params) {
    if ($params['enable_batch'] != 'on') {
        echo "<div class='alert alert-warning'>批量操作功能未启用，请在模块配置中启用。</div>";
        return;
    }
    
    echo "<h2>批量IP更换</h2>";
    
    $pools = Capsule::table('mod_vpsipchange_pools')->orderBy('ippid')->get();
    
    if (empty($pools)) {
        echo "<div class='alert alert-danger'>请先在\"IP池管理\"中刷新IP池信息</div>";
        return;
    }
    
    echo "<form method='post' onsubmit='return confirmBatchChange();'>";
    echo "<input type='hidden' name='batch_change' value='1'>";
    
    echo "<div class='panel panel-default'>";
    echo "<div class='panel-heading'><strong>选择IP段</strong></div>";
    echo "<div class='panel-body'>";
    
    echo "<div class='form-group'>";
    echo "<label>源IP段（将要更换的IP段）</label>";
    echo "<select name='source_pool' class='form-control' required>";
    echo "<option value=''>选择IP池...</option>";
    foreach ($pools as $pool) {
        echo "<option value='{$pool->ippid}'>{$pool->ippool_name} (ID:{$pool->ippid}, 总:{$pool->total_ips})</option>";
    }
    echo "</select>";
    echo "<p class='help-block'>选择包含需要更换IP的VPS所在的IP池</p>";
    echo "</div>";
    
    echo "<div class='form-group'>";
    echo "<label>目标IP段（更换到的IP段）</label>";
    echo "<select name='target_pool' class='form-control' required>";
    echo "<option value=''>选择IP池...</option>";
    foreach ($pools as $pool) {
        echo "<option value='{$pool->ippid}'>{$pool->ippool_name} (ID:{$pool->ippid}, 可用:{$pool->available_ips})</option>";
    }
    echo "</select>";
    echo "<p class='help-block'>选择新IP将从哪个IP池分配</p>";
    echo "</div>";
    
    echo "<div class='checkbox'>";
    echo "<label><input type='checkbox' name='only_active' value='1' checked> 仅处理Active状态的VPS</label>";
    echo "</div>";
    
    echo "<div class='checkbox'>";
    echo "<label><input type='checkbox' name='create_invoice' value='1'> 为每个VPS创建账单（费用：¥{$params['change_fee']}）</label>";
    echo "</div>";
    
    echo "</div>";
    echo "</div>";
    
    echo "<div class='alert alert-info'>";
    echo "<strong>批量操作说明：</strong><br>";
    echo "1. 系统将查找源IP段中的所有VPS<br>";
    echo "2. 从目标IP段中为每个VPS分配新IP<br>";
    echo "3. 逐个执行：停止VPS → 更换IP → 启动VPS<br>";
    echo "4. 每个VPS操作间隔{$params['operation_delay']}秒<br>";
    echo "5. 所有操作将进入队列，可以在\"操作队列\"中查看进度";
    echo "</div>";
    
    echo "<button type='submit' class='btn btn-danger btn-lg'>";
    echo "<i class='fa fa-exchange'></i> 开始批量更换</button>";
    
    echo "</form>";
    
    echo "<script>";
    echo "function confirmBatchChange() {";
    echo "  return confirm('确定要执行批量IP更换吗？\\n\\n这将影响源IP段中的所有VPS。\\n\\n操作期间VPS将被停止并重启。\\n\\n请确保：\\n1. 目标IP段有足够的可用IP\\n2. 选择了正确的源和目标IP段\\n3. 已做好备份');";
    echo "}";
    echo "</script>";
}

function displayQueue($params) {
    $queue = Capsule::table('mod_vpsipchange_queue')
        ->orderBy('created_at', 'desc')
        ->limit(100)
        ->get();
    
    $stats = Capsule::table('mod_vpsipchange_queue')
        ->selectRaw('status, COUNT(*) as count')
        ->groupBy('status')
        ->pluck('count', 'status');
    
    echo "<h2>操作队列</h2>";
    
    echo "<div class='row' style='margin-bottom: 20px;'>";
    echo "<div class='col-md-3'>";
    echo "<div class='panel panel-warning'><div class='panel-body text-center'>";
    echo "<h3>" . ($stats['pending'] ?? 0) . "</h3><p>等待处理</p>";
    echo "</div></div></div>";
    
    echo "<div class='col-md-3'>";
    echo "<div class='panel panel-info'><div class='panel-body text-center'>";
    echo "<h3>" . ($stats['processing'] ?? 0) . "</h3><p>处理中</p>";
    echo "</div></div></div>";
    
    echo "<div class='col-md-3'>";
    echo "<div class='panel panel-success'><div class='panel-body text-center'>";
    echo "<h3>" . ($stats['completed'] ?? 0) . "</h3><p>已完成</p>";
    echo "</div></div></div>";
    
    echo "<div class='col-md-3'>";
    echo "<div class='panel panel-danger'><div class='panel-body text-center'>";
    echo "<h3>" . ($stats['failed'] ?? 0) . "</h3><p>失败</p>";
    echo "</div></div></div>";
    echo "</div>";
    
    if (($stats['pending'] ?? 0) > 0) {
        echo "<a href='?module=vpsipchange&action=queue&process_queue=1' class='btn btn-primary' style='margin-bottom: 15px;'>";
        echo "<i class='fa fa-play'></i> 处理队列（逐个执行）</a>";
        echo "<span class='help-block'>注意：处理大量VPS可能需要较长时间，请耐心等待。</span>";
    }
    
    echo "<table class='table table-striped'>";
    echo "<thead><tr><th>ID</th><th>VPS ID</th><th>用户</th><th>旧IP</th><th>新IP→目标池</th><th>状态</th><th>创建时间</th><th>处理时间</th><th>错误信息</th><th>重试</th></tr></thead>";
    echo "<tbody>";
    foreach($queue as $item){
        $statusClass = [
            'pending' => 'warning',
            'processing' => 'info',
            'completed' => 'success',
            'failed' => 'danger'
        ][$item->status] ?? 'default';
        
        $statusText = [
            'pending' => '等待',
            'processing' => '处理中',
            'completed' => '完成',
            'failed' => '失败'
        ][$item->status] ?? $item->status;
        
        echo "<tr>";
        echo "<td>{$item->id}</td>";
        echo "<td>{$item->vps_id}</td>";
        echo "<td>{$item->userid}</td>";
        echo "<td><code>" . ($item->old_ip ?: '-') . "</code></td>";
        echo "<td><code>" . ($item->new_ip ?: "Pool:{$item->target_pool}") . "</code></td>";
        echo "<td><span class='label label-{$statusClass}'>{$statusText}</span></td>";
        echo "<td>{$item->created_at}</td>";
        echo "<td>" . ($item->processed_at ?: '-') . "</td>";
        echo "<td><small>" . ($item->error_msg ?: '-') . "</small></td>";
        echo "<td>{$item->retry_count}</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
}

/**
 * 刷新IP池信息 - 使用Virtualizor SDK的正确方法
 */
function refreshIPPools($params) {
    try {
        $key = $params['admin_api_key'];
        $pass = $params['admin_api_pass'];
        $ip = $params['server_ip'];
        
        if (empty($key) || empty($pass) || empty($ip)) {
            return [
                'success' => false,
                'message' => 'API配置不完整，请检查模块配置',
                'debug' => 'API Key、API Pass或Server IP为空'
            ];
        }
        
        require_once(ROOTDIR . '/modules/servers/virtualizor/sdk/admin.php');
        $admin = new Virtualizor_Admin_API($ip, $key, $pass);
        
        // 使用ippool方法获取所有IP池 - 按照官方文档
        $page = 1;
        $reslen = 1000; // 每页1000条记录
        $post = array();
        
        $poolsData = $admin->ippool($page, $reslen, $post);
        
        if (!isset($poolsData['ippools']) || empty($poolsData['ippools'])) {
            return [
                'success' => false,
                'message' => 'API调用成功但未找到任何IP池',
                'debug' => print_r($poolsData, true)
            ];
        }
        
        $ippools = $poolsData['ippools'];
        $poolStats = [];
        $updatedCount = 0;
        
        // 直接使用API返回的totalip和freeip
        foreach ($ippools as $poolId => $poolData) {
            // 只处理IPv4池（跳过IPv6）
            if (isset($poolData['ipv6']) && $poolData['ipv6'] == '1') {
                continue;
            }
            
            $totalip = isset($poolData['totalip']) ? (int)$poolData['totalip'] : 0;
            $freeip = isset($poolData['freeip']) ? (int)$poolData['freeip'] : 0;
            
            if ($totalip > 0) {
                Capsule::table('mod_vpsipchange_pools')
                    ->updateOrInsert(
                        ['ippid' => $poolId],
                        [
                            'ippid' => $poolId,
                            'ippool_name' => $poolData['ippool_name'] ?? 'Unknown',
                            'total_ips' => $totalip,
                            'available_ips' => $freeip,
                            'last_update' => date('Y-m-d H:i:s')
                        ]
                    );
                
                $poolStats[$poolId] = [
                    'name' => $poolData['ippool_name'] ?? 'Unknown',
                    'total' => $totalip,
                    'available' => $freeip
                ];
                
                $updatedCount++;
            }
        }
        
        logActivity("IP池信息更新成功，更新了 {$updatedCount} 个IPv4池");
        
        return [
            'success' => true,
            'message' => "成功更新 {$updatedCount} 个IP池的信息（仅IPv4）",
            'pools' => $poolStats
        ];
        
    } catch (Exception $e) {
        logActivity("刷新IP池失败：" . $e->getMessage());
        return [
            'success' => false,
            'message' => '刷新失败：' . $e->getMessage(),
            'debug' => $e->getTraceAsString()
        ];
    }
}

/**
 * 处理测试迁移
 */
function processTestMigration($params) {
    try {
        $testMode = isset($_POST['test_mode']) ? $_POST['test_mode'] : 'auto';
        $targetPool = (int)$_POST['test_target_pool'];
        
        if ($targetPool <= 0) {
            echo "<div class='alert alert-danger'>请选择有效的目标IP池</div>";
            return;
        }
        
        $key = $params['admin_api_key'];
        $pass = $params['admin_api_pass'];
        $ip = $params['server_ip'];
        
        require_once(ROOTDIR . '/modules/servers/virtualizor/sdk/admin.php');
        $admin = new Virtualizor_Admin_API($ip, $key, $pass);
        
        $testVPS = [];
        
        if ($testMode === 'manual') {
            // 手动模式：解析用户输入的VPS ID
            $manualIds = isset($_POST['manual_vps_ids']) ? $_POST['manual_vps_ids'] : '';
            
            if (empty(trim($manualIds))) {
                echo "<div class='alert alert-danger'>请输入要测试的VPS ID</div>";
                return;
            }
            
            // 支持逗号、换行、空格分隔
            $vpsIds = preg_split('/[,\n\s]+/', $manualIds, -1, PREG_SPLIT_NO_EMPTY);
            $vpsIds = array_map('trim', $vpsIds);
            $vpsIds = array_filter($vpsIds, 'is_numeric');
            $vpsIds = array_unique($vpsIds);
            $vpsIds = array_slice($vpsIds, 0, 10); // 最多10台
            
            if (empty($vpsIds)) {
                echo "<div class='alert alert-danger'>未找到有效的VPS ID</div>";
                return;
            }
            
            // 验证每个VPS ID并获取当前IP
            foreach ($vpsIds as $vpsId) {
                $vpsId = (int)$vpsId;
                
                // 修复：使用正确的listvs调用方式
                $vpsInfo = $admin->listvs(1, 1, array('vpsid' => $vpsId));
                
                if (isset($vpsInfo['vs'][$vpsId])) {
                    $vps = $vpsInfo['vs'][$vpsId];
                    $oldIPs = $vps['ips'] ?? [];
                    
                    $oldIPv4 = null;
                    foreach ($oldIPs as $ipAddr) {
                        if (filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $oldIPv4 = $ipAddr;
                            break;
                        }
                    }
                    
                    if ($oldIPv4) {
                        $testVPS[] = [
                            'vpsid' => $vpsId,
                            'old_ip' => $oldIPv4
                        ];
                    } else {
                        echo "<div class='alert alert-warning'>VPS {$vpsId} 未找到IPv4地址，已跳过</div>";
                    }
                } else {
                    echo "<div class='alert alert-warning'>VPS {$vpsId} 不存在或无法访问，已跳过</div>";
                }
            }
            
            if (empty($testVPS)) {
                echo "<div class='alert alert-danger'>没有可测试的VPS</div>";
                return;
            }
            
        } else {
            // 自动模式：从源IP池选择
            $sourcePool = (int)$_POST['test_source_pool'];
            $testCount = (int)$_POST['test_count'];
            
            if ($sourcePool <= 0) {
                echo "<div class='alert alert-danger'>请选择有效的源IP池</div>";
                return;
            }
            
            if ($sourcePool == $targetPool) {
                echo "<div class='alert alert-warning'>源和目标IP池相同，测试已取消</div>";
                return;
            }
            
            if (!in_array($testCount, [1, 3])) {
                echo "<div class='alert alert-danger'>无效的测试数量</div>";
                return;
            }
            
            // 获取源池中的IP
            $post = array(
                'ippid' => $sourcePool,
                'servers_search' => -1
            );
            $ipsData = $admin->ips(1, 1000, $post);
            
            if (!isset($ipsData['ips'])) {
                echo "<div class='alert alert-danger'>无法从源IP池获取IP列表</div>";
                return;
            }
            
            // 找出源池中已使用的IP
            foreach ($ipsData['ips'] as $ipInfo) {
                if (isset($ipInfo['vpsid']) && $ipInfo['vpsid'] > 0) {
                    $testVPS[] = [
                        'vpsid' => $ipInfo['vpsid'],
                        'old_ip' => $ipInfo['ip']
                    ];
                    if (count($testVPS) >= $testCount) {
                        break;
                    }
                }
            }
            
            if (empty($testVPS)) {
                echo "<div class='alert alert-warning'>源IP池中没有正在使用的VPS</div>";
                return;
            }
        }
        
        echo "<div class='panel panel-info'>";
        echo "<div class='panel-heading'><strong>测试开始 - 共 " . count($testVPS) . " 台VPS</strong></div>";
        echo "<div class='panel-body'>";
        
        $stopWait = (int)($params['stop_wait_time'] ?? 10);
        $startWait = (int)($params['start_wait_time'] ?? 15);
        $successCount = 0;
        $failCount = 0;
        
        foreach ($testVPS as $index => $vpsInfo) {
            $vpsNum = $index + 1;
            echo "<div class='well' style='margin-bottom:15px;'>";
            echo "<h4>测试 #{$vpsNum} - VPS ID: {$vpsInfo['vpsid']}</h4>";
            echo "<p><strong>原IP:</strong> <code>{$vpsInfo['old_ip']}</code></p>";
            
            // 获取新IP
            echo "<p>→ 从目标池获取可用IP...</p>";
            $newIP = getAvailableIPFromPool($ip, $key, $pass, $targetPool, $vpsInfo['old_ip']);
            
            if (!$newIP) {
                echo "<p class='text-danger'>✗ 失败：目标IP池没有可用IP</p>";
                echo "</div>";
                $failCount++;
                continue;
            }
            
            echo "<p class='text-success'>✓ 获取到新IP: <code>{$newIP}</code></p>";
            
            // 停止VPS
            echo "<p>→ 停止VPS...</p>";
            $stopResult = $admin->stop($vpsInfo['vpsid']);
            if (!isset($stopResult['done']) || !$stopResult['done']) {
                echo "<p class='text-danger'>✗ VPS停止失败</p>";
                echo "</div>";
                $failCount++;
                continue;
            }
            echo "<p class='text-success'>✓ VPS已停止，等待{$stopWait}秒</p>";
            sleep($stopWait);
            
            // 获取VPS信息
            echo "<p>→ 获取VPS配置...</p>";
            // 修复：使用正确的listvs调用方式
            $vpsListData = $admin->listvs(1, 1, array('vpsid' => $vpsInfo['vpsid']));
            if (!isset($vpsListData['vs'][$vpsInfo['vpsid']])) {
                echo "<p class='text-danger'>✗ 无法获取VPS信息</p>";
                $admin->start($vpsInfo['vpsid']);
                echo "</div>";
                $failCount++;
                continue;
            }
            
            $vps = $vpsListData['vs'][$vpsInfo['vpsid']];
            echo "<p class='text-success'>✓ 获取到VPS配置</p>";
            
            // 更换IP
            echo "<p>→ 更换IP...</p>";
            $post = [
                'vpsid' => $vpsInfo['vpsid'],
                'ips' => [$newIP],
                'hostname' => $vps['hostname'] ?? '',
                'ram' => $vps['ram'] ?? 512,
                'cores' => $vps['cores'] ?? 1,
                'bandwidth' => $vps['bandwidth'] ?? 0,
            ];
            
            $manageResult = $admin->managevps($post);
            
            if (!isset($manageResult['done']['done']) || !$manageResult['done']['done']) {
                echo "<p class='text-danger'>✗ IP更换失败</p>";
                $admin->start($vpsInfo['vpsid']);
                echo "</div>";
                $failCount++;
                continue;
            }
            echo "<p class='text-success'>✓ IP更换成功</p>";
            sleep(3);
            
            // 启动VPS
            echo "<p>→ 启动VPS...</p>";
            $startResult = $admin->start($vpsInfo['vpsid']);
            if (!isset($startResult['done']) || !$startResult['done']) {
                echo "<p class='text-warning'>⚠ VPS启动可能失败</p>";
            } else {
                echo "<p class='text-success'>✓ VPS已启动，等待{$startWait}秒</p>";
            }
            sleep($startWait);
            
            // 记录日志
            $hosting = vpsipchange_findHostingByVPSID($vpsInfo['vpsid']);
            $productName = '测试VPS';
            $userId = 0;
            
            if ($hosting) {
                $service = Capsule::table('tblhosting')
                    ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
                    ->where('tblhosting.id', $hosting->id)
                    ->select('tblproducts.name as productname', 'tblhosting.userid')
                    ->first();
                if ($service) {
                    $productName = $service->productname;
                    $userId = $service->userid;
                }
            }
            
            Capsule::table('mod_vpsipchange_logs')->insert([
                'userid' => $userId,
                'productname' => $productName,
                'vps_id' => $vpsInfo['vpsid'],
                'old_ip' => $vpsInfo['old_ip'],
                'new_ip' => $newIP,
                'change_time' => date('Y-m-d H:i:s'),
                'fee' => 0,
                'invoiceid' => 0,
                'notes' => "测试迁移 #{$vpsNum}"
            ]);
            
            echo "<p class='text-success'><strong>✓ 测试 #{$vpsNum} 完成!</strong></p>";
            echo "</div>";
            $successCount++;
            
            // 多台测试时增加间隔
            if ($testCount > 1 && $index < count($testVPS) - 1) {
                echo "<p class='text-muted'>等待5秒后继续下一个测试...</p>";
                sleep(5);
            }
        }
        
        echo "</div>";
        echo "<div class='panel-footer'>";
        echo "<strong>测试完成！</strong> ";
        echo "成功: <span class='text-success'>{$successCount}</span> | ";
        echo "失败: <span class='text-danger'>{$failCount}</span>";
        echo "</div>";
        echo "</div>";
        
        logActivity("IP迁移测试完成，成功{$successCount}个，失败{$failCount}个");
        
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>测试失败：" . htmlspecialchars($e->getMessage()) . "</div>";
        logActivity("IP迁移测试失败：" . $e->getMessage());
    }
}

function processBatchChange($params) {
    try {
        $sourcePool = isset($_POST['source_pool']) ? (int)$_POST['source_pool'] : 0;
        $targetPool = isset($_POST['target_pool']) ? (int)$_POST['target_pool'] : 0;
        $onlyActive = isset($_POST['only_active']) ? 1 : 0;
        $createInvoice = isset($_POST['create_invoice']) ? 1 : 0;
        
        if ($sourcePool <= 0 || $targetPool <= 0) {
            echo "<div class='alert alert-danger'>请选择有效的源和目标IP池</div>";
            return;
        }
        
        if ($sourcePool == $targetPool) {
            echo "<div class='alert alert-warning'>源和目标IP池相同，操作已取消</div>";
            return;
        }
        
        $key = $params['admin_api_key'];
        $pass = $params['admin_api_pass'];
        $ip = $params['server_ip'];
        
        require_once(ROOTDIR . '/modules/servers/virtualizor/sdk/admin.php');
        $admin = new Virtualizor_Admin_API($ip, $key, $pass);
        
        $ipsData = $admin->ips(1, 1000, ['ippid' => $sourcePool]);
        
        if (!isset($ipsData['ips'])) {
            echo "<div class='alert alert-danger'>无法从源IP池获取IP列表</div>";
            return;
        }
        
        $vpsIds = [];
        foreach ($ipsData['ips'] as $ipInfo) {
            if (isset($ipInfo['vpsid']) && $ipInfo['vpsid'] > 0) {
                $vpsIds[] = [
                    'vpsid' => $ipInfo['vpsid'],
                    'old_ip' => $ipInfo['ip']
                ];
            }
        }
        
        if (empty($vpsIds)) {
            echo "<div class='alert alert-warning'>源IP池中没有分配给VPS的IP</div>";
            return;
        }
        
        $count = 0;
        foreach ($vpsIds as $vpsInfo) {
            if ($onlyActive) {
                $hosting = vpsipchange_findHostingByVPSID($vpsInfo['vpsid']);
                if (!$hosting || $hosting->domainstatus != 'Active') {
                    continue;
                }
            }
            
            $invoiceId = 0;
            $userId = 0;
            
            if ($createInvoice) {
                $hosting = vpsipchange_findHostingByVPSID($vpsInfo['vpsid']);
                if ($hosting) {
                    $userId = $hosting->userid;
                    $invoiceId = localAPI('CreateInvoice', [
                        'userid' => $userId,
                        'status' => 'Unpaid',
                        'sendinvoice' => true,
                        'itemdescription' => ['VPS更换IP费用'],
                        'itemamount' => [$params['change_fee']],
                        'itemtaxed' => [false]
                    ])['invoiceid'] ?? 0;
                }
            }
            
            Capsule::table('mod_vpsipchange_queue')->insert([
                'vps_id' => $vpsInfo['vpsid'],
                'userid' => $userId,
                'invoiceid' => $invoiceId,
                'old_ip' => $vpsInfo['old_ip'],
                'target_pool' => $targetPool,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            $count++;
        }
        
        echo "<div class='alert alert-success'>";
        echo "<strong>成功!</strong> 已添加 {$count} 个VPS到队列中。";
        echo "<br><a href='?module=vpsipchange&action=queue' class='btn btn-primary btn-sm' style='margin-top:10px;'>查看队列</a>";
        echo "</div>";
        
        logActivity("批量换IP：添加了 {$count} 个任务到队列");
        
    } catch (Exception $e) {
        echo "<div class='alert alert-danger'>批量操作失败：" . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

function processQueue($params) {
    try {
        $tasks = Capsule::table('mod_vpsipchange_queue')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit(20)
            ->get();
        
        if (empty($tasks)) {
            return;
        }
        
        $operationDelay = (int)($params['operation_delay'] ?? 8);
        
        foreach ($tasks as $task) {
            Capsule::table('mod_vpsipchange_queue')
                ->where('id', $task->id)
                ->update([
                    'status' => 'processing',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            $result = changeVPSIPFromQueue($task, $params);
            
            if ($result['success']) {
                Capsule::table('mod_vpsipchange_queue')
                    ->where('id', $task->id)
                    ->update([
                        'status' => 'completed',
                        'new_ip' => $result['new_ip'],
                        'processed_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            } else {
                Capsule::table('mod_vpsipchange_queue')
                    ->where('id', $task->id)
                    ->update([
                        'status' => 'failed',
                        'error_msg' => $result['error'],
                        'updated_at' => date('Y-m-d H:i:s'),
                        'retry_count' => $task->retry_count + 1
                    ]);
            }
            
            sleep($operationDelay);
        }
        
        logActivity("队列处理完成，处理了 " . count($tasks) . " 个任务");
        
    } catch (Exception $e) {
        logActivity("处理队列失败：" . $e->getMessage());
    }
}

function changeVPSIPFromQueue($task, $params) {
    try {
        $key = $params['admin_api_key'];
        $pass = $params['admin_api_pass'];
        $ip = $params['server_ip'];
        
        $stopWait = (int)($params['stop_wait_time'] ?? 10);
        $startWait = (int)($params['start_wait_time'] ?? 15);
        
        require_once(ROOTDIR . '/modules/servers/virtualizor/sdk/admin.php');
        $admin = new Virtualizor_Admin_API($ip, $key, $pass);

        // 获取VPS信息以获得所有当前IP
        $vpsInfo = $admin->listvs(1, 1, array('vpsid' => $task->vps_id));
        if (!isset($vpsInfo['vs'][$task->vps_id])) {
            return ['success' => false, 'error' => '无法获取VPS信息'];
        }

        $vps = $vpsInfo['vs'][$task->vps_id];
        $oldIPs = $vps['ips'] ?? [];

        // 分离IPv4和IPv6地址
        $oldIPv4List = [];
        $oldIPv6List = [];
        foreach ($oldIPs as $ipAddr) {
            if (filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $oldIPv4List[] = $ipAddr;
            } elseif (filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $oldIPv6List[] = $ipAddr;
            }
        }

        if (empty($oldIPv4List)) {
            return ['success' => false, 'error' => '未找到IPv4地址'];
        }

        $ipv4Count = count($oldIPv4List);

        // 获取新的IPv4地址（与旧IPv4数量相同）
        $newIPv4List = [];
        for ($i = 0; $i < $ipv4Count; $i++) {
            $excludeIPs = array_merge($oldIPv4List, $newIPv4List);

            $newIP = getAvailableIPFromPool($ip, $key, $pass, (int)$task->target_pool, implode(',', $excludeIPs));

            if (!$newIP) {
                return ['success' => false, 'error' => "IP池可用IP不足，需要{$ipv4Count}个，只获取到{$i}个"];
            }

            $newIPv4List[] = $newIP;
        }

        // 构建新的IP列表：所有新IPv4 + 所有IPv6
        $newIPList = array_merge($newIPv4List, $oldIPv6List);

        // 先更换IP配置
        $post = [
            'vpsid' => $task->vps_id,
            'ips' => $newIPList,
            'hostname' => $vps['hostname'] ?? '',
            'ram' => $vps['ram'] ?? 512,
            'cores' => $vps['cores'] ?? 1,
            'bandwidth' => $vps['bandwidth'] ?? 0,
        ];

        $manageResult = $admin->managevps($post);

        if (!isset($manageResult['done']['done']) || !$manageResult['done']['done']) {
            return ['success' => false, 'error' => 'IP配置更新失败'];
        }

        sleep(3);

        // 再停止VPS
        $stopResult = $admin->stop($task->vps_id);
        if (!isset($stopResult['done']) || !$stopResult['done']) {
            logActivity("警告: VPS {$task->vps_id} 停止可能失败");
        }

        sleep($stopWait);

        // 启动VPS
        $startResult = $admin->start($task->vps_id);
        if (!isset($startResult['done']) || !$startResult['done']) {
            logActivity("警告: VPS {$task->vps_id} 启动可能失败");
        }
        
        sleep($startWait);

        if ($task->userid > 0) {
            $hosting = vpsipchange_findHostingByVPSID($task->vps_id);
            $service = null;

            if ($hosting) {
                $service = Capsule::table('tblhosting')
                    ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
                    ->where('tblhosting.id', $hosting->id)
                    ->select('tblproducts.name as productname')
                    ->first();
            }

            Capsule::table('mod_vpsipchange_logs')->insert([
                'userid' => $task->userid,
                'productname' => $service->productname ?? '未知产品',
                'vps_id' => $task->vps_id,
                'old_ip' => implode(', ', $oldIPv4List),
                'new_ip' => implode(', ', $newIPv4List),
                'change_time' => date('Y-m-d H:i:s'),
                'fee' => 0,
                'invoiceid' => $task->invoiceid,
                'notes' => "批量操作：从IP池{$task->target_pool}，替换{$ipv4Count}个IPv4"
            ]);
        }

        return [
            'success' => true,
            'old_ip' => implode(', ', $oldIPv4List),
            'new_ip' => implode(', ', $newIPv4List)
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getAvailableIPFromPool($serverIP, $apiKey, $apiPass, $poolId, $excludeIP) {
    try {
        require_once(ROOTDIR . '/modules/servers/virtualizor/sdk/admin.php');
        $admin = new Virtualizor_Admin_API($serverIP, $apiKey, $apiPass);
        
        $ipsData = $admin->ips(1, 1000, ['ippid' => $poolId]);
        
        if (!isset($ipsData['ips'])) {
            return null;
        }
        
        foreach ($ipsData['ips'] as $ipInfo) {
            if (!filter_var($ipInfo['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                continue;
            }
            
            $vpsid = isset($ipInfo['vpsid']) ? $ipInfo['vpsid'] : 0;
            $locked = isset($ipInfo['locked']) ? $ipInfo['locked'] : 0;
            
            if ($vpsid == 0 && $locked == 0) {
                if ($ipInfo['ip'] !== $excludeIP) {
                    return $ipInfo['ip'];
                }
            }
        }
        
        return null;
    } catch (Exception $e) {
        logActivity("获取可用IP失败: " . $e->getMessage());
        return null;
    }
}

function getCurrentVPSIP($vpsId, $params) {
    try {
        $key = $params['admin_api_key'];
        $pass = $params['admin_api_pass'];
        $ip = $params['server_ip'];

        require_once(ROOTDIR . '/modules/servers/virtualizor/sdk/admin.php');
        $admin = new Virtualizor_Admin_API($ip, $key, $pass);

        $output = $admin->listvs(1, 1, array('vpsid' => $vpsId));
        
        if (isset($output['vs'][$vpsId]['ips']) && !empty($output['vs'][$vpsId]['ips'])) {
            $ips = $output['vs'][$vpsId]['ips'];
            foreach ($ips as $ipAddr) {
                if (filter_var($ipAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ipAddr;
                }
            }
        }
        
        return '未知';
    } catch (Exception $e) {
        return '获取失败';
    }
}

if (!function_exists('vpsipchange_findHostingByVPSID')) {
    function vpsipchange_findHostingByVPSID($vpsId) {
        $hostings = Capsule::table('tblhosting')->get();
        
        foreach ($hostings as $hosting) {
            $fields = Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $hosting->id)
                ->get();
            
            foreach ($fields as $field) {
                if ($field->value == $vpsId) {
                    return $hosting;
                }
            }
        }
        
        return null;
    }
}

if (!function_exists('vpsipchange_findVPSIDByHosting')) {
    function vpsipchange_findVPSIDByHosting($hostingId) {
        $fields = Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $hostingId)
            ->get();

        foreach ($fields as $field) {
            if (ctype_digit($field->value)) {
                return (int)$field->value;
            }
        }
        return null;
    }
}

if (!function_exists('findVPSIDByHosting')) {
    function findVPSIDByHosting($hostingId) {
        return vpsipchange_findVPSIDByHosting($hostingId);
    }
}

if (!function_exists('findHostingByVPSID')) {
    function findHostingByVPSID($vpsId) {
        return vpsipchange_findHostingByVPSID($vpsId);
    }
}

<?php
use WHMCS\Database\Capsule;

add_hook('InvoicePaid', 1, function($vars) {
    $invoiceId = $vars['invoiceid'];

    try {
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get();

        foreach ($items as $item) {
            if (strpos($item->description, 'VPS更换IP费用') !== false) {
                if (preg_match('/VPS ID:\s*(\d+)/', $item->description, $matches)) {
                    $vps_id = (int)$matches[1];
                    
                    $targetPool = null;
                    if (preg_match('/目标IP池:\s*(\d+)/', $item->description, $poolMatches)) {
                        $targetPool = (int)$poolMatches[1];
                    }
                    
                    logActivity("检测到IP更换支付，VPS ID: {$vps_id}, 目标池: " . ($targetPool ?: '自动'));

                    $existing = Capsule::table('mod_vpsipchange_queue')
                        ->where('vps_id', $vps_id)
                        ->where('invoiceid', $invoiceId)
                        ->where('status', 'pending')
                        ->first();
                    
                    if ($existing) {
                        logActivity("VPS {$vps_id} 已在队列中，跳过");
                        continue;
                    }
                    
                    $changeResult = changeVPSIPDirect($vps_id, $invoiceId, $item->userid, $item->relid, $targetPool);

                    if ($changeResult['success']) {
                        logActivity("VPS IP更换成功，VPS ID: {$vps_id}, {$changeResult['old_ip']} → {$changeResult['new_ip']}");
                    } else {
                        logActivity("VPS IP更换失败，VPS ID: {$vps_id}, 错误: " . $changeResult['error']);
                    }
                }
            }
        }
    } catch (Exception $e) {
        logActivity("InvoicePaid Hook错误: " . $e->getMessage());
    }
});

function changeVPSIPDirect($vps_id, $invoiceId, $userId, $hostingId, $targetPool = null) {
    $lockFile = ROOTDIR . "/tmp/vpsipchange_{$vps_id}.lock";
    
    if (!is_dir(ROOTDIR . '/tmp')) {
        mkdir(ROOTDIR . '/tmp', 0755, true);
    }
    
    if (file_exists($lockFile)) {
        $lockTime = filemtime($lockFile);
        if (time() - $lockTime > 300) {
            unlink($lockFile);
        } else {
            return ['success' => false, 'error' => 'VPS正在被其他进程处理'];
        }
    }
    
    file_put_contents($lockFile, time());
    
    try {
        $result = changeVPSIPWithWait($vps_id, $invoiceId, $userId, $hostingId, $targetPool);
        
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        return $result;
        
    } catch (Exception $e) {
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function changeVPSIPWithWait($vps_id, $invoiceId, $userId, $hostingId, $targetPool = null) {
    logActivity("═══ 开始IP更换流程 ═══");
    logActivity("VPS ID: {$vps_id}, 发票: {$invoiceId}, 目标池: " . ($targetPool ?: '自动'));

    try {
        $params = getAddonModuleParams('vpsipchange');

        if (empty($params['admin_api_key']) || empty($params['admin_api_pass']) || empty($params['server_ip'])) {
            return ['success' => false, 'error' => '模块配置缺失'];
        }

        $key = $params['admin_api_key'];
        $pass = $params['admin_api_pass'];
        $ip = $params['server_ip'];
        
        $stopWaitTime = (int)($params['stop_wait_time'] ?? 10);
        $startWaitTime = (int)($params['start_wait_time'] ?? 15);
        
        logActivity("等待配置 - Stop: {$stopWaitTime}秒, Start: {$startWaitTime}秒");

        require_once(ROOTDIR . '/modules/servers/virtualizor/sdk/admin.php');
        $admin = new Virtualizor_Admin_API($ip, $key, $pass);

        // 步骤1: 获取VPS当前信息
        logActivity("步骤1: 获取VPS当前信息");
        $vpsInfo = $admin->listvs(1, 1, array('vpsid' => $vps_id));
        
        if (!isset($vpsInfo['vs'][$vps_id])) {
            return ['success' => false, 'error' => '无法获取VPS信息'];
        }

        $vps = $vpsInfo['vs'][$vps_id];
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
        logActivity("当前IPv4地址: " . implode(', ', $oldIPv4List));
        logActivity("当前IPv6地址: " . (empty($oldIPv6List) ? '无' : implode(', ', $oldIPv6List)));
        logActivity("需要获取 {$ipv4Count} 个新IPv4地址");

        // 步骤2: 获取新的IPv4地址（与旧IPv4数量相同）
        logActivity("步骤2: 获取可用的新IPv4地址");

        $newIPv4List = [];
        for ($i = 0; $i < $ipv4Count; $i++) {
            $excludeIPs = array_merge($oldIPv4List, $newIPv4List); // 排除旧IP和已获取的新IP

            if ($targetPool) {
                $newIP = getAvailableIPFromPool_Fixed($admin, $targetPool, implode(',', $excludeIPs));
            } else {
                $newIP = getAvailableIP_Fixed($admin, '', implode(',', $excludeIPs));
            }

            if (!$newIP) {
                $poolMsg = $targetPool ? "IP池{$targetPool}" : "系统";
                return ['success' => false, 'error' => "{$poolMsg}可用IP不足，需要{$ipv4Count}个，只获取到{$i}个"];
            }

            $newIPv4List[] = $newIP;
            logActivity("获取新IPv4 " . ($i + 1) . "/{$ipv4Count}: {$newIP}");
        }

        // 构建新的IP列表：所有新IPv4 + 所有IPv6
        $newIPList = array_merge($newIPv4List, $oldIPv6List);
        logActivity("新IP列表: " . implode(', ', $newIPList));

        // 步骤3: 更换IP配置
        logActivity("步骤3: 更换IP配置");

        $post = [
            'vpsid' => $vps_id,
            'ips' => $newIPList,  // 所有新IPv4 + 保留的IPv6
            'hostname' => $vps['hostname'] ?? '',
            'ram' => $vps['ram'] ?? 512,
            'cores' => $vps['cores'] ?? 1,
            'bandwidth' => $vps['bandwidth'] ?? 0,
        ];
        
        $manageResult = $admin->managevps($post);

        if (!isset($manageResult['done']['done']) || !$manageResult['done']['done']) {
            $errorMsg = isset($manageResult['error']) ? json_encode($manageResult['error']) : '未知错误';
            return ['success' => false, 'error' => "IP配置更新失败: {$errorMsg}"];
        }

        logActivity("IP配置已更新");
        sleep(3);

        // 步骤4: 停止VPS
        logActivity("步骤4: 停止VPS");
        $stopResult = $admin->stop($vps_id);

        if (!isset($stopResult['done']) || !$stopResult['done']) {
            logActivity("警告: VPS停止信号发送可能失败");
            $errorMsg = isset($stopResult['error']) ? json_encode($stopResult['error']) : '未知错误';
            logActivity("停止错误: {$errorMsg}");
        } else {
            logActivity("VPS已发送停止信号");
        }

        logActivity("等待VPS完全停止 ({$stopWaitTime}秒)...");
        sleep($stopWaitTime);

        $statusCheck = waitForVPSStatus($admin, $vps_id, 'stopped', 30);
        if ($statusCheck) {
            logActivity("VPS已确认停止");
        } else {
            logActivity("警告: 无法确认VPS停止状态");
        }

        // 步骤5: 启动VPS
        logActivity("步骤5: 启动VPS");
        $startResult = $admin->start($vps_id);
        
        if (!isset($startResult['done']) || !$startResult['done']) {
            logActivity("警告: VPS启动信号发送可能失败");
            $errorMsg = isset($startResult['error']) ? json_encode($startResult['error']) : '未知错误';
            logActivity("启动错误: {$errorMsg}");
        } else {
            logActivity("VPS已发送启动信号");
        }
        
        logActivity("等待VPS完全启动 ({$startWaitTime}秒)...");
        sleep($startWaitTime);
        
        $startCheck = waitForVPSStatus($admin, $vps_id, 'running', 60);
        if ($startCheck) {
            logActivity("VPS已确认启动");
        } else {
            logActivity("警告: 无法确认VPS启动状态");
        }

        // 步骤6: 记录到数据库
        logActivity("步骤6: 记录操作日志");
        
        $service = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblhosting.id', $hostingId)
            ->select('tblproducts.name as productname')
            ->first();

        $fee = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->where('description', 'like', "%VPS ID: {$vps_id}%")
            ->value('amount') ?? 0;

        Capsule::table('mod_vpsipchange_logs')->insert([
            'userid' => $userId,
            'productname' => $service->productname ?? '未知产品',
            'vps_id' => $vps_id,
            'old_ip' => implode(', ', $oldIPv4List),
            'new_ip' => implode(', ', $newIPv4List),
            'change_time' => date('Y-m-d H:i:s'),
            'fee' => $fee,
            'invoiceid' => $invoiceId,
            'notes' => $targetPool ? "从IP池{$targetPool}分配，替换{$ipv4Count}个IPv4" : "自动分配，替换{$ipv4Count}个IPv4"
        ]);

        logActivity("═══ IP更换完成 ═══");
        logActivity("旧IPv4: " . implode(', ', $oldIPv4List));
        logActivity("新IPv4: " . implode(', ', $newIPv4List));

        return [
            'success' => true,
            'old_ip' => implode(', ', $oldIPv4List),
            'new_ip' => implode(', ', $newIPv4List)
        ];

    } catch (Exception $e) {
        logActivity("═══ IP更换异常 ═══");
        logActivity("VPS ID: {$vps_id}");
        logActivity("错误: " . $e->getMessage());
        logActivity("堆栈: " . $e->getTraceAsString());
        
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function waitForVPSStatus($admin, $vpsId, $targetStatus, $maxWait = 30) {
    $waited = 0;
    $checkInterval = 3;
    
    while ($waited < $maxWait) {
        try {
            $vpsInfo = $admin->listvs(1, 1, array('vpsid' => $vpsId));
            
            if (isset($vpsInfo['vs'][$vpsId])) {
                $status = $vpsInfo['vs'][$vpsId]['status'] ?? '';
                
                $isRunning = in_array($status, ['running', 'online', '1']);
                $isStopped = in_array($status, ['stopped', 'offline', '0', 'halted']);
                
                if (($targetStatus == 'running' && $isRunning) || 
                    ($targetStatus == 'stopped' && $isStopped)) {
                    return true;
                }
            }
            
            sleep($checkInterval);
            $waited += $checkInterval;
            
        } catch (Exception $e) {
            logActivity("状态检查失败: " . $e->getMessage());
            return false;
        }
    }
    
    return false;
}

/**
 * 获取可用IP - 使用正确的SDK方法
 */
function getAvailableIP_Fixed($admin, $ipPoolId, $excludeIP) {
    try {
        $post = array();
        if (!empty($ipPoolId)) {
            $post['ippid'] = $ipPoolId;
        }
        
        // 使用SDK的ips()方法
        $ipsData = $admin->ips(1, 5000, $post);
        
        if (!isset($ipsData['ips']) || empty($ipsData['ips'])) {
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

/**
 * 从指定IP池获取可用IP - 使用正确的SDK方法
 */
function getAvailableIPFromPool_Fixed($admin, $poolId, $excludeIP) {
    try {
        $post = array('ippid' => $poolId);
        
        // 使用SDK的ips()方法
        $ipsData = $admin->ips(1, 5000, $post);
        
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
        logActivity("从IP池{$poolId}获取可用IP失败: " . $e->getMessage());
        return null;
    }
}

function getAddonModuleParams($moduleName) {
    $params = [];
    $moduleConfig = Capsule::table('tbladdonmodules')->where('module', $moduleName)->get();
    foreach ($moduleConfig as $conf) {
        $params[$conf->setting] = $conf->value;
    }
    return $params;
}
